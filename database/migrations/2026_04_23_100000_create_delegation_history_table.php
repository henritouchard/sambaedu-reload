<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 7.1 — Historique (audit trail) des délégations.
 *
 * Table append-only : uniquement un `created_at`, pas d'`updated_at`.
 * Les FK sont en `ON DELETE SET NULL` pour garder la ligne d'audit lisible
 * même si l'acteur, la cible ou le WorkstationGroup sont supprimés après coup.
 * Le `permission_name` est stocké en string (pas FK) car une permission peut
 * être renommée/supprimée (Story 7.2 — profils dynamiques).
 *
 * Décision produit 2026-04-23 : pas d'observer cascade_delete — les lignes
 * `delegations` supprimées par cascade FK ne sont pas tracées ici (suivi
 * métier uniquement, pas de tracé technique).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('delegation_history')) {
            return;
        }

        Schema::create('delegation_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Utilisateur qui a effectué l\'opération (null si acteur supprimé)');

            $table->foreignId('target_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Utilisateur cible de la délégation (null si cible supprimée)');

            $table->foreignId('workstation_group_id')
                ->nullable()
                ->constrained('workstation_groups')
                ->nullOnDelete()
                ->comment('Périmètre de la délégation (null si group supprimé)');

            $table->string('permission_name', 255)
                ->comment('Nom Spatie de la permission (stocké en string car peut être renommé)');

            $table->string('action', 32)
                ->comment('Opération : grant, revoke, negate, expire');

            $table->boolean('is_negative')
                ->default(false)
                ->comment('true si délégation négative (exclusion)');

            // JSONB sur Postgres, JSON sur SQLite — le cast 'array' du modèle
            // normalise des deux côtés.
            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('context')->nullable()
                    ->comment('Métadonnées libres : IP, user-agent, batch_id…');
            } else {
                $table->json('context')->nullable()
                    ->comment('Métadonnées libres : IP, user-agent, batch_id…');
            }

            // Append-only : pas de `updated_at`.
            $table->timestamp('created_at')->useCurrent();

            // Indexes — filtres fréquents dans l'onglet Historique.
            $table->index('target_user_id');
            $table->index('workstation_group_id');
            $table->index('action');
            $table->index('created_at');
        });

        // TODO Story 7.2 : en Postgres prod, ajouter un trigger BEFORE UPDATE
        // qui lève une exception pour hardening append-only côté DB. Le guard
        // applicatif (DelegationHistory::save()) protège uniquement les
        // ->save() / ->update() via Eloquent — DB::table()->update() passe
        // outre. Cf. review #6 Story 7.1.
    }

    public function down(): void
    {
        Schema::dropIfExists('delegation_history');
    }
};
