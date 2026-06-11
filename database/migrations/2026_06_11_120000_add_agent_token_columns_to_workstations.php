<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 23.2 — AC1/AC3/AC4.
 *
 * Ajoute les colonnes `agent_*` à `workstations` — couche d'authentification
 * du canal agent desired-state (Epic 23). Le token vit SUR la ligne du poste
 * (pas de table dédiée) : la suppression du poste révoque par construction
 * (AC6).
 *
 *  - `agent_token_hash` VARCHAR(64) NULL UNIQUE — sha256 hex du bearer
 *    courant (jamais de clair persisté, iso `WorkstationRefreshToken`).
 *  - `agent_previous_token_hash` VARCHAR(64) NULL + index — fenêtre de grâce
 *    D5 : l'ancien token reste valide jusqu'au premier usage du nouveau.
 *  - `agent_token_rotated_at` TIMESTAMP NULL — base du calcul d'échéance de
 *    rotation (`config('agent.token_rotation_days')`).
 *  - `agent_last_checkin_at` TIMESTAMP NULL — maj à chaque requête
 *    authentifiée (y compris quarantaine, FR15).
 *  - `agent_quarantined_at` TIMESTAMP NULL — quarantaine anti-clonage
 *    (403 AGENT_QUARANTINED tant que non levée).
 *
 * **Idempotence stricte** : `Schema::hasColumn()` avant chaque création
 * (iso `2026_05_22_120000_add_progress_and_programmed_action_to_workstations`).
 * Types simples (varchar + timestamp) → compatible SQLite tests sans branche
 * driver dédiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            if (! Schema::hasColumn('workstations', 'agent_token_hash')) {
                $table->string('agent_token_hash', 64)->nullable()->unique()
                    ->comment('SHA-256 hex du bearer agent courant (Story 23.2)');
            }
            if (! Schema::hasColumn('workstations', 'agent_previous_token_hash')) {
                $table->string('agent_previous_token_hash', 64)->nullable()->index()
                    ->comment('Fenêtre de grâce rotation D5 (Story 23.2)');
            }
            if (! Schema::hasColumn('workstations', 'agent_token_rotated_at')) {
                $table->timestamp('agent_token_rotated_at')->nullable()
                    ->comment('Dernière émission/rotation du token agent (Story 23.2)');
            }
            if (! Schema::hasColumn('workstations', 'agent_last_checkin_at')) {
                $table->timestamp('agent_last_checkin_at')->nullable()
                    ->comment('Dernier check-in authentifié du canal agent (Story 23.2)');
            }
            if (! Schema::hasColumn('workstations', 'agent_quarantined_at')) {
                $table->timestamp('agent_quarantined_at')->nullable()
                    ->comment('Quarantaine anti-clonage (Story 23.2)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            foreach ([
                'agent_quarantined_at',
                'agent_last_checkin_at',
                'agent_token_rotated_at',
                'agent_previous_token_hash',
                'agent_token_hash',
            ] as $column) {
                if (Schema::hasColumn('workstations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
