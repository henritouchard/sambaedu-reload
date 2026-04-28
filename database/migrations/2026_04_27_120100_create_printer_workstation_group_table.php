<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 6.1 — Pivot N:N imprimante ↔ parc (`WorkstationGroup`).
 *
 * Cascade DELETE depuis `printers` (suppression imprimante = retrait des
 * rattachements) et depuis `workstation_groups` (suppression parc = retrait
 * des rattachements). Les rattachements ne sont jamais conservés à vide.
 *
 * Audit minimal : qui a posé le rattachement, quand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_workstation_group', function (Blueprint $table) {
            $table->string('cups_name', 15);
            $table->foreignId('workstation_group_id')
                ->constrained('workstation_groups')
                ->cascadeOnDelete();
            $table->timestamp('attached_at')->useCurrent();
            $table->foreignId('attached_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->primary(['cups_name', 'workstation_group_id'], 'pwg_pk');

            $table->foreign('cups_name')
                ->references('cups_name')
                ->on('printers')
                ->cascadeOnDelete();

            $table->index('workstation_group_id', 'pwg_wg_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_workstation_group');
    }
};
