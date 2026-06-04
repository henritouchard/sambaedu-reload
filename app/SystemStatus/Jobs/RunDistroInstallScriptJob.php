<?php

declare(strict_types=1);

namespace App\SystemStatus\Jobs;

use App\SystemStatus\Distro;
use App\SystemStatus\DistroInstallTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Job queue — exécute le script de provisioning d'une distro
 * (`/usr/share/sambaedu/scripts/install-*-iso.sh`) pour la rendre
 * disponible à l'installation iPXE.
 *
 * Pattern iso {@see \App\Gpo\Jobs\GenerateWineImageJob} (Process array,
 * tries=1, timeout long, état suivi côté UI).
 *
 * SÉCURITÉ : le script exécuté vient EXCLUSIVEMENT de
 * {@see Distro::installScriptPath()} (whitelist enum) — le constructeur
 * refuse les distros sans script (Windows → orchestrateur ISO dédié).
 * Aucun argument utilisateur n'est passé au script.
 *
 * État : {@see DistroInstallTracker} (cache) — `running` posé au dispatch
 * par le composant Livewire (pas ici : le job peut attendre en queue),
 * `done`/`failed` posés en fin d'exécution.
 */
class RunDistroInstallScriptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Tronquage stderr en cas d'échec (parité GenerateWineImageJob). */
    private const STDIO_TRUNCATE_BYTES = 8 * 1024;

    /**
     * Un échec n'est pas relancé en silence — l'admin relance depuis la
     * page « État du système ».
     */
    public int $tries = 1;

    /**
     * 2h : les scripts téléchargent des ISO/netboot complets depuis les
     * miroirs (débit étab variable).
     */
    public int $timeout = 7200;

    public function __construct(
        public readonly Distro $distro,
    ) {
        if ($distro->installScriptPath() === null) {
            throw new \InvalidArgumentException(
                "RunDistroInstallScriptJob: la distro '{$distro->value}' n'a pas de script d'install async (Windows passe par l'orchestrateur ISO dédié).",
            );
        }
    }

    public function handle(DistroInstallTracker $tracker): void
    {
        try {
            $this->execute($tracker);
        } finally {
            // Fix review F2 : le lock anti double-dispatch est acquis par la
            // page AVANT le push — relâché ici quoi qu'il arrive.
            $tracker->releaseLock($this->distro);
        }
    }

    private function execute(DistroInstallTracker $tracker): void
    {
        $script = (string) $this->distro->installScriptPath();

        Log::info('[SystemStatus] Install distro démarré', [
            'distro' => $this->distro->value,
            'script' => $script,
        ]);

        if (! $this->scriptExists($script)) {
            $tracker->fail($this->distro, sprintf('script absent : %s', $script));
            Log::error('[SystemStatus] Script d\'install introuvable', [
                'distro' => $this->distro->value,
                'script' => $script,
            ]);

            return;
        }

        try {
            // Mode array : pas de concaténation shell, aucun input user.
            $result = Process::timeout($this->timeout)->run([$script]);
        } catch (Throwable $e) {
            $tracker->fail($this->distro, $e->getMessage());
            Log::error('[SystemStatus] Install distro exception', [
                'distro' => $this->distro->value,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 500),
            ]);

            return;
        }

        if ($result->failed()) {
            $stderr = substr($result->errorOutput(), 0, self::STDIO_TRUNCATE_BYTES);
            $tracker->fail($this->distro, sprintf(
                'exit %d — %s',
                (int) $result->exitCode(),
                $stderr !== '' ? $stderr : substr($result->output(), -500),
            ));
            Log::error('[SystemStatus] Install distro échoué', [
                'distro' => $this->distro->value,
                'exit_code' => $result->exitCode(),
                'stderr' => $stderr,
            ]);

            return;
        }

        $tracker->finish($this->distro);
        Log::info('[SystemStatus] Install distro terminé', [
            'distro' => $this->distro->value,
        ]);
    }

    /**
     * Seam de testabilité (fix review F9) : permet aux tests de couvrir les
     * branches script-présent / script-absent indépendamment de la machine.
     */
    protected function scriptExists(string $script): bool
    {
        return is_file($script);
    }

    public function failed(?Throwable $exception): void
    {
        $tracker = app(DistroInstallTracker::class);
        $tracker->fail(
            $this->distro,
            $exception?->getMessage() ?? 'échec inconnu (job failed)',
        );
        $tracker->releaseLock($this->distro);
    }
}
