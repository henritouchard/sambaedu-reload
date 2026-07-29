<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 56.4 — `oidc_clients.granted_scopes` : les scopes RÉELLEMENT ACCORDÉS
 * au client d'une extension (FR23).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI CETTE COLONNE EXISTE, ET POURQUOI ICI
 *
 *  Le consentement est un attribut du CLIENT OIDC (sémantique OAuth standard :
 *  « per-client allowed scopes »), pas du manifest — qui est déclaratif et
 *  réécrit à chaque synchro de catalogue — ni d'une table pivot : une liste
 *  fermée de deux valeurs (`profile`, `groups`) ne justifie pas une table.
 *
 *  Conséquence heureuse : `oidc_clients` est DÉJÀ dans la frontière NFR14
 *  (`UpstreamSyncExtensionsBoundaryTest`), donc cette story n'introduit aucune
 *  table neuve à y déclarer.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **DÉFAUT `[]` = FAIL-CLOSED.** Un client qui n'a rien reçu n'obtient rien :
 * son scope effectif se réduit à `openid`, donc au seul `sub`. C'est
 * volontairement le comportement des clients créés AVANT cette migration
 * (instances de QA) : ils doivent être ré-octroyés explicitement
 * (`oidc:witness:enable --rotate`, ou réinstallation de l'extension), jamais
 * hérités d'un consentement que personne n'a donné.
 *
 * **Additive et idempotente** : la migration `300000` (55.1) n'est PAS
 * retouchée — c'est un livrable clos, passé en review, et déjà appliqué sur des
 * instances. Gardes `hasTable`/`hasColumn` : rejouable.
 *
 * ⚠️ **Aucune borne n'est applicable en base** : SQLite ne contraint pas le
 * JSON, et PostgreSQL ne saurait pas exprimer « sous-ensemble de
 * `{profile, groups}` » sans contrainte figée dans le schéma. La normalisation
 * et le vocabulaire fermé sont donc APPLICATIFS
 * ({@see \App\Models\OidcClient::grantedScopes()} et
 * {@see \App\Auth\Oidc\Services\OidcClientRegistry::normalizeGrantedScopes()}).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oidc_clients')) {
            return;
        }

        if (Schema::hasColumn('oidc_clients', 'granted_scopes')) {
            return;
        }

        $driver = DB::getDriverName();

        Schema::table('oidc_clients', function (Blueprint $table) use ($driver): void {
            if ($driver === 'pgsql') {
                $table->jsonb('granted_scopes')->default('[]');
            } else {
                $table->json('granted_scopes')->default('[]');
            }
        });

        // Les lignes préexistantes reçoivent le défaut du moteur, mais un
        // moteur qui l'appliquerait mal (ou une colonne ajoutée nullable par un
        // driver tiers) laisserait des `null` que le cast Eloquent rendrait
        // indistincts d'une liste vide. On pose la valeur explicitement : le
        // fail-closed doit être une DONNÉE, pas une absence de donnée.
        DB::table('oidc_clients')->whereNull('granted_scopes')->update(['granted_scopes' => '[]']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('oidc_clients')) {
            return;
        }

        if (! Schema::hasColumn('oidc_clients', 'granted_scopes')) {
            return;
        }

        Schema::table('oidc_clients', function (Blueprint $table): void {
            $table->dropColumn('granted_scopes');
        });
    }
};
