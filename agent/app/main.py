"""PhishGuard agent entrypoint.

Starts the agent and supervises its long-running tasks (STRATEGY.md section
6.2): the RabbitMQ command consumer and the heartbeat publisher. Browser,
analysis, and evidence work is driven by incoming commands.

This is the scaffold wiring for the first implementation milestone (section
18): the communication protocol is established before browser automation, so
the agent can be tested independently.
"""
from __future__ import annotations

import asyncio
import contextlib

from app.config import get_settings
from app.logging import configure_logging, get_logger


async def run() -> None:
    settings = get_settings()
    configure_logging(settings.log_level, settings.log_json)
    log = get_logger("agent")
    log.info("agent.starting", agent_id=str(settings.agent_id), version=settings.agent_version)

    # TODO (phase 2): wire concrete implementations.
    #   connection = await RabbitMQConnection.connect(settings)
    #   consumer    = CommandConsumer(connection, campaign_manager)
    #   heartbeat   = HeartbeatPublisher(connection, settings)
    #   await asyncio.gather(consumer.run(), heartbeat.run())
    tasks: list[asyncio.Task] = []
    try:
        if not tasks:
            log.warning("agent.idle", reason="no tasks wired yet — scaffold only")
            await asyncio.Event().wait()  # park until cancelled
        else:
            await asyncio.gather(*tasks)
    except asyncio.CancelledError:  # pragma: no cover
        pass
    finally:
        for t in tasks:
            t.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await t
        log.info("agent.stopped")


def main() -> None:
    try:
        asyncio.run(run())
    except KeyboardInterrupt:  # pragma: no cover
        pass


if __name__ == "__main__":
    main()
