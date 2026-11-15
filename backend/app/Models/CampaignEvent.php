<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An event received from a campaign agent over RabbitMQ (STRATEGY.md 5.1).
 * Stored idempotently keyed on the envelope message_id.
 */
#[Fillable([
    'campaign_id', 'message_id', 'event_type', 'session_id',
    'action', 'severity', 'payload', 'occurred_at',
])]
class CampaignEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload'     => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
