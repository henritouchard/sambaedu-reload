<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * Ajoute la distinction entre salle physique et groupe logique :
     * - Salle physique : lieu réel où se trouvent les machines (relation ManyToOne)
     * - Groupe logique : regroupement arbitraire de machines (relation ManyToMany existante)
     */
    public function up(): void
    {
        // Ajouter is_physical_room à la table parc (si pas déjà présent)
        if (!Schema::hasColumn('parc', 'is_physical_room')) {
            Schema::table('parc', function (Blueprint $table) {
                $table->boolean('is_physical_room')->default(false)->after('flag_parc')
                    ->comment('True si c\'est une salle physique, false si groupe logique');
            });
        }

        // Ajouter physical_room_id à la table postes (relation ManyToOne)
        if (!Schema::hasColumn('postes', 'physical_room_id')) {
            Schema::table('postes', function (Blueprint $table) {
                $table->integer('physical_room_id')->nullable()->after('flag_poste')
                    ->comment('Salle physique où se trouve la machine (relation exclusive)');

                $table->foreign('physical_room_id')
                    ->references('id_parc')
                    ->on('parc')
                    ->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('postes', function (Blueprint $table) {
            $table->dropForeign(['physical_room_id']);
            $table->dropColumn('physical_room_id');
        });

        Schema::table('parc', function (Blueprint $table) {
            $table->dropColumn('is_physical_room');
        });
    }
};
