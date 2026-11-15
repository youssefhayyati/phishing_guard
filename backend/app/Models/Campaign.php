<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A simulated phishing campaign run against consented employees
 * for security-awareness training. Stores only aggregate counts.
 */
#[Fillable([
    'user_id', 'server_id', 'uuid', 'agent_id', 'agent_version', 'agent_last_seen_at',
    'name', 'description', 'template', 'status',
    'target_url', 'server_host', 'server_domain', 'deploy_repo_url', 'deploy_branch', 'site_tweaks',
    'proxy_enabled', 'proxy_on_the_fly',
    'redirect_type', 'redirect_target', 'trigger_action', 'kill_session_url',
    'sent', 'opened', 'clicked', 'reported', 'start_date',
])]
class Campaign extends Model
{
    protected function casts(): array
    {
        return [
            'start_date'         => 'date',
            'proxy_enabled'      => 'boolean',
            'proxy_on_the_fly'   => 'boolean',
            'sent'               => 'integer',
            'opened'             => 'integer',
            'clicked'            => 'integer',
            'reported'           => 'integer',
            'agent_last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Give every campaign a stable UUID + agent identity for RabbitMQ
        // message routing/isolation (STRATEGY.md section 5.2).
        static::creating(function (Campaign $c) {
            $c->uuid ??= (string) \Illuminate\Support\Str::uuid();
            $c->agent_id ??= (string) \Illuminate\Support\Str::uuid();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sessions()
    {
        return $this->hasMany(CampaignSession::class);
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function deployments()
    {
        return $this->hasMany(Deployment::class);
    }

    public function events()
    {
        return $this->hasMany(CampaignEvent::class);
    }

    public function latestDeployment()
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }

    /**
     * Give a user a realistic set of starter campaigns so their
     * dashboard is populated from day one. Idempotent.
     *
     * @return void
     */
    public static function seedSamplesFor(User $user): void
    {
        $samples = [
            ['Q4 Payroll Update', 'HR / Payroll notice', 'completed', 420, 310, 96, 142, '-40 days'],
            ['IT Password Reset', 'IT service desk', 'completed', 380, 265, 74, 118, '-28 days'],
            ['Shared Document Invite', 'Cloud file share', 'active', 512, 301, 88, 97, '-6 days'],
            ['Benefits Enrollment', 'HR / Benefits', 'active', 268, 140, 39, 61, '-3 days'],
            ['Package Delivery Notice', 'Courier notice', 'scheduled', 0, 0, 0, 0, '+5 days'],
            ['CEO Urgent Request', 'Business email (BEC)', 'draft', 0, 0, 0, 0, '+12 days'],
        ];

        foreach ($samples as [$name, $template, $status, $sent, $opened, $clicked, $reported, $date]) {
            static::updateOrCreate(
                ['user_id' => $user->id, 'name' => $name],
                [
                    'template' => $template,
                    'status' => $status,
                    'sent' => $sent,
                    'opened' => $opened,
                    'clicked' => $clicked,
                    'reported' => $reported,
                    'start_date' => now()->modify($date)->toDateString(),
                ],
            );
        }
    }
}
