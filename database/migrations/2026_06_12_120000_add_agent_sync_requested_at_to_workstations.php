<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 24.7 — AC5 (« Forcer la synchro »).
 *
 * Ajoute la colonne `agent_sync_requested_at` à `workstations` — la
 * mécanique PULL du bouton « forcer la synchro » (décision n° 1) : une
 * demande de resynchronisation pendante est un simple timestamp nullable
 * posé sur la ligne du poste.
 *
 *  - `agent_sync_requested_at` TIMESTAMP NULL — non null = demande pendante :
 *    `GET /api/v1/agent/state` répond alors 200 corps complet même si
 *    `If-None-Match` concorde (bypass du 304, même ETag, enveloppe brute) ;
 *    le premier `POST /api/v1/agent/report` suivant la solde (remise à null).
 *
 * Exactement DEUX écrivains (décision n° 2, invariant « colonnes `agent_*` »
 * de 24.1 étendu) : l'UI admin via `SyncRequestService::request()` (canal
 * web authentifié) et `ReportController` via `SyncRequestService::fulfill()`
 * (canal agent). Hors `$fillable` du modèle (anti mass-assignment), iso les
 * autres colonnes `agent_*` (23.2).
 *
 * **Idempotence stricte** : `Schema::hasColumn()` avant création (iso
 * `2026_06_11_120000_add_agent_token_columns_to_workstations`). Type simple
 * (timestamp) → compatible SQLite tests sans branche driver dédiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            if (! Schema::hasColumn('workstations', 'agent_sync_requested_at')) {
                $table->timestamp('agent_sync_requested_at')->nullable()
                    ->comment('Demande de resynchronisation pendante (Story 24.7) — bypass 304 GET /state jusqu\'au solde au POST /report');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            if (Schema::hasColumn('workstations', 'agent_sync_requested_at')) {
                $table->dropColumn('agent_sync_requested_at');
            }
        });
    }
};
