<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 35.3 — RETROFIT `numlock_on_logon` : la clé `HKU` de l'écran de logon.
 *
 * Le palier A (seed CD95 `2026_07_02_100000`) avait EXCLU la partie « numlock à
 * l'écran de logon » de la GPO Verr_num : la clé physique vit sous
 * `HKU\.DEFAULT` (le « profil » lu par LogonUI, qui tourne en SYSTEM) — ruche
 * ni HKLM ni HKCU, hors d'atteinte des deux exécutants d'alors. La ruche `HKU`
 * (troisième valeur du champ `hive`, agent 2.5.0) lève l'exclusion : la spec
 * gagne une clé `hive: 'HKU'` MIROIR de la clé HKCU du palier A — émise par le
 * provider MACHINE, appliquée par le service SYSTEM qui la FAN-OUT vers
 * `HKU\.DEFAULT` (l'écran de logon) + chaque ruche utilisateur chargée.
 *
 * ── PIÈGE DE PATH (story, piège n° 6) ───────────────────────────────────────
 * Le path de la clé est `Control Panel\Keyboard` SANS préfixe `.DEFAULT\` :
 * c'est le HANDLER agent qui préfixe chaque cible physique (`.DEFAULT\<path>`,
 * `<SID>\<path>`). Un path de seed commençant par `.DEFAULT\` produirait un
 * double-préfixe silencieux (`.DEFAULT\.DEFAULT\…`).
 *
 * ── DÉBOUCHÉ (au-delà du numlock) ───────────────────────────────────────────
 * Toute clé `HKCU\Software\Policies\*` (lecture seule pour l'utilisateur — le
 * compagnon de session échoue, leçon fix-Copilot) devient DIFFUSABLE en
 * machine/parc via une clé `hive: 'HKU'` : le contournement « clé HKLM
 * équivalente quand elle existe » (type `windows_copilot_off`) n'est plus le
 * seul chemin.
 *
 * ── DISCIPLINE DOUBLE-CLÉ (story, piège n° 5) ───────────────────────────────
 * Physiquement, `HKU\<SID>\Control Panel\Keyboard` == le HKCU de cet
 * utilisateur : la clé HKU (SYSTEM, valeur machine) et la clé HKCU (compagnon,
 * valeur session) écrivent LE MÊME emplacement dans les ruches des sessions
 * ouvertes. Sous broadcast, les deux maps donnent la même donnée → convergent.
 * RÈGLE D'AUTHORING : une capacité portant une clé HKU ne doit PAS être ciblée
 * par utilisateur / groupe d'utilisateurs, et ses maps HKU/HKCU jumelles
 * doivent rester VALEUR-CONSISTANTES — un override user-maille divergeant de
 * la valeur machine ferait se battre compagnon et SYSTEM (réécriture croisée à
 * chaque cycle, drift perpétuel des deux côtés).
 *
 * ── ⚠️ PRÉALABLE DE PUBLICATION (story, piège n° 2 — NON NÉGOCIABLE) ─────────
 * La release agent 2.5.0 DOIT être PUBLIÉE AVANT de jouer cette migration :
 * `numlock_on_logon` est en broadcast `on` → l'item HKU part à la FLOTTE
 * ENTIÈRE immédiatement. Un binaire ≤ 2.4.1 PARSE l'item HKU puis `rootKey()`
 * le refuse à la première lecture → `{status: error}` pour le type `registry`
 * machine ENTIER, SANS Apply : toutes les clés HKLM du poste cessent de
 * converger tant que l'item est au state. update.sh ne publie jamais seul.
 *
 * NOUVELLE migration (le seed d'origine n'est PAS réécrit — on ne réécrit pas
 * l'histoire, pattern retrofit `2026_07_03_100000`) : les fresh installs jouent
 * seed puis retrofit (ordre chronologique). IDEMPOTENTE : `update` ciblé par
 * `key`, garde `hasTable`, rejouable sans effet de bord (la clé HKU n'est
 * ajoutée que si absente). `down()` restaure la spec 1-clé (HKCU seule) du
 * palier A.
 */
return new class extends Migration
{
    /**
     * La clé HKU ajoutée — MIROIR SYMÉTRIQUE de la clé HKCU du palier A (même
     * path/name/type, même map on/off : si l'UI propose « off », « off » écrit
     * une vraie valeur — invariant maps symétriques). '2' = NumLock actif,
     * '0' = éteint (InitialKeyboardIndicators est un REG_SZ numérique).
     *
     * @var array<string, mixed>
     */
    private const HKU_KEY = [
        'hive' => 'HKU',
        'path' => 'Control Panel\\Keyboard',
        'name' => 'InitialKeyboardIndicators',
        'type' => 'REG_SZ',
        'value' => ['on' => '2', 'off' => '0'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $this->mapSpecKeys(static function (array $keys): array {
            foreach ($keys as $regKey) {
                if (is_array($regKey) && strcasecmp((string) ($regKey['hive'] ?? ''), 'HKU') === 0) {
                    return $keys; // déjà retrofittée (rejouable sans effet de bord)
                }
            }
            $keys[] = self::HKU_KEY;

            return $keys;
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        // Restaure la spec 1-clé du palier A : la clé HKCU est INCHANGÉE par
        // up(), retirer les clés HKU suffit (inverse exact).
        $this->mapSpecKeys(static fn (array $keys): array => array_values(array_filter(
            $keys,
            static fn ($regKey): bool => ! is_array($regKey)
                || strcasecmp((string) ($regKey['hive'] ?? ''), 'HKU') !== 0,
        )));
    }

    /**
     * Applique `$transform` à la liste `spec.keys` de la projection
     * windows/registry de `numlock_on_logon`, puis réécrit la colonne
     * (encodage iso seeds : JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).
     *
     * @param  callable(list<mixed>): list<mixed>  $transform
     */
    private function mapSpecKeys(callable $transform): void
    {
        $capabilityId = DB::table('capabilities')->where('key', 'numlock_on_logon')->value('id');
        if ($capabilityId === null) {
            return; // capacité absente (seed non joué / instance partielle) : no-op
        }

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

        $keys = $transform(array_values($spec['keys']));
        if ($keys === array_values($spec['keys'])) {
            return; // aucun changement → ne pas toucher updated_at (idempotence)
        }
        $spec['keys'] = $keys;

        DB::table('capability_projections')->where('id', $projection->id)->update([
            'spec' => json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
