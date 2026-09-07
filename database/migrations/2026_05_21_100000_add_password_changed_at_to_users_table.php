<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ajoute la colonne password_changed_at — timestamp du dernier changement
     * de mot de passe effectif par l'utilisateur (lu depuis pwdLastSet AD).
     *
     * Sémantique :
     *   - NULL  = jamais changé / pwdLastSet == 0 (forçage obligatoire) → filtre « mdp par défaut »
     *   - date  = dernier changement connu (sync au login ou via cron users:sync-from-ad)
     *
     * On n'y stocke JAMAIS le mot de passe : uniquement le timestamp.
     * Différent de pwd_reset_at qui trace le dernier reset admin.
     *
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'password_changed_at')) {
                // ->after('pwd_reset_at') : cosmétique MySQL uniquement,
                // ignoré silencieusement par pgsql/sqlite.
                // Conservé pour cohérence d'ordre des colonnes en prod MySQL.
                $table->timestamp('password_changed_at')->nullable()->after('pwd_reset_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'password_changed_at')) {
                $table->dropColumn('password_changed_at');
            }
        });
    }
};
