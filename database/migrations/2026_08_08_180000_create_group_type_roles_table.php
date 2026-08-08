<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 62.3 — L'ARÊTE ENTRE LES DEUX CATALOGUES : quels rôles ont un sens dans
 * un type de groupe, et comment ils s'y disent.
 *
 * **Ce qui meurt ici.** La constante privée de `RoleCatalog` qui portait les
 * libellés de rôle par type de groupe était leur dernière recopie en CODE —
 * héritée de la table de la story
 * 60.2, docblockée « donnée de la story 62.3 » par la story 62.1. Elle disait
 * qu'un `manager` se lit « Enseignant » dans une classe, « Porteur » dans un
 * projet, « Référent » dans une équipe. Un administrateur qui renommait
 * « Gestionnaire » depuis l'écran des rôles ne voyait donc RIEN changer sur ses
 * classes, sans qu'aucun écran ne l'en avertisse (review 62.1 #4). Ces libellés
 * deviennent des LIGNES : administrables, visibles, et surchargeables là où ils
 * s'appliquent.
 *
 * **Déclaration ≠ surcharge — d'où SEPT lignes et non cinq.** La constante ne
 * portait que les SURCHARGES de libellé : `projet` n'y avait qu'une entrée
 * (`manager` → « Porteur »), parce qu'un `member` de projet se lisait déjà
 * « Membre » par le repli générique. La table, elle, porte les DÉCLARATIONS —
 * « quels rôles ont un sens dans ce type » — dont le libellé local est un attribut
 * OPTIONNEL. Recopier la constante telle quelle aurait déclaré `projet` →
 * {manager} seul, et rendu `member` — le défaut de TOUT rattachement —
 * inattribuable dans le moindre projet dès la première déclaration.
 * `projet`×`member` et `equipe`×`member` entrent donc avec un `label` à `null` :
 * déclarés, sans surcharge, lus au libellé du catalogue.
 *
 * **Symétriquement, `owner` n'est PAS déclaré hors `classe`.** C'est la donnée qui
 * dit ce que la garde D3 (« professeur principal ⇒ classe ») disait en littéral
 * dans les écrans. La garde LITTÉRALE reste en place, en défense en profondeur :
 * un type SANS déclaration retombe sur tout le catalogue, `owner` compris, et
 * sans D3 un `cours` accepterait un « Professeur principal ».
 *
 * **Pourquoi les lignes de reprise sont ICI et pas dans le seeder.** Les tests de
 * parité de la story 62.1 (`RoleCatalogParityTest`, `RenderedRoleLabelsParityTest`)
 * épinglent « Élève », « Enseignant », « Professeur principal », « Porteur » et
 * « Référent » EN LITTÉRAUX, et doivent passer SANS AUCUNE MODIFICATION après la
 * bascule code→donnée — c'est la preuve exécutable que rien n'a bougé à l'écran.
 * Or `RefreshDatabase` rejoue les MIGRATIONS, pas les seeders. Poser ces lignes au
 * seeder seul ferait tomber ces littéraux, et la tentation serait alors d'« adapter
 * les tests » — c'est-à-dire d'effacer la preuve. Le seeder existe quand même
 * ({@see \Database\Seeders\GroupTypeRoleSeeder}), pour resynchroniser une instance
 * en place sur la baseline du code ; il ne porte pas la parité. Patron identique à
 * la migration 62.2, qui insère ses neuf types statiques plutôt que de s'en
 * remettre à `GroupTypeSeeder`.
 *
 * **Pourquoi `DB::table` et pas le modèle.** {@see \App\Models\GroupTypeRole} porte
 * une garde `saving` qui exige un type PRÉSENT au catalogue et un rôle du
 * vocabulaire. Elle protège les écritures APPLICATIVES. Une migration s'exécute
 * dans un ordre où ces catalogues peuvent être dans n'importe quel état, et une
 * déclaration future sur un type HÉRITÉ non-slug (`Custom`, `class` — review
 * 62.2 #1) doit pouvoir exister. Le modèle ne s'interpose donc jamais sur ce
 * chemin (piège 62.2 #3).
 *
 * **Aucune clé étrangère** — cohérence d'epic (D2) : la référence est la CLÉ
 * immuable, lisible en base, et les refus de retrait sont APPLICATIFS et NOMMÉS
 * (« 42 appartenances portent le rôle "Enseignant" dans des groupes de type
 * "classe" »), ce qu'un `RESTRICT` ne saurait pas formuler.
 *
 * Idempotente : rejouable sans doublon (test d'existence sur la paire avant
 * chaque insertion). Elle n'écrit RIEN dans `user_group_user`, `user_groups`,
 * `group_roles` ni `group_types` — elle ne fait que déclarer.
 */
return new class extends Migration
{
    /**
     * Les SEPT déclarations de reprise.
     *
     * Les cinq premières recopient la constante défunte à l'identique (c'est la
     * parité) ; les deux dernières sont l'écart assumé et docblocké ci-dessus.
     *
     * @var list<array{group_type_key: string, group_role_key: string, label: ?string}>
     */
    private const DECLARATIONS = [
        // `classe` — les trois clés historiques, et leurs trois libellés
        // scolaires, exactement ceux de la constante qui meurt.
        ['group_type_key' => 'classe', 'group_role_key' => 'member', 'label' => 'Élève'],
        ['group_type_key' => 'classe', 'group_role_key' => 'manager', 'label' => 'Enseignant'],
        ['group_type_key' => 'classe', 'group_role_key' => 'owner', 'label' => 'Professeur principal'],
        // `projet` — « Porteur » surchargeait `manager` ; `member` est DÉCLARÉ
        // sans surcharge (il se lit « Membre », libellé du catalogue).
        ['group_type_key' => 'projet', 'group_role_key' => 'member', 'label' => null],
        ['group_type_key' => 'projet', 'group_role_key' => 'manager', 'label' => 'Porteur'],
        // `equipe` — même forme, « Référent » sur `manager`.
        ['group_type_key' => 'equipe', 'group_role_key' => 'member', 'label' => null],
        ['group_type_key' => 'equipe', 'group_role_key' => 'manager', 'label' => 'Référent'],
    ];

    /** Borne de `user_groups.type`, donc de la clé de type qui la référence. */
    private const TYPE_KEY_MAX_LENGTH = 50;

    /** Borne de `user_group_user.role`, donc de la clé de rôle qui la référence. */
    private const ROLE_KEY_MAX_LENGTH = 20;

    public function up(): void
    {
        if (! Schema::hasTable('group_type_roles')) {
            Schema::create('group_type_roles', function (Blueprint $table) {
                $table->id();
                // Les deux clés sont les valeurs STOCKÉES ailleurs
                // (`user_groups.type`, `user_group_user.role`) : mêmes bornes,
                // sinon une déclaration pourrait viser une clé qu'aucune donnée
                // ne peut porter.
                $table->string('group_type_key', self::TYPE_KEY_MAX_LENGTH);
                $table->string('group_role_key', self::ROLE_KEY_MAX_LENGTH);
                // Le libellé LOCAL. `null` = « déclaré sans surcharge » : le
                // libellé du catalogue de rôles s'applique. C'est la forme
                // majoritaire, et c'est ce qui distingue une DÉCLARATION d'une
                // surcharge.
                $table->string('label')->nullable();
                $table->timestamps();

                // Une paire ne se déclare qu'une fois. C'est aussi le filet de
                // la course de l'écran (check-then-act côté modale).
                $table->unique(['group_type_key', 'group_role_key'], 'group_type_roles_pair_unique');
            });
        }

        $this->seedDeclarations();
    }

    /**
     * Le `down()` retire la TABLE, et rien d'autre.
     *
     * Il n'a rien à défaire par ailleurs : cette migration n'a écrit AUCUNE ligne
     * hors de `group_type_roles`. C'est précisément l'invariant qu'elle
     * revendique.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_type_roles');
    }

    /** Les sept déclarations, insérées si absentes (jamais écrasées). */
    private function seedDeclarations(): void
    {
        $now = now();

        foreach (self::DECLARATIONS as $declaration) {
            $exists = DB::table('group_type_roles')
                ->where('group_type_key', $declaration['group_type_key'])
                ->where('group_role_key', $declaration['group_role_key'])
                ->exists();

            if ($exists) {
                // Rejouée : on ne réécrit PAS le libellé local. Un administrateur
                // a pu le changer depuis l'écran des types — le sien fait foi.
                //
                // Review 62.3 #2 — ne pas lire cette phrase comme une garantie
                // GÉNÉRALE : elle vaut pour CETTE migration. `GroupTypeRoleSeeder`
                // a le contrat INVERSE et assumé — il RESYNCHRONISE la baseline,
                // donc il réécrit les libellés locaux, et un test l'épingle. Les
                // deux sont volontairement différents : une migration ne doit
                // jamais défaire un geste d'administration, un seeder de baseline
                // est justement l'outil qu'on lance pour revenir à l'état de
                // référence. La conséquence d'exploitation est réelle et va au
                // runbook : lancer le seeder sur une instance où l'écran a servi
                // écrase les libellés locaux, sans confirmation.
                continue;
            }

            DB::table('group_type_roles')->insert(
                $declaration + ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }
};
