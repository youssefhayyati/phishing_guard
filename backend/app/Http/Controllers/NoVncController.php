<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class NoVncController extends Controller
{
    public function launch(Request $request)
    {
        $data = $request->validate([
            'target_url' => 'required|url',
        ]);

        $cdpBase   = rtrim(config('services.novnc.cdp_url', 'http://novnc:9222'), '/');
        $novncBase = rtrim(config('services.novnc.url', 'http://localhost:6080'), '/');

        $host = parse_url($novncBase, PHP_URL_HOST) ?? 'localhost';
        $port = parse_url($novncBase, PHP_URL_PORT) ?? 6080;

        // vnc_lite.html is the minimal noVNC page — it reads host/port from the
        // page URL itself, skips the complex settings-panel initialisation that
        // breaks inside iframes, and still delivers full VNC functionality.
        // NOTE: vnc_lite.html reads the `scale` query param (→ rfb.scaleViewport),
        // NOT `resize`. Passing `scale=true` makes noVNC fit the framebuffer to
        // the iframe viewport; without it the canvas renders at native 1920×1080
        // and the page shows its own scrollbars.
        $novncUrl = "{$novncBase}/vnc_lite.html?autoconnect=true&scale=true";

        // Navigate Chrome to target_url via CDP (best-effort).
        // Chrome's DevTools Protocol rejects requests whose Host header isn't
        // "localhost", so we override it. If CDP is unreachable the user still
        // gets the noVNC iframe; Chrome will simply be on about:blank.
        // NOTE: Chrome 111+ (we run Chrome for Testing 157) requires PUT for
        // /json/new — a GET now returns HTTP 405 and the tab never navigates.
        $cdpWorked = false;
        try {
            $newTab = Http::timeout(5)
                ->withHeaders(['Host' => 'localhost'])
                ->put("{$cdpBase}/json/new?{$data['target_url']}");

            if ($newTab->successful()) {
                $cdpWorked = true;

                // Close stale about:blank / chrome://newtab tabs.
                $tabs = Http::timeout(5)
                    ->withHeaders(['Host' => 'localhost'])
                    ->get("{$cdpBase}/json")
                    ->json() ?? [];

                foreach ($tabs as $tab) {
                    $url = $tab['url'] ?? '';
                    if ($url === 'about:blank' || str_starts_with($url, 'chrome://newtab')) {
                        Http::timeout(3)
                            ->withHeaders(['Host' => 'localhost'])
                            ->get("{$cdpBase}/json/close/{$tab['id']}");
                    }
                }
            }
        } catch (\Exception $e) {
            // CDP unreachable — Chrome is probably still starting up.
        }

        return response()->json([
            'url'        => $novncUrl,
            'cdp_worked' => $cdpWorked,
        ]);
    }

    public function status()
    {
        $cdpBase   = rtrim(config('services.novnc.cdp_url', 'http://novnc:9222'), '/');
        $novncBase = rtrim(config('services.novnc.url', 'http://localhost:6080'), '/');

        $host = parse_url($novncBase, PHP_URL_HOST) ?? 'localhost';
        $port = parse_url($novncBase, PHP_URL_PORT) ?? 6080;

        $reachable = rescue(
            fn () => Http::timeout(3)
                ->withHeaders(['Host' => 'localhost'])
                ->get("{$cdpBase}/json/version")
                ->successful(),
            false,
        );

        return response()->json([
            'reachable' => $reachable,
            'url'       => "{$novncBase}/vnc_lite.html?autoconnect=true&scale=true",
        ]);
    }
}
