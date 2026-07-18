<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 51.1 — Marqueur du dépôt IMPOSÉ par le contrat amont (controlHub).
 *
 * Migration ADDITIVE (patron
 * `2026_06_29_100000_add_source_to_controlhub_catalog_apps.php`, garde
 * `Schema::hasColumn`, `down()` symétrique) : `is_imposed` boolean default false
 * sur `depots`.
 *
 * Le dépôt imposé est la PROJECTION table→table du catalogue applicatif du
 * contrat amont (`controlhub_contract_catalog_apps` → `depot_applications`). Il
 * est exclu de toute synchro HTTP (`DepotSyncService`) — son URL
 * `controlhub://managed` n'est jamais joignable — et son ajout/sa suppression
 * sont verrouillés tant qu'un contrat amont est actif.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » ; vocabulaire « imposé » / « amont » /
 * `Imposed` / `Upstream`. [Source: prd-contrat-manage-se5.md#R3]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depots', function (Blueprint $table): void {
            if (! Schema::hasColumn('depots', 'is_imposed')) {
                $table->boolean('is_imposed')->default(false)->after('is_active')
                    ->comment('Dépôt imposé par le contrat amont controlHub (projection du catalogue, Story 51.1)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('depots', function (Blueprint $table): void {
            if (Schema::hasColumn('depots', 'is_imposed')) {
                $table->dropColumn('is_imposed');
            }
        });
    }
};
