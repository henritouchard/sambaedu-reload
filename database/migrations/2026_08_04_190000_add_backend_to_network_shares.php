<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 60.3 — « ce partage a une AUTORITÉ D'ÉCRITURE, et elle a un nom ».
 *
 * Une colonne, NOT NULL, défaut `posix`.
 *
 * **Pourquoi le défaut dit vrai, et pourquoi il n'y a aucune reprise de données.**
 * Tous les partages en place SONT écrits par le serveur de fichiers historique :
 * la colonne ne fait que rendre explicite un fait qui l'était déjà, implicitement,
 * dans le code. Une valeur par défaut qui décrit l'existant n'est pas une
 * convention commode, c'est la seule qui ne mente pas — et c'est ce qui dispense
 * de toute migration de données. Un défaut « inconnu » ou nullable aurait fabriqué
 * un état intermédiaire à interpréter, pour ne rien décrire de plus.
 *
 * **Ce que cette colonne ACHÈTE** (décision Q-D, 2026-08-04) : la réversibilité.
 * POSIX est conservé, et il sera retirable — le jour venu, le retirer sera basculer
 * cette valeur puis lancer une migration explicite (jamais implicite, D9), pas
 * réécrire le domaine. C'est la colonne qui rend ce futur bon marché, et c'est la
 * raison pour laquelle elle arrive AVANT que quoi que ce soit ne route par elle.
 *
 * **Rien ne route par elle dans cette story.** Les flux de provisioning continuent
 * d'appeler le service historique exactement comme avant ; la colonne est affichée
 * (elle détermine le chemin d'accès de l'utilisateur, ce n'est pas un détail
 * d'implémentation) mais elle n'est PAS éditable, parce qu'une propriété qu'on
 * peut changer sans effet est une propriété qui MENT. Le routage et l'éditabilité
 * arrivent ensemble, en 60.4.
 *
 * Le vocabulaire est porté par une enum FERMÉE ({@see App\Enums\FileBackendName}) :
 * `posix|preview`. Pas de contrainte de vérification en base — la validation vit
 * dans l'applicatif, où elle peut nommer ce qui était attendu ; une valeur hors
 * vocabulaire y provoque un échec EXPLICITE, jamais un repli silencieux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_shares', function (Blueprint $table) {
            $table->string('backend')->default('posix')->after('directory_name');
        });
    }

    public function down(): void
    {
        Schema::table('network_shares', function (Blueprint $table) {
            $table->dropColumn('backend');
        });
    }
};
