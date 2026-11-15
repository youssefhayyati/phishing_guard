"""PhishGuard agent entrypoint.

Establishes the RabbitMQ communication protocol first (STRATEGY.md section 18),
then supervises the long-running tasks (section 6.2):

  * command consumer   — campaign.activate / stop / delete
  * heartbeat publisher — agent.heartbeat every N seconds
  * session monitor     — coarse session.notification events from the browser

Browser/analysis/evidence automation is driven by incoming commands and hangs
off these channels; this module owns only the wiring and supervision.
"""
from __future__ import annotations

import asyncio
import contextlib

from app.config import Settings, get_settings
from app.logging import configure_logging, get_logger
from app.messaging.connection import RabbitMQConnection
from app.messaging.consumer import CommandConsumer
from app.messaging.publisher import EventPublisher
from app.messaging.queues import Topology
from app.monitoring.heartbeat import HeartbeatPublisher
from app.monitoring.session_events import SessionEventMonitor
from app.schemas.events import (
    ActivationResultPayload,
    DeleteResultPayload,
    EventType,
    StopResultPayload,
)


def _build_command_handler(publisher: EventPublisher, settings: Settings, log):
    """Return an async handler that answers lifecycle commands with results."""

    async def handle(envelope: dict) -> None:
        event_type = envelope.get("event_type")
        correlation_id = envelope.get("message_id")
        log.info("command.received", event_type=event_type)

        if event_type == "campaign.activate":
            await publisher.publish(
                EventType.ACTIVATED.value,
                ActivationResultPayload(agent_version=settings.agent_version),
                correlation_id=correlation_id,
            )
        elif event_type == "campaign.stop":
            await publisher.publish(
                EventType.STOPPED.value,
                StopResultPayload(),
                correlation_id=correlation_id,
            )
        elif event_type == "campaign.delete":
            await publisher.publish(
                EventType.DELETED.value,
                DeleteResultPayload(),
                correlation_id=correlation_id,
            )
        else:
            log.warning("command.unknown", event_type=event_type)

    return handle


async def run() -> None:
    settings = get_settings()
    configure_logging(settings.log_level, settings.log_json)
    log = get_logger("agent")
    log.info(
        "agent.starting",
        agent_id=str(settings.agent_id),
        campaign_id=str(settings.campaign_id),
        version=settings.agent_version,
    )

    connection = await RabbitMQConnection.connect(settings)
    topology = await Topology.declare(connection.channel, settings)
    publisher = EventPublisher(topology, settings)

    # Announce readiness immediately so the control plane sees the agent online
    # even before it receives an explicit activate command.
    await publisher.publish(
        EventType.ACTIVATED.value,
        ActivationResultPayload(agent_version=settings.agent_version),
    )

    consumer = CommandConsumer(topology, settings, _build_command_handler(publisher, settings, log))
    heartbeat = HeartbeatPublisher(publisher, settings)
    sessions = SessionEventMonitor(publisher, settings)

    tasks = [
        asyncio.create_task(consumer.run()),
        asyncio.create_task(heartbeat.run()),
        asyncio.create_task(sessions.run()),
    ]
    try:
        await asyncio.gather(*tasks)
    except asyncio.CancelledError:  # pragma: no cover
        pass
    finally:
        for t in tasks:
            t.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await t
        await connection.close()
        log.info("agent.stopped")


def main() -> None:
    try:
        asyncio.run(run())
    except KeyboardInterrupt:  # pragma: no cover
        pass


if __name__ == "__main__":
    main()
