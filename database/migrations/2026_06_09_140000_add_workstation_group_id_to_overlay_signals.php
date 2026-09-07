<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le ciblage par **salle** (WorkstationGroup) au canal des signaux
 * postés. Un signal `workstation_group_id = X` matche tout poll dont le poste
 * appartient au groupe X (jointure résolue au poll). null = joker.
 *
 * Mailles de ciblage possibles : salle, poste, utilisateur, broadcast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overlay_signals', function (Blueprint $table): void {
            $table->unsignedBigInteger('workstation_group_id')
                ->nullable()
                ->index()
                ->after('workstation_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('overlay_signals', function (Blueprint $table): void {
            $table->dropColumn('workstation_group_id');
        });
    }
};
