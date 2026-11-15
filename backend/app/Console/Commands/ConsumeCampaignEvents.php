<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignSession;
use App\Services\RabbitMq;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Long-running consumer: reads agent events from `phishguard.events` and
 * persists them so the dashboard reflects what happens on campaign servers
 * (STRATEGY.md section 5.1). Runs in the backend container alongside the API.
 */
class ConsumeCampaignEvents extends Command
{
    protected $signature = 'phishguard:consume';
    protected $description = 'Consume campaign agent events from RabbitMQ and persist them';

    public function handle(RabbitMq $rabbit): int
    {
        $ch = $rabbit->channel();
        $ch->basic_qos(null, 20, null);

        $this->info('Consuming ' . RabbitMq::EVENTS_QUEUE . ' …');

        $ch->basic_consume(
            RabbitMq::EVENTS_QUEUE,
            '',
            false,
            false, // manual ack (section 5.5)
            false,
            false,
            fn (AMQPMessage $msg) => $this->onMessage($msg),
        );

        while ($ch->is_consuming()) {
            try {
                $ch->wait();
            } catch (\Throwable $e) {
                $this->error('consume error: ' . $e->getMessage());
                break;
            }
        }

        $rabbit->close();
        return self::SUCCESS;
    }

    private function onMessage(AMQPMessage $msg): void
    {
        try {
            $env = json_decode($msg->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $this->persist($env);
            $msg->ack();
        } catch (\Throwable $e) {
            // Malformed/poison message — ack to avoid a tight redelivery loop.
            $this->error('persist failed: ' . $e->getMessage());
            $msg->ack();
        }
    }

    private function persist(array $env): void
    {
        $campaign = Campaign::where('uuid', $env['campaign_id'] ?? null)->first();
        if (! $campaign) {
            return; // unknown campaign — ignore (campaign isolation)
        }

        $type      = $env['event_type'] ?? 'unknown';
        $payload   = $env['payload'] ?? [];
        $messageId = $env['message_id'] ?? (string) \Illuminate\Support\Str::uuid();
        $occurred  = $env['timestamp'] ?? now()->toIso8601String();

        // Heartbeats only refresh the agent liveness fields (not stored as rows).
        if ($type === 'agent.heartbeat') {
            $campaign->update([
                'agent_last_seen_at' => now(),
                'agent_version'      => $payload['agent_version'] ?? $campaign->agent_version,
            ]);
            return;
        }

        // Activation result — record agent version + liveness.
        if ($type === 'campaign.activated') {
            $campaign->update([
                'agent_version'      => $payload['agent_version'] ?? $campaign->agent_version,
                'agent_last_seen_at' => now(),
            ]);
        }

        // Session notifications maintain the campaign_sessions table.
        if ($type === 'session.notification') {
            $this->applySessionNotification($campaign, $payload);
        }

        // Store everything (except heartbeats) idempotently for the event feed.
        CampaignEvent::updateOrCreate(
            ['message_id' => $messageId],
            [
                'campaign_id' => $campaign->id,
                'event_type'  => $type,
                'session_id'  => $payload['session_id'] ?? null,
                'action'      => $payload['action'] ?? null,
                'severity'    => $payload['severity'] ?? $payload['classification'] ?? null,
                'payload'     => $payload,
                'occurred_at' => $occurred,
            ],
        );
    }

    private function applySessionNotification(Campaign $campaign, array $payload): void
    {
        $sessionId = $payload['session_id'] ?? null;
        $action    = $payload['action'] ?? null;
        if (! $sessionId) {
            return;
        }

        $session = CampaignSession::firstOrNew([
            'campaign_id' => $campaign->id,
            'token'       => $sessionId,
        ]);

        if (! $session->exists) {
            $session->status = 'active';
            $session->ip_address = $payload['metadata']['ip'] ?? null;
            $session->user_agent = $payload['metadata']['browser'] ?? null;
        }

        match ($action) {
            'user_left', 'session_error' => tap($session, function ($s) {
                $s->status = 'expired';
                $s->ended_at = now();
            }),
            'click_activity', 'form_interaction_detected' => tap($session, function ($s) use ($action) {
                $s->triggered_action = $action;
                $s->triggered_at = now();
                if ($s->status === 'active') {
                    $s->status = 'triggered';
                }
            }),
            default => null, // user_joined, page_navigation, idle → keep active
        };

        $session->save();
    }
}
