<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ajoute la colonne pwd_reset_at — timestamp du dernier reset
     * de mot de passe (bulk ou unitaire) pour traçabilité RGPD (NFR8).
     *
     * On n'y stocke JAMAIS le mot de passe : uniquement la date.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'pwd_reset_at')) {
                $table->timestamp('pwd_reset_at')->nullable()->after('ad_synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'pwd_reset_at')) {
                $table->dropColumn('pwd_reset_at');
            }
        });
    }
};
