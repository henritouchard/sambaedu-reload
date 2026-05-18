<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 16.11 — AC6.1 / T1.1.
 *
 * Table `workstations_migration_status` : état stable de migration d'un poste.
 *
 * Un poste qui a réussi son enrôlement transitoire bootstrap → JWT (16.10)
 * dispose d'une row ici (upsert sur `workstation_uuid`). Le middleware
 * `InjectBootstrapFragment` consulte cette table pour décider de préfixer
 * ou non le fragment de bootstrap dans les réponses legacy `*_out.php`.
 *
 * Conventions importantes :
 *
 *  - **Pas de FK** vers `workstations.uuid` (cf. Dev Notes 16.10 / 16.11) :
 *    un poste peut s'enrôler avant d'apparaître dans la table `workstations`
 *    Eloquent. On stocke `workstation_uuid` libre + index unique.
 *  - **Unique constraint** sur `workstation_uuid` : un poste a au plus une
 *    row → upsert idempotent (re-bootstrap d'un poste après perte de state
 *    local met juste à jour `migrated_at` et `bootstrap_token_used_md5`).
 *  - **`access_token_emitted_jti`** : soft ref vers `workstation_refresh_tokens`
 *    (pas de FK contrainte — un refresh peut être rotated ou révoqué sans
 *    invalider le status migration).
 *  - **`bootstrap_token_hash_prefix`** : 16 premiers chars du sha256 du token
 *    bootstrap transitoire — traçabilité sans exposer le token clair ni le
 *    hash complet. Utile audit forensique a posteriori.
 *  - **`os`** : enum ouverte string (`windows|linux`). Pas de CHECK contraint
 *    pour portabilité SQLite (tests) / Postgres (prod).
 *  - **`se4fs_name`** : snapshot de `config('sambaedu.se4fs_name')` au moment
 *    de la migration. Debug si étab change de hostname (rare).
 *
 * @see App\Auth\V1\Models\WorkstationMigrationStatus
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstations_migration_status', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('workstation_uuid', 36)->unique();
            $table->timestamp('migrated_at');
            $table->string('access_token_emitted_jti', 36)->nullable();
            $table->string('bootstrap_token_hash_prefix', 16)->nullable();
            $table->string('os', 16);
            $table->string('se4fs_name', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstations_migration_status');
    }
};
