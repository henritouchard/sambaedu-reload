<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 23.3 — AC1.
 *
 * Ajoute les colonnes du ticket d'enrôlement one-time (porte 1 — chaîne
 * d'install iPXE Windows) à `workstations`. Le ticket est émis à la
 * génération de l'unattend.xml et échangé contre le token agent via
 * `POST /api/v1/agent/enrollment` ({@see \App\Services\Agent\Enrollment\EnrollmentService}).
 *
 *  - `agent_enroll_ticket_hash` VARCHAR(64) NULL UNIQUE — sha256 hex du
 *    ticket en cours (jamais de clair persisté, iso `agent_token_hash`).
 *  - `agent_enroll_ticket_expires_at` TIMESTAMP NULL — expiration
 *    (`config('agent.enroll_ticket_ttl_minutes')`, défaut 240 min).
 *
 * **Idempotence stricte** : `Schema::hasColumn()` avant chaque création
 * (iso `2026_06_11_120000_add_agent_token_columns_to_workstations`).
 * Types simples (varchar + timestamp) → compatible SQLite tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            if (! Schema::hasColumn('workstations', 'agent_enroll_ticket_hash')) {
                $table->string('agent_enroll_ticket_hash', 64)->nullable()->unique()
                    ->comment('SHA-256 hex du ticket d\'enrôlement one-time (Story 23.3)');
            }
            if (! Schema::hasColumn('workstations', 'agent_enroll_ticket_expires_at')) {
                $table->timestamp('agent_enroll_ticket_expires_at')->nullable()
                    ->comment('Expiration du ticket d\'enrôlement (Story 23.3)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            foreach ([
                'agent_enroll_ticket_expires_at',
                'agent_enroll_ticket_hash',
            ] as $column) {
                if (Schema::hasColumn('workstations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
