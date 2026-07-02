<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 34.x — Ajoute une `description` libre au lecteur réseau géré.
 *
 * Champ purement informatif (admin) : affiché dans la liste `app/shares` pour
 * documenter l'usage d'un répertoire. Nullable, sans impact provisioning/ACL ni
 * projection agent (le provider ne consomme que name/directory_name/label/letter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_shares', function (Blueprint $table) {
            $table->text('description')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('network_shares', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
