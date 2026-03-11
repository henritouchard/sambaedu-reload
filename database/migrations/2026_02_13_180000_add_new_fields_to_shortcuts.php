<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajout des nouveaux champs pour le format ControlHub v2 des raccourcis :
 * - category, description, is_active, is_url, metadata
 * - windows_workdir, linux_workdir
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shortcuts', function (Blueprint $table) {
            $table->string('category', 100)->nullable()->comment('Catégorie du raccourci');
            $table->text('description')->nullable()->comment('Description du raccourci');
            $table->boolean('is_active')->default(true)->comment('Raccourci actif');
            $table->boolean('is_url')->default(false)->comment('Raccourci de type URL');
            $table->jsonb('metadata')->nullable()->comment('Métadonnées supplémentaires');
            $table->string('windows_workdir', 512)->nullable()->comment('Répertoire de travail Windows');
            $table->string('linux_workdir', 512)->nullable()->comment('Répertoire de travail Linux');
        });
    }

    public function down(): void
    {
        Schema::table('shortcuts', function (Blueprint $table) {
            $table->dropColumn([
                'category',
                'description',
                'is_active',
                'is_url',
                'metadata',
                'windows_workdir',
                'linux_workdir',
            ]);
        });
    }
};
