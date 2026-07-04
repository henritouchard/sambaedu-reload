<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 36.1 (AC5) — capacité de PREUVE du mécanisme HORS-REGISTRE `fs_acl` :
 * `program_files_browse_denied`. La demande fondatrice « masquer Program Files
 * aux élèves sans casser le lancement des applications » se projette sur UNE ACE
 * NTFS `deny list_folder folder_only` : l'Explorateur ne peut plus ÉNUMÉRER le
 * dossier, mais le traverse/execute reste intact → les raccourcis vers des exe
 * sous Program Files se lancent toujours (la variante SÛRE ; la variante
 * dangereuse — deny à héritage descendant sur une racine système — est
 * INEXPRIMABLE par le guard d'authoring, Q2).
 *
 * Pattern iso lot CD95/35.2 (`2026_07_03_110000`) : `updateOrInsert` par `key`
 * puis par `(capability_id, os, mechanism)`, idempotent, garde `hasTable`,
 * `down()` par suppression de la `key` (FK cascade → projection + assignments).
 *
 * ── ENUM OPT-IN À QUATRE VALEURS (écart assumé vs enum à 3 de l'epic) ────────
 * Motivé par le piège #3 (fenêtres d'orphelin) + l'invariant projet « un off
 * proposé fait une VRAIE action » :
 *   - `unmanaged` (défaut, sentinelle) : hors de toutes les maps ⇒ RIEN n'est
 *     émis (aucun item fs_acl → le handler n'est même pas invoqué, engine.go
 *     itère les types présents) ;
 *   - `off` « Parcours autorisé (ACE retirées) » : émet les items `ensure:absent`
 *     des DEUX trustees → RETRAIT HONNÊTE quel que soit l'armement antérieur.
 *     Le retrait PROPRE d'une ACE gérée passe par `off`, JAMAIS par `unmanaged`
 *     (une bascule vers `unmanaged` cesse d'émettre l'item → le store agent
 *     réconcilie l'orphelin AU CYCLE SUIVANT SEULEMENT SI un autre item fs_acl
 *     est présent ; `off` garantit le retrait immédiat) ;
 *   - `eleves` « Masqué aux élèves » : deny pour le jeton `@eleves` ;
 *   - `tous` « Masqué à tous (utilisateurs du domaine) » : deny pour le littéral
 *     `Domain Users`.
 *
 * ── DEUX CHEMINS × DEUX TRUSTEES = 4 ENTRÉES DE SPEC ─────────────────────────
 * `C:\Program Files` ET `C:\Program Files (x86)` (les deux arborescences
 * d'install natives). Par chemin : une entrée trustee `@eleves` (valeurs
 * eleves/off) + une entrée trustee `Domain Users` (valeurs tous/off).
 *
 * ── « Domain Users » À VÉRIFIER SUR LE DC LAB (piège #15) ────────────────────
 * `Domain Users` est le nom Samba AD par défaut (provisioning anglophone) — le
 * trustee littéral part VERBATIM au payload, c'est l'AGENT qui le résout via LSA
 * sur le poste joint (échec ⇒ erreur d'item, visible). ⚠️ À VÉRIFIER sur le DC
 * lab avant armement de la valeur `tous` (discipline « pas de clé recopiée de
 * mémoire »). Le jeton `@eleves` est résolu conventionnellement par le serveur
 * (AudienceTokens → groupe `Eleves` de `user_groups`).
 *
 * ── PAS DE CIBLAGE PAR UTILISATEUR (piège #10) ──────────────────────────────
 * Mécanisme portée MACHINE : « quel utilisateur est bridé » = le `trustee` DANS
 * le payload (`@eleves` / `Domain Users`), « quels postes » = les assignations
 * parc/salle/poste/broadcast. Un override UserGroup/User serait SANS EFFET.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        // Libellés convention « sujet + état » : le label est un sujet NEUTRE, le
        // statut est porté par la valeur ; « Non géré » réservé à la sentinelle.
        DB::table('capabilities')->updateOrInsert(
            ['key' => 'program_files_browse_denied'],
            [
                'label' => 'Navigation dans Program Files (Explorateur)',
                // Description ≤ 255 (contrainte varchar PG, test structurel 35.5 —
                // sinon migrate /vm casse en 22001, invisible en SQLite).
                'description' => 'Masque l\'énumération de C:\\Program Files et Program Files (x86) '
                    .'dans l\'Explorateur (ACE NTFS deny list_folder, dossier seul). Les applications '
                    .'installées se lancent toujours. Remplace « interdire l\'Explorateur sur Program '
                    .'Files », sans GPO.',
                'category' => 'Sécurité',
                'value_type' => 'enum',
                'options' => json_encode([
                    ['value' => 'unmanaged', 'label' => 'Non géré'],
                    ['value' => 'off', 'label' => 'Parcours autorisé (ACE retirées)'],
                    ['value' => 'eleves', 'label' => 'Masqué aux élèves'],
                    ['value' => 'tous', 'label' => 'Masqué à tous (utilisateurs du domaine)'],
                ], JSON_UNESCAPED_UNICODE),
                'default_value' => 'unmanaged',
                // warning NON VIDE (capacité porteuse de deny — exigé par le guard).
                'warning' => 'Refus d\'accès en énumération : l\'Explorateur ne montrera plus le contenu '
                    .'de Program Files aux utilisateurs visés. Les applications restent lançables. '
                    .'Le retrait propre passe par « Parcours autorisé (ACE retirées) », PAS par « Non géré ».',
                'applies_to_os' => json_encode(['windows'], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'overrides_locked' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $capabilityId = DB::table('capabilities')->where('key', 'program_files_browse_denied')->value('id');
        if ($capabilityId === null) {
            return;
        }

        // 4 entrées de spec : {C:\Program Files, C:\Program Files (x86)} ×
        // {@eleves (eleves/off), Domain Users (tous/off)}. `unmanaged` absent de
        // toutes les maps = sentinelle (rien émis) ; `off` émet les items absent
        // des DEUX trustees (retrait honnête).
        $aces = [];
        foreach (['C:\\Program Files', 'C:\\Program Files (x86)'] as $path) {
            // Trustee @eleves : présent pour `eleves`, retiré pour `off`.
            $aces[] = [
                'path' => $path,
                'ace_type' => 'deny',
                'rights' => 'list_folder',
                'applies_to' => 'folder_only',
                'trustee' => ['eleves' => '@eleves', 'off' => '@eleves'],
                'ensure' => ['eleves' => 'present', 'off' => 'absent'],
            ];
            // Trustee Domain Users (littéral, résolu LSA côté poste — À VÉRIFIER
            // sur le DC lab) : présent pour `tous`, retiré pour `off`.
            $aces[] = [
                'path' => $path,
                'ace_type' => 'deny',
                'rights' => 'list_folder',
                'applies_to' => 'folder_only',
                'trustee' => ['tous' => 'Domain Users', 'off' => 'Domain Users'],
                'ensure' => ['tous' => 'present', 'off' => 'absent'],
            ];
        }

        DB::table('capability_projections')->updateOrInsert(
            [
                'capability_id' => $capabilityId,
                'os' => 'windows',
                'mechanism' => 'fs_acl',
            ],
            [
                'spec' => json_encode(['aces' => $aces], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
        DB::table('capabilities')->where('key', 'program_files_browse_denied')->delete();
    }
};
