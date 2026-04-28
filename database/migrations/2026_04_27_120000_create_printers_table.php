<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 6.1 — Couche métier SER pour les imprimantes (option B 2026-04-27).
 *
 * Complète CUPS sans le remplacer : CUPS reste source de vérité runtime pour
 * nom/URI/état/PPD/file. Cette table porte uniquement :
 *  - audit (created_at / updated_at / created_by_user_id),
 *  - métadata métier (description_ser distincte de la description CUPS),
 *  - flag de drift (orphan = présent en SER mais absent de CUPS).
 *
 * PK : `cups_name` (string 15, cohérent avec la regex CUPS `[a-zA-Z0-9_-]{1,15}`).
 *
 * Réconciliation via `php artisan printers:sync` (planifié 03:30, idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->string('cups_name', 15)->primary();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->boolean('orphan')->default(false)->index();
            $table->text('description_ser')->nullable();
            $table->timestamps();

            $table->index('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
