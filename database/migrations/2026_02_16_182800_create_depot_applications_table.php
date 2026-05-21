<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Table depot_applications : applications disponibles sur le dépôt distant, non installées sur l'instance
     */
    public function up(): void
    {
        Schema::create('depot_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained('depots')->onDelete('cascade');
            $table->string('app_id')->comment('Identifiant technique de l\'application');
            $table->string('name')->comment('Nom d\'affichage');
            $table->string('version')->nullable()->comment('Version/revision');
            $table->string('category')->nullable()->comment('Catégorie');
            $table->string('compatibility')->nullable()->comment('Compatibilité OS (bitmask)');
            $table->string('branch')->nullable()->default('stable')->comment('Branche (stable, testing)');
            $table->text('xml')->nullable()->comment('Contenu XML de la recette');
            $table->string('xml_url')->nullable()->comment('URL du fichier XML');
            $table->string('xml_sha', 128)->nullable()->comment('Hash SHA du XML');
            $table->string('log_url')->nullable()->comment('URL du log');
            $table->text('description')->nullable()->comment('Description de l\'application');
            $table->string('author')->nullable()->comment('Auteur du paquet');
            $table->string('icon_url')->nullable()->comment('URL de l\'icône');
            $table->timestamp('last_checked_at')->nullable()->comment('Dernière vérification');
            $table->timestamps();

            $table->unique(['depot_id', 'app_id'], 'depot_app_unique');
            $table->index('app_id');
            $table->index('category');
            $table->index('name');
        });

        // COMMENT ON TABLE est Postgres-only, skipper sur SQLite (tests).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("COMMENT ON TABLE depot_applications IS 'Applications disponibles sur le dépôt distant, non installées sur l''instance'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('depot_applications');
    }
};
