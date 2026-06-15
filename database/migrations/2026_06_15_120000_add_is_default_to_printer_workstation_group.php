<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.2 (décision Henri n° 5) — drapeau « imprimante par défaut » sur le
 * pivot `printer_workstation_group`.
 *
 * Réglage EXPLICITE porté par l'attachement imprimante↔WG (settable par l'admin
 * dans la liste des imprimantes du groupe), valable pour un WG physique (salle)
 * COMME logique (parc). PAS d'auto-dérivation « la salle ».
 *
 * **Aucun index unique global** : un poste peut appartenir à plusieurs WG
 * porteurs chacun d'un défaut. L'unicité (un seul défaut par poste) est résolue
 * à la COMPILATION (`PrintersStateProvider` — WG physique l'emporte sur le
 * logique, départage `cups_name` asc), JAMAIS par contrainte SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printer_workstation_group', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('workstation_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('printer_workstation_group', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
