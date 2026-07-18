<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 6.1 — Crée les tables `printers` + `printer_workstation_group` en
 * SQLite mémoire pour les tests Feature/Unit.
 *
 * À utiliser AVEC `CreatesPermissionSchema` (qui pose `users` et
 * `workstation_groups`, FKs nécessaires).
 */
trait CreatesPrintersSchema
{
    /** @var string[] */
    protected array $createdPrinterTables = [];

    protected function createPrintersSchema(): void
    {
        if (!Schema::hasTable('printers')) {
            Schema::create('printers', function (Blueprint $table) {
                $table->string('cups_name', 15)->primary();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->boolean('orphan')->default(false)->index();
                $table->text('description_ser')->nullable();
                $table->timestamps();
                $table->index('created_by_user_id');
            });
            $this->createdPrinterTables[] = 'printers';
        }

        if (!Schema::hasTable('printer_workstation_group')) {
            Schema::create('printer_workstation_group', function (Blueprint $table) {
                $table->string('cups_name', 15);
                $table->unsignedBigInteger('workstation_group_id');
                $table->timestamp('attached_at')->useCurrent();
                $table->unsignedBigInteger('attached_by_user_id')->nullable();
                $table->boolean('is_default')->default(false); // story 27.2 (migration 2026_06_15_120000)
                $table->primary(['cups_name', 'workstation_group_id'], 'pwg_pk');
                $table->index('workstation_group_id', 'pwg_wg_idx');
            });
            $this->createdPrinterTables[] = 'printer_workstation_group';
        }

        // `workstations` + pivot `workstation_group_workstation` : minimum requis
        // pour `loadCount('workstations')` de l'onglet Imprimantes (nombre de postes
        // par parc). Scopé aux tests imprimantes — PAS dans CreatesPermissionSchema,
        // car ce nom de table est déjà défini par plusieurs bootstrappers plus riches
        // (IpxeSchemaBootstrapper avec `uuid`, etc.) qu'une table minimale masquerait.
        if (!Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->timestamps();
            });
            $this->createdPrinterTables[] = 'workstations';
        }
        if (!Schema::hasTable('workstation_group_workstation')) {
            Schema::create('workstation_group_workstation', function (Blueprint $table) {
                $table->unsignedBigInteger('workstation_group_id');
                $table->unsignedBigInteger('workstation_id');
                $table->primary(['workstation_group_id', 'workstation_id'], 'wgw_pk');
            });
            $this->createdPrinterTables[] = 'workstation_group_workstation';
        }
    }

    protected function dropPrintersSchema(): void
    {
        $dropOrder = ['workstation_group_workstation', 'workstations', 'printer_workstation_group', 'printers'];
        foreach ($dropOrder as $table) {
            if (in_array($table, $this->createdPrinterTables, true)) {
                Schema::dropIfExists($table);
            }
        }
        $this->createdPrinterTables = [];
    }
}
