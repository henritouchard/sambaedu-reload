<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helper Story 15.2 — Crée les tables minimales nécessaires pour tester
 * les services / generators / listeners du pipeline `App\Wpkg\Deployment`
 * sur SQLite :memory: sans rejouer la baseline complète des migrations
 * (incompatibilité historique cf. WpkgDeploymentMigrationsTest::class).
 */
final class WpkgSchemaBootstrapper
{
    /**
     * Booste l'ensemble des tables nécessaires : workstations, workstation_groups,
     * pivots groupes, app_profiles + pivots, applications + pivots resolver +
     * dépendances, wpkg_workstation_options.
     *
     * Désactive aussi les observers Eloquent métier (WorkstationGroupObserver,
     * WorkstationObserver, AppProfileObserver…) qui déclenchent du LDAP en
     * background — incompatible avec un environnement testing offline.
     */
    public static function bootstrap(): void
    {
        // Mute le dispatcher Eloquent global (pipeline 15.2 = Eloquent-only,
        // les observers métier touchent LDAP/AD = incompatible test offline).
        // `unsetEventDispatcher` met à null la référence statique partagée par
        // tous les modèles ; `tearDown()` restaure via le container.
        Model::unsetEventDispatcher();


        if (! Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->string('status', 32)->default('active');
                $table->string('ad_dn', 512)->nullable();
                $table->string('ad_guid', 36)->nullable();
                // Story 15.3 — colonne d'archivage logique (cf.
                // 2026_05_06_100000_add_archived_at_to_workstations_and_groups).
                // Présente en bootstrap shim pour que les requêtes
                // resolver/listener filtrant `archived_at IS NULL`
                // tournent en SQLite :memory:.
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->boolean('is_physical')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('ad_dn', 512)->nullable();
                $table->string('ad_guid', 36)->nullable();
                $table->string('display_name', 255)->nullable();
                $table->text('description')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workstation_group_workstation')) {
            Schema::create('workstation_group_workstation', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('workstation_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('app_profiles')) {
            Schema::create('app_profiles', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->boolean('is_active')->default(true);
                $table->string('ad_dn', 512)->nullable();
                $table->string('ad_guid', 36)->nullable();
                $table->string('display_name', 255)->nullable();
                $table->text('description')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $table): void {
                $table->id();
                $table->string('app_id', 100);
                $table->string('name', 100)->default('');
                $table->string('status', 32)->default('available');
                // Story 17.6 — fragment XML `<package>` lu par
                // LinuxOutController / WingetPackagesResolver (extraction des
                // noeuds <linux type=apt> / <windows type=winget>). Nullable :
                // non-breaking pour les tests 15.2 qui ne le renseignent pas.
                $table->text('xml')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('app_profile_workstation_group')) {
            Schema::create('app_profile_workstation_group', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('app_profile_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('app_profile_workstation')) {
            Schema::create('app_profile_workstation', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('app_profile_id');
                $table->unsignedBigInteger('workstation_id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('app_profile_application')) {
            Schema::create('app_profile_application', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('app_profile_id');
                $table->unsignedBigInteger('application_id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('application_workstation')) {
            Schema::create('application_workstation', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('application_id');
                $table->unsignedBigInteger('workstation_id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('application_workstation_group')) {
            Schema::create('application_workstation_group', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('application_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('application_dependencies')) {
            Schema::create('application_dependencies', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('application_id');
                $table->unsignedBigInteger('required_application_id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wpkg_workstation_options')) {
            Schema::create('wpkg_workstation_options', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('workstation_id');
                $table->string('option_key', 64);
                $table->string('option_value', 255);
                $table->timestamps();
            });
        }
    }

    public static function tearDown(): void
    {
        foreach ([
            'wpkg_workstation_options',
            'application_dependencies',
            'application_workstation_group',
            'application_workstation',
            'app_profile_application',
            'app_profile_workstation',
            'app_profile_workstation_group',
            'workstation_group_workstation',
            'applications',
            'app_profiles',
            'workstation_groups',
            'workstations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (app()->bound('events')) {
            Model::setEventDispatcher(app('events'));
        }
    }
}
