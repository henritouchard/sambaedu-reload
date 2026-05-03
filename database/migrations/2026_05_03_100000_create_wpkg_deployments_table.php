<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 15.1 — Table racine de tracking des déploiements WPKG.
 *
 * Le `id` UUID sert de `deployment_id` corrélé dans toute la chaîne
 * (générateurs XML, jobs, channel logs `wpkg-deploy`, dashboard Story 15.5).
 *
 * `status` est géré en `string` (pas enum natif Postgres) pour la portabilité
 * vers SQLite des tests + cohérence avec le pattern Epic 4 / 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wpkg_deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('triggered_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('triggered_at');
            $table->json('target_scope')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('triggered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wpkg_deployments');
    }
};
