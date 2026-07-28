<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 54.2 (FR36 socle) — Audit append-only du cycle de vie des extensions.
 *
 * `ExtensionLifecycleService::integrate()` / `uninstall()` sont les DEUX SEULS
 * écrivains de `extensions.status` du projet. Cette table consigne CHAQUE
 * transition réelle (`available → integrated`, `integrated → available`),
 * jamais un clic sans effet (no-op = zéro ligne, NFR8). La trace est écrite
 * DANS LA MÊME transaction que la mutation de `status` (atomicité acte ↔
 * trace) — un acte sans sa trace ne peut pas exister.
 *
 * Patron MAISON append-only (calque `capability_override_audit_logs` 29.5 /
 * `controlhub_link_audit_logs` 32.1, `QuotaAuditLog::log()` l'ancêtre — Spatie
 * activitylog ABSENT du projet) :
 *  - un seul `created_at` (`useCurrent()`), PAS d'`updated_at` ;
 *  - FKs `extension_id` / `actor_user_id` en `nullOnDelete` (la trace survit à
 *    la suppression des entités référencées — le prune de `syncBundled()` peut
 *    supprimer une ligne `available` déjà tracée) ; les colonnes DÉNORMALISÉES
 *    `extension_key` / `extension_name` / `actor_login` préservent la
 *    lisibilité après suppression ;
 *  - `action` est un STRING LIBRE (pas d'`enum()` DB, convention projet) :
 *    `integrate` | `uninstall` aujourd'hui, extensible SANS migration par
 *    l'Epic 56 (`install`, `update`, `remove`, `signature_failure`,
 *    `scope_revoked`…) ;
 *  - le hardening append-only (UPDATE interdit) vit côté modèle Eloquent
 *    {@see \App\Models\ExtensionAuditLog}.
 *
 * Pourquoi une table DÉDIÉE plutôt que la réutilisation d'un journal existant :
 * aucun journal du projet n'a la bonne forme (quota = partitions, capability
 * override = polymorphe capacités, controlhub link = mono-événement), et NFR14
 * (isolement du registre d'extensions PAR CONSTRUCTION, aucune FK/service
 * partagé avec un autre domaine) interdit de verser les traces d'extensions
 * dans le journal d'un autre domaine — voir le test frontière étendu
 * {@see \Tests\Feature\ControlHub\UpstreamSyncExtensionsBoundaryTest}.
 *
 * ⚠️ GARDE-FOU : aucun mot « central ». Vocabulaire « amont » / `Upstream`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('extension_audit_logs')) {
            return;
        }

        Schema::create('extension_audit_logs', function (Blueprint $table) {
            $table->id();

            // Extension : id (FK nullOnDelete) + clé/nom DÉNORMALISÉS.
            $table->foreignId('extension_id')
                ->nullable()
                ->constrained('extensions')
                ->nullOnDelete()
                ->comment('Extension concernée (null si supprimée du registre)');
            $table->string('extension_key')
                ->comment('Clé dénormalisée de l\'extension (lisible même après suppression)');
            $table->string('extension_name')
                ->comment('Nom dénormalisé de l\'extension au moment de l\'acte');

            // Action dérivée CÔTÉ SERVEUR (jamais du flag client) : string libre.
            $table->string('action')->comment('integrate | uninstall — extensible Epic 56 (string libre, pas d\'enum() DB)');

            // Acteur : id (FK nullOnDelete) + login DÉNORMALISÉ.
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Auteur de la transition (null si l\'utilisateur est supprimé)');
            $table->string('actor_login')->nullable()
                ->comment('Login dénormalisé de l\'acteur (lisible même après suppression)');

            // Append-only : un seul created_at, PAS d'updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('extension_id');
            $table->index('actor_user_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_audit_logs');
    }
};
