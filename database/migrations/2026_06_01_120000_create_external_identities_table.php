<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Story 20.1 — D-8.
     *
     * Identité externe persistante d'un acteur fédéré (technicien flotte,
     * hors-AD). Upsert au login fédéré, clé = `external_sub` (claim `sub` du
     * JWT). Soft-delete : une identité externe n'est JAMAIS hard-delete
     * (audit / RGPD — base légale détaillée en Story 20.2).
     *
     * Schéma minimal volontaire (M2/IR) : le cycle de vie complet est porté
     * par 20.2.
     */
    public function up(): void
    {
        if (Schema::hasTable('external_identities')) {
            return;
        }

        Schema::create('external_identities', function (Blueprint $table): void {
            $table->id();

            // Identifiant externe stable côté IdP (claim `sub`). Clé d'upsert.
            $table->string('external_sub')->unique();

            // Émetteur de confiance (claim `iss`). Domain-neutral : string
            // opaque, jamais « central ».
            $table->string('issuer');

            // Profil d'affichage (non sensible côté autorisation).
            $table->string('login')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();

            // État d'accès. is_active=false → session refusée au prochain check
            // guard (D-5), sans supprimer l'identité.
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_identities');
    }
};
