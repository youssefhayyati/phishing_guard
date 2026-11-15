<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Stable UUID identity used as the RabbitMQ envelope campaign_id.
            $table->uuid('uuid')->nullable()->unique()->after('id');
            // The agent instance serving this campaign.
            $table->uuid('agent_id')->nullable()->after('uuid');
            $table->string('agent_version')->nullable()->after('agent_id');
            // Last agent.heartbeat seen — drives the "agent online" indicator.
            $table->timestamp('agent_last_seen_at')->nullable()->after('agent_version');
        });

        // Generic, idempotent store for incoming agent events (section 4.2 /
        // 5.1): heartbeats are folded into campaigns above; everything else
        // (navigations, training demos, detections, evidence) lands here.
        Schema::create('campaign_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            // Envelope message_id — unique for dedup / idempotency (section 5.5).
            $table->uuid('message_id')->unique();
            $table->string('event_type');       // e.g. session.notification
            $table->uuid('session_id')->nullable();
            $table->string('action')->nullable();
            $table->string('severity')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_events');
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'agent_id', 'agent_version', 'agent_last_seen_at']);
        });
    }
};
