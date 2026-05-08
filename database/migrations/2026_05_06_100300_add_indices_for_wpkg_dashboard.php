<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 15.5 / T1 — Indices DB pour atteindre NFR1 < 2s sur 500 postes
 * (dashboard `/app/wpkg/deployments`).
 *
 * Indices ajoutés :
 *   - `workstations.last_report_at` (utilisé pour KPI « postes silencieux >7j »)
 *   - `wpkg_deployment_workstation_status.client_status` (KPI agrégé par status)
 *   - `wpkg_deployment_workstation_status.client_reported_at` (tri DESC + filtre 24h)
 *   - composite `(workstation_id, client_reported_at DESC)` (pour le
 *     `DISTINCT ON (workstation_id) ... ORDER BY workstation_id, client_reported_at DESC`)
 *
 * Note : l'index `wdws_ws_reported_idx` créé en 15.1 couvre déjà
 * `(workstation_id, client_reported_at)` — on évite donc le doublon.
 * On ajoute uniquement les indices simples manquants.
 *
 * Hasonconditional `Schema::hasIndex` non disponible en Laravel 11 (DB-spécifique).
 * On utilise `try/catch` pour gérer la création idempotente : si l'index existe
 * déjà (cas de redéploiement / migration partielle), on log et on continue.
 */
return new class extends Migration
{
    public function up(): void
    {
        // workstations.last_report_at — utilisé par le dashboard pour
        // détecter les postes silencieux > 7 jours.
        if (Schema::hasTable('workstations') && Schema::hasColumn('workstations', 'last_report_at')) {
            $this->addIndexSafely('workstations', ['last_report_at'], 'workstations_last_report_at_index');
        }

        // wpkg_deployment_workstation_status.client_status — KPI agrégé
        // par status (success/partial/failed).
        if (Schema::hasTable('wpkg_deployment_workstation_status')) {
            $this->addIndexSafely(
                'wpkg_deployment_workstation_status',
                ['client_status'],
                'wdws_client_status_index'
            );
            $this->addIndexSafely(
                'wpkg_deployment_workstation_status',
                ['client_reported_at'],
                'wdws_client_reported_at_index'
            );
        }
    }

    public function down(): void
    {
        $this->dropIndexSafely('workstations', 'workstations_last_report_at_index');
        $this->dropIndexSafely('wpkg_deployment_workstation_status', 'wdws_client_status_index');
        $this->dropIndexSafely('wpkg_deployment_workstation_status', 'wdws_client_reported_at_index');
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndexSafely(string $table, array $columns, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
                $blueprint->index($columns, $indexName);
            });
        } catch (\Throwable $e) {
            // Index déjà existant (ré-exécution migration) : silencieux.
            // Tout autre erreur est remontée.
            if (! str_contains($e->getMessage(), 'already exists')
                && ! str_contains($e->getMessage(), 'duplicate key')) {
                throw $e;
            }
        }
    }

    private function dropIndexSafely(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            // Idempotence : drop OK si déjà absent.
        }
    }
};
