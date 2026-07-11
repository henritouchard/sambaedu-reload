<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 43.2 (D4/AC4) — RETROFIT CONSERVATEUR : pose le hint `spec.refresh`
 * (Story 43.2, D1 — champ optionnel à la RACINE du `spec` JSON, vocabulaire
 * fermé `shell_notify | policy_broadcast | explorer_restart`) sur les
 * capacités Explorer/`Policies\Explorer` déjà seedées, pour que l'agent 2.10.0
 * (43.1, mécanisme mergé) applique le bon geste de rafraîchissement en session
 * courante au lieu de laisser l'admin croire « j'ai appliqué, rien ne se passe ».
 *
 * ── CHOIX CONSERVATEUR (D4) ──────────────────────────────────────────────────
 * La validation LAB de `policy_broadcast` N'EST PAS FAITE (le lab n'est pas
 * accessible) : le scénario QA 43.1.1 (`docs/qa/domains/agent.md`) tranchera.
 * En attendant :
 *   - `shell_notify` sur les 6 capacités de préférences de vues Explorer HKCU
 *     (`show_file_extensions`, `show_hidden_files`, `quick_access_history_hidden`,
 *     `onedrive_hidden`, `quick_access_hidden`, `explorer_gallery_hidden`) : le
 *     plancher `shell_notify` (43.1-D2, tout changed HKCU sans hint) s'applique
 *     DÉJÀ à ces clés — ce retrofit ne change RIEN au comportement du poste,
 *     il rend la sémantique DÉCLARATIVE (le spec devient la source de vérité)
 *     et le badge UI honnête (« Immédiat » au lieu de « À la prochaine
 *     session »).
 *   - `policy_broadcast` sur `blocked_executables` (LES DEUX projections de la
 *     bi-projection — flag `registry` ET conteneur `registry_list`) et sur
 *     `registry_editing_disabled` : clés `…\Policies\*` re-lues par Explorer
 *     sur `WM_SETTINGCHANGE("Policy")` (comportement documenté qu'exploite le
 *     moteur GPO) — comportement PLAUSIBLE, non prouvé en lab. Si le lab
 *     l'infirme, l'ajustement est un SIMPLE UPDATE de seed (`refresh` →
 *     `explorer_restart`), AUCUN CODE.
 *   - AUCUN `explorer_restart` posé ici (choix conservateur : ce geste est le
 *     plus intrusif — kill+relaunch de l'Explorateur).
 *   - SANS hint (motivé, ne pas y toucher) : `explorer_sidebar_pins_hidden`
 *     (HKLM only — un hint y serait REFUSÉ par la règle 5b du guard),
 *     `numlock_on_logon` (lu au logon — « à la prochaine session » est EXACT),
 *     `outlook_disable_o365_account_creation` (lu au lancement d'Outlook,
 *     aucun geste shell n'aide), et toutes les capacités machine/HKLM/HKU.
 *
 * ── ROLLOUT (NFR-A4, D9) ─────────────────────────────────────────────────────
 * ⚠️ En PROD/VM, cette migration ne doit être JOUÉE qu'APRÈS la publication
 * MANUELLE de la release agent 2.10.0 (43.1, mécanisme mergé — `update.sh` ne
 * publie JAMAIS seul ; les états 2.6.0→2.9.0 ne sont toujours pas publiés,
 * cf. Dev Agent Record 43.1). Un binaire agent ≤ 2.9.0 IGNORE le hint EN
 * SILENCE (clés écrites, aucun geste de rafraîchissement) : l'« Immédiat »
 * affiché par l'UI serait un MENSONGE sur les postes non à jour. Les
 * migrations VM ne sont PAS auto-jouées (`project_vm_migrations_not_auto_applied`)
 * — cette contrainte est CONSIGNÉE ici, pas exécutable depuis le dev/tests
 * hôte (sqlite, RefreshDatabase, non concerné).
 *
 * ── DRIFT PONCTUEL (NFR-A4, piège n°3) ───────────────────────────────────────
 * Le hint `refresh` entre dans le `hash` de CHAQUE item du contrat
 * (`App\Services\Agent\StateHasher::hashItem()` — référence textuelle, une
 * migration n'importe jamais le code applicatif) : au premier state
 * compilé après ce retrofit, chaque poste concerné re-applique UNE fois les
 * items dont le hash a changé (rapport `drift` puis `compliant` au cycle
 * suivant — écriture idempotente de la MÊME valeur + un geste). Attendu et
 * BÉNIN — ne JAMAIS « corriger » (le hash est opaque côté agent).
 *
 * ── PATRON (piège n°7, iso 35.1/`2026_07_03_100000`) ─────────────────────────
 * NOUVELLE migration (les seeds d'ORIGINE — 2026_06_18_100300, 2026_07_02_100000,
 * 2026_07_03_110000, 2026_07_04_100000 — ne sont PAS réécrits). Les valeurs du
 * vocabulaire sont des LITTÉRAUX dupliqués ici (les migrations ne référencent
 * jamais le code applicatif — iso `$ensure`/35.1). `up()`/`down()` DÉCODENT le
 * `spec` EXISTANT, posent/retirent SEULEMENT la clé `refresh` (les clés
 * `keys` d'origine sont préservées OCTET POUR OCTET), puis RÉ-ENCODENT
 * (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`). IDEMPOTENTE (rejouable
 * sans effet de bord), `down()` = inverse EXACT, garde `Schema::hasTable`.
 */
return new class extends Migration
{
    /**
     * Une entrée par PROJECTION à retrofitter : `blocked_executables` en porte
     * DEUX (bi-projection D5 — le flag `registry` ET le conteneur `registry_list`,
     * MÊME hint dans les deux specs, D4).
     *
     * @var list<array{key:string, mechanism:string, refresh:string}>
     */
    private const RETROFIT = [
        // shell_notify — préférences de vues Explorer HKCU (iso-comportement
        // du plancher agent 43.1-D2 : DÉCLARATIF, pas un changement de geste).
        ['key' => 'show_file_extensions', 'mechanism' => 'registry', 'refresh' => 'shell_notify'],
        ['key' => 'show_hidden_files', 'mechanism' => 'registry', 'refresh' => 'shell_notify'],
        ['key' => 'quick_access_history_hidden', 'mechanism' => 'registry', 'refresh' => 'shell_notify'],
        ['key' => 'onedrive_hidden', 'mechanism' => 'registry', 'refresh' => 'shell_notify'],
        ['key' => 'quick_access_hidden', 'mechanism' => 'registry', 'refresh' => 'shell_notify'],
        ['key' => 'explorer_gallery_hidden', 'mechanism' => 'registry', 'refresh' => 'shell_notify'],
        // policy_broadcast — famille …\Policies\* (lab 43.1.1 NON validé,
        // choix conservateur — ajustement post-lab = UPDATE de seed sans code).
        ['key' => 'blocked_executables', 'mechanism' => 'registry', 'refresh' => 'policy_broadcast'],
        ['key' => 'blocked_executables', 'mechanism' => 'registry_list', 'refresh' => 'policy_broadcast'],
        ['key' => 'registry_editing_disabled', 'mechanism' => 'registry', 'refresh' => 'policy_broadcast'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        foreach (self::RETROFIT as $entry) {
            $this->mapSpec($entry['key'], $entry['mechanism'], $now, function (array $spec) use ($entry): array {
                $spec['refresh'] = $entry['refresh'];

                return $spec;
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        foreach (self::RETROFIT as $entry) {
            $this->mapSpec($entry['key'], $entry['mechanism'], $now, static function (array $spec): array {
                unset($spec['refresh']);

                return $spec;
            });
        }
    }

    /**
     * Décode le `spec` de la projection windows `(capability.key, $mechanism)`,
     * applique `$transform` à la `spec` ENTIÈRE (racine — pas juste `keys`, D1),
     * puis réécrit la colonne (encodage iso seeds). No-op défensif si la
     * capacité/projection est absente (instance partielle / seed non joué) ou
     * si la `spec` est d'une forme inattendue.
     *
     * @param  callable(array<string,mixed>): array<string,mixed>  $transform
     */
    private function mapSpec(string $capabilityKey, string $mechanism, DateTimeInterface $now, callable $transform): void
    {
        $capabilityId = DB::table('capabilities')->where('key', $capabilityKey)->value('id');
        if ($capabilityId === null) {
            return; // capacité absente (seed non joué / instance partielle) : no-op.
        }

        $projection = DB::table('capability_projections')
            ->where('capability_id', $capabilityId)
            ->where('os', 'windows')
            ->where('mechanism', $mechanism)
            ->first(['id', 'spec']);
        if ($projection === null) {
            return;
        }

        $spec = json_decode((string) $projection->spec, true);
        if (! is_array($spec) || ! isset($spec['keys']) || ! is_array($spec['keys'])) {
            return; // spec inattendue : no-op défensif (jamais d'exception).
        }

        DB::table('capability_projections')->where('id', $projection->id)->update([
            'spec' => json_encode($transform($spec), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ]);
    }
};
