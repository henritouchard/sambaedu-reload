<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 15.1 — Statut par poste pour un déploiement WPKG donné.
 *
 * Une ligne = un (deployment, workstation) ; `app_profile_id` est nullable
 * (un déploiement peut cibler un poste sans profil dédié, ex bulk machine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wpkg_deployment_workstation_status', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('deployment_id');
            $table->foreign('deployment_id')
                ->references('id')->on('wpkg_deployments')
                ->cascadeOnDelete();

            $table->foreignId('workstation_id')
                ->constrained('workstations')
                ->cascadeOnDelete();

            $table->foreignId('app_profile_id')
                ->nullable()
                ->constrained('app_profiles')
                ->nullOnDelete();

            $table->timestamp('client_reported_at')->nullable();
            $table->string('client_status', 20)->default('pending');
            $table->json('details')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['deployment_id', 'workstation_id'], 'wdws_deploy_ws_idx');
            $table->index(['workstation_id', 'client_reported_at'], 'wdws_ws_reported_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wpkg_deployment_workstation_status');
    }
};
