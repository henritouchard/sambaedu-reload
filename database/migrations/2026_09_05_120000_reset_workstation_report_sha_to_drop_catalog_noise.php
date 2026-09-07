<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un poste dont le rapport ne change pas est sauté par l'idempotence SHA : la
 * persistance de `workstation_application_status` ne rejouerait jamais, et les
 * lignes héritées de l'ancienne ingestion resteraient en place.
 *
 * Effacer `report_sha` force cette ré-ingestion au prochain passage.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workstations')->whereNotNull('report_sha')->update(['report_sha' => null]);
    }

    public function down(): void
    {
        // Le SHA se réécrit au prochain rapport : rien à restaurer.
    }
};
