<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 61.2, correction de revue #2 — UNE IDENTITÉ NEXTCLOUD N'EST PORTÉE QUE PAR
 * UN SEUL UTILISATEUR SE5.
 *
 * ---------------------------------------------------------------------------
 * **CE QUE CET INDEX FERME.** `users.nextcloud_user_id` est un cache de résolution
 * (61.1). Rien n'empêchait deux logins SE5 de porter la même valeur — ni le
 * rattachement explicite (qui vérifiait l'EXISTENCE distante de l'identité, jamais
 * qu'elle fût LIBRE), ni la résolution automatique. Or
 * {@see \App\Services\Nextcloud\NextcloudUserProvisioner::propagatePassword()} écrit
 * le mot de passe AD sur le compte désigné par cette colonne : deux porteurs, et le
 * changement de mot de passe de l'un écrase le compte de l'autre — silencieusement,
 * journalisé comme un succès. C'est exactement le défaut que la correction #2 de la
 * revue 61.1 avait fermé côté adoption automatique.
 *
 * **LA DÉFENSE PRINCIPALE EST APPLICATIVE, PAS ICI.** Les deux points d'écriture du
 * cache ({@see \App\Services\Nextcloud\NextcloudIdentityLinker::link()} et
 * `NextcloudUserProvisioner::cacheIdentity()`) refusent désormais en NOMMANT le
 * login qui détient l'identité, et le balayage COMPTE ce refus dans son rapport au
 * lieu de lever quoi que ce soit. Cet index est la défense en profondeur : il
 * garantit la propriété même si un futur chemin d'écriture oublie la garde. Il a été
 * posé — et non abandonné — précisément parce que la garde applicative rend
 * impossible qu'un import de masse le heurte : aucun chemin n'écrit plus une valeur
 * déjà prise, et l'unique écriture restante (`cacheIdentity()`) avale de toute façon
 * ses erreurs de base en `warning` sans interrompre le lot.
 *
 * **Plusieurs `NULL` restent permis** — SQLite comme PostgreSQL traitent les NULL
 * comme distincts dans un index unique. C'est indispensable : l'écrasante majorité
 * des utilisateurs n'a aucune identité Nextcloud en cache, et un partiel serait une
 * complication sans objet. Un test l'épingle.
 * ---------------------------------------------------------------------------
 */
return new class extends Migration
{
    private const INDEX = 'users_nextcloud_user_id_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'nextcloud_user_id')) {
            return;
        }

        if (Schema::hasIndex('users', self::INDEX)) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('nextcloud_user_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'nextcloud_user_id')) {
            return;
        }

        if (! Schema::hasIndex('users', self::INDEX)) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
        });
    }
};
