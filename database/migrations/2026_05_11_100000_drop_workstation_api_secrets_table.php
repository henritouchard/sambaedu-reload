<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retour iso-legacy auth WPKG (2026-05-11) — la table `workstation_api_secrets`
 * créée en Story 15.5 (auth Bearer Phase 2) est supprimée. L'ingestion des
 * rapports redevient pilotée par le worker `wpkg:process-reports` (Story 9.4 /
 * Phase 1), authentifié par IP allowlist locale. La jointure AD + ACL Samba
 * du partage `rapports/` reste l'autorité d'identité machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('workstation_api_secrets');
    }

    public function down(): void
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
            $table->index('revoked_at');
        });
    }
};
