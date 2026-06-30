<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 32.1 (NFR5) — Audit append-only de la transition de lien amont
 * `active → severed` (rupture du lien de management controlHub).
 *
 * La rupture du lien (FR7) lève AUTOMATIQUEMENT tous les verrous et le bornage
 * catalogue (via `ControlHubContract::active()` → null une fois `link_state =
 * severed`). Cette table consigne CHAQUE transition `active → severed`, son
 * origine (commande artisan / endpoint controlHub authentifié) et un
 * récapitulatif (nombre d'items levés, apps conservées, valeurs matérialisées).
 * Une seule ligne par transition ; un re-signal sur un contrat déjà `severed`
 * n'écrit RIEN (idempotence — AC1/AC6).
 *
 * Patron MAISON append-only (calque {@see \App\Models\CapabilityOverrideAuditLog}
 * 29.5 / `quota_audit_logs` / `delegation_history` ; Spatie activitylog ABSENT du
 * projet) :
 *  - un seul `created_at` (`useCurrent()`), PAS d'`updated_at` ;
 *  - FK `controlhub_contract_id` en `nullOnDelete` (la trace survit à la
 *    suppression du contrat référencé) ;
 *  - le hardening append-only (UPDATE interdit) vit côté modèle Eloquent
 *    {@see \App\Models\ControlHubLinkAuditLog}.
 *
 * Migration ADDITIVE (jamais réécrite en review) — garde-fou projet.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». Vocabulaire « amont » / `Upstream` /
 * `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('controlhub_link_audit_logs')) {
            return;
        }

        Schema::create('controlhub_link_audit_logs', function (Blueprint $table): void {
            $table->id();

            // Contrat amont rompu (FK nullOnDelete : la trace survit à sa suppression).
            $table->foreignId('controlhub_contract_id')
                ->nullable()
                ->constrained('controlhub_contracts')
                ->nullOnDelete()
                ->comment('Contrat amont rompu (null si le contrat est supprimé)');

            // Transition d'état du lien (active → severed).
            $table->string('from_state')->comment('État du lien avant la transition (active)');
            $table->string('to_state')->comment('État du lien après la transition (severed)');

            // Origine du signal de rupture : 'command' (artisan) | 'api' (endpoint
            // controlHub authentifié). Acteur DÉNORMALISÉ (lisible, pas de FK : le
            // signal peut venir d'un canal non-utilisateur).
            $table->string('origin')->comment('Canal du signal : command | api');
            $table->string('actor_label')->nullable()
                ->comment('Acteur/origine dénormalisé (login refnum, clé controlHub, cli…)');
            $table->text('reason')->nullable()->comment('Motif optionnel de la rupture');

            // Récapitulatif JSON : items levés, apps conservées, valeurs matérialisées.
            $table->json('summary')->nullable()
                ->comment('Récap : items_lifted, apps_preserved, values_materialized');

            // Append-only : un seul created_at, PAS d'updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('controlhub_contract_id');
            $table->index('origin');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controlhub_link_audit_logs');
    }
};
