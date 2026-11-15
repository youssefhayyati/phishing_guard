"""Event publisher (STRATEGY.md sections 5.1, 5.5).

Publishes validated event envelopes to `phishguard.events` with persistent
delivery and publisher confirms (the channel is in confirm mode).
"""
from __future__ import annotations

from uuid import UUID

import aio_pika
from pydantic import BaseModel

from app.config import Settings
from app.logging import get_logger
from app.messaging.queues import Topology
from app.schemas.messages import Envelope

log = get_logger("messaging.publisher")


class EventPublisher:
    def __init__(self, topology: Topology, settings: Settings) -> None:
        self._events = topology.events
        self._settings = settings

    async def publish(
        self,
        event_type: str,
        payload: BaseModel,
        *,
        correlation_id: UUID | None = None,
    ) -> None:
        envelope: Envelope = Envelope(
            campaign_id=self._settings.campaign_id,
            agent_id=self._settings.agent_id,
            correlation_id=correlation_id,
            event_type=event_type,
            payload=payload,
        )
        message = aio_pika.Message(
            body=envelope.to_json().encode(),
            content_type="application/json",
            delivery_mode=aio_pika.DeliveryMode.PERSISTENT,
            message_id=str(envelope.message_id),
            correlation_id=str(correlation_id) if correlation_id else None,
            type=event_type,
        )
        # Routing key is the event type (topic exchange) — section 5.1.
        await self._events.publish(message, routing_key=event_type)
        log.info("event.published", event_type=event_type, message_id=str(envelope.message_id))
