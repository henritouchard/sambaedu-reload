<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 61.1 — LE CACHE DE RÉSOLUTION D'IDENTITÉ NEXTCLOUD.
 *
 * ---------------------------------------------------------------------------
 * **C'EST UN CACHE, PAS UNE AUTORITÉ.** La vérité de l'identité Nextcloud est
 * chez Nextcloud. Cette colonne évite de la redemander à chaque geste ; elle est
 * nullable, reconstructible (`nextcloud:provision` la remplit à nouveau), et sa
 * perte ne coûte que des appels réseau — jamais un accès.
 * ---------------------------------------------------------------------------
 *
 * **Le précédent qu'elle remplace.** SE4 cachait cette même correspondance dans
 * l'attribut AD `Id NC` (`../sambaedu/includes/cloud.inc.php:702`, réécriture
 * `:715-719`). SE5 ne peut PAS reprendre ce précédent : depuis l'Epic 49, l'AD est
 * un artefact COMPILÉ depuis Postgres, ni source d'autorité ni lieu d'écriture
 * d'état applicatif. Y écrire un identifiant applicatif ferait de l'annuaire une
 * base de données parallèle, que la prochaine reprojection écraserait en silence.
 *
 * **Pourquoi une colonne et pas une table.** Un utilisateur ↔ une identité
 * Nextcloud sur UNE instance configurée globalement (`files.policy`). Le jour où
 * plusieurs instances coexisteraient — jamais au cadrage — la colonne migrerait ;
 * généraliser d'avance coûterait une jointure à chaque lecture pour une
 * éventualité qui n'existe pas.
 *
 * **Hors `$fillable` par conception** : elle est écrite NOMINATIVEMENT par le
 * provisionnement, jamais par un formulaire en assignation de masse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'nextcloud_user_id')) {
                $table->string('nextcloud_user_id', 191)
                    ->nullable()
                    ->after('ad_guid')
                    ->comment('CACHE de résolution de l\'identité Nextcloud (story 61.1). Pas une autorité : la vérité est chez Nextcloud.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'nextcloud_user_id')) {
                $table->dropColumn('nextcloud_user_id');
            }
        });
    }
};
