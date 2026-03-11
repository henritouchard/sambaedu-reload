<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le support de pré-compilation des raccourcis.
 *
 * - is_dynamic : indique si le raccourci contient des variables dynamiques ($user, $userprofile, etc.)
 * - compiled_data : données pré-compilées du raccourci (résolution des cibles, scripts pré-générés)
 * - compiled_at : timestamp de la dernière compilation
 *
 * Table compiled_shortcuts : cache pré-compilé par cible (workstation/workstation_group)
 * pour servir les raccourcis instantanément au téléchargement Windows/Linux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortcuts', function (Blueprint $table) {
            $table->boolean('is_dynamic')->default(false)->after('icon_path')
                ->comment('True si le raccourci contient des variables dynamiques ($user, $userprofile, etc.)');
            $table->jsonb('compiled_data')->nullable()->after('is_dynamic')
                ->comment('Données pré-compilées du raccourci (cibles résolues, scripts statiques)');
            $table->timestamp('compiled_at')->nullable()->after('compiled_data')
                ->comment('Timestamp de la dernière compilation');
        });

        // Cache pré-compilé par cible pour export rapide
        // Les fichiers .lnk et .desktop sont stockés sur le filesystem
        // dans /etc/sambaedu/applications/shortcuts/compiled/
        Schema::create('compiled_shortcuts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shortcut_id')->constrained('shortcuts')->cascadeOnDelete();
            $table->string('target_type', 50)->comment('Type de cible: workstation, workstation_group, ad_user, ad_user_group');
            $table->string('target_identifier')->comment('Identifiant de la cible (id SQL ou CN AD)');
            $table->string('os', 10)->default('windows')->comment('Système cible: windows, linux');
            $table->string('action', 20)->default('logon')->comment('Action GPO: logon, logoff, startup, shutdown');
            $table->text('script_fragment')->nullable()->comment('Fragment de script pré-compilé (.cmd ou .sh)');
            $table->string('compiled_path')->nullable()->comment('Chemin vers le fichier pré-compilé (.lnk ou .desktop) sur le filesystem');
            $table->timestamps();

            $table->unique(
                ['shortcut_id', 'target_type', 'target_identifier', 'os', 'action'],
                'compiled_shortcut_unique'
            );
            $table->index(['target_type', 'target_identifier', 'os', 'action'], 'compiled_shortcut_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compiled_shortcuts');

        Schema::table('shortcuts', function (Blueprint $table) {
            $table->dropColumn(['is_dynamic', 'compiled_data', 'compiled_at']);
        });
    }
};
