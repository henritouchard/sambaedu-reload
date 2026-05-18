<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 16.10 — AC3.2.
 *
 * Table `workstation_jwt_revocations` : journal des JWT révoqués (jti) avec
 * leur expiration originale (pour permettre la purge future des entrées
 * dont le JWT était déjà expiré).
 *
 * Consultée par `WorkstationJwtRevocationChecker` en fallback DB après un
 * cache miss APCu (D4). Volumétrie attendue : < 100k entries/an même pour
 * un parc de 1000 postes.
 *
 *  - `jti` : claim JWT unique (UUID v4 généré par `WorkstationJwtIssuer`).
 *  - `workstation_uuid` : pour audit + révocation cascade (replay detection).
 *  - `expires_at` : claim `exp` original du JWT. Les entrées dont
 *    `expires_at < now()` peuvent être purgées (le JWT serait de toute
 *    façon refusé `jwt.expired` par le verifier).
 *  - `reason` : enum string ouverte (`manual_admin`, `lost_device`,
 *    `rotation_kid`, `replay_cascade`).
 *  - `revoked_by` : audit qui (`system`, `admin:<login>`, `automated:<job>`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_jwt_revocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('jti')->unique();
            $table->uuid('workstation_uuid')->index();
            $table->timestamp('revoked_at');
            $table->string('reason', 128);
            $table->string('revoked_by')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_jwt_revocations');
    }
};
