<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 35.6 (AC5) — capacité de PREUVE du mécanisme HORS-REGISTRE `privilege` :
 * `rdp_denied_for_group`. Dernière brique de la GPO CD95 « Blocages élèves » :
 * « les élèves ne peuvent pas ouvrir de session RDP, mais les profs OUI, sur le
 * MÊME parc » — inatteignable par `remote_desktop_enabled=off` (machine-wide,
 * coupe RDP pour tout le monde) comme par un blocage d'exe (mstsc local ≠
 * session RDP ENTRANTE). La réponse Windows canonique est le privilège LSA
 * `SeDenyRemoteInteractiveLogonRight` accordé au groupe des élèves : Windows
 * refuse l'ouverture de session Bureau à distance à tout membre du groupe, en
 * laissant les autres passer.
 *
 * Pattern iso 36.1/36.2 (`2026_07_04_100000_seed_capability_program_files_browse_denied`) :
 * `updateOrInsert` par `key` puis par `(capability_id, os, mechanism)`,
 * idempotent, garde `hasTable`, `down()` par suppression de la `key` (FK
 * cascade → projection + assignments).
 *
 * ── ENUM OPT-IN À TROIS VALEURS (patron « off réel » 36.1, piège #6) ─────────
 *   - `unmanaged` (défaut, sentinelle) : absent de la map `accounts` ⇒ RIEN
 *     n'est émis (le handler n'est même pas invoqué — engine.go itère les
 *     types présents). Un privilège armé PUIS remis à `unmanaged` resterait
 *     PEUPLÉ (orphelin) : le retrait propre ne passe JAMAIS par ici ;
 *   - `eleves` « RDP refusé aux élèves » : `accounts: ['@eleves']` — le jeton
 *     d'audience est résolu à l'EXPANSION (AudienceTokens → groupe `Eleves` de
 *     `user_groups`, donnée d'établissement — JAMAIS un nom en dur au seed) ;
 *   - `off` « RDP autorisé (droit retiré) » : `accounts: []` — item ÉMIS avec
 *     liste vide ⇒ l'agent VIDE le privilège (révoque tous les titulaires) ⇒
 *     RDP rétabli. C'est le retrait HONNÊTE.
 *
 * ── EFFET AU LOGON SUIVANT (piège #5) ────────────────────────────────────────
 * Les droits de logon `SeDeny*` sont évalués par Windows à l'OUVERTURE de
 * session : armer la capacité ne coupe PAS une session RDP en cours (la
 * PROCHAINE tentative est refusée) ; `off` rétablit le RDP au logon suivant,
 * sans reboot. Sémantique Windows, pas un bug — dit au `warning`.
 *
 * ── PAS DE CIBLAGE PAR UTILISATEUR (piège #11) ──────────────────────────────
 * Mécanisme portée MACHINE : « qui est refusé » = la liste `accounts` DANS le
 * payload (`@eleves`), « quels postes » = les assignations parc/salle/poste/
 * broadcast. Un override UserGroup/User serait SANS EFFET.
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
            ['key' => 'rdp_denied_for_group'],
            [
                'label' => 'Ouverture de session RDP (droit de logon)',
                // Description ≤ 255 (contrainte varchar PG — sinon migrate /vm
                // casse en 22001, invisible en SQLite).
                'description' => 'Refuse l\'ouverture de session Bureau à distance aux membres du groupe '
                    .'visé (privilège LSA SeDenyRemoteInteractiveLogonRight). Les autres utilisateurs '
                    .'(profs) gardent le RDP sur le même poste. Dernière brique GPO « Blocages élèves ».',
                'category' => 'Sécurité',
                'value_type' => 'enum',
                'options' => json_encode([
                    ['value' => 'unmanaged', 'label' => 'Non géré'],
                    ['value' => 'eleves', 'label' => 'RDP refusé aux élèves'],
                    ['value' => 'off', 'label' => 'RDP autorisé (droit retiré)'],
                ], JSON_UNESCAPED_UNICODE),
                'default_value' => 'unmanaged',
                // warning NON VIDE (mécanisme de REFUS par nature — exigé par le
                // guard) : effet logon suivant + retrait par off, jamais unmanaged.
                'warning' => 'Refus de logon RDP : effet à la PROCHAINE ouverture de session (ne coupe '
                    .'pas les sessions RDP en cours). L\'agent possède la liste entière du droit : toute '
                    .'entrée SeDeny posée à la main (secpol.msc) sur ce droit sera RETIRÉE à la convergence. '
                    .'Le retrait propre passe par « RDP autorisé (droit retiré) », PAS par « Non géré ».',
                'applies_to_os' => json_encode(['windows'], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'overrides_locked' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $capabilityId = DB::table('capabilities')->where('key', 'rdp_denied_for_group')->value('id');
        if ($capabilityId === null) {
            return;
        }

        // Spec `{privilege, accounts}` : `accounts` par MAP de valeur —
        // `unmanaged` ABSENT de la map = sentinelle (rien émis), `eleves` =
        // jeton @eleves (résolu à l'expansion), `off` = [] (privilège VIDÉ).
        DB::table('capability_projections')->updateOrInsert(
            [
                'capability_id' => $capabilityId,
                'os' => 'windows',
                'mechanism' => 'privilege',
            ],
            [
                'spec' => json_encode([
                    'privilege' => 'SeDenyRemoteInteractiveLogonRight',
                    'accounts' => [
                        'eleves' => ['@eleves'],
                        'off' => [],
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
        DB::table('capabilities')->where('key', 'rdp_denied_for_group')->delete();
    }
};
