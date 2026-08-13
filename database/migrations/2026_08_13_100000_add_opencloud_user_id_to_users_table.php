<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LE CACHE DE RÉSOLUTION D'IDENTITÉ OPENCLOUD, et son index d'unicité.
 *
 * ---------------------------------------------------------------------------
 * **C'EST UN CACHE, PAS UNE AUTORITÉ.** La vérité de l'identité OpenCloud est
 * chez OpenCloud. Cette colonne évite de la redemander à chaque geste ; elle est
 * nullable, reconstructible, et sa perte ne coûte que des appels réseau — jamais
 * un accès.
 * ---------------------------------------------------------------------------
 *
 * **POURQUOI UNE COLONNE DE PLUS, ET PAS CELLE DE L'AUTRE PRODUIT.** Les deux
 * instances sont des annuaires DIFFÉRENTS : le même professeur y porte deux
 * identifiants qui n'ont aucune raison de coïncider, et rien n'interdit qu'une
 * école exploite les deux. Réutiliser la colonne existante ferait pointer un
 * backend vers l'identité d'un autre produit — un accès accordé à la mauvaise
 * personne, et rien pour le voir. La colonne est donc distincte, comme les deux
 * capacités le sont.
 *
 * **La forme mesurée de l'identifiant** (2026-08-13) : un UUID, jamais le login.
 * Et c'est la mesure qui l'impose plutôt qu'une doctrine : le filtre sur le
 * `onPremisesSamAccountName` est **refusé par l'API**
 * (`{"error":{"code":"generalException","message":"unsupported filter"}}`), donc
 * retrouver un compte par son login exige d'énumérer l'annuaire. Le cache n'est
 * pas une optimisation confortable, c'est la seule jointure praticable.
 *
 * **L'INDEX UNIQUE est une protection de sécurité, pas une commodité.** Sans
 * lui, deux logins SE5 pourraient porter la même identité distante, et l'octroi
 * nominatif du dossier personnel d'un élève atterrirait chez un tiers. Plusieurs
 * `NULL` restent permis — SQLite comme PostgreSQL les traitent comme distincts —
 * ce qui est indispensable : l'écrasante majorité des utilisateurs n'a aucune
 * identité OpenCloud en cache.
 *
 * **Hors `$fillable` par conception** : elle est écrite NOMINATIVEMENT, jamais par
 * un formulaire en assignation de masse.
 */
return new class extends Migration
{
    private const INDEX = 'users_opencloud_user_id_unique';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'opencloud_user_id')) {
                $table->string('opencloud_user_id', 191)
                    ->nullable()
                    ->after('nextcloud_user_id')
                    ->comment('CACHE de résolution de l\'identité OpenCloud. Pas une autorité : la vérité est chez OpenCloud.');
            }
        });

        if (Schema::hasColumn('users', 'opencloud_user_id') && ! Schema::hasIndex('users', self::INDEX)) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('opencloud_user_id', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'opencloud_user_id')) {
            return;
        }

        if (Schema::hasIndex('users', self::INDEX)) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('opencloud_user_id');
        });
    }
};
