<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 3.1 — Helper de bootstrap SQLite :memory: pour les tests iPXE.
 *
 * Crée les tables minimales nécessaires pour tester
 * {@see \App\Ipxe\Services\WorkstationLocator},
 * {@see \App\Ipxe\Services\IpxeService}, et les feature tests de
 * `/ipxe/boot` — sans rejouer la baseline complète des migrations
 * (incompatibilité historique cf. WpkgDeploymentMigrationsTest).
 *
 * Tables provisionnées :
 *
 *  - `workstations` (Story 4.1 — schema réduit aux colonnes utilisées par
 *    le locator/renderer)
 *  - `workstation_groups` + pivot (eager-load — sinon `with('groups')` plante)
 *  - `app_profiles` + pivot (eager-load — sinon `with('appProfiles')` plante)
 *  - `physical_rooms` (relation `physicalRoom` — minimal)
 *  - `machine_boot_logs` (Story 4.2 — schema iso migration)
 *
 * Pattern iso `Tests\Support\WpkgSchemaBootstrapper` (Story 15.2).
 */
final class IpxeSchemaBootstrapper
{
    /**
     * Crée les tables nécessaires si absentes (idempotent).
     */
    public static function bootstrap(): void
    {
        // Mute le dispatcher Eloquent global (les observers
        // WorkstationObserver/WorkstationGroupObserver touchent LDAP/AD
        // = incompatible test offline).
        Model::unsetEventDispatcher();

        if (! Schema::hasTable('physical_rooms')) {
            Schema::create('physical_rooms', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->timestamps();
            });
        }

        // se4install : les builders Windows lisent ServiceCredentials qui
        // interroge cette table (effectivePassword → repli config si vide).
        if (! Schema::hasTable('service_credentials')) {
            Schema::create('service_credentials', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 64)->unique();
                $table->text('secret')->nullable();
                $table->text('totp_secret')->nullable();
                $table->unsignedBigInteger('totp_applied_counter')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->string('os', 32)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('mac', 32)->nullable();
                $table->string('uuid', 64)->nullable();
                $table->string('status', 32)->default('active');
                // Story 3.8 — D12 — colonnes progress + programmed_action.
                $table->string('progress', 8)->nullable();
                $table->text('programmed_action')->nullable();
                $table->timestamp('last_report_at')->nullable();
                $table->string('report_sha', 128)->nullable();
                $table->string('log_path', 512)->nullable();
                $table->string('report_path', 512)->nullable();
                $table->string('ad_dn', 512)->nullable();
                $table->string('ad_guid', 64)->nullable();
                $table->boolean('managed_by_control_hub')->default(false);
                $table->timestamp('archived_at')->nullable();
                // Story 23.3 — canal agent : la génération unattend ouvre un
                // ticket d'enrôlement (openTicket → colonnes agent_*).
                $table->string('agent_token_hash', 64)->nullable();
                $table->string('agent_previous_token_hash', 64)->nullable();
                $table->timestamp('agent_token_rotated_at')->nullable();
                $table->timestamp('agent_last_checkin_at')->nullable();
                $table->timestamp('agent_quarantined_at')->nullable();
                $table->string('agent_enroll_ticket_hash', 64)->nullable();
                $table->timestamp('agent_enroll_ticket_expires_at')->nullable();
                $table->timestamps();
            });
        } else {
            // Story 3.8 — D12 — ajout colonnes sur table provisionnée
            // antérieurement par un autre test (idempotent).
            if (! Schema::hasColumn('workstations', 'progress')) {
                Schema::table('workstations', function (Blueprint $table): void {
                    $table->string('progress', 8)->nullable();
                });
            }
            if (! Schema::hasColumn('workstations', 'programmed_action')) {
                Schema::table('workstations', function (Blueprint $table): void {
                    $table->text('programmed_action')->nullable();
                });
            }
            // Story 23.3 — idem, idempotent (iso pattern progress).
            if (! Schema::hasColumn('workstations', 'agent_token_hash')) {
                Schema::table('workstations', function (Blueprint $table): void {
                    $table->string('agent_token_hash', 64)->nullable();
                    $table->string('agent_previous_token_hash', 64)->nullable();
                    $table->timestamp('agent_token_rotated_at')->nullable();
                    $table->timestamp('agent_last_checkin_at')->nullable();
                    $table->timestamp('agent_quarantined_at')->nullable();
                });
            }
            if (! Schema::hasColumn('workstations', 'agent_enroll_ticket_hash')) {
                Schema::table('workstations', function (Blueprint $table): void {
                    $table->string('agent_enroll_ticket_hash', 64)->nullable();
                    $table->timestamp('agent_enroll_ticket_expires_at')->nullable();
                });
            }
        }

        if (! Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->boolean('is_physical')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('locked', 32)->nullable();
                $table->text('description')->nullable();
                $table->string('ad_dn', 512)->nullable();
                $table->string('ad_guid', 64)->nullable();
                $table->string('app_profile_name', 100)->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workstation_group_workstation')) {
            Schema::create('workstation_group_workstation', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('workstation_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->boolean('physical')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('app_profiles')) {
            Schema::create('app_profiles', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->boolean('is_active')->default(true);
                $table->timestamp('archived_at')->nullable();
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

        if (! Schema::hasTable('machine_boot_logs')) {
            Schema::create('machine_boot_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('workstation_id')->nullable();
                $table->string('machine_name', 100);
                $table->string('action', 20)->nullable();
                $table->string('initiated_by', 100)->nullable();
                $table->boolean('success')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('stopped_at')->nullable();
                $table->string('os', 32)->nullable();
                $table->integer('wol_score')->nullable();
                $table->integer('ipxe_score')->nullable();
                $table->integer('error_flags')->nullable();
                $table->integer('boot_speed')->nullable();
                $table->string('vlan', 32)->nullable();
                $table->string('switch_port', 32)->nullable();
                $table->string('switch_ip', 45)->nullable();
                $table->string('switch_name', 100)->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Drop des tables provisionnées (utilisé en tearDown si nécessaire).
     * Pas appelé par défaut — SQLite :memory: est recyclée par phpunit
     * entre les classes.
     */
    public static function tearDown(): void
    {
        Schema::dropIfExists('machine_boot_logs');
        Schema::dropIfExists('app_profile_workstation');
        Schema::dropIfExists('app_profiles');
        Schema::dropIfExists('workstation_group_workstation');
        Schema::dropIfExists('workstation_groups');
        Schema::dropIfExists('workstations');
        Schema::dropIfExists('physical_rooms');
    }
}
