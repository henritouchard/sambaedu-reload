<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 25.5 — AC4 (greffe persistance de la version rapportée par l'agent).
 *
 * `agent_version` arrive dans CHAQUE rapport (contrat 23.1), est validé
 * (`ReportRequest`) puis aujourd'hui SILENCIEUSEMENT jeté : aucune colonne ne
 * le persiste, la surface « progression du déploiement » (25.5) est donc
 * impossible sans cette greffe. Décision actée (option A, Henri 2026-06-13) :
 * deux colonnes `agent_*` sur `workstations` (la frontière `agent_*` héberge
 * déjà `agent_token_hash`, `agent_last_checkin_at`, `agent_sync_requested_at`…).
 *
 *  - `agent_reported_version` VARCHAR(32) NULL — dernière version rapportée
 *    (borne = `max:32` de la règle de validation, cohérence stricte) ;
 *  - `agent_reported_version_at` TIMESTAMP NULL — fraîcheur de la donnée
 *    (un poste « jamais vu » = null ; la surface progression montre la
 *    récence pour distinguer convergé / en retard / silencieux).
 *
 * Écriture UNIQUE : `ReportController::store()`, APRÈS `ingest()`, hors
 * transaction D3 (iso `syncRequests->fulfill()` et le check-in middleware —
 * une écriture `agent_*` idempotente, indépendante du stockage des items).
 * `ReportIngestService` reste volontairement read-only sur `workstations`.
 * Hors `$fillable` du modèle (anti mass-assignment, iso colonnes `agent_*`).
 *
 * **Idempotence stricte** : `Schema::hasColumn()` avant création (iso
 * `2026_06_12_120000_add_agent_sync_requested_at_to_workstations`). Types
 * simples (varchar/timestamp) → compatibles SQLite des tests sans branche
 * driver. `down()` symétrique (drop conditionnel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            if (! Schema::hasColumn('workstations', 'agent_reported_version')) {
                $table->string('agent_reported_version', 32)->nullable()
                    ->comment('Dernière version d\'agent rapportée (Story 25.5) — greffe ReportController, hors $fillable');
            }
            if (! Schema::hasColumn('workstations', 'agent_reported_version_at')) {
                $table->timestamp('agent_reported_version_at')->nullable()
                    ->comment('Fraîcheur de la version rapportée (Story 25.5) — null = jamais vu');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            if (Schema::hasColumn('workstations', 'agent_reported_version_at')) {
                $table->dropColumn('agent_reported_version_at');
            }
            if (Schema::hasColumn('workstations', 'agent_reported_version')) {
                $table->dropColumn('agent_reported_version');
            }
        });
    }
};
