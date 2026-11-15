<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'campaign_id', 'token', 'ip_address', 'user_agent',
    'status', 'triggered_action', 'triggered_at', 'ended_at',
])]
class CampaignSession extends Model
{
    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'ended_at'     => 'datetime',
        ];
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
