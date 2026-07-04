<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 36.2 (AC5) — capacité de PREUVE du mécanisme HORS-REGISTRE `firewall` :
 * `internet_access`. La demande fondatrice « couper l'accès Internet d'un parc
 * de postes (salle d'examen) en gardant le réseau local » se projette sur UNE
 * règle pare-feu `block out internet any` dans le conteneur possédé
 * `SambaEdu-Agent` : le poste ne joint plus Internet, mais le LAN reste ouvert
 * (check-in agent, serveur SE5, partages SMB, DNS local préservés — les plages
 * privées sont EXCLUES de la traduction `internet` par construction, Q3).
 *
 * Pattern iso 36.1 / lot CD95 : `updateOrInsert` par `key` puis par
 * `(capability_id, os, mechanism)`, idempotent, garde `hasTable`, `down()` par
 * suppression de la `key` (FK cascade → projection + assignments).
 *
 * ── ÉCART ASSUMÉ `ensure` (vs epic « sans verbe ensure ») ────────────────────
 * L'enum reste celui de l'epic TEL QUEL (unmanaged/on/off — contrairement à
 * fs_acl, PAS besoin d'une 4e valeur : `on` EST déjà l'action réelle symétrique).
 * Mais la projection porte `ensure ∈ present|absent` (TOUJOURS émis). Motivation
 * (piège #2 + invariant « un off proposé fait une VRAIE action ») : le
 * compilateur arbitre par IDENTITÉ (`rule_id`) entre items ÉMIS par maille — une
 * valeur qui n'émet RIEN ne peut JAMAIS battre une maille plus large qui émet
 * quelque chose. Si `on` n'émettait rien, un broadcast `off` (item block) NE
 * serait PAS annulé par un override de parc `on` → la salle resterait coupée.
 *   - `unmanaged` (défaut, sentinelle) : hors de toutes les maps ⇒ RIEN n'est
 *     émis (aucun item firewall → le handler n'est même pas invoqué).
 *   - `on` « Autorisé » : émet le MÊME `rule_id` en `ensure:absent` → même
 *     identité → override de parc `on` annule un broadcast `off`, et le groupe
 *     `SambaEdu-Agent` finit VIDE (l'AC epic « on ⇒ groupe vide » à la LETTRE,
 *     sans règle allow inerte qui interagirait avec une politique par défaut).
 *   - `off` « Coupé — réseau local seulement » : émet `ensure:present` → règle
 *     `block out internet any`.
 *
 * ── FENÊTRES D'ORPHELIN & GRAVITÉ TERRAIN (piège #3) ─────────────────────────
 * Le type ABSENT du state ⇒ le handler n'est jamais invoqué : la règle block
 * SURVIVRAIT. Conséquence GRAVE ici (contrairement à l'ACE bénigne de fs_acl) :
 * la salle resterait SANS INTERNET. Le retrait PROPRE passe donc par « Autorisé »
 * (`on`), JAMAIS par « Non géré » (`unmanaged`). Remède manuel trivial : les
 * règles du groupe `SambaEdu-Agent` sont VISIBLES dans wf.msc (le marqueur de
 * propriété est DANS l'objet, pas dans un store opaque) et supprimables par un
 * admin. Le `warning` de la capacité ET la doc contrat le disent en toutes
 * lettres.
 *
 * ── PROXYS D'ÉTABLISSEMENT ───────────────────────────────────────────────────
 * Un proxy LAN re-donne Internet malgré la coupure (le trafic sort par une
 * adresse privée autorisée) — à couper le cas échéant via une règle `explicit`
 * dédiée ciblant l'adresse PUBLIQUE du proxy (l'authoring `explicit` refuse les
 * plages privées, Q3). Documenté au `warning`.
 *
 * ── PAS DE CIBLAGE PAR UTILISATEUR (Q4) ──────────────────────────────────────
 * Mécanisme portée MACHINE : « couper Internet » se cible par parc/salle. Un
 * override UserGroup/User serait SANS EFFET (limitation Windows assumée).
 *
 * ── DESCRIPTION ≤ 255 (piège #12) ────────────────────────────────────────────
 * `capabilities.description`/`label` sont des varchar(255) PG : un dépassement
 * passe en SQLite de test et explose en 22001 sur /vm. `warning` est un TEXT
 * (pas de limite dure) — rester concis quand même.
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
            ['key' => 'internet_access'],
            [
                'label' => 'Accès Internet',
                // Description ≤ 255 (contrainte varchar PG — sinon migrate /vm
                // casse en 22001, invisible en SQLite).
                'description' => 'Coupe l\'accès Internet d\'un parc de postes (salle d\'examen) en gardant '
                    .'le réseau local (serveur SE5, partages, DNS). Règle pare-feu « block out » '
                    .'possédée par l\'agent, sans toucher au câblage ni au DHCP.',
                'category' => 'Sécurité',
                'value_type' => 'enum',
                'options' => json_encode([
                    ['value' => 'unmanaged', 'label' => 'Non géré'],
                    ['value' => 'on', 'label' => 'Autorisé'],
                    ['value' => 'off', 'label' => 'Coupé — réseau local seulement'],
                ], JSON_UNESCAPED_UNICODE),
                'default_value' => 'unmanaged',
                // warning NON VIDE (capacité porteuse de block — exigé par le guard).
                'warning' => 'Coupe l\'accès Internet des postes visés (le réseau local reste ouvert : '
                    .'check-in agent, serveur SE5, partages et DNS local préservés). '
                    .'⚠️ Le retrait propre passe par « Autorisé », JAMAIS par « Non géré » (sinon la '
                    .'règle survit et la salle reste coupée — remède manuel : wf.msc, groupe '
                    .'SambaEdu-Agent). Un proxy d\'établissement peut re-donner Internet : le couper '
                    .'via une règle « explicit » dédiée sur son adresse publique.',
                'applies_to_os' => json_encode(['windows'], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'overrides_locked' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $capabilityId = DB::table('capabilities')->where('key', 'internet_access')->value('id');
        if ($capabilityId === null) {
            return;
        }

        // UNE règle : `internet-block`. `off` → present (règle block posée) ;
        // `on` → absent (MÊME rule_id → groupe vidé) ; `unmanaged` absent de la
        // map = sentinelle (rien émis).
        $rules = [[
            'rule_id' => 'internet-block',
            'direction' => 'out',
            'action' => 'block',
            'remote_scope' => 'internet',
            'protocol' => 'any',
            'ensure' => ['off' => 'present', 'on' => 'absent'],
        ]];

        DB::table('capability_projections')->updateOrInsert(
            [
                'capability_id' => $capabilityId,
                'os' => 'windows',
                'mechanism' => 'firewall',
            ],
            [
                'spec' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
        DB::table('capabilities')->where('key', 'internet_access')->delete();
    }
};
