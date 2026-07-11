<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 8.1 — Schémas SQLite mémoire pour les tests `dhcp_reservations`.
 *
 * Pattern aligné `CreatesPrintersSchema`. Pose la table `dhcp_reservations`
 * + dépendance minimale `workstations` (FK nullable cible) si elle n'existe
 * pas encore.
 */
trait CreatesDhcpSchema
{
    /** @var string[] */
    protected array $createdDhcpTables = [];

    protected function createDhcpSchema(): void
    {
        if (!Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('os')->nullable();
                $table->string('ip')->nullable();
                $table->string('mac')->nullable();
                $table->string('uuid')->nullable();
                $table->string('status')->default('active');
                $table->timestamp('last_report_at')->nullable();
                $table->string('report_sha')->nullable();
                $table->string('log_path')->nullable();
                $table->string('report_path')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->boolean('managed_by_control_hub')->default(false);
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
            $this->createdDhcpTables[] = 'workstations';
        }

        if (!Schema::hasTable('dhcp_reservations')) {
            Schema::create('dhcp_reservations', function (Blueprint $table) {
                $table->id();
                $table->string('name', 63);
                $table->string('mac', 17);
                $table->string('ip', 45);
                $table->foreignId('workstation_id')->nullable()->constrained('workstations')->nullOnDelete();
                $table->text('description')->nullable();
                $table->string('source', 32)->default('manual');
                $table->timestamps();

                $table->unique('name');
                $table->unique('mac');
                $table->unique('ip');
                $table->index('workstation_id');
                $table->index('source');
            });
            $this->createdDhcpTables[] = 'dhcp_reservations';
        }

        // Story 8.3 — sous-réseaux (VLAN).
        if (!Schema::hasTable('dhcp_subnets')) {
            Schema::create('dhcp_subnets', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('vlan_id');
                $table->string('network', 45);
                $table->string('gateway', 45);
                $table->json('ranges');
                $table->string('extra_option', 255)->nullable();
                $table->string('description', 255)->nullable();
                $table->timestamps();

                $table->unique('vlan_id');
            });
            $this->createdDhcpTables[] = 'dhcp_subnets';
        }
    }

    protected function dropDhcpSchema(): void
    {
        $dropOrder = ['dhcp_subnets', 'dhcp_reservations', 'workstations'];
        foreach ($dropOrder as $table) {
            if (in_array($table, $this->createdDhcpTables, true)) {
                Schema::dropIfExists($table);
            }
        }
        $this->createdDhcpTables = [];
    }
}
