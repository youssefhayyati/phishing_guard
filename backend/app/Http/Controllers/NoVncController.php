<?php

namespace App\Http\Controllers;

use App\Services\Cdp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class NoVncController extends Controller
{
    /**
     * Read the Site Tweaker modifications currently present in the preview
     * browser (mirrored to the page's localStorage by the extension) via CDP.
     * Returns the JSON string, or null if there are none.
     */
    public function captureTweaks(Cdp $cdp)
    {
        try {
            $val = $cdp->evaluate("localStorage.getItem('__siteTweakerExport')");
        } catch (\Throwable $e) {
            return response()->json(['data' => ['site_tweaks' => null, 'error' => $e->getMessage()]]);
        }

        $tweaks = null;
        if (is_string($val) && $val !== '') {
            $o = json_decode($val, true);
            // Only accept a real Site Tweaker store that actually has edits.
            if (is_array($o) && isset($o['pages']) && is_array($o['pages']) && count($o['pages']) > 0) {
                $tweaks = $val;
            }
        }

        return response()->json(['data' => ['site_tweaks' => $tweaks]]);
    }

    /**
     * Clear the preview browser's Site Tweaker storage so a new campaign starts
     * from an unmodified site (dispatches the extension's clear hook via CDP).
     */
    public function resetTweaks(Cdp $cdp)
    {
        try {
            $cdp->evaluate("window.dispatchEvent(new Event('__st_clear')); 'ok'");
        } catch (\Throwable $e) {
            return response()->json(['data' => ['reset' => false, 'error' => $e->getMessage()]]);
        }

        return response()->json(['data' => ['reset' => true]]);
    }

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
        $novncUrl = "{$novncBase}/pgvnc.html?autoconnect=true&scale=true";

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
            'url'       => "{$novncBase}/pgvnc.html?autoconnect=true&scale=true",
        ]);
    }
}
