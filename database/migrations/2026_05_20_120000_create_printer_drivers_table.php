<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 6.2 — Table SER pour les pilotes Windows associés aux imprimantes Samba.
 *
 * Complète Samba sans le remplacer : `rpcclient enumdrivers` reste source de
 * vérité runtime pour la liste effective des drivers publiés sur `[print$]`.
 * Cette table porte uniquement :
 *  - audit (`created_at`/`updated_at`/`created_by_user_id`),
 *  - métadata métier (`notes` libre — nom interne lisible),
 *  - flag de drift (`orphan` = présent en SER mais absent de Samba),
 *  - provenance (`source` = `upload-w10` / `synced` / `manual-cli`),
 *  - rattachement métier driver SMB ↔ imprimante CUPS (FK CASCADE depuis
 *    `printers.cups_name`).
 *
 * PK composite : `(printer_cups_name, architecture)` — un même driver peut
 * être rattaché à plusieurs imprimantes (1 ligne par imprimante), et une
 * imprimante peut avoir des variantes x64 / x86 (D5 6.2 = x64 uniquement
 * en pratique, mais la PK ouvre la porte à 6.2bis sans migration).
 *
 * Réconciliation via `php artisan printer-drivers:sync` (planifié 03:35,
 * idempotent, skip orphan-marking si Samba down — cohérent fix #12 6.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_drivers', function (Blueprint $table) {
            $table->string('printer_cups_name', 15);
            $table->string('architecture', 16)->default('x64');
            $table->string('driver_name', 255);
            $table->string('source', 32)->default('synced');
            $table->boolean('orphan')->default(false)->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->primary(['printer_cups_name', 'architecture'], 'pd_pk');

            $table->foreign('printer_cups_name')
                ->references('cups_name')
                ->on('printers')
                ->cascadeOnDelete();

            $table->index('created_by_user_id');
            $table->index('driver_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_drivers');
    }
};
