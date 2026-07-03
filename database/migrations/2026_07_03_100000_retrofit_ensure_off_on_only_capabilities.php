<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 35.1 — RETROFIT des capacités on-only : vrai « off » par SUPPRESSION.
 *
 * Le verbe `ensure` (contrat §7.1, agent 2.3.0) permet enfin un « off » HONNÊTE
 * pour les deux capacités bloquées en « Géré » perpétuel — leurs clés n'ont pas
 * de « valeur de restauration » écrite en dur qui soit correcte (NodeType dépend
 * du réseau, le bundle WindowsUpdate du défaut Windows) :
 *
 *   - `llmnr_disabled`          (seed CD95 `2026_07_02_100000`) — 2 clés HKLM ;
 *   - `windows_updates_managed` (seed ISO  `2026_06_18_100300`) — 6 clés HKLM.
 *
 * Le nouveau « off » est une ACTION (marqueur réservé `'off' => {"$ensure":
 * "absent"}` dans la map de chaque clé → l'agent SUPPRIME les valeurs, Windows
 * reprend ses défauts), PAS un « Non géré » (libellé réservé à la sentinelle
 * UNMANAGED des capacités opt-in). L'invariant « un off proposé fait une vraie
 * action » (review #2 du lot ISO) est satisfait par la suppression. Les valeurs
 * `'on'` existantes sont INCHANGÉES.
 *
 * NOUVELLE migration (les seeds d'origine ne sont PAS réécrits — on ne réécrit
 * pas l'histoire) : les fresh installs jouent seed puis retrofit (ordre
 * chronologique). IDEMPOTENTE : `update` ciblé par `key`, garde `hasTable`,
 * rejouable sans effet de bord. `down()` restaure l'état on-only d'origine.
 *
 * Marqueur : valeur FIGÉE du contrat d'authoring
 * {@see \App\Services\Agent\Providers\AbstractCapabilityStateProvider::SPEC_ENSURE}
 * (`$ensure`) / `ENSURE_ABSENT` (`absent`) — dupliquée en littéral ici (les
 * migrations ne référencent pas le code applicatif, iso seeds d'origine).
 */
return new class extends Migration
{
    /** Capacités on-only retrofittées : key → libellés d'options {on, off}. */
    private const RETROFIT = [
        // Libellés `on` = ceux d'ORIGINE des seeds (« Géré » pour les deux) :
        // le retrofit n'ajoute que le « off », il ne relabelle rien (review
        // 35.1 #2 — up() et down() sont des inverses exacts, libellés compris).
        'llmnr_disabled' => [
            'on' => 'Géré',
            'off' => 'Désactivé (clés supprimées)',
        ],
        'windows_updates_managed' => [
            'on' => 'Géré',
            'off' => 'Désactivé (clés supprimées)',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        foreach (self::RETROFIT as $key => $labels) {
            $capability = DB::table('capabilities')->where('key', $key)->first(['id']);
            if ($capability === null) {
                continue; // capacité absente (seed non joué / instance partielle) : no-op
            }

            // 1) `options` : abandonner le régime « Géré » on-only, exposer le
            // vrai « off » (convention libellés sujet+état — le statut est porté
            // par la valeur, jamais « Non géré » qui est réservé à la sentinelle).
            DB::table('capabilities')->where('key', $key)->update([
                'options' => json_encode([
                    ['value' => 'on', 'label' => $labels['on']],
                    ['value' => 'off', 'label' => $labels['off']],
                ], JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);

            // 2) `spec` : CHAQUE clé de la projection registry gagne
            // `'off' => {"$ensure": "absent"}` (valeurs `'on'` inchangées).
            $this->mapSpecKeys($capability->id, static function (array $regKey): array {
                if (isset($regKey['value']) && is_array($regKey['value']) && ! array_is_list($regKey['value'])) {
                    $regKey['value']['off'] = ['$ensure' => 'absent'];
                }

                return $regKey;
            }, $now);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        foreach (array_keys(self::RETROFIT) as $key) {
            $capability = DB::table('capabilities')->where('key', $key)->first(['id']);
            if ($capability === null) {
                continue;
            }

            // Restaure l'état on-only d'origine des seeds (« Géré » seul).
            DB::table('capabilities')->where('key', $key)->update([
                'options' => json_encode([
                    ['value' => 'on', 'label' => 'Géré'],
                ], JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);

            $this->mapSpecKeys($capability->id, static function (array $regKey): array {
                if (isset($regKey['value']) && is_array($regKey['value']) && ! array_is_list($regKey['value'])) {
                    unset($regKey['value']['off']);
                }

                return $regKey;
            }, $now);
        }
    }

    /**
     * Applique `$transform` à chaque clé de la `spec` de la projection
     * windows/registry de la capacité, puis réécrit la colonne (encodage iso
     * seeds : JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).
     *
     * @param  callable(array<string,mixed>): array<string,mixed>  $transform
     */
    private function mapSpecKeys(int|string $capabilityId, callable $transform, \DateTimeInterface $now): void
    {
        $projection = DB::table('capability_projections')
            ->where('capability_id', $capabilityId)
            ->where('os', 'windows')
            ->where('mechanism', 'registry')
            ->first(['id', 'spec']);
        if ($projection === null) {
            return;
        }

        $spec = json_decode((string) $projection->spec, true);
        if (! is_array($spec) || ! isset($spec['keys']) || ! is_array($spec['keys'])) {
            return; // spec inattendue : no-op défensif (jamais d'exception)
        }

        $spec['keys'] = array_map(
            static fn ($regKey) => is_array($regKey) ? $transform($regKey) : $regKey,
            $spec['keys'],
        );

        DB::table('capability_projections')->where('id', $projection->id)->update([
            'spec' => json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ]);
    }
};
