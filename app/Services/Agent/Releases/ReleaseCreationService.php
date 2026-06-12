<?php

declare(strict_types=1);

namespace App\Services\Agent\Releases;

use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Story 25.1 — Cycle de vie des releases agent côté serveur (D6, FR24, AC1).
 *
 * SEUL écrivain des tables `agent_releases` / `agent_release_rings` (les
 * commandes artisan 25.1 et l'UI 25.5 passent par lui). Trois opérations :
 *
 *  - {@see create()} — création VÉRIFIÉE : le `--hash` déclaré (produit par
 *    le pipeline de build 24.5) est contre-vérifié par `hash_file('sha256')`
 *    sur le fichier réel de `releases_path`. Toute incohérence (fichier
 *    absent/illisible, hash divergent, version dupliquée, formats invalides)
 *    = refus AVANT toute écriture ({@see ReleaseOperationException}, log
 *    `agent.release.rejected`) — impossible de publier un artefact
 *    incohérent. Piège n° 5 assumé : le SHA-256 d'un FICHIER binaire n'est
 *    pas un hash d'ÉTAT — `hash_file()` standard est l'outil correct ici,
 *    l'anti-pattern « hash ad hoc » (enforcement n° 2) vise les hashes JSON
 *    canonicalisés, domaine exclusif de `StateHasher`.
 *  - {@see promote()} — déplace le pointeur stable (au plus une ligne à
 *    true, invariant TRANSACTIONNEL — pas de contrainte partielle PG,
 *    parité SQLite des tests). C'est le rollback du défaut parc avant
 *    l'UI 25.5.
 *  - {@see target()} — cible un ring (UN WorkstationGroup existant) sur une
 *    version : `updateOrCreate` + `touch()` — le touch garantit le
 *    rafraîchissement d'`updated_at` même si la ligne est inchangée
 *    (re-ciblage de la même version = cas rollback), cohérent avec la règle
 *    de récence (décision n° 4).
 *
 * Domaines fermés validés EN CODE (regex) — SQLite n'applique pas les
 * varchar en test (piège n° 9). Frontière AC5 : aucune écriture hors
 * `agent_*` (le ciblage LIT le WorkstationGroup passé par l'appelant).
 * Intégrité : SHA-256 à la création (ici) ; la vérification de SIGNATURE
 * Authenticode avant exécution = l'agent (25.2), jamais le serveur
 * (décision n° 8).
 */
class ReleaseCreationService
{
    /** Domaine fermé de `version` (32 max — largeur colonne, validée en code). */
    public const VERSION_PATTERN = '/^[0-9A-Za-z.+~-]{1,32}$/';

    /** Forme produite par le build 24.5 : `sambaedu-agent-<version>.exe`. */
    public const FILENAME_PATTERN = '/^sambaedu-agent-[0-9A-Za-z.+~-]+\.exe$/';

    /** SHA-256 hex minuscule (normalisé avant comparaison). */
    private const HASH_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * Crée une release après contre-vérification du hash déclaré (AC1).
     * Refus = exception, AUCUNE écriture.
     *
     * @throws ReleaseOperationException
     */
    public function create(string $version, string $filename, string $declaredHash, bool $stable = false): AgentRelease
    {
        $declaredHash = strtolower(trim($declaredHash));

        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw $this->reject('invalid_version', $version, $filename, sprintf(
                'Version "%s" malformée (attendu : %s).',
                Str::limit($version, 64),
                self::VERSION_PATTERN,
            ));
        }
        if (strlen($filename) > 255 || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            throw $this->reject('invalid_filename', $version, $filename, sprintf(
                'Filename "%s" malformé (attendu : sambaedu-agent-<version>.exe).',
                Str::limit($filename, 128),
            ));
        }
        if ($filename !== sprintf('sambaedu-agent-%s.exe', $version)) {
            throw $this->reject('filename_version_mismatch', $version, $filename, sprintf(
                'Filename "%s" ne correspond pas à la version "%s" (attendu : sambaedu-agent-%s.exe) — manifest et binaire divergeraient.',
                Str::limit($filename, 128),
                Str::limit($version, 64),
                Str::limit($version, 64),
            ));
        }
        if (preg_match(self::HASH_PATTERN, $declaredHash) !== 1) {
            throw $this->reject('invalid_hash', $version, $filename,
                'Hash déclaré malformé (attendu : SHA-256 hex, 64 caractères).');
        }

        $path = $this->releasesPath() . DIRECTORY_SEPARATOR . $filename;
        if (! is_file($path) || ! is_readable($path)) {
            throw $this->reject('file_missing', $version, $filename, sprintf(
                'Binaire introuvable ou illisible : %s (déposé ? lisible www-admin ?).',
                $path,
            ));
        }

        // Piège n° 5 : hash de FICHIER binaire — hash_file() standard, PAS
        // StateHasher (réservé aux hashes d'état JSON canonicalisé).
        $computed = hash_file('sha256', $path);
        if ($computed === false) {
            throw $this->reject('file_missing', $version, $filename, sprintf(
                'Lecture du binaire impossible pour hachage : %s.',
                $path,
            ));
        }
        if (! hash_equals($computed, $declaredHash)) {
            throw $this->reject('hash_mismatch', $version, $filename, sprintf(
                'Hash divergent : déclaré %s, calculé %s — artefact incohérent, release refusée.',
                $declaredHash,
                $computed,
            ));
        }

        // Le filename étant strictement dérivé de la version (cross-check
        // ci-dessus), un doublon de filename EST un doublon de version :
        // un seul refus suffit, la contrainte unique DB reste le filet.
        if (AgentRelease::query()->where('version', $version)->exists()) {
            throw $this->reject('duplicate_version', $version, $filename, sprintf(
                'La version "%s" existe déjà dans agent_releases.',
                $version,
            ));
        }

        $release = DB::transaction(function () use ($version, $filename, $declaredHash, $stable): AgentRelease {
            if ($stable) {
                // Invariant « au plus une stable » : swap transactionnel.
                AgentRelease::query()->where('is_stable', true)->update(['is_stable' => false]);
            }

            return AgentRelease::query()->create([
                'version' => $version,
                'hash' => $declaredHash,
                'filename' => $filename,
                'is_stable' => $stable,
            ]);
        });

        Log::channel('agent')->info('[ReleaseCreationService] agent.release.created', [
            'action_type' => 'agent.release.created',
            'version' => $version,
            'hash' => $declaredHash,
            'stable' => $stable,
        ]);

        return $release;
    }

    /**
     * Déplace le pointeur stable sur une version existante (décision n° 5).
     *
     * @throws ReleaseOperationException version inconnue
     */
    public function promote(string $version): AgentRelease
    {
        $release = AgentRelease::query()->where('version', $version)->first();
        if ($release === null) {
            throw ReleaseOperationException::unknownVersion($version);
        }

        DB::transaction(function () use ($release): void {
            AgentRelease::query()
                ->where('is_stable', true)
                ->whereKeyNot($release->getKey())
                ->update(['is_stable' => false]);
            $release->is_stable = true;
            $release->save();
        });

        Log::channel('agent')->info('[ReleaseCreationService] agent.release.promoted', [
            'action_type' => 'agent.release.promoted',
            'version' => $release->version,
        ]);

        return $release;
    }

    /**
     * Cible un ring (= un WorkstationGroup) sur une version (décision n° 6).
     * Idempotent ; rafraîchit TOUJOURS `updated_at` (donnée de récence —
     * un re-ciblage de la même version doit regagner la précédence).
     *
     * @throws ReleaseOperationException version inconnue
     */
    public function target(string $version, WorkstationGroup $group): AgentReleaseRing
    {
        $release = AgentRelease::query()->where('version', $version)->first();
        if ($release === null) {
            throw ReleaseOperationException::unknownVersion($version);
        }

        $ring = AgentReleaseRing::query()->updateOrCreate(
            ['workstation_group_id' => $group->id],
            ['agent_release_id' => $release->id],
        );
        // updateOrCreate ne touche pas updated_at quand rien n'est dirty
        // (re-ciblage de la même version) : touch() garantit la récence.
        $ring->touch();

        Log::channel('agent')->info('[ReleaseCreationService] agent.release.targeted', [
            'action_type' => 'agent.release.targeted',
            'version' => $release->version,
            'workstation_group_id' => $group->id,
            'workstation_group' => $group->name,
        ]);

        return $ring->refresh();
    }

    /**
     * Refus AC1 : log warning `agent.release.rejected` (raison machine +
     * contexte borné — input opérateur) puis exception, AUCUNE écriture.
     */
    private function reject(string $reason, string $version, string $filename, string $message): ReleaseOperationException
    {
        Log::channel('agent')->warning('[ReleaseCreationService] agent.release.rejected', [
            'action_type' => 'agent.release.rejected',
            'reason' => $reason,
            'version' => Str::limit($version, 64),
            'filename' => Str::limit($filename, 128),
        ]);

        return ReleaseOperationException::rejected($reason, $message);
    }

    private function releasesPath(): string
    {
        return rtrim((string) config('agent.releases_path'), '/\\');
    }
}
