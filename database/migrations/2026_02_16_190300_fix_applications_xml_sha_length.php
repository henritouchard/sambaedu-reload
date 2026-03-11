<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Le hash SHA512 du dépôt fait 128 caractères, il faut agrandir le champ
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('xml_sha', 128)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('xml_sha', 64)->nullable()->change();
        });
    }
};
