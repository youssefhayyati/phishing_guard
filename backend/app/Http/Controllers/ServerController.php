<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ServerController extends Controller
{
    /**
     * List the authenticated user's servers.
     */
    public function index(Request $request)
    {
        $servers = $request->user()->servers()
            ->latest()
            ->get()
            ->map(fn ($s) => $this->format($s));

        return response()->json(['data' => $servers]);
    }

    /**
     * Register a new server. Password is required on create.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => 'nullable|string|max:255',
            'ip_address' => 'required|string|max:255',
            'ssh_port'   => 'nullable|integer|min:1|max:65535',
            'ssh_user'   => 'nullable|string|max:255',
            'password'   => 'required|string|max:1024',
        ]);

        $data['ip_address'] = $this->normalizeHost($data['ip_address']);

        $server = $request->user()->servers()->create($data);

        return response()->json(['data' => $this->format($server)], 201);
    }

    /**
     * Return one server (password never included).
     */
    public function show(Request $request, int $id)
    {
        $server = $request->user()->servers()->findOrFail($id);

        return response()->json(['data' => $this->format($server)]);
    }

    /**
     * Update a server. Password is optional — only changed when provided.
     */
    public function update(Request $request, int $id)
    {
        $server = $request->user()->servers()->findOrFail($id);

        $data = $request->validate([
            'name'       => 'nullable|string|max:255',
            'ip_address' => ['sometimes', 'required', 'string', 'max:255'],
            'ssh_port'   => 'nullable|integer|min:1|max:65535',
            'ssh_user'   => 'nullable|string|max:255',
            'password'   => 'nullable|string|max:1024',
        ]);

        // Don't overwrite the stored password with an empty value.
        if (! array_key_exists('password', $data) || $data['password'] === null || $data['password'] === '') {
            unset($data['password']);
        }

        if (array_key_exists('ip_address', $data)) {
            $data['ip_address'] = $this->normalizeHost($data['ip_address']);
        }

        $server->update($data);

        return response()->json(['data' => $this->format($server->fresh())]);
    }

    /**
     * Test connectivity to a server by opening a TCP connection to its SSH
     * port. This checks reachability of the port, not SSH authentication.
     * Updates the stored status and last_checked_at.
     */
    public function test(Request $request, int $id)
    {
        $server = $request->user()->servers()->findOrFail($id);

        $errno = 0;
        $errstr = '';
        // Strip any scheme (e.g. "http://1.2.3.4") and path so fsockopen does
        // not mistake it for a socket transport, and force the tcp:// transport.
        $host = $this->normalizeHost($server->ip_address);
        $start = microtime(true);
        $conn = @fsockopen('tcp://' . $host, (int) $server->ssh_port, $errno, $errstr, 5.0);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $reachable = $conn !== false;
        if ($conn !== false) {
            fclose($conn);
        }

        $server->update([
            'status'          => $reachable ? 'online' : 'offline',
            'last_checked_at' => now(),
        ]);

        return response()->json([
            'data' => array_merge($this->format($server->fresh()), [
                'reachable'  => $reachable,
                'latency_ms' => $reachable ? $latencyMs : null,
                'message'    => $reachable
                    ? "Port {$server->ssh_port} reachable ({$latencyMs} ms)"
                    : ($errstr !== '' ? $errstr : 'Connection failed or timed out'),
            ]),
        ]);
    }

    /**
     * Delete a server.
     */
    public function destroy(Request $request, int $id)
    {
        $server = $request->user()->servers()->findOrFail($id);
        $server->delete();

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    /**
     * Reduce a user-entered address to a bare host (no scheme, no port, no
     * path). This prevents a value like "http://1.2.3.4" from being read by
     * fsockopen as the (non-existent) "http" socket transport.
     */
    private function normalizeHost(string $value): string
    {
        $value = trim($value);

        // Drop any scheme such as http://, https://, ssh://, tcp://.
        if (($pos = strpos($value, '://')) !== false) {
            $value = substr($value, $pos + 3);
        }

        // Keep only the host portion (before the first / or ? or #).
        $value = preg_split('/[\/?#]/', $value, 2)[0];

        // Drop any userinfo such as "root@" that may have been pasted in.
        if (($at = strrpos($value, '@')) !== false) {
            $value = substr($value, $at + 1);
        }

        // Strip a trailing :port for hostnames / IPv4 (leave IPv6 in brackets).
        if (! str_contains($value, '[') && substr_count($value, ':') === 1) {
            $value = explode(':', $value)[0];
        }

        return trim($value);
    }

    /**
     * Shape a server for the API. The password is write-only; we only expose
     * whether one is set.
     */
    private function format($s): array
    {
        return [
            'id'              => $s->id,
            'name'            => $s->name,
            'ip_address'      => $s->ip_address,
            'ssh_port'        => $s->ssh_port,
            'ssh_user'        => $s->ssh_user,
            'has_password'    => filled($s->getRawOriginal('password')),
            'status'          => $s->status,
            'last_checked_at' => optional($s->last_checked_at)->format('M j, Y H:i'),
            'created_at'      => optional($s->created_at)->format('M j, Y H:i'),
        ];
    }
}
