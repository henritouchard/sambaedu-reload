<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 35.7 (D7/AC5) — RETROFIT : re-route les capacités Session écrivant sous
 * `HKCU\…\Policies\*` vers l'exécutant SYSTEM (marqueur `writer` par CLÉ de
 * `spec`, contrat §7.1/§7.6) ET retire leur hint `refresh` (exclusion mutuelle,
 * piège n°6 — `refresh` n'est émis QUE sur les items appliqués par le compagnon).
 *
 * ── CAUSE RACINE (défaut CONFIRMÉ runtime) ───────────────────────────────────
 * Le compagnon de session tourne en contexte USER : sur machine JOINTE AU
 * DOMAINE, TOUT le sous-arbre `HKCU\…\Policies\*` — y compris
 * `CurrentVersion\Policies`, PAS seulement `Software\Policies` comme le
 * planning 35.3 le supposait — est en LECTURE SEULE pour l'utilisateur standard
 * (durcissement ACL anti-contournement de GPO). `blocked_executables` échouait
 * au poste en « Accès refusé » sur ses DEUX projections (flag `DisallowRun` +
 * conteneur `DisallowRun\1..5`). Même classe de bug que `hide_drives` et
 * `windows_copilot_off`, déjà rustinés par déplacement HKLM (migrations
 * `2026_07_06_100000`, `2026_06_19_100000`) — mais ces capacités-ci sont
 * CIBLÉES PAR UTILISATEUR (override UserGroup élèves, 35.4) : un déplacement
 * HKLM machine-wide bloquerait aussi profs/techniciens sur le même poste.
 * Correctif retenu (décision Henri, option 1) : le SERVICE SYSTEM applique ces
 * items PAR-SESSION dans `HKU\<SID de la session ciblée>`, comme une GPO user
 * policy ; le compagnon cesse de tenter ces écritures.
 *
 * ── AUDIT DU CATALOGUE (D8 — capacités Session sous HKCU\…\Policies\*) ───────
 * RE-ROUTÉES (ce retrofit) :
 *   - `blocked_executables` (registry : flag `…\CurrentVersion\Policies\
 *     Explorer!DisallowRun` + registry_list : conteneur `…\DisallowRun` —
 *     défaut CONFIRMÉ runtime) ;
 *   - `registry_editing_disabled` (registry : `…\CurrentVersion\Policies\
 *     System!DisableRegistryTools` — même tree durci, même commentaire faux
 *     « → OK companion » du seed `2026_07_02_100000` ; cible override
 *     UserGroup élèves, défaut LATENT).
 * NON CANDIDATES (justifié) :
 *   - `outlook_disable_o365_account_creation` (`Software\Microsoft\Office\…`,
 *     tree applicatif user-writable) ;
 *   - `photo_viewer_restored` (`Software\Classes`, user-writable) ;
 *   - `numlock_on_logon` (Control Panel + clé HKU 35.3, trees user-writable) ;
 *   - `hide_drives` / `windows_copilot_off` : déjà HKLM = machine-scope
 *     CORRECT pour leur usage (diffusion parc) — NE PAS « rapatrier »
 *     (piège n°10, décision de cadrage).
 * Toute capacité Session FUTURE sous `HKCU\…\Policies\*` = poser
 * `'writer' => 'system'` dans la spec : data seule, ZÉRO release agent.
 *
 * ── EFFET « LOGON SUIVANT » (AC7 — attendu, pas une régression) ──────────────
 * Les clés sont posées dans `HKU\<SID>` par SYSTEM ; Explorer lit `DisallowRun`
 * / `DisableRegistryTools` AU LOGON → effet au logon suivant de la session
 * ciblée (comportement d'une GPO user policy). AUCUN geste de rafraîchissement
 * mid-session (le hint `refresh` est justement RETIRÉ ici — pas de broadcast
 * SYSTEM→session, sur-conception refusée).
 *
 * ── ROLLOUT — ORDRE OPÉRATEUR IMPÉRATIF (piège n°1) ──────────────────────────
 * ⚠️ PUBLIER la release agent **2.12.0 AVANT** de jouer cette migration sur
 * /vm (la version RAPPORTÉE au check-in fait foi ; `update.sh` ne publie
 * JAMAIS seul ; les migrations VM ne sont PAS auto-jouées). Un binaire
 * ≤ 2.11.x ignore le marqueur EN SILENCE : AUCUNE casse ne flotte
 * (contrairement au piège HKU/35.3) mais AUCUN correctif non plus — le
 * compagnon garde son « Accès refusé », le service n'applique rien. Jouer la
 * migration d'abord laisserait le défaut visible en croyant l'avoir corrigé.
 * Drift ponctuel bénin attendu à l'armement (le marqueur entre dans le hash
 * des items re-routés) : re-application idempotente unique par session ciblée.
 *
 * ── PATRON (iso `2026_07_11_100000_retrofit_capabilities_refresh_hints`) ─────
 * NOUVELLE migration (les seeds d'origine `2026_07_02_100000` /
 * `2026_07_03_110000` ne sont PAS réécrits — leurs commentaires faux sont
 * corrigés à part, zéro donnée). Littéraux dupliqués ('system',
 * 'policy_broadcast' — les migrations ne référencent jamais le code
 * applicatif, décision 35.1). `up()`/`down()` DÉCODENT le `spec` existant,
 * posent/retirent la clé `writer` sur les SEULES clés `hive: HKCU` + la clé
 * racine `refresh` (le reste du spec est préservé octet pour octet), puis
 * RÉ-ENCODENT (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`). IDEMPOTENTE
 * (rejouable sans effet de bord), `down()` = état antérieur EXACT (writer
 * retiré, `refresh: policy_broadcast` reposé — l'état laissé par le retrofit
 * 43.2), garde `Schema::hasTable`.
 */
return new class extends Migration
{
    /**
     * Une entrée par PROJECTION re-routée : `blocked_executables` en porte
     * DEUX (bi-projection D5 — flag `registry` + conteneur `registry_list`).
     * `refresh` = le hint que le retrofit 43.2 (`2026_07_11_100000`) avait
     * posé et que `down()` doit REPOSER à l'identique.
     *
     * @var list<array{key:string, mechanism:string, refresh:string}>
     */
    private const RETROFIT = [
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
            $this->mapSpec($entry['key'], $entry['mechanism'], $now, static function (array $spec): array {
                // Marqueur PAR CLÉ (granularité D1 : propriété de la CLÉ — de
                // l'ACL de son tree), sur les seules clés HKCU. Les 3 specs
                // visées sont HKCU-only, la garde reste défensive.
                foreach ($spec['keys'] as $i => $key) {
                    if (is_array($key) && strcasecmp((string) ($key['hive'] ?? ''), 'HKCU') === 0) {
                        $spec['keys'][$i]['writer'] = 'system';
                    }
                }
                // Exclusion mutuelle refresh/writer (piège n°6) : le hint posé
                // par le retrofit 43.2 est RETIRÉ (donnée cohérente — l'effet
                // devient « logon suivant », comportement GPO user attendu).
                unset($spec['refresh']);

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
            $this->mapSpec($entry['key'], $entry['mechanism'], $now, static function (array $spec) use ($entry): array {
                foreach ($spec['keys'] as $i => $key) {
                    if (is_array($key)) {
                        unset($spec['keys'][$i]['writer']);
                    }
                }
                $spec['keys'] = array_values($spec['keys']);
                // État antérieur EXACT : le hint policy_broadcast du retrofit
                // 43.2 est reposé.
                $spec['refresh'] = $entry['refresh'];

                return $spec;
            });
        }
    }

    /**
     * Décode le `spec` de la projection windows `(capability.key, $mechanism)`,
     * applique `$transform` à la `spec` ENTIÈRE (racine + keys), puis réécrit
     * la colonne (encodage iso seeds). No-op défensif si la capacité/projection
     * est absente (instance partielle / seed non joué) ou si la `spec` est
     * d'une forme inattendue. Iso `2026_07_11_100000` (patron du plus proche
     * parent — il touchait EXACTEMENT les 3 projections visées ici).
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
