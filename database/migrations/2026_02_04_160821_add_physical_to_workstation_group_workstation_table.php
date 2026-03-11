<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workstation_group_workstation', function (Blueprint $table) {
            $table->boolean('physical')->default(false)->after('workstation_group_id')
                ->comment('Indique si c\'est le lien physique (salle) - un seul par workstation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workstation_group_workstation', function (Blueprint $table) {
            $table->dropColumn('physical');
        });
    }
};
