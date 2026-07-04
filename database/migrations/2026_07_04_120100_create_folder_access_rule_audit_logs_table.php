<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 36.4 (D7) — Audit APPEND-ONLY des règles d'accès aux dossiers.
 *
 * Chaque create / update / delete (activation-désactivation et mutation
 * d'assignations comprises = `update`) écrit une ligne DANS la transaction de la
 * mutation `folder_access_rules` (atomicité acte ↔ trace). Patron MAISON
 * (calque `capability_override_audit_logs` / `quota_audit_logs` — Spatie
 * activitylog ABSENT du projet) :
 *  - un seul `created_at` (`useCurrent()`), PAS d'`updated_at` ;
 *  - FKs `actor_user_id` / `rule_id` en `nullOnDelete` (la trace survit à la
 *    suppression des entités référencées ; `actor_login` / `rule_label`
 *    dénormalisés préservent la lisibilité) ;
 *  - le hardening append-only (UPDATE interdit) vit côté modèle Eloquent
 *    {@see \App\Models\FolderAccessRuleAuditLog}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_access_rule_audit_logs', function (Blueprint $table) {
            $table->id();

            // Acteur : id (FK nullOnDelete) + login DÉNORMALISÉ (survit à la suppression).
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('actor_login')->nullable();

            // Action dérivée CÔTÉ SERVEUR : create | update | delete.
            $table->string('action');

            // Règle : id (FK nullOnDelete) + libellé DÉNORMALISÉ.
            $table->foreignId('rule_id')
                ->nullable()
                ->constrained('folder_access_rules')
                ->nullOnDelete();
            $table->string('rule_label');

            // Snapshots JSON (champs + ids de parcs assignés) avant / après l'acte.
            $table->json('old_state')->nullable();
            $table->json('new_state')->nullable();

            // Append-only : un seul created_at, PAS d'updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('actor_user_id');
            $table->index('rule_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_access_rule_audit_logs');
    }
};
