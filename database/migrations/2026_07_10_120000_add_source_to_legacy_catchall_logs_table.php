<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 38.2 — Observabilité d'extinction (D3).
 *
 * Extension ADDITIVE de `legacy_catchall_logs` : distinguer les hits servis par
 * les tombstones natifs (`source='tombstone'`) des hits encore proxifiés vers le
 * vhost legacy (`source='catchall'`, default DB → le catchall existant reste
 * INCHANGÉ, il ne renseigne pas la colonne). Les colonnes `machine`/`user_login`
 * capturent l'identité poste/utilisateur extraite des paramètres d'appel (non
 * authentifiés) pour agréger l'extinction par poste (critère GO 38.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_catchall_logs', function (Blueprint $table) {
            $table->string('source', 16)->default('catchall')->index()->after('id');
            $table->string('machine', 255)->nullable()->after('ip');
            $table->string('user_login', 255)->nullable()->after('machine');
        });
    }

    public function down(): void
    {
        Schema::table('legacy_catchall_logs', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'machine', 'user_login']);
        });
    }
};
