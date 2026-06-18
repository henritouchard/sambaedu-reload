<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.5 (2026-06-18) — AC4 : inventaire PAR POSTE des applications WPKG
 * rapporté par l'agent (champ additif `inventory` sur l'item `applications` du
 * rapport, {@see \App\Services\Agent\Reporting\ReportIngestService}).
 *
 * Fondation des LICENCES À POOL (sans implémenter l'UI) : un résultat PAR
 * application par poste (`app_id` + statut), comptabilisable. Le comptage de
 * sièges = lignes `status ∈ {compliant, drift}` (toutes deux = installé ;
 * `error` = non installé).
 *
 * **Donnée ADDITIVE sous la ligne d'état par type** (Décision D1 — grain 27.8
 * intact) : le VERDICT de conformité du type `applications` reste UN statut par
 * (poste, type) dans `agent_resource_states` (worst-status, circule dans
 * `ConformityService` + l'UI conformité SANS modification). Cette table N'EST
 * PAS une réintroduction du grain `item × poste` du débat 27.8 : c'est une
 * DONNÉE (l'inventaire), pas un verdict.
 *
 * **Idempotence stricte** : `Schema::hasTable()` avant création (iso
 * `2026_06_11_140000_create_agent_report_tables`). Types simples
 * (varchar/timestamp) → compatibles SQLite tests ; les longueurs varchar ne
 * sont PAS appliquées par SQLite — les bornes réelles sont garanties par la
 * validation `ReportRequest` en amont (piège SQLite : overflow PG 22001
 * invisible en test).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_application_inventory')) {
            Schema::create('agent_application_inventory', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
                $table->string('app_id', 191)
                    ->comment('Identifiant de paquet WPKG rapporté (Application::app_id) — Story 27.5');
                $table->string('status', 32)
                    ->comment('compliant|drift|error (AgentResourceStatus) ; compliant/drift = installé, error = non installé');
                $table->string('detail', 2000)->nullable()
                    ->comment('Message d\'erreur WPKG (ex. code 1603) — non vide si status=error, null sinon. Fondation diagnostic licences à pool. Option A décision Henri 2026-06-18.');
                $table->timestamp('reported_at')
                    ->comment('Horodatage du dernier rapport d\'inventaire — rafraîchi à chaque rapport');
                $table->timestamps();

                // Comptabilité des sièges (licences à pool) : au plus 1 ligne par
                // (poste, app). Level-triggered : une app retirée du rapport est
                // nettoyée (n'occupe plus de siège).
                $table->unique(['workstation_id', 'app_id']);
                // Lectures futures par app (comptage de sièges installés).
                $table->index('app_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_application_inventory');
    }
};
