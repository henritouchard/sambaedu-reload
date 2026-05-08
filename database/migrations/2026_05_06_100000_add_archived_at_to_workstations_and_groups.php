<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivage logique sur `workstations` et `workstation_groups`
 * (`archived_at`) en remplacement de tout `DELETE` sec. Le scope
 * `notArchived()` est appliqué côté resolver WPKG et listings UI.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
        });

        Schema::table('workstation_groups', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at']);
        });

        Schema::table('workstation_groups', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at']);
        });
    }
};
