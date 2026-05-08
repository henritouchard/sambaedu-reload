<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 15.5 / AC5.1 — Table de stockage des secrets API par machine
 * pour l'authentification Phase 2 (Bearer token) de l'endpoint d'ingestion
 * des rapports WPKG.
 *
 * Le `secret_hash` est un bcrypt du secret clair (généré par
 * `wpkg:provision-secrets`). Le secret clair n'est jamais persisté.
 *
 * `previous_secret_hash` + `previous_valid_until` permettent une rotation
 * 7 jours avec chevauchement (ancien secret valide jusqu'à
 * `previous_valid_until` après rotation).
 *
 * `revoked_at` non null → toute requête avec ce token → 401.
 *
 * Décision Story 15.5 : option « colonne » plutôt que table historique
 * (un seul ancien secret valide à la fois suffit au cas d'usage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_api_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_id')
                ->unique()
                ->constrained('workstations')
                ->cascadeOnDelete();
            $table->string('secret_hash', 255);
            $table->string('previous_secret_hash', 255)->nullable();
            $table->timestamp('previous_valid_until')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Index partiel sur revoked_at non null (PG-only — fallback
            // index simple en SQLite/test). Permet le filtrage rapide des
            // secrets révoqués en audit.
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_api_secrets');
    }
};
