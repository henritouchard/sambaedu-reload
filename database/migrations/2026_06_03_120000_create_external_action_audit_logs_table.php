<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 20.4 — D-1 / D-5 / D-7.
 *
 * Journal d'audit DÉNORMALISÉ des actions d'administration réalisées par un
 * acteur fédéré (technicien flotte, hors-AD). Calqué sur `quota_audit_logs`
 * (Story quotas) : table Eloquent interrogeable, écriture best-effort, pas un
 * fichier de log à parser.
 *
 * RAISON D'ÊTRE de la dénormalisation : la Story 20.2 anonymise
 * l'`ExternalIdentity` en fin de rétention (PII vidée + `external_sub` réécrit
 * en `anon:<hmac>`, ligne soft-deletée, JAMAIS hard-delete). Un log qui ne
 * référencerait l'identité que par FK deviendrait illisible après
 * anonymisation. On COPIE donc l'identité (login + sub + nom + rôle actif)
 * dans chaque ligne au moment de l'action : le journal reste attribuable et
 * lisible même après soft-delete ET anonymisation de l'identité externe.
 *
 * Migration additive (D-7) : nouvelle table uniquement, aucune modif des
 * tables existantes. FK nullable best-effort `set null` (pattern 20.1 — sqlite
 * :memory: ne supporte pas tous les ALTER, mais `constrained()->nullOnDelete()`
 * passe en sqlite récent et est enveloppé d'un `try/catch` défensif).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('external_action_audit_logs')) {
            return;
        }

        Schema::create('external_action_audit_logs', function (Blueprint $table): void {
            $table->id();

            // -----------------------------------------------------------------
            // Identité DÉNORMALISÉE (copiée au moment de l'action — D-5).
            // Ces 4 colonnes sont la SOURCE DE LECTURE du journal : elles
            // survivent intactes à la soft-delete ET à l'anonymisation de
            // l'identité externe correspondante.
            // -----------------------------------------------------------------
            // `users.login` de l'acteur fédéré (ex. `ext:<sub>`).
            $table->string('actor_login')->comment('Login local de l\'acteur fédéré (ex. ext:<sub>), copié');
            // `external_sub` au moment de l'action (clair tant que non anonymisé).
            $table->string('actor_external_sub')->nullable()->comment('external_sub copié au moment de l\'action');
            // Nom affiché (PII assumée et bornée par la finalité d'imputabilité).
            $table->string('actor_name')->nullable()->comment('Nom affiché de l\'acteur, copié');
            // Nom du rôle Spatie ACTIF résolu par 20.3 (ex. `technicien`).
            $table->string('actor_role')->nullable()->comment('Nom du rôle Spatie actif, copié');

            // -----------------------------------------------------------------
            // Traçabilité de l'action.
            // -----------------------------------------------------------------
            // Discrimine l'origine externe vs AD locale (AC2). En dur
            // `federated` au MVP ; colonne extensible sans migration (Q-3).
            $table->string('source', 32)->default('federated')->comment('Origine de l\'action : federated');
            $table->string('http_method', 10)->comment('GET, POST, PUT, PATCH, DELETE — distingue lecture vs mutation');
            $table->string('route_name')->nullable()->comment('Nom de route Laravel (nullable)');
            $table->string('path')->comment('Chemin de la requête');
            $table->string('action_label')->nullable()->comment('Libellé lisible si dérivable');
            $table->integer('status_code')->comment('Code HTTP de la réponse');
            $table->timestamp('occurred_at')->comment('Instant de l\'action (posé à la main, D-6)');

            // -----------------------------------------------------------------
            // FK best-effort (corrélation forensique optionnelle — JAMAIS la
            // source de lecture). `set null` : si l'identité/user disparaît, la
            // lisibilité reste assurée par les colonnes dénormalisées.
            // -----------------------------------------------------------------
            $table->unsignedBigInteger('external_identity_id')->nullable()
                ->comment('FK best-effort vers external_identities (corrélation)');
            $table->unsignedBigInteger('user_id')->nullable()
                ->comment('FK best-effort vers users (corrélation)');

            // Index de requête d'audit (AC9).
            $table->index('actor_login');
            $table->index('actor_external_sub');
            $table->index('source');
            $table->index('occurred_at');
            $table->index('external_identity_id');
            $table->index('user_id');
        });

        // FK best-effort (pattern 20.1) : sur certaines plateformes
        // (sqlite :memory: ancien) l'ajout de contrainte échoue — on ne casse
        // PAS la création de table pour autant (la corrélation reste assurée
        // par les colonnes + index, la lecture par la dénormalisation).
        try {
            Schema::table('external_action_audit_logs', function (Blueprint $table): void {
                $table->foreign('external_identity_id')
                    ->references('id')->on('external_identities')
                    ->nullOnDelete();
                $table->foreign('user_id')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        } catch (\Throwable $e) {
            // FK non posées (plateforme) : non bloquant — colonnes nullables.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('external_action_audit_logs');
    }
};
