<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 55.2 — `oidc_access_tokens.user_id` : la clé qui permet à `/userinfo`
 * de résoudre l'utilisateur **sans jamais passer par le `sub`**.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI CETTE COLONNE EXISTE
 *
 *  Sans elle, `/userinfo` devrait retrouver l'utilisateur à partir de
 *  `user_login` — c'est-à-dire à partir du **sub**. Ce serait une adhérence
 *  durable : le jour où le sujet OIDC cesserait d'être le login (l'arbitrage
 *  `login` / `ad_guid` / `users.id` est documenté sur
 *  {@see \App\Auth\Oidc\Support\OidcSubjectResolver}), la résolution casserait
 *  silencieusement. Un `sub` est une VALEUR PUBLIÉE, pas une clé de jointure.
 *
 *  Miroir exact de `oidc_authorization_codes.user_id` (migration 55.1) :
 *  nullable + `nullOnDelete()`. Nullable parce que la suppression d'un compte
 *  ne doit jamais bloquer la purge des jetons — et parce qu'un `user_id` nul
 *  est précisément le signal fail-closed que `/userinfo` attend
 *  (`oidc.user_missing`, aucune donnée servie).
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Additive et idempotente** : la migration 55.1 n'est PAS retouchée (elle est
 * passée en review, un diff dessus rouvrirait un livrable clos et casserait les
 * instances déjà migrées). Gardes `hasTable`/`hasColumn` : rejouable.
 *
 * **Aucun backfill** : les jetons vivent 600 s et la fonctionnalité n'a jamais
 * été déployée hors worktree. Les rares lignes préexistantes expirent d'
 * elles-mêmes ; leur `user_id` nul produit un refus fail-closed, jamais une
 * donnée servie au mauvais destinataire.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oidc_access_tokens')) {
            return;
        }

        if (! Schema::hasColumn('oidc_access_tokens', 'user_id')) {
            Schema::table('oidc_access_tokens', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->index('oidc_tokens_user_idx');
            });
        }

        // FK posée séparément et en best-effort : SQLite (driver de la suite de
        // tests) ne sait pas ajouter une contrainte à une table existante. La
        // colonne indexée nullable suffit fonctionnellement — et la résolution
        // applicative (`User::find()` ⇒ null ⇒ refus) ne dépend PAS de la FK.
        // L'intégrité référentielle reste assurée en production (PostgreSQL).
        if (Schema::hasTable('users')) {
            try {
                Schema::table('oidc_access_tokens', function (Blueprint $table): void {
                    $table->foreign('user_id', 'oidc_tokens_user_fk')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // Driver sans ALTER ADD CONSTRAINT : ignoré volontairement.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('oidc_access_tokens')) {
            return;
        }

        if (! Schema::hasColumn('oidc_access_tokens', 'user_id')) {
            return;
        }

        Schema::table('oidc_access_tokens', function (Blueprint $table): void {
            try {
                $table->dropForeign('oidc_tokens_user_fk');
            } catch (\Throwable) {
                // Aucune FK posée (SQLite) — on ignore.
            }

            $table->dropColumn('user_id');
        });
    }
};
