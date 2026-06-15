<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode debug du poste.
 *
 * Ajoute la colonne `workstations.debug` — un drapeau par poste exposé à
 * l'agent dans l'enveloppe desired-state (`GET /api/v1/agent/state`). En
 * debug, le compagnon de session GARDE sa console ouverte (toutes sessions)
 * et y recopie ses logs (diagnostic direct). Le même toggle pilote en
 * parallèle les options WPKG `.ini` `debug` et `logdebug`
 * (cf. WorkstationDebugService).
 *
 *  - `debug` BOOLEAN NOT NULL DEFAULT false — true = poste en mode debug.
 *    Dans `$fillable` (contrairement aux colonnes `agent_*`) : c'est un
 *    réglage de contrôle piloté par l'admin, pas un état du cycle token.
 *
 * Le champ entre dans le hash d'état (`StateHasher::hashState` hashe toute
 * l'enveloppe sauf `generated_at`) : un toggle change donc l'ETag et franchit
 * le cache 304 — l'agent reçoit un état frais au prochain check-in.
 *
 * **Idempotence stricte** : `Schema::hasColumn()` avant création (iso
 * `2026_06_12_120000_add_agent_sync_requested_at_to_workstations`). Type
 * simple (boolean) → compatible SQLite tests sans branche driver dédiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            if (! Schema::hasColumn('workstations', 'debug')) {
                $table->boolean('debug')->default(false)
                    ->comment('Mode debug du poste : console agent conservée + logs WPKG verbeux (debug/logdebug)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            if (Schema::hasColumn('workstations', 'debug')) {
                $table->dropColumn('debug');
            }
        });
    }
};
