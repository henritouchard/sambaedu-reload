<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GroupRole;
use App\Models\Pivot\UserGroupUserPivot;
use Illuminate\Support\Facades\Schema;
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
 * **Les libellés par TYPE de groupe sont encore du code, et c'est transitoire.**
 * La table {@see self::BY_GROUP_TYPE} est la recopie littérale de celle qui
 * mourait avec la classe supprimée. Elle devient une DONNÉE en story 62.3 (« le
 * catalogue de types de groupes porte ses propres libellés de rôle ») ; d'ici là,
 * elle prime sur le libellé générique du catalogue, exactement comme avant.
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
     * Libellés SPÉCIFIQUES par type de groupe — **donnée de la story 62.3**,
     * encore écrite en code ici.
     *
     * Le même `manager` se lit « Enseignant » dans une classe, « Porteur » dans un
     * projet, « Référent » dans une équipe. Une entrée absente retombe sur le
     * libellé du catalogue : inutile de recopier les trois rôles quand un seul se
     * dit autrement.
     *
     * @var array<string, array<string, string>>
     */
    private const BY_GROUP_TYPE = [
        'classe' => [
            UserGroupUserPivot::ROLE_MEMBER => 'Élève',
            UserGroupUserPivot::ROLE_MANAGER => 'Enseignant',
            UserGroupUserPivot::ROLE_OWNER => 'Professeur principal',
        ],
        'projet' => [
            UserGroupUserPivot::ROLE_MANAGER => 'Porteur',
        ],
        'equipe' => [
            UserGroupUserPivot::ROLE_MANAGER => 'Référent',
        ],
    ];

    /**
     * Mémo : `clé => libellé`, dans l'ordre d'affichage du catalogue.
     *
     * @var array<string, string>|null
     */
    private static ?array $memo = null;

    /** Vide la mémoïsation. Appelée par les hooks d'écriture et par les tests. */
    public static function flush(): void
    {
        self::$memo = null;
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
     */
    public static function label(?string $groupType, ?string $roleKey): string
    {
        $rows = self::rows();

        $role = ($roleKey !== null && isset($rows[$roleKey]))
            ? $roleKey
            : UserGroupUserPivot::ROLE_MEMBER;

        $type = $groupType === null ? '' : mb_strtolower(trim($groupType));

        return self::BY_GROUP_TYPE[$type][$role]
            ?? $rows[$role]
            ?? self::GENERIC_FALLBACK[$role]
            ?? $role;
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
        } catch (Throwable) {
            // Pas de connexion (test unitaire nu), schéma absent, migration en
            // cours : le plancher tient.
            return [];
        }
    }
}
