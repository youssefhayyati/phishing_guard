"""Queue/exchange topology (STRATEGY.md section 5.1).

Declares the two durable topic exchanges and this agent's campaign-scoped,
durable command queue, bound to the three lifecycle command routing keys.
"""
from __future__ import annotations

import aio_pika
from aio_pika.abc import AbstractRobustChannel, AbstractRobustExchange, AbstractRobustQueue

from app.config import Settings
from app.logging import get_logger

log = get_logger("messaging.queues")

# Laravel → Python command routing keys (section 5.1).
COMMAND_ROUTING_KEYS = ("campaign.activate", "campaign.stop", "campaign.delete")


class Topology:
    """Declared exchanges + the agent's command queue."""

    def __init__(
        self,
        commands: AbstractRobustExchange,
        events: AbstractRobustExchange,
        command_queue: AbstractRobustQueue,
    ) -> None:
        self.commands = commands
        self.events = events
        self.command_queue = command_queue

    @classmethod
    async def declare(cls, channel: AbstractRobustChannel, settings: Settings) -> "Topology":
        commands = await channel.declare_exchange(
            settings.commands_exchange, aio_pika.ExchangeType.TOPIC, durable=True
        )
        events = await channel.declare_exchange(
            settings.events_exchange, aio_pika.ExchangeType.TOPIC, durable=True
        )

        # Durable, campaign-scoped command queue (section 12 queue isolation).
        queue = await channel.declare_queue(settings.resolved_command_queue, durable=True)
        for key in COMMAND_ROUTING_KEYS:
            await queue.bind(commands, routing_key=key)

        log.info(
            "topology.declared",
            command_queue=settings.resolved_command_queue,
            commands_exchange=settings.commands_exchange,
            events_exchange=settings.events_exchange,
        )
        return cls(commands, events, queue)
