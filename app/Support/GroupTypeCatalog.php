<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GroupType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Story 62.2 — LE POINT DE LECTURE UNIQUE du catalogue de types de groupes.
 *
 * Il remplace les `match` de libellés recopiés — et DIVERGENTS — que portaient la
 * fiche groupe, la fiche utilisateur et le tiroir de sélection : trois écrans, trois
 * vocabulaires, dont un qui rendait la valeur technique brute en description. La
 * source est désormais une table administrable ({@see \App\Models\GroupType}).
 *
 * **C'est ICI que vit la mémoïsation, et nulle part ailleurs.** Le vocabulaire est
 * consulté à chaque validation de groupe, à chaque rendu de liste, à chaque garde
 * d'accrochage. Une requête par appel serait un coût absurde pour une donnée qui
 * change une fois par trimestre. La mémo est vidée par les hooks `saved`/`deleted`
 * du modèle, par `Queue::before` (un worker enchaîne les jobs sans réinitialiser les
 * statiques — leçon de la review 62.1), et dans le `setUp()` des tests, parce qu'un
 * rollback de transaction ne dispatche aucun événement Eloquent.
 *
 * **Le PLANCHER n'est pas une politesse, c'est un invariant.** La lecture rend
 * TOUJOURS au moins les neuf clés statiques, quel que soit l'état de la base :
 * elles sont écrites en littéral par du code vivant — la détection de type au
 * balayage AD (`detectTypeFromAdGroupName()`), le défaut `custom` de deux
 * formulaires et du service, le fold des groupes legacy. Une base neuve, non migrée
 * ou non seedée ne doit jamais faire refuser `classe` par la garde d'accrochage ni
 * par la validation d'un groupe. Le repli RESTREINT au vocabulaire recensé : il
 * n'élargit rien, une valeur inconnue reste inconnue.
 *
 * **Ce que la classe ne fait PAS.** Elle n'est importée par AUCUN fichier du
 * namespace pur du plan de fichiers : `GroupNameNormalizer::TYPE_PREFIXES` reste la
 * connaissance LOCALE du normalizer (une table de préfixes de nommage, pas un
 * vocabulaire d'affichage), et un test d'architecture verrouille cette frontière.
 * Elle ne porte pas non plus de charte de couleurs : les `match` de badge restent
 * locaux aux vues, sur des clés immuables.
 */
final class GroupTypeCatalog
{
    /**
     * Le PLANCHER : les neuf clés statiques, dans l'ordre des `<select>`
     * historiques.
     *
     * @var list<string>
     */
    public const STATIC_KEYS = [
        'custom',
        'classe',
        'cours',
        'matiere',
        'matiere_classe',
        'projet',
        'equipe',
        'role',
        'function',
    ];

    /**
     * Libellés GÉNÉRIQUES de secours des neuf clés statiques.
     *
     * Ils sont IDENTIQUES aux libellés posés par la migration et le seeder : sur
     * une base migrée, ils ne servent jamais. Ils servent sur une base neuve, non
     * migrée ou non seedée — là où le plancher tient tout seul et où il faut bien
     * afficher quelque chose qui ne soit pas une valeur technique.
     *
     * @var array<string, string>
     */
    private const GENERIC_LABELS = [
        'custom' => 'Personnalisé',
        'classe' => 'Classe',
        'cours' => 'Cours',
        'matiere' => 'Matière',
        'matiere_classe' => 'Matière / Classe',
        'projet' => 'Projet',
        'equipe' => 'Équipe',
        'role' => 'Rôle',
        'function' => 'Fonction',
    ];

    /**
     * Icônes GÉNÉRIQUES de secours, identiques à celles posées par la migration.
     *
     * @var array<string, string>
     */
    private const GENERIC_ICONS = [
        'custom' => 'fa-solid fa-users',
        'classe' => 'fa-solid fa-graduation-cap',
        'cours' => 'fa-solid fa-book-open',
        'matiere' => 'fa-solid fa-book',
        'matiere_classe' => 'fa-solid fa-book-bookmark',
        'projet' => 'fa-solid fa-diagram-project',
        'equipe' => 'fa-solid fa-people-group',
        'role' => 'fa-solid fa-id-badge',
        'function' => 'fa-solid fa-briefcase',
    ];

    /** Icône rendue quand un type n'en déclare aucune, ou qu'il est inconnu. */
    public const DEFAULT_ICON = 'fa-solid fa-users';

    /**
     * Le libellé d'un type ABSENT, VIDE, ou du jeton LDAP `other_group`.
     *
     * **`other_group` n'est PAS une valeur de `user_groups.type`** : c'est le
     * vocabulaire de routage d'unité d'organisation de
     * {@see \App\Repositories\GroupRepository} (`mapTypeToLdap()`), qui n'a jamais
     * été stocké en SQL. Il ne se seed donc PAS. Les trois vues qui affichaient un
     * type le traitaient défensivement — on conserve ce littéral, parce qu'un
     * chemin de lecture qui rendrait « Other_group » à l'écran serait un pire
     * mensonge qu'un « Autre » générique.
     */
    public const UNKNOWN_LABEL = 'Autre';

    /** Le jeton LDAP qui n'est jamais une ligne du catalogue. */
    private const LDAP_ROUTING_TOKEN = 'other_group';

    /**
     * Mémo : `clé => ['label' => …, 'icon' => …]`, dans l'ordre d'affichage.
     *
     * @var array<string, array{label: string, icon: ?string}>|null
     */
    private static ?array $memo = null;

    /** Vide la mémoïsation. Appelée par les hooks d'écriture, la queue et les tests. */
    public static function flush(): void
    {
        self::$memo = null;
    }

    /**
     * Les clés du catalogue, dans l'ordre d'affichage, plancher inclus.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::rows());
    }

    /**
     * Libellé FR d'un type de groupe.
     *
     * Le repli est DÉFENSIF, jamais une valeur technique nue :
     *  - `null` ou chaîne vide → « Autre » (un groupe sans type est un accident de
     *    donnée, pas un type nommable) ;
     *  - `other_group` → « Autre » (jeton LDAP, voir {@see self::UNKNOWN_LABEL}) ;
     *  - toute autre valeur inconnue → `ucfirst`, exactement ce que rendaient les
     *    `match` locaux remplacés par cette story. La parité d'écran prime.
     */
    public static function label(?string $type): string
    {
        $key = $type === null ? '' : trim($type);

        if ($key === '' || $key === self::LDAP_ROUTING_TOKEN) {
            return self::UNKNOWN_LABEL;
        }

        $rows = self::rows();

        return $rows[$key]['label'] ?? ucfirst($key);
    }

    /**
     * Classe Font Awesome d'un type, icône générique comprise.
     *
     * Une valeur inconnue, vide ou sans icône déclarée rend
     * {@see self::DEFAULT_ICON} : une ligne de liste sans icône serait un trou
     * visuel, jamais une information.
     */
    public static function icon(?string $type): string
    {
        $key = $type === null ? '' : trim($type);

        if ($key === '') {
            return self::DEFAULT_ICON;
        }

        $icon = self::rows()[$key]['icon'] ?? null;

        return is_string($icon) && $icon !== '' ? $icon : self::DEFAULT_ICON;
    }

    /**
     * Tous les types du catalogue et leur libellé, dans l'ordre d'affichage.
     *
     * Destiné aux listes de choix : sans cette forme, chaque écran recopierait
     * l'ordre et retomberait dans les `<option>` en dur qu'on vient de supprimer.
     *
     * @return array<string, string> clé stockée => libellé
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::rows() as $key => $row) {
            $options[$key] = $row['label'];
        }

        return $options;
    }

    /**
     * `true` si cette valeur est un type du catalogue — comparaison INSENSIBLE À
     * LA CASSE.
     *
     * C'est la question que pose la garde d'accrochage de
     * {@see \App\Models\DirectoryTemplate}, et l'accrochage est normalisé en
     * minuscules à l'écriture depuis la story 60.5 tandis que `user_groups.type`,
     * lui, ne l'a jamais été : un catalogue contenant `Classe` (valeur découverte)
     * doit reconnaître un accrochage à `classe`. Comparer strictement ici
     * refuserait un accrochage qui s'apparie parfaitement.
     */
    public static function isKnown(?string $type): bool
    {
        $key = $type === null ? '' : trim($type);

        if ($key === '') {
            return false;
        }

        $needle = mb_strtolower($key);

        foreach (self::keys() as $known) {
            if (mb_strtolower($known) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * `clé => ['label', 'icon']`, mémoïsé, ordre d'affichage, plancher garanti.
     *
     * @return array<string, array{label: string, icon: ?string}>
     */
    public static function rows(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $rows = self::readCatalog();

        // UNION avec le plancher : les clés statiques absentes de la table sont
        // ajoutées à la fin, avec leur libellé et leur icône génériques. Le
        // vocabulaire minimal ne disparaît jamais.
        foreach (self::STATIC_KEYS as $key) {
            if (! array_key_exists($key, $rows)) {
                $rows[$key] = [
                    'label' => self::GENERIC_LABELS[$key],
                    'icon' => self::GENERIC_ICONS[$key],
                ];
            }
        }

        return self::$memo = $rows;
    }

    /**
     * Lecture BRUTE de la table, triée par rang puis par identité.
     *
     * **Pourquoi elle est défensive.** Le catalogue est consulté par des chemins
     * qui existaient AVANT lui : la validation d'un groupe, la garde d'accrochage
     * d'une recette, le rendu de trois écrans. Beaucoup de tests unitaires les
     * exercent sur un schéma fabriqué à la main, sans cette table ; une instance
     * en cours de migration est dans le même cas. Absence de table ⇒ plancher,
     * sans exception qui remonterait dans un écran. Ce n'est PAS un fail-open : le
     * repli restreint au vocabulaire recensé, il n'autorise aucune valeur de plus.
     *
     * @return array<string, array{label: string, icon: ?string}>
     */
    private static function readCatalog(): array
    {
        try {
            if (! Schema::hasTable('group_types')) {
                return [];
            }

            $catalog = [];
            foreach (GroupType::orderBy('sort_order')->orderBy('id')->get(['key', 'label', 'icon']) as $type) {
                $icon = $type->icon;
                $catalog[(string) $type->key] = [
                    'label' => (string) $type->label,
                    'icon' => is_string($icon) && $icon !== '' ? $icon : null,
                ];
            }

            return $catalog;
        } catch (Throwable $e) {
            // Pas de connexion (test unitaire nu), schéma absent, migration en
            // cours : le plancher tient. On JOURNALISE la dégradation — patron
            // établi en review 62.1 : sans ce journal, une vraie panne de base en
            // production (bascule de réplique, pool épuisé, droits révoqués)
            // empruntait exactement le même chemin qu'une base non migrée, et les
            // types administrés disparaissaient du vocabulaire sans une ligne de
            // trace. Pas de risque d'inondation : `rows()` mémoïse même un
            // résultat vide. Le journal est lui-même gardé, parce que ce chemin
            // doit rester traversable par un test unitaire nu.
            try {
                Log::warning(
                    '[GroupTypeCatalog] Catalogue des types de groupes illisible — repli sur le plancher '
                    . 'des neuf types recensés. Les types administrés sont temporairement ignorés.',
                    ['exception' => $e->getMessage()],
                );
            } catch (Throwable) {
                // Aucune application bootée : le repli reste la bonne réponse.
            }

            return [];
        }
    }
}
