"""Command consumer (STRATEGY.md sections 5.1, 5.5).

Consumes the agent's campaign-scoped command queue with manual acknowledgements
and a bounded idempotency cache keyed on message_id, so duplicate deliveries do
not run a command twice.
"""
from __future__ import annotations

from collections import deque
from typing import Awaitable, Callable

from aio_pika.abc import AbstractIncomingMessage

from app.config import Settings
from app.logging import get_logger
from app.messaging.queues import Topology

log = get_logger("messaging.consumer")

# Handler receives the parsed envelope dict; raising requeues (nack).
CommandHandler = Callable[[dict], Awaitable[None]]


class CommandConsumer:
    def __init__(self, topology: Topology, settings: Settings, handler: CommandHandler) -> None:
        self._queue = topology.command_queue
        self._settings = settings
        self._handler = handler
        self._seen: deque[str] = deque(maxlen=2048)
        self._seen_set: set[str] = set()

    async def run(self) -> None:
        log.info("consumer.start", queue=self._settings.resolved_command_queue)
        await self._queue.consume(self._on_message)

    async def _on_message(self, message: AbstractIncomingMessage) -> None:
        mid = message.message_id or ""
        # Idempotency (section 5.5 rule 5): drop a message_id we've handled.
        if mid and mid in self._seen_set:
            await message.ack()
            log.info("command.duplicate_dropped", message_id=mid)
            return
        try:
            import json

            envelope = json.loads(message.body.decode())
        except Exception as exc:  # malformed → ack to avoid poison loop
            await message.ack()
            log.warning("command.malformed", error=str(exc))
            return

        # Campaign isolation (section 5.2): ignore commands for other campaigns.
        if str(envelope.get("campaign_id")) != str(self._settings.campaign_id):
            await message.ack()
            log.warning("command.wrong_campaign", campaign_id=str(envelope.get("campaign_id")))
            return

        try:
            await self._handler(envelope)
            await message.ack()
            if mid:
                self._remember(mid)
        except Exception as exc:  # pragma: no cover - requeue once for retry
            await message.nack(requeue=not message.redelivered)
            log.error("command.failed", event_type=envelope.get("event_type"), error=str(exc))

    def _remember(self, mid: str) -> None:
        if len(self._seen) == self._seen.maxlen:
            self._seen_set.discard(self._seen[0])
        self._seen.append(mid)
        self._seen_set.add(mid)
