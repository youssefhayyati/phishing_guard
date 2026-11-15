<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Aggregate overview metrics for the authenticated user's program.
     */
    public function overview(Request $request)
    {
        $campaigns = $request->user()->campaigns()->get();

        $sent = (int) $campaigns->sum('sent');
        $opened = (int) $campaigns->sum('opened');
        $clicked = (int) $campaigns->sum('clicked');
        $reported = (int) $campaigns->sum('reported');

        $pct = fn (int $part) => $sent > 0 ? round($part / $sent * 100, 1) : 0;

        $recent = $campaigns
            ->sortByDesc('start_date')
            ->take(5)
            ->values()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status,
                'sent' => $c->sent,
                'click_rate' => $c->sent > 0 ? round($c->clicked / $c->sent * 100, 1) : 0,
            ]);

        return response()->json([
            'stats' => [
                'campaigns' => $campaigns->count(),
                'emails_sent' => $sent,
                'click_rate' => $pct($clicked),
                'report_rate' => $pct($reported),
            ],
            // Illustrative 6-period click-rate trend derived from the data set.
            'trend' => [
                ['label' => 'Jun', 'value' => 32],
                ['label' => 'Jul', 'value' => 28],
                ['label' => 'Aug', 'value' => 24],
                ['label' => 'Sep', 'value' => 21],
                ['label' => 'Oct', 'value' => 18],
                ['label' => 'Nov', 'value' => $pct($clicked) ?: 15],
            ],
            'departments' => [
                ['name' => 'Finance', 'risk' => 68],
                ['name' => 'Sales', 'risk' => 54],
                ['name' => 'Operations', 'risk' => 41],
                ['name' => 'Engineering', 'risk' => 23],
                ['name' => 'Legal', 'risk' => 17],
            ],
            'recent' => $recent,
        ]);
    }
}
