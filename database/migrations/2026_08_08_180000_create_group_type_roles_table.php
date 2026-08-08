<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 62.3 — L'ARÊTE ENTRE LES DEUX CATALOGUES : quels rôles ont un sens dans
 * un type de groupe, et comment ils s'y disent.
 *
 * **Cette migration crée la table, et RIEN D'AUTRE. Elle naît VIDE.**
 *
 * Elle a d'abord posé sept déclarations de reprise — les libellés scolaires
 * « Élève », « Enseignant », « Professeur principal », « Porteur », « Référent ».
 * Elles en sont sorties, pour deux raisons qui n'ont rien de cosmétique :
 *
 *  1. **une déclaration RESTREINT.** {@see \App\Support\RoleCatalog::assignableKeys()}
 *     rend TOUT le catalogue à un type SANS déclaration, et SEULEMENT les rôles
 *     déclarés à un type qui en a. Poser ces sept lignes FERME `classe`, `projet`
 *     et `equipe` en laissant ouverts tous les autres types. C'est iso au jour de
 *     la story — trois rôles au catalogue, et la garde D3 bloque déjà `owner` hors
 *     classe — mais au premier rôle personnalisé créé par un administrateur, il
 *     serait attribuable partout SAUF dans les trois types les plus utilisés.
 *     Fermer un type est une DÉCISION ; une migration ne la prend pas à la place de
 *     l'administrateur ;
 *  2. **SE5 est multi-vertical.** Une instance sans rapport avec une école n'a
 *     aucune raison de recevoir « Élève » et « Professeur principal ». Le
 *     vocabulaire scolaire est un PROFIL qu'on installe, pas un défaut qu'on subit
 *     — même geste que la décision de 2026-08-03 sur le seed `Profs`→`prof`
 *     (runbook « rights-management », scénario 19.12).
 *
 * **Le profil scolaire s'installe donc à la demande**, par la commande
 * {@see \App\Console\Commands\CollegeSeedRoleXTypeCommand} :
 *
 *     php artisan college:seed:role-x-type
 *
 * Additive et idempotente ; `--resync` réaligne les libellés existants. C'est le
 * point d'entrée UNIQUE — `GroupTypeRoleSeeder` a été supprimé au profit d'elle.
 *
 * **Ce que la table est.** Une DÉCLARATION dit deux choses à la fois : que ce rôle
 * a un sens dans ce type, et éventuellement comment il s'y dit (`label`, OPTIONNEL).
 * `null` ne veut pas dire « pas de libellé » mais « pas de surcharge » : le libellé
 * du catalogue de rôles s'applique. C'est ce qui sépare une déclaration d'une
 * surcharge, et c'est pourquoi le profil scolaire compte SEPT lignes et non cinq —
 * `projet`×`member` et `equipe`×`member` sont déclarés sans surcharge, faute de quoi
 * `member`, le défaut de tout rattachement, deviendrait inattribuable dans le
 * moindre projet.
 *
 * **Aucune clé étrangère** — cohérence d'epic (D2) : la référence est la CLÉ
 * immuable, lisible en base, et les refus de retrait sont APPLICATIFS et NOMMÉS
 * (« 42 appartenances portent le rôle "Enseignant" dans des groupes de type
 * "classe" »), ce qu'un `RESTRICT` ne saurait pas formuler.
 *
 * Idempotente : rejouable sans effet (test d'existence de la table). Elle n'écrit
 * RIEN, nulle part — ni dans `user_group_user`, ni dans `user_groups`, ni dans
 * `group_roles`, ni dans `group_types`, ni dans sa propre table.
 */
return new class extends Migration
{
    /** Borne de `user_groups.type`, donc de la clé de type qui la référence. */
    private const TYPE_KEY_MAX_LENGTH = 50;

    /** Borne de `user_group_user.role`, donc de la clé de rôle qui la référence. */
    private const ROLE_KEY_MAX_LENGTH = 20;

    public function up(): void
    {
        if (Schema::hasTable('group_type_roles')) {
            return;
        }

        Schema::create('group_type_roles', function (Blueprint $table) {
            $table->id();
            // Les deux clés sont les valeurs STOCKÉES ailleurs
            // (`user_groups.type`, `user_group_user.role`) : mêmes bornes, sinon
            // une déclaration pourrait viser une clé qu'aucune donnée ne peut
            // porter.
            $table->string('group_type_key', self::TYPE_KEY_MAX_LENGTH);
            $table->string('group_role_key', self::ROLE_KEY_MAX_LENGTH);
            // Le libellé LOCAL. `null` = « déclaré sans surcharge » : le libellé
            // du catalogue de rôles s'applique. C'est la forme majoritaire, et
            // c'est ce qui distingue une DÉCLARATION d'une surcharge.
            $table->string('label')->nullable();
            $table->timestamps();

            // Une paire ne se déclare qu'une fois. C'est aussi le filet de la
            // course de l'écran (check-then-act côté modale).
            $table->unique(['group_type_key', 'group_role_key'], 'group_type_roles_pair_unique');
        });
    }

    /**
     * Le `down()` retire la TABLE, et rien d'autre.
     *
     * Il n'a rien à défaire par ailleurs : cette migration n'a écrit AUCUNE ligne,
     * ni dans sa table ni hors d'elle. C'est précisément l'invariant qu'elle
     * revendique.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_type_roles');
    }
};
