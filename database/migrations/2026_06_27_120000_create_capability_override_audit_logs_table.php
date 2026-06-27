<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 29.5 (NFR5) — Audit append-only des overrides de capacité par parc.
 *
 * `saveOverride()` / `removeOverride()` (capabilities-tab) écrivaient
 * `capability_assignments` SANS aucune trace. Cette table consigne chaque pose /
 * mise à jour / retrait d'un override par `workstationGroup`, en distinguant un
 * override d'item imposé-PERMISSIF (`upstream_status = permissive`) d'un override
 * purement LOCAL (`upstream_status = local`).
 *
 * Patron MAISON append-only (calque `quota_audit_logs` / `delegation_history`,
 * Spatie activitylog ABSENT du projet) :
 *  - un seul `created_at` (`useCurrent()`), PAS d'`updated_at` ;
 *  - FKs `actor_user_id` / `capability_id` en `nullOnDelete` (la trace survit à la
 *    suppression des entités référencées ; les colonnes DÉNORMALISÉES
 *    `actor_login` / `capability_label` / `scope_label` préservent la lisibilité) ;
 *  - le hardening append-only (UPDATE interdit) vit côté modèle Eloquent
 *    {@see \App\Models\CapabilityOverrideAuditLog}.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». Vocabulaire « amont » / `Upstream` /
 * `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('capability_override_audit_logs')) {
            return;
        }

        Schema::create('capability_override_audit_logs', function (Blueprint $table) {
            $table->id();

            // Acteur : id (FK nullOnDelete) + login DÉNORMALISÉ (survit à la suppression).
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Refnum auteur de l\'override (null si l\'utilisateur est supprimé)');
            $table->string('actor_login')->nullable()
                ->comment('Login dénormalisé de l\'acteur (lisible même après suppression)');

            // Action dérivée côté serveur (jamais du flag client) : create | update | delete.
            $table->string('action')->comment('create | update | delete');

            // Item : capability id (FK nullOnDelete) + libellé DÉNORMALISÉ.
            $table->foreignId('capability_id')
                ->nullable()
                ->constrained('capabilities')
                ->nullOnDelete()
                ->comment('Capacité concernée (null si la capacité est supprimée)');
            $table->string('capability_label')
                ->comment('Libellé dénormalisé de la capacité');

            // Périmètre : assignable polymorphe (WorkstationGroup) + nom DÉNORMALISÉ.
            $table->string('assignable_type')->comment('Type d\'entité ciblée (WorkstationGroup)');
            $table->unsignedBigInteger('assignable_id')->comment('Id de l\'entité ciblée (parc/groupe)');
            $table->string('scope_label')->nullable()
                ->comment('Nom dénormalisé du périmètre (groupe/parc)');

            // Valeurs avant / après l'acte.
            $table->text('old_value')->nullable()->comment('Valeur d\'override avant l\'acte (null si aucun)');
            $table->text('new_value')->nullable()->comment('Valeur d\'override après l\'acte (null pour un retrait)');

            // Statut amont au moment de l'acte : permissif imposé OU purement local.
            $table->string('upstream_status')->comment('permissive | local');

            // Append-only : un seul created_at, PAS d'updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('actor_user_id');
            $table->index('capability_id');
            $table->index(['assignable_type', 'assignable_id']);
            $table->index('action');
            $table->index('upstream_status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_override_audit_logs');
    }
};
