<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Enums\ExtensionType;
use App\Exceptions\ExtensionOperationException;
use App\Jobs\RunExtensionOperationJob;
use App\Models\Extension;
use App\Models\ExtensionInstallRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 56.3 (AC2, AC4, AC5) — L'ORCHESTRATEUR des opérations d'extension
 * lancées depuis l'UI : il crée le run, met le Job en file, et il est le SEUL
 * lecteur des runs pour les deux pages admin.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI UNE TÂCHE DE FOND, ET CELLE-LÀ
 *
 *  Une installation `apt` dure des minutes : l'exécuter dans la requête web
 *  est exclu. Le projet a DÉJÀ le mécanisme — `QUEUE_CONNECTION=database`,
 *  trois workers systemd provisionnés par `install.sh`/`update.sh` et
 *  surveillés par `/admin/workers` — et le précédent canonique d'une tâche
 *  longue pilotée depuis une page :
 *  {@see \App\Ipxe\Iso\Services\WindowsIsoDownloadOrchestrator} (row d'état +
 *  dispatch dans la MÊME transaction) → `DownloadWindowsIsoJob` (`tries = 1`,
 *  timeout large) → page en `wire:poll` conditionnel. On le reconduit tel quel.
 *
 *  Queue `default` et non une queue dédiée : les installations d'extensions
 *  sont des actes d'administration RARES, et `default` est déjà consommée par
 *  les workers en place — créer une file dédiée imposerait une unité systemd
 *  de plus à provisionner et à surveiller, pour rien (même raisonnement que
 *  `config/ipxe.php` pour l'ISO).
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CONCURRENCE : TROIS COUCHES, CHACUNE À SA PLACE
 *
 *  1. **UI** (confort) — les boutons sont désactivés tant qu'un run est actif.
 *     Évite la quasi-totalité des collisions, ne garantit rien.
 *  2. **Ici** (intégrité des runs) — verrou fichier COURT puis re-vérification
 *     `hasActiveRun()` dans la foulée : deux admins ne peuvent pas créer deux
 *     rows actives ni empiler deux Jobs.
 *  3. **Moteur 56.2** (vérité) — `extensions:install-engine`, 600 s. Si tout
 *     le reste échouait, le second Job terminerait `failed` / `engine_busy`
 *     sans demi-installation.
 *
 *  Cette couche-ci REFLÈTE le verrou du moteur, elle ne le remplace pas : le
 *  verrou du moteur est global, la garde d'ici l'est donc aussi.
 *
 *  ⚠️ `Cache::store('file')->lock()` OBLIGATOIRE — le store par défaut du
 *  projet est APCu, qui n'implémente pas `lock()` (fiche mémoire). Un
 *  `Cache::lock()` serait un verrou qui ne verrouille rien.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * NFR15 — les méthodes de LECTURE rendent des tableaux plats : aucun Eloquent
 * ne remonte dans un SFC Livewire, et la présentation des runs (libellés
 * d'étape, d'opération, de statut) vit ICI, pas dans les vues.
 */
class ExtensionOperationRunner
{
    /**
     * Verrou COURT de création de run — sans rapport avec le verrou du moteur
     * (`extensions:install-engine`, 600 s), qui protège l'exécution.
     * Celui-ci ne protège qu'une lecture-puis-écriture de quelques
     * millisecondes.
     */
    private const CREATION_LOCK_KEY = 'extensions:ui-run';

    private const CREATION_LOCK_SECONDS = 10;

    /**
     * Marge ajoutée au timeout du Job avant de considérer un run actif comme
     * INTERROMPU. Elle couvre le délai entre le dispatch et le pickup par un
     * worker occupé : sans elle, un run légitimement en file derrière un autre
     * travail serait déclaré mort.
     */
    private const STALE_MARGIN_SECONDS = 300;

    // =====================================================================
    // Écriture
    // =====================================================================

    /**
     * Ouvre une opération de fond : crée le run `pending` et met le Job en
     * file, ATOMIQUEMENT.
     *
     * La transaction n'est pas décorative : avec le driver `database`, le
     * dispatch est un `INSERT` dans `jobs` qui peut échouer. L'englober avec la
     * création du run garantit qu'on n'a jamais une row `pending` que personne
     * n'exécutera — l'UI l'afficherait « en cours » jusqu'à ce que la staleness
     * la libère, pour une opération qui n'a jamais démarré (patron
     * `WindowsIsoDownloadOrchestrator`).
     *
     * @param  string  $operation  une des constantes `ExtensionInstallRun::OPERATION_*`
     *
     * @throws ExtensionOperationException  refus métier destiné à un toast, jamais une 500
     */
    public function start(string $operation, int $extensionId, User $actor): ExtensionInstallRun
    {
        if (! in_array($operation, ExtensionInstallRun::OPERATIONS, true)) {
            throw ExtensionOperationException::unsupportedOperation($operation);
        }

        // Gardes de base AVANT de verrouiller quoi que ce soit. Elles ne
        // remplacent pas celles du moteur (qui revalide tout : source, bloc
        // install, unicité, versions) — elles évitent seulement de mettre en
        // file une opération dont on sait déjà qu'elle n'a aucun sens, et
        // rendent le refus immédiat et lisible pour l'admin.
        $extension = Extension::query()->find($extensionId);

        if ($extension === null) {
            throw ExtensionOperationException::unknownExtension();
        }

        if ($extension->type !== ExtensionType::App) {
            throw ExtensionOperationException::notAnApp();
        }

        $lock = Cache::store('file')->lock(self::CREATION_LOCK_KEY, self::CREATION_LOCK_SECONDS);

        if (! $lock->get()) {
            throw ExtensionOperationException::alreadyRunning();
        }

        try {
            // Re-vérification SOUS le verrou : c'est elle qui compte. La même
            // lecture faite avant l'acquisition ne prouverait rien.
            if ($this->hasActiveRun() !== null) {
                throw ExtensionOperationException::alreadyRunning();
            }

            return DB::transaction(function () use ($operation, $extension, $actor): ExtensionInstallRun {
                $run = ExtensionInstallRun::query()->create([
                    'extension_id' => (int) $extension->id,
                    'operation' => $operation,
                    'status' => ExtensionInstallRun::STATUS_PENDING,
                    'current_step' => '',
                    'steps' => [],
                    'error' => '',
                    'requested_by_user_id' => (int) $actor->id,
                    'requested_by_login' => (string) $actor->login,
                ]);

                // ⚠️ On met en file l'IDENTIFIANT du run, jamais le modèle : un
                // payload `SerializesModels` qui référencerait un admin supprimé
                // entre-temps lèverait `ModelNotFoundException` au unserialize,
                // c'est-à-dire au pickup, hors de tout filet applicatif.
                RunExtensionOperationJob::dispatch((int) $run->id)->onQueue('default');

                Log::info('[Extensions] Opération de fond mise en file', [
                    'run_id' => $run->id,
                    'operation' => $operation,
                    'extension' => $extension->key,
                    'actor' => $actor->login,
                ]);

                return $run;
            });
        } finally {
            $lock->release();
        }
    }

    // =====================================================================
    // Lecture (pages admin) — LE seul point de lecture des runs
    // =====================================================================

    /**
     * Le run actif et NON interrompu de l'instance, s'il y en a un.
     *
     * Un seul à la fois par construction (le verrou du moteur est global), mais
     * on parcourt quand même : un run resté actif après la mort d'un worker
     * peut coexister avec un run récent, et c'est le RÉCENT qui fait foi.
     */
    public function hasActiveRun(): ?ExtensionInstallRun
    {
        $timeout = $this->staleAfterSeconds();

        foreach (ExtensionInstallRun::query()->active()->orderByDesc('id')->get() as $run) {
            if (! $run->isStale($timeout)) {
                return $run;
            }
        }

        return null;
    }

    /**
     * Le run actif, prêt à afficher.
     *
     * @return array<string, mixed>|null
     */
    public function activeRunRow(): ?array
    {
        $run = $this->hasActiveRun();

        return $run === null ? null : $this->present($run, $this->staleAfterSeconds());
    }

    /**
     * Le DERNIER run de chaque extension, prêt à afficher — la lecture de la
     * bibliothèque en UNE requête (plus celle du run actif).
     *
     * ⚠️ La clé `active` NE dérive PAS de `by_extension` : elle vient de
     * {@see self::hasActiveRun()}, la même méthode que celle qui décide, dans
     * {@see self::start()}, de refuser une seconde opération. Deux définitions
     * de « il y a un run actif » finiraient par diverger, et l'écran dirait
     * alors le contraire de ce que le serveur applique — exactement le défaut
     * relevé en review 56.1 #1.
     *
     * @return array{active: array<string, mixed>|null, by_extension: array<int, array<string, mixed>>}
     */
    public function runsForLibrary(): array
    {
        $timeout = $this->staleAfterSeconds();

        $latestIds = ExtensionInstallRun::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('extension_id')
            ->pluck('id')
            ->all();

        $byExtension = [];

        if ($latestIds !== []) {
            foreach (ExtensionInstallRun::query()->whereIn('id', $latestIds)->orderByDesc('id')->get() as $run) {
                $byExtension[(int) $run->extension_id] = $this->present($run, $timeout);
            }
        }

        return ['active' => $this->activeRunRow(), 'by_extension' => $byExtension];
    }

    /**
     * Le dernier run d'UNE extension, prêt à afficher (fiche).
     *
     * @return array<string, mixed>|null
     */
    public function latestRunFor(int $extensionId): ?array
    {
        $run = ExtensionInstallRun::query()
            ->where('extension_id', $extensionId)
            ->orderByDesc('id')
            ->first();

        return $run === null ? null : $this->present($run, $this->staleAfterSeconds());
    }

    /**
     * Seuil au-delà duquel un run resté actif est considéré INTERROMPU.
     *
     * Timeout du Job + marge de mise en file. Volontairement généreux : se
     * tromper dans ce sens fait patienter, se tromper dans l'autre ferait
     * déclarer mort un travail bien vivant et rouvrirait les boutons pendant
     * qu'apt tourne.
     */
    public function staleAfterSeconds(): int
    {
        return (int) config('extensions.install.job_timeout', 1800) + self::STALE_MARGIN_SECONDS;
    }

    /**
     * Mise en forme d'un run pour la vue. C'est ICI que les libellés d'étapes
     * du moteur sont résolus — la vue n'a rien à décider, et la CLI comme l'UI
     * lisent la MÊME map ({@see ExtensionInstallService::stepLabels()}).
     *
     * @return array<string, mixed>
     */
    private function present(ExtensionInstallRun $run, int $timeout): array
    {
        $labels = ExtensionInstallService::stepLabels((string) $run->operation);
        $isStale = $run->isStale($timeout);

        $steps = [];
        foreach ((array) ($run->steps ?? []) as $step) {
            $key = (string) $step;
            $steps[] = ['key' => $key, 'label' => $labels[$key] ?? $key];
        }

        $currentStep = (string) $run->current_step;

        return [
            'id' => (int) $run->id,
            'extension_id' => (int) $run->extension_id,
            'operation' => (string) $run->operation,
            'operation_label' => $run->operationLabel(),
            'status' => (string) $run->status,
            // Un run interrompu N'EST PAS « en cours » : le dire ainsi
            // laisserait la bibliothèque gelée après la mort d'un worker.
            'status_label' => $isStale ? 'Interrompue' : $run->statusLabel(),
            'status_badge' => $isStale ? 'badge-warning' : $run->statusBadgeClass(),
            'is_active' => $run->isActive() && ! $isStale,
            'is_stale' => $isStale,
            'is_failed' => (string) $run->status === ExtensionInstallRun::STATUS_FAILED,
            // Review 56.3 #3 — un succès SANS acte (l'état demandé était déjà
            // en place : écran périmé, autre admin passé avant) ne se raconte
            // pas comme un acte accompli. AC5 : toast info, pas toast succès.
            'changed' => (bool) $run->changed,
            'current_step' => $currentStep,
            'current_step_label' => $labels[$currentStep] ?? $currentStep,
            'steps' => $steps,
            'error' => (string) $run->error,
            'error_label' => $run->errorLabel(),
            'requested_by_login' => (string) $run->requested_by_login,
            'finished_at' => $run->finished_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * Lecture DÉFENSIVE des runs : une table absente ou illisible ne doit pas
     * rendre une 500 sur la bibliothèque (patron `loadExtensions()` 54.1).
     *
     * @return array{active: array<string, mixed>|null, by_extension: array<int, array<string, mixed>>}
     */
    public function runsForLibrarySafely(): array
    {
        try {
            return $this->runsForLibrary();
        } catch (Throwable $e) {
            report($e);

            return ['active' => null, 'by_extension' => []];
        }
    }
}
