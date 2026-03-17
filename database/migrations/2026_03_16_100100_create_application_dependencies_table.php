<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration pour les dépendances entre applications WPKG
 *
 * Remplace la table `dependance` du legacy MySQL.
 * Une application peut dépendre d'une ou plusieurs autres applications
 * qui doivent être installées au préalable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_dependencies', function (Blueprint $table) {
            $table->id();

            // L'application qui a une dépendance
            $table->foreignId('application_id')
                ->constrained('applications')
                ->onDelete('cascade');

            // L'application requise
            $table->foreignId('required_application_id')
                ->constrained('applications')
                ->onDelete('cascade');

            $table->timestamps();

            $table->unique(
                ['application_id', 'required_application_id'],
                'app_dep_unique'
            );

            $table->index('application_id');
            $table->index('required_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_dependencies');
    }
};
