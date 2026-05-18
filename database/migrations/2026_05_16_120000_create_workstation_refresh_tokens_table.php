<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 16.10 — AC3.1.
 *
 * Table `workstation_refresh_tokens` : stocke les refresh tokens émis aux
 * postes lors de l'enrôlement (`POST /api/v1/agent/enroll`) et lors des
 * rotations (`POST /api/v1/agent/refresh`).
 *
 * Conventions importantes :
 *
 *  - **Pas de FK** vers `workstations.uuid` (dev notes story) : un poste
 *    peut s'enrôler avant d'apparaître dans la table `workstations`
 *    Eloquent (cas 16.11 auto-bootstrap). On stocke `workstation_uuid` libre
 *    + index (uuid) — pas de contrainte référentielle.
 *  - **Seul le hash sha256 est stocké** (`refresh_token_hash`, 64 chars hex).
 *    Le token clear n'apparaît jamais en DB ni dans les logs.
 *  - **`client_meta` JSON** : capture mac/hostname/os/enroll_ip à des fins
 *    d'audit et de debug. Pas de PII utilisateur, uniquement métadonnées
 *    machine.
 *  - **`revocation_reason`** : enum string ouverte — `manual_admin`,
 *    `refresh_rotation`, `replay_detected`, `cascade_revoke`. Pas une
 *    contrainte CHECK pour permettre des valeurs futures.
 *
 * Index :
 *
 *  - `workstation_uuid` (lookup par poste)
 *  - `expires_at` (job de purge des tokens expirés)
 *  - `revoked_at` (scope `active`)
 *  - `refresh_token_hash` unique (lookup principal `EnsureRefreshToken`)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_refresh_tokens', function (Blueprint $table): void {
            // Primary UUID v4 généré côté Laravel (cf. Model::booted).
            $table->uuid('id')->primary();

            // Workstation cible — pas de FK (cf. Dev Notes story 16.10).
            $table->uuid('workstation_uuid')->index();

            // Hash sha256 du token clear (64 chars hex, unique).
            $table->string('refresh_token_hash', 64)->unique();

            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();

            // Raison de révocation (enum string ouverte — pas de CHECK).
            $table->string('revocation_reason', 64)->nullable();

            $table->timestamp('last_used_at')->nullable();

            // Metadata client (mac, hostname, os, enroll_ip).
            // jsonb sur Postgres, text JSON cast côté Eloquent sur SQLite.
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('client_meta')->nullable();
            } else {
                $table->json('client_meta')->nullable();
            }

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_refresh_tokens');
    }
};
