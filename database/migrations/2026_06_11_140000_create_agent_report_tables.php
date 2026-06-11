<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 24.1 — AC1, AC2, AC3.
 *
 * Stockage D3 des rapports de conformité agent (`POST /api/v1/agent/report`,
 * {@see \App\Services\Agent\Reporting\ReportIngestService}) — trois tables :
 *
 *  - `agent_resource_states` — état COURANT par (poste, type) : UPSERT,
 *    volume borné structurellement (UNIQUE (workstation_id, type) — « un
 *    poste conforme = 1 ligne par type », jamais N rapports = N lignes).
 *  - `agent_report_events` — journal des SEULS changements (dérive
 *    détectée/corrigée, apply échoué), append-only, rétention courte
 *    (`config('agent.report_events_retention_days')`, purge
 *    `agent:reports:prune`).
 *  - `agent_report_history` — payloads bruts complets, append-only,
 *    derrière le flag `AGENT_REPORT_HISTORY` (défaut off). Table de
 *    DÉBOGAGE : retrait prévu par D3 à la sortie de debug — d'où une table
 *    dédiée, supprimable d'un bloc. Rétention
 *    `config('agent.report_history_retention_days')`.
 *
 * **Idempotence stricte** : `Schema::hasTable()` avant chaque création
 * (iso `2026_06_11_120000_add_agent_token_columns_to_workstations`).
 * Types simples (varchar/text/timestamp/jsonb) → compatibles SQLite tests ;
 * les longueurs varchar ne sont PAS appliquées par SQLite — les bornes
 * réelles sont garanties par la validation `ReportRequest` en amont.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_resource_states')) {
            Schema::create('agent_resource_states', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
                $table->string('type', 64)->comment('Identifiant de type figé (§7 contrat v1)');
                $table->string('status', 32)->comment('compliant|drift|drifted_allowed|error (AgentResourceStatus)');
                $table->string('hash', 64)->comment('Hash opaque StateHasher rapporté tel quel par l\'agent');
                $table->text('detail')->nullable()->comment('Cause d\'erreur rapportée (obligatoire si status=error)');
                $table->timestamp('reported_at')->comment('Horodatage du dernier rapport — rafraîchi même si identique');
                $table->timestamps();

                // Volume borné D3 : au plus 1 ligne par (poste, type).
                $table->unique(['workstation_id', 'type']);
                // Lectures UI conformité (24.5) : « tous les postes en drift ».
                $table->index('status');
            });
        }

        if (! Schema::hasTable('agent_report_events')) {
            Schema::create('agent_report_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('previous_status', 32)->nullable()
                    ->comment('Statut avant le changement — null au premier rapport du type');
                $table->string('status', 32);
                $table->string('hash', 64);
                $table->text('detail')->nullable();
                // Append-only : pas d'updated_at (UPDATED_AT = null côté modèle).
                $table->timestamp('created_at')->useCurrent();

                $table->index(['workstation_id', 'type']);
                // Purge par âge (agent:reports:prune).
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('agent_report_history')) {
            Schema::create('agent_report_history', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
                $table->jsonb('payload')->comment('Rapport brut complet (payload validé) — debug uniquement');
                $table->timestamp('created_at')->useCurrent();

                // Purge par âge (agent:reports:prune).
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_report_history');
        Schema::dropIfExists('agent_report_events');
        Schema::dropIfExists('agent_resource_states');
    }
};
