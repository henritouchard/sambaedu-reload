<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GroupRole;
use App\Models\Pivot\UserGroupUserPivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Story 62.1 — LE POINT DE LECTURE UNIQUE du catalogue de rôles d'arête.
 *
 * Il remplace la table de libellés de la story 60.2 (supprimée), dont il reprend le rôle —
 * dire comment un rôle stocké se LIT — en changeant sa source : les trois lignes
 * ne sont plus une constante, elles sont une table administrable
 * ({@see \App\Models\GroupRole}).
 *
 * **C'est ICI que vit la mémoïsation, et nulle part ailleurs.** Le vocabulaire est
 * consulté à chaque validation d'arête, à chaque résolution de plan, à chaque
 * rendu de liste de membres. Une requête par appel serait un coût absurde pour une
 * donnée qui change une fois par trimestre. La mémo est vidée par les hooks
 * `saved`/`deleted` du modèle — et dans le `setUp()` des tests, parce qu'un
 * rollback de transaction ne dispatche aucun événement Eloquent.
 *
 * **Le PLANCHER historique n'est pas une politesse, c'est un invariant.** La
 * lecture rend TOUJOURS au moins `member`, `manager` et `owner`, quel que soit
 * l'état de la base : ces trois clés sont écrites en littéral par du code vivant
 * (dérivation au rattachement, garde « professeur principal », projection
 * d'annuaire `PP_`, recettes seedées). Une base neuve, non migrée ou non seedée ne
 * doit jamais faire refuser `member` par la garde d'arête. Le repli RESTREINT au
 * vocabulaire historique — il n'élargit rien : une valeur inconnue reste refusée.
 *
 * **Story 62.3 — les libellés par TYPE de groupe ne sont plus du code.** La table
 * privée qui les portait ici, dernier vestige de la classe supprimée en 62.1, est
 * MORTE : ses lignes sont devenues des DÉCLARATIONS administrables
 * ({@see \App\Models\GroupTypeRole}), lues ici et surchargeables depuis l'onglet
 * « Types de groupes ». Une déclaration dit deux choses à la fois : que ce rôle a
 * un SENS dans ce type (c'est la base de {@see self::assignableKeys()}), et
 * éventuellement comment il s'y DIT (le libellé local, optionnel). Un
 * administrateur qui renomme « Gestionnaire » voit désormais où sa modification
 * est masquée, et peut la lever — c'est la clôture du point reporté par la review
 * 62.1 #4.
 *
 * **Ce que la classe ne fait PAS.** Elle n'est pas importée par le namespace pur
 * du plan de fichiers ({@see \App\Services\Filesystem\Plan\GroupNameNormalizer}),
 * qui reçoit le vocabulaire par injection au boot. La pureté du plan est
 * verrouillée par un test d'architecture, pas par de la discipline.
 */
final class RoleCatalog
{
    /**
     * Le PLANCHER : les trois clés historiques, dans leur ordre stocké (du moins
     * doté au plus doté).
     *
     * @var list<string>
     */
    public const HISTORICAL_KEYS = [
        UserGroupUserPivot::ROLE_MEMBER,
        UserGroupUserPivot::ROLE_MANAGER,
        UserGroupUserPivot::ROLE_OWNER,
    ];

    /**
     * Libellés GÉNÉRIQUES de secours des trois clés historiques.
     *
     * Ils sont IDENTIQUES aux libellés seedés : sur une base seedée, ils ne
     * servent jamais. Ils servent sur une base neuve, non migrée ou non seedée —
     * là où le plancher tient tout seul et où il faut bien afficher quelque chose
     * qui ne soit pas une valeur technique.
     *
     * @var array<string, string>
     */
    private const GENERIC_FALLBACK = [
        UserGroupUserPivot::ROLE_MEMBER => 'Membre',
        UserGroupUserPivot::ROLE_MANAGER => 'Gestionnaire',
        UserGroupUserPivot::ROLE_OWNER => 'Propriétaire',
    ];

    /**
     * Mémo : `clé => libellé`, dans l'ordre d'affichage du catalogue.
     *
     * @var array<string, string>|null
     */
    private static ?array $memo = null;

    /**
     * Mémo des DÉCLARATIONS par type, sous deux index.
     *
     * `exact` : clé de type telle que STOCKÉE => [rôle => libellé local|null].
     * `lower` : clé de type ABAISSÉE => idem, une seule clé déclarante par
     * entrée (voir {@see self::readDeclarations()}).
     *
     * Elle vit ICI et pas dans une classe à part : `flush()` vide les deux mémos
     * d'un coup, donc elle hérite gratuitement du `Queue::before` de
     * `AppServiceProvider` (review 62.1 #1 : un worker `queue:work --max-time=3600`
     * ne réinitialise aucune statique) et du `setUp()` de `tests/TestCase.php`.
     * Une mémo séparée avec son propre flush rejouerait cette review à
     * l'identique.
     *
     * @var array{exact: array<string, array<string, ?string>>, lower: array<string, array<string, ?string>>}|null
     */
    private static ?array $declarationsMemo = null;

    /** Vide la mémoïsation. Appelée par les hooks d'écriture et par les tests. */
    public static function flush(): void
    {
        self::$memo = null;
        self::$declarationsMemo = null;
    }

    /**
     * Les clés du catalogue, dans l'ordre d'affichage, plancher historique inclus.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::rows());
    }

    /**
     * Libellé FR d'un rôle d'arête dans un groupe de ce type.
     *
     * Un rôle vide ou hors catalogue (donnée héritée) est lu comme `member` — même
     * normalisation que les écrans de groupes depuis la story 42.3 : on affiche le
     * rôle le moins doté plutôt qu'une valeur technique ou un vide.
     *
     * Précédence, inchangée depuis 60.2 sauf pour la SOURCE du premier terme :
     * libellé LOCAL déclaré → libellé du catalogue → repli générique → clé brute.
     * Un rôle déclaré avec `label = null` (déclaré SANS surcharge) tombe donc au
     * libellé du catalogue, et un rôle NON déclaré reste AFFICHÉ : la lecture ne
     * refuse jamais rien. Un `owner` hérité sur un projet se lit
     * « Propriétaire » — c'est de la donnée existante, pas une attribution.
     */
    public static function label(?string $groupType, ?string $roleKey): string
    {
        $rows = self::rows();

        $role = ($roleKey !== null && isset($rows[$roleKey]))
            ? $roleKey
            : UserGroupUserPivot::ROLE_MEMBER;

        $local = self::declarationsFor($groupType)[$role] ?? null;

        return $local
            ?? $rows[$role]
            ?? self::GENERIC_FALLBACK[$role]
            ?? $role;
    }

    /**
     * Story 62.3 — les rôles ATTRIBUABLES dans un groupe de ce type, dans l'ordre
     * d'affichage du catalogue.
     *
     * Deux régimes, et c'est délibéré :
     *  - **type DÉCLARÉ** (au moins une déclaration) : ses rôles déclarés, et eux
     *    seuls. C'est la contrainte que l'epic demandait — on n'attribue pas un
     *    rôle qu'un type ne reconnaît pas ;
     *  - **type SANS déclaration** : TOUT le catalogue. Un type découvert en base
     *    (`class`, `Custom`) ou créé à l'écran n'a aucune déclaration, et le
     *    priver de tout rôle le rendrait inutilisable — la déclaration est une
     *    restriction volontaire, pas un péage d'entrée.
     *
     * Un rôle déclaré puis supprimé du catalogue de rôles ne ressort pas ici : la
     * liste est un sous-ensemble ORDONNÉ de {@see self::keys()}. Ce cas ne devrait
     * pas exister — {@see \App\Models\GroupRole::deletionRefusal()} refuse la
     * suppression d'un rôle déclaré — mais l'ordre du catalogue reste la seule
     * source d'ordre, y compris quand la donnée est incohérente.
     *
     * @return list<string>
     */
    public static function assignableKeys(?string $groupType): array
    {
        $declared = self::declarationsFor($groupType);

        if ($declared === []) {
            return self::keys();
        }

        return array_values(array_filter(
            self::keys(),
            static fn (string $role): bool => array_key_exists($role, $declared),
        ));
    }

    /**
     * Story 62.3 — LA CONTRAINTE D'ATTRIBUTION, et la FRONTIÈRE qu'elle trace.
     *
     * Lève si ce rôle n'est pas attribuable dans un groupe de ce type.
     *
     * **Où elle mord — et où elle NE mord PAS, délibérément.** Elle vit aux trois
     * points d'étranglement HUMAINS, ceux où quelqu'un CHOISIT un rôle en
     * connaissant le groupe, tous dans `resources/views/pages/users/groups/[id]/` :
     * `updateMemberRole()`, `setPendingRole()`, et la revalidation de `save()`.
     *
     * Elle n'est PAS sur le modèle pivot, PAS dans
     * {@see \App\Models\Pivot\UserGroupUserPivot::assertValidRole()}, PAS dans un
     * événement Eloquent — c'est le précédent posé par 62.2 (garde au service, pas
     * sur `UserGroup`) et il tient pour la même raison : ces chemins-là écrivent
     * des arêtes sans qu'aucun humain n'ait rien choisi, et les brider casserait
     * le flux dont l'annuaire est autoritaire. `assertValidRole()` en particulier
     * est appelée par `defaultRoleForGlobalRole()` sur le chemin d'import « D6
     * fail-soft intégral, jamais de levée », et elle ne connaît même pas le type
     * du groupe.
     *
     * **Le recensement des écrivains de `user_group_user.role`**, pour que le
     * prochain lecteur n'ait pas à refaire l'audit :
     *  - GARDÉS (humains) : `updateMemberRole()`, `setPendingRole()`, `save()` ;
     *  - LIBRES (dérivation, import, reprise) : `UserGroupService` (balayage AD et
     *    fold du trio), `UserService::persistUserGroupsToSql()` (import
     *    d'utilisateurs), `User::userGroupSyncPayloadWithDerivedRole()` (payload
     *    partagé fiche user / drawer), `MergeLegacyUserGroups` et
     *    `BackfillUserGroupUserRoles` (reprises one-shot), les factories et les
     *    tests.
     *
     * **Ce qui rend l'ensemble cohérent n'est pas la garde, c'est la
     * COMPOSITION** : les chemins libres n'écrivent que des CONSTANTES du plancher
     * (`member|manager|owner`), et les déclarations de reprise les couvrent —
     * `classe` déclare les trois, `projet` et `equipe` déclarent `member` et
     * `manager`, les autres types n'ont pas de déclaration donc pas de contrainte.
     * Un test épingle cette composition (« import ⊆ déclarations seedées »), jumeau
     * du « balayage ⊆ plancher » de 62.2 : si quelqu'un ampute le seed, il tombe.
     */
    public static function assertAssignable(?string $groupType, string $roleKey): void
    {
        $assignable = self::assignableKeys($groupType);

        if (in_array($roleKey, $assignable, true)) {
            return;
        }

        $type = $groupType === null ? '' : trim($groupType);

        throw new InvalidArgumentException(sprintf(
            'Le rôle « %s » n\'est pas déclaré pour les groupes de type « %s ». Vocabulaire déclaré : %s. '
            . 'Ajoutez-le depuis l\'onglet « Types de groupes » des réglages.',
            self::label($groupType, $roleKey),
            $type === '' ? '—' : $type,
            implode(', ', array_map(
                static fn (string $role): string => self::label($groupType, $role),
                $assignable,
            )),
        ));
    }

    /**
     * Les déclarations qui s'appliquent à ce type, `rôle => libellé local|null`.
     *
     * **Deux appariements, dans cet ordre, et c'est docblocké parce que ce n'est
     * pas un hasard de tri :**
     *  1. correspondance EXACTE sur la clé stockée (trimée). Elle prime : si
     *     `Custom` et `custom` déclarent tous deux, un groupe stocké `Custom` lit
     *     les siennes ;
     *  2. à défaut, correspondance sur la clé ABAISSÉE — la normalisation
     *     historique de la résolution (minuscule + trim, story 60.2), celle que la
     *     parité de 62.1 épingle (`label('Classe', 'member') === 'Élève'`). En cas
     *     d'homonymie de casse, le bucket appartient ENTIÈREMENT au premier type
     *     déclarant dans l'ordre du catalogue de types : fusionner les
     *     déclarations de deux types produirait un vocabulaire qui n'est celui
     *     d'aucun des deux.
     *
     * @return array<string, ?string>
     */
    public static function declarationsFor(?string $groupType): array
    {
        $raw = $groupType === null ? '' : trim($groupType);

        if ($raw === '') {
            return [];
        }

        $maps = self::declarations();

        return $maps['exact'][$raw] ?? $maps['lower'][mb_strtolower($raw)] ?? [];
    }

    /**
     * Les deux index des déclarations, mémoïsés.
     *
     * @return array{exact: array<string, array<string, ?string>>, lower: array<string, array<string, ?string>>}
     */
    private static function declarations(): array
    {
        if (self::$declarationsMemo !== null) {
            return self::$declarationsMemo;
        }

        return self::$declarationsMemo = self::readDeclarations();
    }

    /**
     * Lecture BRUTE des déclarations, ordonnée par le rang du type déclarant.
     *
     * **Pourquoi elle est défensive, et pourquoi elle JOURNALISE.** Même raison
     * que {@see self::readCatalog()} : ce point de lecture est traversé par des
     * chemins antérieurs à la table (garde d'arête, validation de recette,
     * résolveur de plan), dont beaucoup de tests unitaires sur schéma fabriqué à
     * la main. Absence de table ⇒ aucune déclaration, donc régime de REPLI (tout
     * le catalogue attribuable, libellés génériques) — jamais une exception dans
     * un écran, et jamais un refus d'attribution causé par une panne. Review
     * 62.1 #2 : une panne de base ne doit pas être indiscernable d'une base non
     * migrée, on journalise donc la dégradation ; et le journal est lui-même gardé,
     * parce que ce chemin doit rester traversable sans application bootée.
     *
     * @return array{exact: array<string, array<string, ?string>>, lower: array<string, array<string, ?string>>}
     */
    private static function readDeclarations(): array
    {
        $empty = ['exact' => [], 'lower' => []];

        try {
            if (! Schema::hasTable('group_type_roles')) {
                return $empty;
            }

            $rank = [];
            if (Schema::hasTable('group_types')) {
                $position = 0;
                foreach (
                    DB::table('group_types')->orderBy('sort_order')->orderBy('id')->get(['key']) as $type
                ) {
                    $rank[(string) $type->key] = $position++;
                }
            }

            $rows = DB::table('group_type_roles')
                ->orderBy('id')
                ->get(['group_type_key', 'group_role_key', 'label'])
                ->all();

            // Ordre du CATALOGUE de types : c'est lui qui départage une homonymie
            // de casse. Une clé absente du catalogue (déclaration posée par une
            // migration sur un type disparu) passe en dernier, sans disparaître.
            usort($rows, static function (object $a, object $b) use ($rank): int {
                $rankA = $rank[(string) $a->group_type_key] ?? PHP_INT_MAX;
                $rankB = $rank[(string) $b->group_type_key] ?? PHP_INT_MAX;

                return [$rankA, (string) $a->group_type_key] <=> [$rankB, (string) $b->group_type_key];
            });

            $exact = [];
            $lower = [];
            $lowerOwner = [];

            foreach ($rows as $row) {
                $type = (string) $row->group_type_key;
                $role = (string) $row->group_role_key;
                $label = $row->label === null ? null : (string) $row->label;

                $exact[$type][$role] = $label;

                $lowerKey = mb_strtolower(trim($type));
                $lowerOwner[$lowerKey] ??= $type;

                if ($lowerOwner[$lowerKey] === $type) {
                    $lower[$lowerKey][$role] = $label;
                }
            }

            return ['exact' => $exact, 'lower' => $lower];
        } catch (Throwable $e) {
            try {
                Log::warning(
                    '[RoleCatalog] Déclarations de rôles par type de groupe illisibles — repli sur le régime '
                    . 'générique (tous les rôles attribuables, libellés du catalogue). Les libellés locaux et '
                    . 'la contrainte d\'attribution sont temporairement ignorés.',
                    ['exception' => $e->getMessage()],
                );
            } catch (Throwable) {
                // Aucune application bootée : le repli reste la bonne réponse.
            }

            return $empty;
        }
    }

    /**
     * Tous les rôles du catalogue et leur libellé pour ce type, dans l'ordre
     * d'affichage du catalogue.
     *
     * Destiné aux listes de choix : sans cette forme, chaque écran recopierait
     * l'ordre et retomberait dans le `match` local qu'on a supprimé en 60.2.
     *
     * @return array<string, string> clé stockée => libellé
     */
    public static function options(?string $groupType): array
    {
        $options = [];
        foreach (self::keys() as $role) {
            $options[$role] = self::label($groupType, $role);
        }

        return $options;
    }

    /**
     * `clé => libellé`, mémoïsé, ordre d'affichage, plancher garanti.
     *
     * @return array<string, string>
     */
    public static function rows(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $rows = [];
        foreach (self::readCatalog() as $key => $label) {
            $rows[$key] = $label;
        }

        // UNION avec le plancher : les clés historiques absentes de la table sont
        // ajoutées à la fin, avec leur libellé générique. Le vocabulaire minimal ne
        // disparaît jamais.
        foreach (self::HISTORICAL_KEYS as $key) {
            if (! array_key_exists($key, $rows)) {
                $rows[$key] = self::GENERIC_FALLBACK[$key];
            }
        }

        return self::$memo = $rows;
    }

    /**
     * Lecture BRUTE de la table, triée par rang puis par identité.
     *
     * **Pourquoi elle est défensive.** Le catalogue est consulté par des chemins
     * qui existaient AVANT lui : la garde d'arête, la validation d'une recette, le
     * résolveur de plan. Beaucoup de tests unitaires les exercent sur un schéma
     * fabriqué à la main, sans cette table ; une instance en cours de migration est
     * dans le même cas. Absence de table ⇒ plancher, sans exception qui remonterait
     * dans un écran. Ce n'est PAS un fail-open : le repli restreint au vocabulaire
     * historique, il n'autorise aucune valeur de plus.
     *
     * @return array<string, string>
     */
    private static function readCatalog(): array
    {
        try {
            if (! Schema::hasTable('group_roles')) {
                return [];
            }

            $catalog = [];
            foreach (GroupRole::orderBy('sort_order')->orderBy('id')->get(['key', 'label']) as $role) {
                $catalog[(string) $role->key] = (string) $role->label;
            }

            return $catalog;
        } catch (Throwable $e) {
            // Pas de connexion (test unitaire nu), schéma absent, migration en
            // cours : le plancher tient.
            //
            // Review 62.1 #2 — mais il tenait en SILENCE. Une vraie panne de base
            // en production (bascule de réplique, pool épuisé, droits révoqués)
            // empruntait exactement le même chemin qu'une base non migrée : les
            // rôles administrés disparaissaient du vocabulaire, un rôle
            // personnalisé se faisait rejeter, et aucune ligne de journal ne
            // permettait de relier le symptôme à l'incident. On journalise donc la
            // dégradation. Pas de risque d'inondation : `rows()` mémoïse même un
            // résultat vide, donc cette lecture n'a lieu qu'une fois par processus
            // (par flush). Le journal est lui-même gardé, parce que ce chemin doit
            // rester traversable par un test unitaire nu, sans application bootée.
            try {
                Log::warning(
                    '[RoleCatalog] Catalogue des rôles illisible — repli sur le plancher historique '
                    . '(member|manager|owner). Les rôles administrés sont temporairement ignorés.',
                    ['exception' => $e->getMessage()],
                );
            } catch (Throwable) {
                // Aucune application bootée : le repli reste la bonne réponse.
            }

            return [];
        }
    }
}
