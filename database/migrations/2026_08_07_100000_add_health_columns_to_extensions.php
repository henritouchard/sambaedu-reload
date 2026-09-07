<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SANTÉ d'une extension `app` installée : ce que l'instance a
 * OBSERVÉ de son backend, et quand.
 *
 * Migration **strictement ADDITIVE** : aucune migration antérieure n'est
 * retouchée — les instances les ont déjà jouées. Aucune table nouvelle : la santé est un ATTRIBUT de l'extension, pas
 * une entité (la frontière d'isolement `UpstreamSyncExtensionsBoundaryTest` reste
 * donc vraie verbatim).
 *
 *  DÉCISIONS DE CONCEPTION
 *
 *  1. **L'état est PERSISTÉ, jamais mesuré au rendu.** La navbar est
 *     rendue sur TOUTE page authentifiée : elle LIT ces colonnes dans sa
 *     requête unique. La MESURE appartient à la commande planifiée
 *     `ext:health:check` (toutes les 5 min) et à
 *     {@see \App\Services\Extensions\ExtensionHealthService}, **seul écrivain**
 *     de ces quatre colonnes. Sans persistance, afficher l'indisponibilité
 *     coûterait une requête HTTP par tuile et par page vue.
 *
 *  2. **`health_status` est un string libre borné, pas un `enum()` DB.**
 *     Valeurs actuelles : `''` (JAMAIS sondé — l'état inconnu se dit en base,
 *     il ne se devine pas), `ok`, `unreachable`. Même doctrine que `action` du
 *  journal d'audit : la colonne survit à l'ajout d'un état sans
 *     migration, et `NOT NULL DEFAULT ''` évite le piège du tri-état
 *     `null`/`''`/valeur, comme les colonnes voisines.
 *
 *  3. **`health_last_incident_*` porte le DERNIER incident, pas un historique**
 *     Écrit à la TRANSITION seulement
 *     (`ok`/`''` → `unreachable`) : sinon le scheduler réécrirait la même
 *     information toutes les 5 minutes. Il SURVIT au retour du backend — c'est
 *     précisément sa raison d'être : « ça a été indisponible, voici quand ».
 *     Un historique de santé serait une table, donc une entité, donc un
 *     franchissement de la frontière d'isolement pour un besoin non exprimé.
 *
 *  4. **`health_last_incident_detail` est une CATÉGORIE courte (200), jamais un
 *  message brut.** Même règle que `last_error` et que
 *  `extension_audit_logs.details` : un message d'exception Guzzle
 *     suffixe l'URI complète, et cette colonne est
 *     lisible par tout admin sur la fiche. Le détail complet reste dans
 *     `Log::`.
 *
 *  5. **Hors `$fillable`** d'{@see \App\Models\Extension} — même doctrine que
 *  `status` et `installed_*` : le `fill` de l'upsert de
 *     catalogue reçoit un manifest de source TIERCE. S'il pouvait écrire ces
 *     colonnes, un manifest hostile se déclarerait `health_status = ok` et
 *     effacerait le seul signal qui dit que son backend est mort.
 *
 * **Rejouable** : gardes `hasTable` / `hasColumn` partout. Branches driver
 * `timestampTz` / `timestamp` : les tests HÔTE rejouent toutes les migrations
 * sur SQLite (`RefreshDatabase`), qui ne connaît pas `timestamptz`.
 *
 * ⚠️ La comparaison de FRAÎCHEUR de `health_checked_at` se fait toujours CÔTÉ
 * PHP ({@see \App\Models\Extension::healthIsStale()}), jamais en SQL : les
 * sessions Postgres sont en UTC et l'application à Paris (fiche mémoire
 * « Fuseau session Postgres »).
 *
 * ⚠️ Vocabulaire : « amont » / `Upstream`, jamais « central ».
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (! Schema::hasTable('extensions')) {
            return;
        }

        Schema::table('extensions', function (Blueprint $table) use ($driver): void {
            if (! Schema::hasColumn('extensions', 'health_status')) {
                $table->string('health_status', 20)->default('')
                    ->comment("'' = jamais sondé, ok, unreachable — écrit par ExtensionHealthService SEUL (décisions #1/#2)");
            }

            if (! Schema::hasColumn('extensions', 'health_checked_at')) {
                if ($driver === 'pgsql') {
                    $table->timestampTz('health_checked_at')->nullable()
                        ->comment('Dernier passage de la sonde (fraîcheur comparée CÔTÉ PHP — fuseau)');
                } else {
                    $table->timestamp('health_checked_at')->nullable()
                        ->comment('Dernier passage de la sonde (fraîcheur comparée CÔTÉ PHP — fuseau)');
                }
            }

            if (! Schema::hasColumn('extensions', 'health_last_incident_at')) {
                if ($driver === 'pgsql') {
                    $table->timestampTz('health_last_incident_at')->nullable()
                        ->comment('DERNIER incident — écrit à la transition, conservé au retour du backend (décision #3)');
                } else {
                    $table->timestamp('health_last_incident_at')->nullable()
                        ->comment('DERNIER incident — écrit à la transition, conservé au retour du backend (décision #3)');
                }
            }

            if (! Schema::hasColumn('extensions', 'health_last_incident_detail')) {
                $table->string('health_last_incident_detail', 200)->default('')
                    ->comment('Catégorie COURTE — jamais une URL, jamais un message Guzzle brut (décision #4)');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('extensions')) {
            return;
        }

        foreach ([
            'health_status',
            'health_checked_at',
            'health_last_incident_at',
            'health_last_incident_detail',
        ] as $column) {
            if (Schema::hasColumn('extensions', $column)) {
                Schema::table('extensions', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
