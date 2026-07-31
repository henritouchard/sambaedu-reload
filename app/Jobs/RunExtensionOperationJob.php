<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ExtensionInstallException;
use App\Models\Extension;
use App\Models\ExtensionInstallRun;
use App\Models\User;
use App\Services\Extensions\ExtensionInstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 56.3 (AC2, AC4, AC5) — Le Job qui exécute UNE opération d'extension en
 * tâche de fond et tient la ligne `extension_install_runs` à jour.
 *
 * Il n'installe RIEN lui-même : il appelle
 * {@see ExtensionInstallService::install()} / `update()` / `remove()`, c'est-à-dire
 * EXACTEMENT ce qu'appellent `ext:install`, `ext:update` et `ext:remove`. Il
 * n'existe pas deux chemins d'installation (doctrine AR1) ; ce Job est un
 * rapporteur, pas un second moteur.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LE MOTEUR JETTE **OU** RETOURNE — LES DEUX CHEMINS MÈNENT AU MÊME TERMINUS
 *
 *  Décision 56.2 #1 : `ExtensionInstallException` = refus de CONTRAT (avant que
 *  l'extension soit résolue : clé inconnue, ambiguë, type `link`, moteur
 *  occupé) ; un `error` non vide dans le tableau retourné = échec AUDITÉ d'une
 *  extension résolue. En oublier un laisserait un run éternellement `running`
 *  jusqu'à ce que la staleness le libère — c'est-à-dire un mensonge à l'écran
 *  pendant une demi-heure. Les deux sont traités, plus un `Throwable` de
 *  dernier recours.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ **PAS de middleware `WithoutOverlapping`.** Il s'appuie sur le cache PAR
 * DÉFAUT (APCu dans ce projet), qui n'implémente pas `lock()` : il lève
 * « undefined method ApcStore::lock() » AU PICKUP, et n'expose aucune API pour
 * lui passer un store lock-capable. C'est pour cette raison exacte qu'il a été
 * retiré de {@see \App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob} le 2026-06-22. La
 * mutuelle exclusion est portée par le verrou FICHIER global du moteur
 * (`extensions:install-engine`) et par la garde de
 * {@see \App\Services\Extensions\ExtensionOperationRunner::start()}.
 *
 * ⚠️ **`tries = 1`.** Un échec d'installation est TERMINAL : le moteur a déjà
 * compensé, et rejouer automatiquement re-téléchargerait, re-tenterait apt et
 * ré-écrirait une seconde ligne d'audit d'échec pour un acte que personne n'a
 * redemandé. La relance est un geste d'admin.
 */
class RunExtensionOperationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Un échec est terminal — cf. docblock de classe. */
    public int $tries = 1;

    /** Cf. `config('extensions.install.job_timeout')`. */
    public int $timeout;

    /**
     * ⚠️ Un IDENTIFIANT, jamais un modèle : `SerializesModels` rechargerait
     * l'entité au unserialize et lèverait `ModelNotFoundException` si elle a
     * disparu entre le dispatch et le pickup — hors de tout filet applicatif.
     */
    public function __construct(public readonly int $runId)
    {
        $this->timeout = (int) config('extensions.install.job_timeout', 1800);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // Volontairement VIDE — voir le docblock de classe (piège APCu).
        return [];
    }

    public function handle(ExtensionInstallService $installer): void
    {
        $run = ExtensionInstallRun::query()->find($this->runId);

        if ($run === null) {
            Log::warning('[Extensions] Run introuvable au pickup — rien à exécuter', [
                'run_id' => $this->runId,
            ]);

            return;
        }

        // Pré-vol : un run qui n'est plus `pending` a déjà été pris en charge
        // (double dispatch, rejeu manuel d'une file). On ne le rejoue pas.
        if ((string) $run->status !== ExtensionInstallRun::STATUS_PENDING) {
            Log::info('[Extensions] Run déjà pris en charge — pickup ignoré', [
                'run_id' => $run->id,
                'status' => $run->status,
            ]);

            return;
        }

        $extension = Extension::query()->find((int) $run->extension_id);

        if ($extension === null) {
            $this->finish($run, ExtensionInstallRun::STATUS_FAILED, ExtensionInstallRun::ERROR_EXTENSION_GONE);

            return;
        }

        $key = (string) $extension->key;

        // ⚠️ L'acteur est RECHARGÉ par identifiant, jamais sérialisé dans le
        // payload : un admin supprimé entre le clic et le pickup ne doit pas
        // faire exploser le Job. `null` ⇒ le moteur audite sous `system`, ce
        // qui reste vrai (l'acte est bien celui de la machine, faute d'humain
        // à qui l'attribuer).
        $actor = $run->requested_by_user_id === null
            ? null
            : User::query()->find((int) $run->requested_by_user_id);

        $run->status = ExtensionInstallRun::STATUS_RUNNING;
        $run->started_at = now();
        $run->save();

        $onStep = function (string $step) use ($run): void {
            $steps = (array) ($run->steps ?? []);
            $steps[] = $step;

            $run->current_step = $step;
            $run->steps = array_values($steps);
            $run->save();
        };

        try {
            $result = match ((string) $run->operation) {
                ExtensionInstallRun::OPERATION_INSTALL => $installer->install($key, null, $actor, $onStep),
                ExtensionInstallRun::OPERATION_UPDATE => $installer->update($key, $actor, $onStep),
                ExtensionInstallRun::OPERATION_REMOVE => $installer->remove($key, $actor, $onStep),
                default => null,
            };
        } catch (ExtensionInstallException $e) {
            // Refus de CONTRAT : le message est écrit pour un terminal, la
            // catégorie pour une UI. On persiste la catégorie, le message va au
            // journal serveur.
            Log::warning('[Extensions] Opération de fond refusée par le moteur', [
                'run_id' => $run->id,
                'operation' => $run->operation,
                'extension' => $key,
                'category' => $e->category,
                'message' => $e->getMessage(),
            ]);

            $this->finish($run, ExtensionInstallRun::STATUS_FAILED, $e->category);

            return;
        } catch (Throwable $e) {
            // Dernier recours. Le message brut ne va JAMAIS en base : il peut
            // porter une URL de dépôt (donc, potentiellement, un jeton).
            Log::error('[Extensions] Opération de fond interrompue par une erreur inattendue', [
                'run_id' => $run->id,
                'operation' => $run->operation,
                'extension' => $key,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->finish($run, ExtensionInstallRun::STATUS_FAILED, ExtensionInstallRun::ERROR_UNEXPECTED);

            return;
        }

        if ($result === null) {
            // Opération inconnue en base : impossible par l'orchestrateur, mais
            // une ligne bricolée à la main ne doit pas rester « en cours ».
            $this->finish($run, ExtensionInstallRun::STATUS_FAILED, ExtensionInstallRun::ERROR_UNEXPECTED);

            return;
        }

        if ($result['error'] !== '') {
            $this->finish($run, ExtensionInstallRun::STATUS_FAILED, $result['error']);

            return;
        }

        // `changed = false` (no-op propre : déjà installée, déjà à jour, déjà
        // retirée) est un SUCCÈS : l'état demandé est celui qui est en place.
        // Mais ce n'est PAS le même succès qu'un acte réel, et l'écran doit le
        // dire (AC5 : toast info) — d'où la propagation de `changed` jusqu'au
        // run (review 56.3 #3).
        $this->finish($run, ExtensionInstallRun::STATUS_SUCCESS, '', (bool) $result['changed']);
    }

    /**
     * Filet de dernier recours de la file : `handle()` a levé sans être
     * intercepté, ou le Job a dépassé son `timeout` (le worker le tue alors
     * sans repasser par les `catch`).
     *
     * Sans ce handler, la ligne resterait `running` jusqu'à ce que la staleness
     * la libère — l'écran mentirait pendant tout ce temps.
     */
    public function failed(?Throwable $exception): void
    {
        $run = ExtensionInstallRun::query()->find($this->runId);

        if ($run === null || ! $run->isActive()) {
            return;
        }

        Log::error('[Extensions] Job d\'opération en échec — run clos par le filet de la file', [
            'run_id' => $run->id,
            'exception' => $exception?->getMessage(),
        ]);

        $this->finish($run, ExtensionInstallRun::STATUS_FAILED, ExtensionInstallRun::ERROR_INTERRUPTED);
    }

    /** Terminus du run : statut, catégorie d'échec, horodatage. */
    private function finish(ExtensionInstallRun $run, string $status, string $error, bool $changed = true): void
    {
        $run->status = $status;
        $run->changed = $changed;
        // Borne alignée sur la colonne (191) : une catégorie du moteur est
        // toujours plus courte, mais on ne fait pas dépendre l'intégrité d'une
        // convention.
        $run->error = mb_substr($error, 0, 191);
        $run->finished_at = now();
        $run->save();
    }
}
