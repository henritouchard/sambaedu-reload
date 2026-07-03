<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 35.5 — Capacité `photo_viewer_restored` : dernière brique de la GPO CD95
 * « Ajustement_Photo » (réenregistrement de la Visionneuse de photos Windows),
 * transformée en capacité (modèle capability-first, patron EXACT du palier A
 * `2026_07_02_100000` : `updateOrInsert` par `key` puis par
 * `(capability_id, os, mechanism)`, idempotent, `down()` par `key`).
 *
 * Source (valeurs iso-GPO à l'octet près, PAS « de mémoire ») :
 * ../GPO_spécialesCD95/Ajustement_Photo/{B1E4CA63-2196-40A7-A7AF-50B0FFE099BD}/
 * DomainSysvol/GPO/Machine/Preferences/Registry/Registry.xml
 *
 * ── ROUTAGE HKCR → HKCU\Software\Classes (iso `onedrive_hidden`, seed ISO) ────
 * La GPO écrit HKEY_CLASSES_ROOT (vue MACHINE machine-wide). Le handler Go
 * `registry` ne route que HKLM (SYSTEM) / HKCU (compagnon de session). HKCR est la
 * vue FUSIONNÉE HKLM+HKCU\Software\Classes ; la branche per-user est écrite par le
 * compagnon → portée SESSION ({@see RegistryUserCapabilityProvider}), aucun droit
 * admin requis. On transcrit donc les 4 clés en `hive=HKCU,
 * path=Software\Classes\Applications\photoviewer.dll\…`. Nuance assumée par l'epic :
 * la GPO appliquait machine-wide, la capacité applique par session convergée
 * (iso-intention : chaque session gérée voit la visionneuse ; l'overlay per-user
 * prime sur la vue machine).
 *
 * ── QUIRK GPO PRÉSERVÉ (fidélité iso-GPO stricte) ────────────────────────────
 * La commande `print` utilise `ImageView_Fullscreen` (PAS `ImageView_PrintTo`) —
 * quirk de la GPO CD95, PRÉSERVÉ tel quel : le but est de REMPLACER la GPO à
 * l'identique, pas de la « corriger ». Les DEUX commandes portent donc la même
 * chaîne. Les deux `DropTarget\Clsid` sont, eux, DISTINCTS :
 *   - open  = {FFE2A43C-56B9-4bf5-9A79-CC6D4285608A}
 *   - print = {60fd46de-f830-4894-a628-6fa81bc0190d}
 *
 * ── « off » = VRAIE ACTION (marqueur 35.1, DONE) ─────────────────────────────
 * Chaque clé porte `'off' => ['$ensure' => 'absent']` (marqueur littéral dupliqué
 * ici — les migrations ne référencent pas le code applicatif, iso retrofit
 * `2026_07_03_100000` ; la référence d'authoring reste
 * {@see \App\Services\Agent\Providers\AbstractCapabilityStateProvider::SPEC_ENSURE}
 * / `ENSURE_ABSENT`). Trois régimes : on = réenregistrer (4 écritures), off =
 * désenregistrer (suppression des 4 valeurs, Windows reprend son état),
 * unmanaged = rien d'émis (opt-in par override de parc, aucun broadcast).
 *
 * ── GATE D'HONNÊTETÉ `is_active = false` (Découverte de cadrage) ──────────────
 * Les DEUX clés `…\shell\open\command` et `…\shell\print\command` écrivent la
 * valeur PAR DÉFAUT de la clé (`name=""` dans le Registry.xml source, c'est ce que
 * lit le shell — aucune valeur nommée alternative n'existe). Côté serveur tout
 * passe (le provider émet `name: ''` sans broncher). MAIS l'agent actuel rejette
 * `name == ""` (`agent/shared/handler_registry.go:parseRegistrySpec` — garde AVANT
 * la branche `ensure`, conflation « champ absent » ≡ « chaîne vide »), pour
 * l'écriture COMME pour la suppression. Une capacité ARMÉE écrirait donc les 2
 * Clsid mais pas les 2 command → nœud à moitié enregistré, PIRE que rien. La
 * contrainte « zéro évolution moteur » étant non négociable, la capacité est seedée
 * COMPLÈTE et FIDÈLE (les 4 clés, `name: ''` compris — c'est le contrat cible) mais
 * `is_active = false` : invisible des onglets d'armement, grisée dans les réglages
 * parc-defaults, ignorée par le provider (`where('is_active', true)`) → RIEN n'est
 * émis, golden files intacts. L'ACTIVATION (`is_active = true`) est gated par une
 * micro-évolution agent hors story (accepter `name: ""` = valeur par défaut de la
 * clé : ~3 lignes de parse + doc contrat + bump + note de publication ; candidat
 * 35.2/35.3 qui touchent déjà `handler_registry.go`, sinon micro-story 35.5bis).
 * Le flip se fera par une migration POSTÉRIEURE (`update(is_active=true)`).
 * ⚠️ La migration de flip doit AUSSI réécrire `description` (retirer la phrase
 * « Inactive tant que… ») — sinon le tooltip UI mentirait après activation
 * (review 35.5 #3).
 *
 * ── LIMITE DE PÉRIMÈTRE (à documenter partout) ───────────────────────────────
 * La capacité RÉENREGISTRE la visionneuse (rend l'app existante invocable —
 * iso-GPO CD95, qui ne touchait PAS UserChoice) ; le CHOIX effectif de
 * l'application par extension (`UserChoice`) relève du composer d'associations
 * existant (27.11) — HORS story. Corollaire : la visionneuse reste EXCLUE du
 * catalogue `NativeApplicationSeeder` (exe `rundll32.exe` générique non
 * fonctionnel — décision de curation 2026-06-18, inchangée).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        // Commande de réenregistrement (rundll32) — guillemets DOUBLES littéraux
        // dans la chaîne, backslashes doublés en PHP. Quirk GPO : `ImageView_Fullscreen`
        // sur open ET print (fidélité iso-GPO, cf. docblock).
        $command = '%SystemRoot%\\System32\\rundll32.exe "%ProgramFiles%\\Windows Photo Viewer\\PhotoViewer.dll", ImageView_Fullscreen %1';

        // Marqueur de SUPPRESSION (35.1) — dupliqué en littéral (les migrations ne
        // référencent pas le code applicatif). {@see AbstractCapabilityStateProvider::SPEC_ENSURE}.
        $ensureAbsent = ['$ensure' => 'absent'];

        // Les 4 clés HKCR routées HKCU\Software\Classes (portée Session). `name`
        // explicitement `''` sur les 2 command (valeur PAR DÉFAUT de la clé, iso
        // Registry.xml) — NE PAS omettre le champ : la `spec` est le contrat d'authoring.
        $keys = [
            [
                'hive' => 'HKCU',
                'path' => 'Software\\Classes\\Applications\\photoviewer.dll\\shell\\open\\command',
                'name' => '', // valeur PAR DÉFAUT de la clé (iso Registry.xml : name="")
                'type' => 'REG_EXPAND_SZ',
                'value' => ['on' => $command, 'off' => $ensureAbsent],
            ],
            [
                'hive' => 'HKCU',
                'path' => 'Software\\Classes\\Applications\\photoviewer.dll\\shell\\print\\command',
                'name' => '', // valeur PAR DÉFAUT de la clé (iso Registry.xml : name="")
                'type' => 'REG_EXPAND_SZ',
                // Quirk GPO : MÊME commande (`ImageView_Fullscreen`) que open — préservé.
                'value' => ['on' => $command, 'off' => $ensureAbsent],
            ],
            [
                'hive' => 'HKCU',
                'path' => 'Software\\Classes\\Applications\\photoviewer.dll\\shell\\open\\DropTarget',
                'name' => 'Clsid',
                'type' => 'REG_SZ',
                'value' => ['on' => '{FFE2A43C-56B9-4bf5-9A79-CC6D4285608A}', 'off' => $ensureAbsent],
            ],
            [
                'hive' => 'HKCU',
                'path' => 'Software\\Classes\\Applications\\photoviewer.dll\\shell\\print\\DropTarget',
                'name' => 'Clsid',
                // Clsid DISTINCT de open (la source GPO fait foi).
                'type' => 'REG_SZ',
                'value' => ['on' => '{60fd46de-f830-4894-a628-6fa81bc0190d}', 'off' => $ensureAbsent],
            ],
        ];

        DB::table('capabilities')->updateOrInsert(
            ['key' => 'photo_viewer_restored'],
            [
                'label' => 'Visionneuse de photos Windows',
                // ≤ 255 caractères : capabilities.description = varchar(255) sur
                // Postgres, invisible sous SQLite (review 35.5 #1 — piège 22001).
                // La nuance « ne choisit pas l'app par extension » vit dans la
                // story + runbook QA (limite de périmètre), pas ici.
                'description' => 'Réenregistre la Visionneuse de photos Windows (commandes open/print + DropTarget) '
                    .'pour la session — iso-GPO CD95 « Ajustement_Photo ». Inactive tant que l\'agent ne sait '
                    .'pas écrire la valeur par défaut d\'une clé.',
                'category' => 'Bureau',
                'value_type' => 'toggle',
                // Convention libellés « sujet + état » : label = sujet neutre, le
                // statut est porté par la VALEUR. « Non géré » RÉSERVÉ à la
                // sentinelle `unmanaged` (opt-in, rien d'émis en broadcast).
                'options' => json_encode([
                    ['value' => 'unmanaged', 'label' => 'Non géré'],
                    ['value' => 'on', 'label' => 'Restaurée (réenregistrée)'],
                    ['value' => 'off', 'label' => 'Désenregistrée (clés supprimées)'],
                ], JSON_UNESCAPED_UNICODE),
                'default_value' => 'unmanaged',
                'warning' => null,
                'applies_to_os' => json_encode(['windows'], JSON_UNESCAPED_UNICODE),
                // GATE D'HONNÊTETÉ (cf. docblock) : seedée INACTIVE tant que l'agent
                // ne sait pas écrire la valeur par défaut d'une clé (`name == ""`).
                'is_active' => false,
                'overrides_locked' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $capabilityId = DB::table('capabilities')->where('key', 'photo_viewer_restored')->value('id');
        if ($capabilityId === null) {
            return;
        }

        DB::table('capability_projections')->updateOrInsert(
            [
                'capability_id' => $capabilityId,
                'os' => 'windows',
                'mechanism' => 'registry',
            ],
            [
                'spec' => json_encode(['keys' => $keys], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
        DB::table('capabilities')->where('key', 'photo_viewer_restored')->delete();
    }
};
