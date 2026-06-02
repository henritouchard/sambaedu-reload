<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Story 20.1 — D-6.
     *
     * Trace la consommation d'un jeton fédéré (`jti` à usage unique). Couche
     * de persistance derrière le cache APCu du
     * {@see \App\Auth\Federated\Jwt\FederatedJwtReplayChecker} : filet de
     * sécurité multi-worker + survie au-delà du TTL cache.
     *
     * Calqué sur `workstation_jwt_revocations` (UUID PK, `jti` unique). Les
     * rows dont `expires_at < now()` peuvent être purgées (jeton expiré → ne
     * peut plus être rejoué de toute façon).
     */
    public function up(): void
    {
        if (Schema::hasTable('federated_jwt_consumptions')) {
            return;
        }

        Schema::create('federated_jwt_consumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // jti consommé — UNIQUE = garantie d'usage unique au niveau DB.
            $table->string('jti')->unique();

            // Émetteur (corrélation / purge ciblée).
            $table->string('iss')->nullable();

            $table->timestamp('consumed_at');

            // Borne de purge : au-delà, le jeton est expiré.
            $table->timestamp('expires_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federated_jwt_consumptions');
    }
};
