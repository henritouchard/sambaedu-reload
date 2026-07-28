<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 55.1 — SE5 FOURNISSEUR OIDC : registre des clients confidentiels,
 * codes d'autorisation à usage unique, access tokens opaques.
 *
 * Trois tables, une seule migration (une migration par feature) :
 *
 *  - `oidc_clients` : le registre des clients confidentiels (FR19 amorce).
 *    Adossé au registre d'extensions (`extension_id` nullable) — c'est le point
 *    d'accroche du provisioning automatique de l'Epic 56.
 *  - `oidc_authorization_codes` : codes à usage unique, TTL 60 s, porteurs du
 *    challenge PKCE et du `nonce` entre `/oidc/authorize` et `/oidc/token`.
 *  - `oidc_access_tokens` : jetons OPAQUES (TTL 600 s). Posés ici pour que la
 *    réponse du token endpoint soit conforme (RFC 6749 : la réponse DOIT
 *    contenir un `access_token`) sans re-plomberie en 55.2, où `/userinfo` les
 *    consommera.
 *
 * DÉCISIONS DE CONCEPTION (figées par la story) :
 *
 *  1. **AUCUN secret en clair, nulle part.** `client_secret_hash`, `code_hash`
 *     et `token_hash` stockent un sha256 ; le clair n'existe qu'en mémoire, le
 *     temps d'une réponse HTTP (ou d'un affichage artisan unique). NFR3.
 *  2. **`redirect_uris` = liste STRICTE** (JSON), correspondance EXACTE de
 *     chaîne — ni préfixe, ni wildcard, ni normalisation. Une comparaison lâche
 *     transformerait SE5 en open-redirector et ferait fuiter les codes.
 *  3. **`extension_id` nullable + `extension_key` dénormalisée** : l'app-témoin
 *     (55.3) et les clients de test précèdent le canal d'installation (Epic 56).
 *     La clé dénormalisée SURVIT à la suppression de l'extension — patron du
 *     journal d'audit 54.2 : une trace qui s'efface avec son objet ne trace rien.
 *  4. **Révocation = `enabled = false`**, jamais une suppression : les codes et
 *     tokens déjà émis restent inutilisables (toute résolution passe par
 *     `findEnabledByClientId()`) ET l'historique du registre est conservé.
 *  5. **Aucun `enum()` DB** (convention maison) ; `code_challenge_method` est
 *     une `string` — seul `S256` est accepté applicativement, la colonne existe
 *     pour l'audit et une éventuelle extension future.
 *  6. **Colonnes `NOT NULL DEFAULT ''`** plutôt que nullable (`nonce`, `scope`,
 *     `extension_key`) : une colonne nullable participant à une comparaison
 *     produit des résultats à trois états (NULL ≠ NULL) — piège déjà rencontré
 *     en 54.1.
 *  7. **`created_at` seul** (via `useCurrent()`) pour les deux tables
 *     techniques : un code et un token ne se « modifient » pas, ils se
 *     consomment. Patron des tables techniques du projet.
 *
 * ⚠️ ISOLEMENT (NFR14) : ces trois tables sont un PROLONGEMENT du registre
 * d'extensions — la sync amont (controlHub) ne doit JAMAIS les toucher. Prouvé
 * et verrouillé par `tests/Feature/ControlHub/UpstreamSyncExtensionsBoundaryTest.php`.
 *
 * Branches driver : `jsonb`/`json` et `timestampsTz()`/`timestamps()` — les
 * tests HÔTE rejouent toutes les migrations sur SQLite (`RefreshDatabase`).
 *
 * ⚠️ Vocabulaire : « amont » / `Upstream`, jamais « central ».
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (! Schema::hasTable('oidc_clients')) {
            Schema::create('oidc_clients', function (Blueprint $table) use ($driver): void {
                $table->id();

                // Lien vers le registre d'extensions. NULLABLE (décision #3) et
                // `nullOnDelete` : la suppression d'une extension ne doit pas
                // faire disparaître la trace de son client — `extension_key`
                // reste renseignée.
                $table->foreignId('extension_id')
                    ->nullable()
                    ->constrained('extensions', indexName: 'oidc_clients_ext_fk')
                    ->nullOnDelete();

                // Clé dénormalisée, survit à la suppression de l'extension.
                $table->string('extension_key', 64)->default('');

                // Libellé opérateur (affiché par l'UI admin de l'Epic 56).
                $table->string('name');

                // Identifiant public opaque du client (32 hex CSPRNG).
                // Nom d'index COURT (PostgreSQL tronque à 63 caractères).
                $table->string('client_id', 64)->unique('oidc_clients_client_id_unique');

                // sha256 du secret — JAMAIS le clair (décision #1). `$hidden`
                // sur le modèle : ne sort ni en JSON, ni en log, ni en UI.
                $table->string('client_secret_hash', 64);

                // Liste stricte des URI de redirection (décision #2).
                if ($driver === 'pgsql') {
                    $table->jsonb('redirect_uris');
                } else {
                    $table->json('redirect_uris');
                }

                // Révocation = désactivation (décision #4).
                $table->boolean('enabled')->default(true);

                if ($driver === 'pgsql') {
                    $table->timestampsTz();
                } else {
                    $table->timestamps();
                }
            });
        }

        if (! Schema::hasTable('oidc_authorization_codes')) {
            Schema::create('oidc_authorization_codes', function (Blueprint $table) use ($driver): void {
                $table->id();

                // Un code n'a aucun sens sans son client : cascade.
                $table->foreignId('oidc_client_id')
                    ->constrained('oidc_clients', indexName: 'oidc_codes_client_fk')
                    ->cascadeOnDelete();

                // L'utilisateur SE5 authentifié à l'autorisation. Nullable :
                // la suppression d'un compte ne doit pas empêcher la purge.
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users', indexName: 'oidc_codes_user_fk')
                    ->nullOnDelete();

                // Sujet dénormalisé — c'est la valeur qui deviendra le claim
                // `sub` de l'id_token. ⚠️ Sa RÉSOLUTION est confiée à un point
                // UNIQUE : {@see \App\Auth\Oidc\Support\OidcSubjectResolver}.
                // Aucun autre endroit du code ne décide de ce qu'est le sujet.
                $table->string('user_login');

                // sha256 du code — le clair ne touche jamais la base.
                $table->string('code_hash', 64)->unique('oidc_codes_hash_unique');

                // URI de la requête d'autorisation, RE-VÉRIFIÉE à l'échange
                // (obligation RFC 6749 §4.1.3).
                $table->string('redirect_uri', 512);

                // PKCE — challenge base64url (décision #5).
                $table->string('code_challenge', 128);
                $table->string('code_challenge_method', 16)->default('S256');

                // Relayé dans l'id_token s'il est non vide (anti-rejeu client).
                $table->string('nonce', 255)->default('');

                // Stocké pour 55.2/56 ; 55.1 ne l'interprète pas au-delà de
                // « contient openid ».
                $table->string('scope', 255)->default('openid');

                if ($driver === 'pgsql') {
                    $table->timestampTz('expires_at');
                    $table->timestampTz('consumed_at')->nullable();
                    $table->timestampTz('created_at')->useCurrent();
                } else {
                    $table->timestamp('expires_at');
                    $table->timestamp('consumed_at')->nullable();
                    $table->timestamp('created_at')->useCurrent();
                }

                // Purge opportuniste des codes périmés.
                $table->index('expires_at', 'oidc_codes_expires_idx');
            });
        }

        if (! Schema::hasTable('oidc_access_tokens')) {
            Schema::create('oidc_access_tokens', function (Blueprint $table) use ($driver): void {
                $table->id();

                $table->foreignId('oidc_client_id')
                    ->constrained('oidc_clients', indexName: 'oidc_tokens_client_fk')
                    ->cascadeOnDelete();

                // Même sujet que l'id_token (résolu par OidcSubjectResolver).
                $table->string('user_login');

                // sha256 du jeton opaque — le clair ne touche jamais la base.
                $table->string('token_hash', 64)->unique('oidc_tokens_hash_unique');

                $table->string('scope', 255)->default('openid');

                if ($driver === 'pgsql') {
                    $table->timestampTz('expires_at');
                    $table->timestampTz('created_at')->useCurrent();
                } else {
                    $table->timestamp('expires_at');
                    $table->timestamp('created_at')->useCurrent();
                }

                $table->index('expires_at', 'oidc_tokens_expires_idx');
            });
        }
    }

    public function down(): void
    {
        // Ordre INVERSE de la création (FK vers `oidc_clients` en dernier).
        Schema::dropIfExists('oidc_access_tokens');
        Schema::dropIfExists('oidc_authorization_codes');
        Schema::dropIfExists('oidc_clients');
    }
};
