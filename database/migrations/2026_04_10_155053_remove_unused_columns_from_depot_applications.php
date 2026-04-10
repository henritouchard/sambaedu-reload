<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depot_applications', function (Blueprint $table) {
            $table->dropColumn(['xml', 'description', 'author']);
        });
    }

    public function down(): void
    {
        Schema::table('depot_applications', function (Blueprint $table) {
            $table->text('xml')->nullable()->comment('Contenu XML de la recette');
            $table->text('description')->nullable()->comment('Description de l\'application');
            $table->string('author')->nullable()->comment('Auteur du paquet');
        });
    }
};
