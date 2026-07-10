<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 38.3 (AC2) — capacité de GATING du mécanisme HORS-REGISTRE
 * `legacy_cleanup` : `legacy_hooks_cleanup`. Les postes (y compris migrés à
 * l'agent) portent encore les crochets clients SE4 (blobs `applications-*`,
 * tâches WPKG, scripts GPO locale, helpers, autologon `se4install` résiduel,
 * paires Mozilla `sambaedu.default` — incident Firefox du 2026-07-03) : armer
 * la capacité fait retirer ces artefacts LOCAUX par l'agent (canal authentifié,
 * D2/Q1 de l'epic 38 — jamais du code servi en HTTP).
 *
 * Pattern iso 35.6/36.x (`2026_07_04_140000_seed_capability_rdp_denied_for_group`) :
 * `updateOrInsert` par `key` puis par `(capability_id, os, mechanism)`,
 * idempotent, garde `hasTable`, `down()` par suppression de la `key` (FK
 * cascade → projection + assignments).
 *
 * ── TOGGLE À DEUX VALEURS — PAS DE `off` (piège #7 de la story) ─────────────
 *   - `unmanaged` (défaut Broadcast, sentinelle) : absent de la map `mozilla`
 *     ⇒ RIEN n'est émis (le handler n'est même pas invoqué — engine.go itère
 *     les types présents). L'agent est INACTIF sur ce type ;
 *   - `on` « Nettoyés » : item `{mozilla: "vanilla"}` émis ⇒ scan + suppression
 *     idempotente du catalogue d'artefacts versionné DANS l'agent (D3).
 *
 * Le nettoyage est ONE-WAY : on ne « restaure » pas des crochets legacy — un
 * `off` n'aurait AUCUNE sémantique opératoire (rien à écrire, rien à re-poser).
 * La règle « off écrit une vraie valeur »
 * (`project_capability_value_map_symmetric_rule`) s'applique aux maps REGISTRE
 * symétriques (où `unmanaged` laisserait la dernière valeur écrite orpheline) :
 * ici, revenir à `unmanaged` cesse simplement de scanner — aucun état posé par
 * l'agent ne survit (le handler ne POSE rien, il ne fait que retirer).
 *
 * ── VALEUR DE MAP `vanilla` (Q5-a, Henri 2026-07-10) ───────────────────────
 * `{"on": "vanilla"}` encode la décision Q5 DANS la donnée : suppression des
 * paires `profiles.ini`/`installs.ini` référençant `sambaedu.default`
 * (Firefox ET Thunderbird), dossier de profil PRÉSERVÉ, AUCUN profil forcé
 * posé — Firefox/Thunderbird recréent et gèrent leur profil localement.
 * Enum contractuel FERMÉ 1 valeur (§7.10), extensible si (b)/(c) revenait.
 *
 * ── ROLLOUT (piège #2 de la story) ──────────────────────────────────────────
 * Un binaire ≤ 2.8.0 IGNORE le type `legacy_cleanup` EN SILENCE (contrat §8) :
 * publier MANUELLEMENT la release 2.9.0 AVANT de jouer cette migration sur la
 * cible et d'armer la capacité (update.sh ne publie jamais seul).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        // Libellés convention « sujet + état » : le label est un sujet NEUTRE,
        // le statut est porté par la valeur ; « Non géré » réservé à la sentinelle.
        DB::table('capabilities')->updateOrInsert(
            ['key' => 'legacy_hooks_cleanup'],
            [
                'label' => 'Crochets legacy SE4',
                // Description ≤ 255 (contrainte varchar PG — sinon migrate /vm
                // casse en 22001, invisible en SQLite).
                'description' => 'Retire du poste les crochets clients SE4 (blobs applications-*, tâches WPKG, '
                    .'scripts GPO locale, helpers obsolètes, autologon se4install résiduel, paires Mozilla '
                    .'forcées sambaedu.default). Nettoyage idempotent par l\'agent, canal authentifié.',
                'category' => 'Sécurité',
                'value_type' => 'enum',
                'options' => json_encode([
                    ['value' => 'unmanaged', 'label' => 'Non géré'],
                    ['value' => 'on', 'label' => 'Nettoyés'],
                ], JSON_UNESCAPED_UNICODE),
                'default_value' => 'unmanaged',
                // warning NON VIDE (mécanisme qui SUPPRIME des fichiers sur le
                // parc) : one-way + prérequis de publication binaire.
                'warning' => 'Nettoyage ONE-WAY : les artefacts legacy supprimés ne sont pas restaurés en '
                    .'repassant à « Non géré » (l\'agent cesse simplement de scanner). Les paires profiles.ini/'
                    .'installs.ini Mozilla référençant sambaedu.default sont supprimées (dossiers de profil '
                    .'préservés — Firefox/Thunderbird recréent un profil local sain). Requiert l\'agent ≥ 2.9.0 '
                    .'publié : un binaire antérieur ignore ce réglage EN SILENCE.',
                'applies_to_os' => json_encode(['windows'], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'overrides_locked' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $capabilityId = DB::table('capabilities')->where('key', 'legacy_hooks_cleanup')->value('id');
        if ($capabilityId === null) {
            return;
        }

        // Spec `{mozilla}` par MAP de valeur : `unmanaged` ABSENT de la map =
        // sentinelle (rien émis, agent inactif), `on` = "vanilla" (Q5-a).
        // PAS de clé `off` — nettoyage one-way (piège #7, cf. bloc-comment).
        DB::table('capability_projections')->updateOrInsert(
            [
                'capability_id' => $capabilityId,
                'os' => 'windows',
                'mechanism' => 'legacy_cleanup',
            ],
            [
                'spec' => json_encode([
                    'mozilla' => [
                        'on' => 'vanilla',
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('capabilities')) {
            return;
        }

        // FK cascadeOnDelete : supprimer la capacité retire projection + overrides.
        DB::table('capabilities')->where('key', 'legacy_hooks_cleanup')->delete();
    }
};
