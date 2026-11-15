<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Chrome DevTools Protocol client.
 *
 * Target discovery is over HTTP (/json), but Runtime.evaluate requires a
 * WebSocket, which we speak by hand (no extra dependency). Chrome's DevTools
 * endpoint rejects any Host header that isn't "localhost", so every request
 * spoofs it — the socat bridge in the noVNC container already delivers the
 * connection over loopback, so this is safe, not a bypass of real isolation.
 */
class Cdp
{
    private string $cdpBase;

    public function __construct(?string $cdpBase = null)
    {
        $this->cdpBase = rtrim($cdpBase ?? config('services.novnc.cdp_url', 'http://novnc:9222'), '/');
    }

    /** First real page target (http/https), or null. */
    public function pageTarget(): ?array
    {
        try {
            $tabs = Http::timeout(5)->withHeaders(['Host' => 'localhost'])
                ->get("{$this->cdpBase}/json")->json() ?? [];
        } catch (\Throwable) {
            return null;
        }
        foreach ($tabs as $t) {
            if (($t['type'] ?? '') === 'page' && str_starts_with($t['url'] ?? '', 'http')) {
                return $t;
            }
        }
        return null;
    }

    /**
     * Evaluate a JS expression in the active page's main world and return the
     * value (returnByValue). Returns null if there is no page or on error.
     */
    public function evaluate(string $expression, bool $awaitPromise = false): mixed
    {
        $target = $this->pageTarget();
        if (! $target || empty($target['webSocketDebuggerUrl'])) {
            return null;
        }

        $path = parse_url($target['webSocketDebuggerUrl'], PHP_URL_PATH) ?: '/';
        $host = parse_url($this->cdpBase, PHP_URL_HOST) ?: 'novnc';
        $port = parse_url($this->cdpBase, PHP_URL_PORT) ?: 9222;

        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
        if (! $sock) {
            throw new RuntimeException("CDP connect failed: {$errstr}");
        }
        stream_set_timeout($sock, 6);

        try {
            $this->handshake($sock, $path);
            $payload = json_encode([
                'id'     => 1,
                'method' => 'Runtime.evaluate',
                'params' => [
                    'expression'    => $expression,
                    'returnByValue' => true,
                    'awaitPromise'  => $awaitPromise,
                ],
            ]);
            $this->writeFrame($sock, $payload);

            // Read messages until we see our id (Runtime without enable is quiet).
            for ($i = 0; $i < 10; $i++) {
                $msg = $this->readMessage($sock);
                if ($msg === null) {
                    break;
                }
                $data = json_decode($msg, true);
                if (($data['id'] ?? null) === 1) {
                    return $data['result']['result']['value'] ?? null;
                }
            }
            return null;
        } finally {
            @fclose($sock);
        }
    }

    private function handshake($sock, string $path): void
    {
        $key = base64_encode(random_bytes(16));
        $req = "GET {$path} HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($sock, $req);

        $resp = '';
        while (! str_contains($resp, "\r\n\r\n")) {
            $chunk = fread($sock, 512);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $resp .= $chunk;
        }
        if (! str_contains($resp, ' 101 ')) {
            throw new RuntimeException('CDP WebSocket upgrade failed');
        }
    }

    private function writeFrame($sock, string $payload): void
    {
        $len = strlen($payload);
        $header = chr(0x81); // FIN + text
        if ($len < 126) {
            $header .= chr(0x80 | $len);
        } elseif ($len <= 0xFFFF) {
            $header .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $len);
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        fwrite($sock, $header . $mask . $masked);
    }

    private function readMessage($sock): ?string
    {
        $data = '';
        do {
            $first = $this->readExact($sock, 2);
            if ($first === null) {
                return null;
            }
            $b0 = ord($first[0]);
            $b1 = ord($first[1]);
            $fin = ($b0 & 0x80) !== 0;
            $opcode = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $len = $b1 & 0x7F;
            if ($len === 126) {
                $ext = $this->readExact($sock, 2);
                $len = $ext === null ? 0 : unpack('n', $ext)[1];
            } elseif ($len === 127) {
                $ext = $this->readExact($sock, 8);
                $len = $ext === null ? 0 : unpack('J', $ext)[1];
            }
            $mask = $masked ? $this->readExact($sock, 4) : '';
            $payload = $len > 0 ? $this->readExact($sock, $len) : '';
            if ($payload === null) {
                return null;
            }
            if ($masked && $mask !== null) {
                $unmasked = '';
                for ($i = 0; $i < $len; $i++) {
                    $unmasked .= $payload[$i] ^ $mask[$i % 4];
                }
                $payload = $unmasked;
            }
            if ($opcode === 0x8) { // close
                return null;
            }
            if ($opcode === 0x9 || $opcode === 0xA) { // ping/pong — ignore
                continue;
            }
            $data .= $payload;
        } while (! $fin);

        return $data;
    }

    private function readExact($sock, int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($sock, $n - strlen($buf));
            if ($chunk === '' || $chunk === false) {
                $meta = stream_get_meta_data($sock);
                if (! empty($meta['timed_out'])) {
                    return null;
                }
                if (feof($sock)) {
                    return null;
                }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}
