<?php

namespace App\Services;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Thin RabbitMQ helper for the control plane (STRATEGY.md section 5).
 *
 * Laravel publishes commands to `phishguard.commands` and consumes events from
 * `phishguard.events`. Both are durable topic exchanges; messages are persistent.
 */
class RabbitMq
{
    public const COMMANDS_EXCHANGE = 'phishguard.commands';
    public const EVENTS_EXCHANGE = 'phishguard.events';
    public const EVENTS_QUEUE = 'laravel.events';

    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;

    public function channel(): AMQPChannel
    {
        if ($this->channel) {
            return $this->channel;
        }

        $url = env('PHISHGUARD_AMQP_URL', 'amqp://guest:guest@rabbitmq:5672/');
        $p = parse_url($url);
        $vhost = isset($p['path']) && $p['path'] !== '' ? ltrim($p['path'], '/') : '/';
        $vhost = $vhost === '' ? '/' : $vhost;

        $this->connection = new AMQPStreamConnection(
            $p['host'] ?? 'rabbitmq',
            $p['port'] ?? 5672,
            isset($p['user']) ? urldecode($p['user']) : 'guest',
            isset($p['pass']) ? urldecode($p['pass']) : 'guest',
            $vhost,
        );
        $this->channel = $this->connection->channel();
        $this->channel->confirm_select(); // publisher confirms (section 5.5)
        $this->declareTopology($this->channel);

        return $this->channel;
    }

    /** Declare the exchanges and the control-plane events queue + bindings. */
    public function declareTopology(AMQPChannel $ch): void
    {
        $ch->exchange_declare(self::COMMANDS_EXCHANGE, 'topic', false, true, false);
        $ch->exchange_declare(self::EVENTS_EXCHANGE, 'topic', false, true, false);

        // One durable queue consumes every Python → Laravel routing key (5.1).
        $ch->queue_declare(self::EVENTS_QUEUE, false, true, false, false);
        foreach (['campaign.*', 'session.*', 'agent.*', 'training.*', 'security.*', 'evidence.*'] as $key) {
            $ch->queue_bind(self::EVENTS_QUEUE, self::EVENTS_EXCHANGE, $key);
        }
    }

    /**
     * Publish a command to the agent (STRATEGY.md 5.3). The envelope mirrors the
     * agent's schema so the Python side validates it.
     */
    public function publishCommand(string $eventType, string $campaignUuid, ?string $agentId, array $payload): void
    {
        $ch = $this->channel();

        $envelope = [
            'message_id'     => (string) \Illuminate\Support\Str::uuid(),
            'correlation_id' => null,
            'campaign_id'    => $campaignUuid,
            'agent_id'       => $agentId,
            'event_type'     => $eventType,
            'schema_version' => 1,
            'timestamp'      => now()->toIso8601String(),
            'payload'        => (object) $payload,
        ];

        $msg = new AMQPMessage(json_encode($envelope), [
            'content_type'  => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id'    => $envelope['message_id'],
            'type'          => $eventType,
        ]);

        $ch->basic_publish($msg, self::COMMANDS_EXCHANGE, $eventType);
        $ch->wait_for_pending_acks(5.0);
    }

    public function close(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (\Throwable) {
            // ignore
        } finally {
            $this->channel = null;
            $this->connection = null;
        }
    }
}
