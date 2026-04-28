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
                $table->primary(['cups_name', 'workstation_group_id'], 'pwg_pk');
                $table->index('workstation_group_id', 'pwg_wg_idx');
            });
            $this->createdPrinterTables[] = 'printer_workstation_group';
        }
    }

    protected function dropPrintersSchema(): void
    {
        $dropOrder = ['printer_workstation_group', 'printers'];
        foreach ($dropOrder as $table) {
            if (in_array($table, $this->createdPrinterTables, true)) {
                Schema::dropIfExists($table);
            }
        }
        $this->createdPrinterTables = [];
    }
}
