<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 15.2 / AC5.1 — Table d'overrides des options `.ini` per-poste.
 *
 * Stocke les overrides ciblés des 8 options legacy (debug, logdebug, force,
 * forceinstall, nonotify, dryrun, nowpkg, noforcedremove). Un poste sans ligne
 * dans cette table reçoit les valeurs `false` par défaut (parité
 * `sambaedu/wpkg/poste_maintenance_options.php:90-191`). Les descriptions ne
 * sont pas stockées en BDD : constante PHP `WorkstationIniGenerator::LEGACY_OPTIONS`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wpkg_workstation_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workstation_id')
                ->constrained('workstations')
                ->cascadeOnDelete();
            $table->string('option_key', 64);
            $table->string('option_value', 255);
            $table->timestamps();

            $table->unique(['workstation_id', 'option_key'], 'wpkg_wks_opt_unique');
            $table->index('workstation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wpkg_workstation_options');
    }
};
