<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 6.2 — Crée la table `printer_drivers` en SQLite mémoire pour les
 * tests Feature / Unit.
 *
 * À utiliser AVEC `CreatesPermissionSchema` (pose `users`) ET
 * `CreatesPrintersSchema` (pose `printers` — FK CASCADE dépendante).
 *
 * Décalque `CreatesPrintersSchema` (Story 6.1) : PK composite via
 * `Blueprint::primary([...])`. La FK `printer_cups_name` → `printers.cups_name`
 * est volontairement non-déclarée en SQLite mémoire (PRAGMA foreign_keys par
 * défaut = OFF), donc CASCADE n'est pas testé ici — couverture via
 * migration prod + runbook E2E (cohérent décision 6.1 « tests cascade
 * pivot non joués SQLite »).
 */
trait CreatesPrinterDriversSchema
{
    /** @var string[] */
    protected array $createdPrinterDriverTables = [];

    protected function createPrinterDriversSchema(): void
    {
        if (!Schema::hasTable('printer_drivers')) {
            Schema::create('printer_drivers', function (Blueprint $table) {
                $table->string('printer_cups_name', 15);
                $table->string('architecture', 16)->default('x64');
                $table->string('driver_name', 255);
                $table->string('source', 32)->default('synced');
                $table->boolean('orphan')->default(false)->index();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
                $table->primary(['printer_cups_name', 'architecture'], 'pd_pk');
                $table->index('created_by_user_id');
                $table->index('driver_name');
            });
            $this->createdPrinterDriverTables[] = 'printer_drivers';
        }
    }

    protected function dropPrinterDriversSchema(): void
    {
        foreach ($this->createdPrinterDriverTables as $table) {
            Schema::dropIfExists($table);
        }
        $this->createdPrinterDriverTables = [];
    }
}
