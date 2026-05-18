<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 16.11 — AC6.2 / T1.2.
 *
 * Table `workstation_migration_attempts` : traçabilité fine de chaque
 * tentative de migration (succès OU échec).
 *
 * Une row est insérée à chaque évènement marquant du flot bootstrap :
 *
 *  - `started` : le poste a appelé `GET /api/v1/agent/bootstrap.{cmd,sh}`
 *                (workstation_uuid encore inconnu à ce stade — nullable).
 *  - `enrolled`: l'enrôlement a réussi (upsert depuis `EnrollController`).
 *  - `failed`  : enrollment / bootstrap script a échoué (uuid_mismatch,
 *                LAN block, certutil refus, etc.). `error_code` capture le
 *                code AuthV1ErrorCatalog.
 *  - `aborted` : abandon volontaire (script poste détecte état migré pendant
 *                la fenêtre — rare, log info uniquement).
 *
 * Cette table sert :
 *
 *  1. à la commande `migration:health-check` pour calculer le ratio
 *     d'échecs sur 7 jours glissants → alerte critical si > 5%.
 *  2. à l'audit forensique (corrélation IP / UA / OS / error_code en cas
 *     de campagne d'attaque sur l'enroll).
 *
 * Conventions :
 *
 *  - **Pas de FK** vers `workstations` ni `workstations_migration_status`
 *    (un attempt peut échouer avant que ces lignes existent).
 *  - `error_message` truncate à 1024 chars côté model (setter mutator) pour
 *    éviter qu'un message stderr d'un script poste fasse exploser la row.
 *  - `client_ip` string(45) → support IPv4-mapped + IPv6 full (39 chars max
 *    + marge).
 *
 * @see App\Auth\V1\Models\WorkstationMigrationAttempt
 * @see App\Console\Commands\MigrationHealthCheck
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_migration_attempts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('workstation_uuid', 36)->nullable()->index();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->string('client_ip', 45);
            $table->text('user_agent')->nullable();
            $table->string('os', 16)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_migration_attempts');
    }
};
