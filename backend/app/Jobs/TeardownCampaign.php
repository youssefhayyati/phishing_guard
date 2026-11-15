<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Stop and remove a campaign's containers on its server (noVNC + agent) and
 * delete its project directory. Connection details are captured at dispatch
 * time so this still works after the campaign row has been deleted.
 */
class TeardownCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    /**
     * @param array{host:string,port:int,user:string,password:?string,project:string,dir:string} $conn
     */
    public function __construct(public array $conn)
    {
    }

    public function handle(): void
    {
        $c = $this->conn;
        if (empty($c['host']) || empty($c['password'])) {
            return;
        }

        try {
            $ssh = new SSH2($c['host'], (int) ($c['port'] ?: 22));
            $ssh->setTimeout(120);
            if (! $ssh->login($c['user'] ?: 'root', $c['password'])) {
                return;
            }

            $project = escapeshellarg($c['project']);
            $dir     = escapeshellarg($c['dir']);

            // Compose down (if the project dir still exists), then hard-remove any
            // leftover containers for this project, then drop the directory.
            $ssh->exec(<<<SH
                if [ -d {$dir} ]; then
                    cd {$dir} && docker compose -f deploy/campaign-compose.yml -p {$project} down --remove-orphans --volumes 2>/dev/null || true
                fi
                docker ps -aq --filter "name={$c['project']}" | xargs -r docker rm -f 2>/dev/null || true
                rm -rf {$dir}
                SH);

            $ssh->disconnect();
        } catch (Throwable) {
            // Best-effort cleanup — never fail loudly.
        }
    }
}
