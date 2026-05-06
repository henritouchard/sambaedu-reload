<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 15.2 — Pivots du resolver WPKG.
 *
 * Crée les deux tables pivots manquantes côté schéma Reload, équivalents legacy
 * `applications_profile.type_entite IN ('poste','parc')` (cf. `sambaedu/includes/wpkg_libsql.php:212-291`
 * `info_poste_applications`) :
 *
 *  - `application_workstation`       — Apps directement rattachées à un poste.
 *  - `application_workstation_group` — Apps directement rattachées à un parc.
 *
 * Le pivot `application_dependencies` (auto-référence Application ↔ Application
 * requise) existe déjà via `2026_03_16_100100_create_application_dependencies_table.php`
 * — pas de doublon.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('application_workstation')) {
            Schema::create('application_workstation', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('application_id')
                    ->constrained('applications')
                    ->cascadeOnDelete();
                $table->foreignId('workstation_id')
                    ->constrained('workstations')
                    ->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['application_id', 'workstation_id'], 'app_wks_unique');
                $table->index('application_id');
                $table->index('workstation_id');
            });
        }

        if (! Schema::hasTable('application_workstation_group')) {
            Schema::create('application_workstation_group', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('application_id')
                    ->constrained('applications')
                    ->cascadeOnDelete();
                $table->foreignId('workstation_group_id')
                    ->constrained('workstation_groups')
                    ->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['application_id', 'workstation_group_id'], 'app_wgrp_unique');
                $table->index('application_id');
                $table->index('workstation_group_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('application_workstation_group');
        Schema::dropIfExists('application_workstation');
    }
};
