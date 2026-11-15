"""Session event monitor (STRATEGY.md section 5.4).

Observes the browser through the Chrome DevTools Protocol HTTP endpoint and
emits *coarse* `session.notification` events: a `user_joined` when a real page
first appears, and `page_navigation` when the active page's URL changes.

This records interaction metadata only. It never captures typed characters,
passwords, cookies, or page contents (sections 5.4, 11, 13).
"""
from __future__ import annotations

import asyncio
from datetime import datetime, timezone
from uuid import uuid5, NAMESPACE_URL

import httpx

from app.config import Settings
from app.logging import get_logger
from app.messaging.publisher import EventPublisher
from app.schemas.events import EventType, SessionAction, SessionNotificationPayload

log = get_logger("monitoring.session_events")


def _now() -> datetime:
    return datetime.now(timezone.utc)


class SessionEventMonitor:
    def __init__(self, publisher: EventPublisher, settings: Settings) -> None:
        self._publisher = publisher
        self._settings = settings
        # Stable per-campaign session id (one shared browser session per campaign).
        self._session_id = uuid5(NAMESPACE_URL, f"phishguard-session-{settings.campaign_id}")
        self._joined = False
        self._last_url: str | None = None

    async def run(self) -> None:
        url = f"{self._settings.browser_cdp_url.rstrip('/')}/json/list"
        interval = self._settings.session_poll_seconds
        log.info("session_monitor.start", cdp=url, interval=interval)
        # Chrome's CDP rejects any Host header that isn't localhost or an IP
        # (DevTools remote security check). The connection already arrives over
        # loopback via the container's socat bridge, so spoofing Host=localhost
        # satisfies the check without exposing the debugger more widely.
        headers = {"Host": "localhost"}
        async with httpx.AsyncClient(timeout=4.0, headers=headers) as client:
            while True:
                try:
                    await self._tick(client, url)
                except Exception as exc:  # pragma: no cover - keep polling
                    log.debug("session_monitor.tick_failed", error=str(exc))
                await asyncio.sleep(interval)

    async def _tick(self, client: httpx.AsyncClient, url: str) -> None:
        resp = await client.get(url)
        resp.raise_for_status()
        tabs = resp.json()
        # Pick the first real "page" tab (ignore devtools/extension targets).
        page = next(
            (t for t in tabs if t.get("type") == "page" and t.get("url", "").startswith("http")),
            None,
        )
        if not page:
            return

        current = page.get("url", "")
        if not self._joined:
            self._joined = True
            await self._emit(SessionAction.USER_JOINED, {"browser": "chromium"})

        if current != self._last_url:
            self._last_url = current
            # Report only the origin + path, never query/fragment (may carry tokens).
            await self._emit(SessionAction.PAGE_NAVIGATION, {"url": _sanitize_url(current)})

    async def _emit(self, action: SessionAction, metadata: dict) -> None:
        payload = SessionNotificationPayload(
            session_id=self._session_id,
            action=action,
            timestamp=_now(),
            metadata=metadata,
        )
        await self._publisher.publish(EventType.SESSION_NOTIFICATION.value, payload)


def _sanitize_url(raw: str) -> str:
    """Strip query string and fragment so no tokens/secrets leak upstream."""
    for sep in ("?", "#"):
        i = raw.find(sep)
        if i != -1:
            raw = raw[:i]
    return raw[:512]
