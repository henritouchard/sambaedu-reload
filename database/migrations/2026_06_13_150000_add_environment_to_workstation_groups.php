<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 26.1 — AC2 (la nature du poste devient une donnée du domaine, FR28).
 *
 * Greffe la colonne `environment` sur `workstation_groups` : un parc (logique
 * OU physique) déclare si ses postes sont partagés / personnels / nomades. Lue
 * par le `WorkstationEnvironmentResolver` (résolution serveur, précédence
 * `nomade > personal_local > shared_local`), elle sera consommée par les
 * handlers de l'Epic 27. AUCUN retrofit legacy (note de transition 26.1).
 *
 *  - `environment` VARCHAR(32) **NULL** — null = « non déclaré ». Décision D2 :
 *    PAS de default SQL `'shared_local'`, le défaut est résolu côté service. On
 *    distingue ainsi « parc non encore configuré » de « parc explicitement
 *    partagé », et on évite une migration de backfill.
 *  - Type varchar simple (pas d'enum Postgres natif) → compatible SQLite des
 *    tests sans branche driver (iso conventions migration agent).
 *  - Cast enum `App\Enums\WorkstationEnvironment` ajouté sur le modèle
 *    `WorkstationGroup` (`$casts` + `$fillable`).
 *
 * **Idempotence stricte** : `Schema::hasColumn()` avant création (iso
 * `2026_06_13_140000_add_agent_reported_version_to_workstations`). `down()`
 * symétrique (drop conditionnel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstation_groups', function (Blueprint $table): void {
            if (! Schema::hasColumn('workstation_groups', 'environment')) {
                $table->string('environment', 32)->nullable()
                    ->comment('Environnement de poste du parc (Story 26.1) — shared_local/personal_local/nomade ; null = non déclaré, défaut shared_local résolu côté service');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workstation_groups', function (Blueprint $table): void {
            if (Schema::hasColumn('workstation_groups', 'environment')) {
                $table->dropColumn('environment');
            }
        });
    }
};
