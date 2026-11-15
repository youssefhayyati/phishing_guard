"""RabbitMQ connection management (aio-pika).

Establishes a robust AMQP(S) connection with automatic reconnect/backoff and a
publisher-confirms channel, per STRATEGY.md sections 5.5 and 13.
"""
from __future__ import annotations

import aio_pika
from aio_pika.abc import AbstractRobustChannel, AbstractRobustConnection

from app.config import Settings
from app.logging import get_logger

log = get_logger("messaging.connection")


class RabbitMQConnection:
    """Owns the robust connection and a confirm-mode channel."""

    def __init__(self, connection: AbstractRobustConnection, channel: AbstractRobustChannel) -> None:
        self.connection = connection
        self.channel = channel

    @classmethod
    async def connect(cls, settings: Settings) -> "RabbitMQConnection":
        # connect_robust transparently reconnects with backoff on failure.
        connection = await aio_pika.connect_robust(settings.amqp_url)
        channel = await connection.channel(publisher_confirms=True)
        await channel.set_qos(prefetch_count=settings.prefetch_count)
        log.info("rabbitmq.connected", prefetch=settings.prefetch_count)
        return cls(connection, channel)

    async def close(self) -> None:
        try:
            await self.connection.close()
        finally:
            log.info("rabbitmq.closed")
