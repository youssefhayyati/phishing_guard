<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A single provisioning run for a campaign: SSH to the target server,
 * install dependencies, clone the project, start the containers, and
 * publish the link to the modified-site session.
 */
#[Fillable([
    'campaign_id', 'server_id', 'status', 'current_step',
    'log', 'link', 'admin_link', 'error', 'started_at', 'finished_at',
])]
class Deployment extends Model
{
    protected function casts(): array
    {
        return [
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Append a line to the streamed log and persist immediately so the
     * polling UI sees progress as it happens.
     */
    public function pushLog(string $text): void
    {
        $this->log = ($this->log ?? '') . $text;
        $this->save();
    }
}
