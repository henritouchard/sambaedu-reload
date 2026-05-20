<?php

declare(strict_types=1);

namespace App\Gpo\Jobs;

use App\Gpo\Support\GpoLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Job queue Laravel — génère l'image Wine partagée pour un conteneur donné.
 *
 * Port natif du `batch_command("/usr/share/sambaedu/scripts/make_wine_image.sh $application")`
 * legacy (`gpo/wine.php:61`). Le legacy reposait sur une queue APCu primitive +
 * flush `/tmp/admin_script_*.sh` + cron — remplacé proprement par Laravel Queue.
 *
 * Story 16.3c — AC2.1, AC2.3, AC5.2.
 *
 * Sécurité (audit §6.F F7) :
 * - Validation regex `^[a-zA-Z0-9._-]*$` au constructeur (défense en profondeur)
 * - `Process::run(['/usr/share/sambaedu/scripts/make_wine_image.sh', $application])`
 *   en mode **array** — pas de concaténation shell, pas d'`exec()` direct.
 *
 * Idempotence : `Cache::lock('gpo:wine:generate-image:{application}', 1800)`
 * acquis dans `WineImageQueuer::dispatch` AVANT le push. Le lock est libéré
 * dans `handle()` / `failed()` du Job (ceinture + bretelles, cf. SM
 * discrepance (a) tranchement 2026-05-12 : lock côté queuer + release Job).
 *
 * @legacy-port path="sambaedu/gpo/wine.php:61"
 */
class GenerateWineImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Path absolu du script shell exécuté. Hardcodé iso-legacy
     * (`gpo/wine.php:61`). Doit appartenir au système (root:root 755).
     */
    public const SCRIPT_PATH = '/usr/share/sambaedu/scripts/make_wine_image.sh';

    /**
     * Regex stricte iso `WineImageQueuer::APPLICATION_REGEX` — défense
     * en profondeur (le Job peut être dispatché ailleurs, ex. tests).
     */
    private const APPLICATION_REGEX = '/^[a-zA-Z0-9._\-]*$/';

    /** Tronquage stderr/stdout en cas d'échec (parité GpoActionLog 8 Ko). */
    private const STDIO_TRUNCATE_BYTES = 8 * 1024;

    /**
     * Parité legacy idempotent : un échec n'est PAS relancé en silence.
     * L'admin relance manuellement depuis la page UI Wine.
     */
    public int $tries = 1;

    /**
     * Timeout 30 min (10 min annoncés legacy + marge). Au-delà → Process kill +
     * `failed()` appelé avec ProcessTimedOutException.
     */
    public int $timeout = 1800;

    public function __construct(
        public readonly string $application,
        public readonly string $operationId,
    ) {
        if (preg_match(self::APPLICATION_REGEX, $application) !== 1) {
            throw new \InvalidArgumentException(
                "GenerateWineImageJob: application name '{$application}' violates regex " . self::APPLICATION_REGEX,
            );
        }
    }

    public function handle(): void
    {
        // Propagation operation_id depuis le dispatcher (cf. WineImageQueuer)
        // pour corréler les 3 logs start/step/end côté admin UI.
        $log = GpoLogger::action('gpo.wine.image.generate', $this->operationId, [
            'application' => $this->application,
            'sub' => 'job.handle',
        ]);

        try {
            $log->step('invoking make_wine_image.sh', [
                'script' => self::SCRIPT_PATH,
            ]);

            // AC2.1 — Process::run MODE ARRAY (pas de concat shell). Audit §6.F F7 corrigé.
            $command = $this->application === ''
                ? [self::SCRIPT_PATH]
                : [self::SCRIPT_PATH, $this->application];

            $result = Process::timeout($this->timeout)->run($command);

            if (! $result->successful()) {
                throw new \RuntimeException(sprintf(
                    'make_wine_image.sh exit %d: %s',
                    $result->exitCode() ?? -1,
                    $this->truncate($result->errorOutput()),
                ));
            }

            $log->success([
                'exit_code' => $result->exitCode(),
                'stdout_size_bytes' => strlen($result->output()),
            ]);

            // Story 16.14 Q2 — invalider le cache santé GPO après la génération
            // de l'image Wine (le script `make_wine_image.sh` peut modifier la GPO
            // se4_wine côté SYSVOL). On ne sait pas précisément quelle GPO →
            // flush global. Best-effort silencieux.
            try {
                app(\App\Gpo\Support\CachedGpoLookups::class)->forgetAll();
            } catch (Throwable) {
                // log silencieux — n'affecte pas le succès du Job.
            }
        } catch (Throwable $e) {
            $log->failure($e, [
                'stderr_truncated' => $this->extractStderrFromException($e),
            ]);
            $this->releaseLock();
            throw $e;
        }

        $this->releaseLock();
    }

    /**
     * Hook Laravel appelé en cas d'échec final (après `tries` épuisés ou
     * exception non-catchée dans `handle()`). Libère le lock idempotence.
     */
    public function failed(?Throwable $e): void
    {
        $this->releaseLock();

        if ($e !== null) {
            // Log de second niveau (le failure() de handle() a déjà loggé) —
            // utile si le Job timeout (handle() jamais sorti proprement).
            $log = GpoLogger::action('gpo.wine.image.generate', $this->operationId, [
                'application' => $this->application,
                'sub' => 'job.failed',
            ]);
            $log->failure($e);
        }
    }

    /**
     * Libère le lock idempotence posé dans `WineImageQueuer::dispatch`.
     *
     * Iso pattern Laravel `Cache::restoreLock(name, owner)->release()` — mais
     * sans owner (lock posé via `get()` non-bloquant), on utilise
     * `Cache::lock($key)->forceRelease()` pour garantir le release même si le
     * processus de dispatch est différent du worker queue.
     */
    private function releaseLock(): void
    {
        Cache::lock($this->lockKey())->forceRelease();
    }

    /**
     * Clé lock idempotence — identique côté queuer (cf. WineImageQueuer).
     */
    public function lockKey(): string
    {
        return 'gpo:wine:generate-image:' . ($this->application === '' ? '__default__' : $this->application);
    }

    private function truncate(string $output): string
    {
        if (strlen($output) <= self::STDIO_TRUNCATE_BYTES) {
            return $output;
        }
        return substr($output, 0, self::STDIO_TRUNCATE_BYTES) . "\n[truncated]";
    }

    /**
     * Tente d'extraire stderr d'une exception Process tronqué à 8 Ko.
     */
    private function extractStderrFromException(Throwable $e): string
    {
        $message = $e->getMessage();
        return $this->truncate($message);
    }
}
