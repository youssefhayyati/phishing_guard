"""Heartbeat publisher (STRATEGY.md section 13).

Publishes an `agent.heartbeat` event on a fixed interval so the control plane
can track agent health and detect stalls.
"""
from __future__ import annotations

import asyncio
import time

from app.config import Settings
from app.logging import get_logger
from app.messaging.publisher import EventPublisher
from app.schemas.events import EventType, HeartbeatPayload

log = get_logger("monitoring.heartbeat")


class HeartbeatPublisher:
    def __init__(self, publisher: EventPublisher, settings: Settings) -> None:
        self._publisher = publisher
        self._settings = settings
        self._started = time.monotonic()

    async def run(self) -> None:
        interval = self._settings.heartbeat_interval_seconds
        log.info("heartbeat.start", interval=interval)
        while True:
            payload = HeartbeatPayload(
                agent_version=self._settings.agent_version,
                status="healthy",
                uptime_seconds=round(time.monotonic() - self._started, 1),
            )
            try:
                await self._publisher.publish(EventType.AGENT_HEARTBEAT.value, payload)
            except Exception as exc:  # pragma: no cover - keep looping on transient errors
                log.warning("heartbeat.publish_failed", error=str(exc))
            await asyncio.sleep(interval)
