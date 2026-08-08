<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanAnchor;
use App\Enums\PlanNodeNature;
use App\Enums\RoleResolutionStrategy;
use App\Exceptions\Filesystem\InvalidTreeSpecException;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\GroupNameNormalizer;
use App\Services\Filesystem\Plan\PlanGrant;
use Illuminate\Database\Eloquent\Model;

/**
 * Story 34.3 — « template de répertoire » (recette d'échange préfabriquée).
 *
 * Lecture seule côté applicatif (peuplé par {@see Database\Seeders\DirectoryTemplateSeeder},
 * jamais édité en 34.3 — Q3 option B). Une recette décrit les RÔLES-cibles d'un
 * pattern métier récurrent ; {@see App\Services\Filesystem\DirectoryTemplateService}
 * lit `roles_spec` depuis la DB pour matérialiser un {@see NetworkShare} + ses
 * assignations par maille au bon niveau (le vocabulaire binaire des
 * assignations, traduit depuis les verbes de la recette — story 62.4).
 *
 * **Mailles autorisées dans une recette** : `User` et `UserGroup` UNIQUEMENT.
 * Aucune recette ne porte d'ACL sur un `WorkstationGroup` (invariant WG-montage-
 * seul de 34.1 : POSIX ne sait pas exprimer « les users de la machine X »).
 *
 * ---------------------------------------------------------------------------
 * Story 60.1 — la recette sait aussi décrire un ARBRE (`path_pattern` +
 * `nodes_spec`, colonnes additives NULLABLES). Le vocabulaire d'arbre vit À CÔTÉ
 * du vocabulaire de rôles, jamais à sa place : les octrois de nœud RÉFÉRENCENT
 * les rôles de `roles_spec` par leur `key`. Une recette sans arbre (les 4 recettes
 * seedées) se comporte exactement comme avant.
 *
 * ---------------------------------------------------------------------------
 * Story 60.2 — la recette sait aussi dire COMMENT chaque rôle trouve sa cible
 * (clé additive `resolution` dans `roles_spec`, {@see RoleResolutionStrategy}) et
 * À QUEL TYPE DE GROUPE elle s'accroche (colonne additive nullable
 * `attached_group_type`). L'absence de `resolution` vaut « cible désignée à la
 * matérialisation » : les 4 recettes seedées ne savent pas qu'il y a du nouveau.
 *
 * ---------------------------------------------------------------------------
 * Story 60.5 — la recette dit aussi DANS QUELLE ZONE son arbre vit (colonne
 * additive nullable `root_anchor`, {@see PlanAnchor}) — un jeton NEUTRE, jamais un
 * chemin. Et l'ACCROCHAGE change de portée : il vaut désormais « cette recette sait
 * se résoudre seule à partir d'un groupe de ce type », sans plus exiger un arbre.
 * Ce qu'une recette d'ARBRE accrochée gagne en plus, et elle seule, c'est la
 * MATÉRIALISATION AUTOMATIQUE à la création d'un groupe
 * ({@see materializesOnGroupCreation()}) — voir le docblock de cette méthode pour
 * la raison, qui est une histoire de 302 partages non demandés.
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string|null $description
 * @property array<int, array<string, mixed>> $roles_spec
 * @property string|null $path_pattern
 * @property array<int, array<string, mixed>>|null $nodes_spec
 * @property string|null $attached_group_type
 * @property string|null $root_anchor
 */
class DirectoryTemplate extends Model
{
    /** Clés stables des 4 recettes seedées (34.3 — `élèves → profs` reporté 34.x). */
    public const KEY_DIRECTION_TO_ALL = 'direction_to_all';
    public const KEY_PROFS_TO_ELEVES = 'profs_to_eleves';
    public const KEY_USER_TO_USER = 'user_to_user';
    public const KEY_GROUP_SPACE = 'group_space';

    /**
     * Story 60.5 — la 5ᵉ recette : le partage de classe historique, dit en
     * vocabulaire de plan et matérialisé dans la racine NEUVE.
     */
    public const KEY_CLASSE_SE4 = 'classe_se4';

    /**
     * Mailles autorisées dans une recette (sous-ensemble STRICT des
     * {@see NetworkShare::ALLOWED_ASSIGNABLE_TYPES} : pas de `WorkstationGroup`).
     */
    public const ALLOWED_ROLE_MAILLES = [
        User::class,
        UserGroup::class,
    ];

    /**
     * Story 60.1 — jeton RÉSERVÉ désignant, dans les octrois d'un nœud par membre,
     * le membre énuméré lui-même (octroi nominatif). Il commence par `@`, donc il
     * ne peut jamais entrer en collision avec une `key` de `roles_spec` (snake_case).
     *
     * Ce jeton n'étant PAS un rôle de recette, un octroi nominatif ne décharge
     * aucun rôle de la clôture d'un nœud : le dossier personnel d'un élève
     * n'accorde rien à la classe entière, et la clôture le dit.
     */
    public const TREE_ROLE_MEMBER = '@member';

    /** Vocabulaire de substitution FERMÉ du motif de chemin et des chemins de nœuds. */
    public const PLACEHOLDER_GROUP_NAME = 'group.name';

    public const PLACEHOLDER_GROUP_BARE_NAME = 'group.bare_name';

    public const PLACEHOLDER_MEMBER_LOGIN = 'member.login';

    /**
     * Story 60.2 — les deux moitiés du nom d'un groupe « matière × classe ».
     *
     * Résolvables UNIQUEMENT pour un groupe de type `matiere_classe`, dont le nom
     * (`Matiere_Maths@6A`) porte deux mailles séparées par un « @ » et n'est donc
     * PAS un segment de chemin sûr. Ailleurs, ces placeholders ne sont pas
     * fournis : la résolution échoue explicitement, comme tout placeholder non
     * résolvable. Voir {@see \App\Services\Filesystem\Plan\GroupNameNormalizer::matiereClasseParts()}
     * pour le pourquoi de la décomposition (plutôt que la normalisation du
     * séparateur, qui perd de l'information, ou l'exclusion, qui interdirait
     * d'accueillir les partages matières).
     */
    public const PLACEHOLDER_GROUP_MATIERE = 'group.matiere';

    public const PLACEHOLDER_GROUP_CLASSE = 'group.classe';

    /**
     * Placeholders admis dans le MOTIF de chemin et dans les chemins de nœuds
     * ordinaires. `member.login` en est EXCLU : il n'a de sens que dans un nœud
     * par membre, et sa présence ailleurs est une recette invalide.
     *
     * @var list<string>
     */
    public const TREE_PLACEHOLDERS = [
        self::PLACEHOLDER_GROUP_NAME,
        self::PLACEHOLDER_GROUP_BARE_NAME,
        self::PLACEHOLDER_GROUP_MATIERE,
        self::PLACEHOLDER_GROUP_CLASSE,
    ];

    /**
     * Story 60.2 — placeholders admis dans le MOTIF DE NOM d'un rôle en stratégie
     * `pattern`.
     *
     * Plus étroit que {@see TREE_PLACEHOLDERS} — et volontairement : un motif de
     * nom sert à retrouver un groupe APPARENTÉ, pas à fabriquer un chemin. Les
     * moitiés d'un nom « matière × classe » n'y ont rien à faire (un apparenté
     * d'une matière×classe n'existe pas comme ligne distincte), et `member.login`
     * encore moins.
     *
     * @var list<string>
     */
    public const RESOLUTION_PATTERN_PLACEHOLDERS = [
        self::PLACEHOLDER_GROUP_NAME,
        self::PLACEHOLDER_GROUP_BARE_NAME,
    ];

    /**
     * Clés ADMISES dans un nœud de `nodes_spec`. Liste FERMÉE, et c'est ce qui
     * rend la clôture (story 60.1) inauthorable : `closure`, `excluded_roles` ou
     * tout autre champ qui prétendrait la saisir est refusé à la validation.
     * L'auteur d'une recette n'a qu'un seul levier sur la clôture — écrire ou
     * retirer un octroi.
     *
     * @var list<string>
     */
    public const TREE_NODE_KEYS = ['path', 'label', 'nature', 'edge_role', 'grants', 'plafond', 'activable'];

    /**
     * Clés admises dans un octroi de nœud. Fermée pour la même raison : aucun
     * champ d'interdiction n'est exprimable.
     *
     * **Story 62.4 — `verbs` REMPLACE `access`, il ne cohabite pas avec lui.**
     * C'est le vocabulaire de clés FERMÉ qui rend une recette non migrée bruyante :
     * elle est refusée avec « champ inconnu », jamais lue de travers. Accepter les
     * deux clés « par tolérance » recréerait le JSON à deux vocabulaires que le
     * garde-fou d'epic interdit.
     *
     * @var list<string>
     */
    public const TREE_GRANT_KEYS = ['role', 'verbs', 'suspendable'];

    protected $table = 'directory_templates';

    protected $fillable = [
        'key',
        'label',
        'description',
        'roles_spec',
        'path_pattern',
        'nodes_spec',
        'attached_group_type',
        'root_anchor',
    ];

    protected $casts = [
        'roles_spec' => 'array',
        'nodes_spec' => 'array',
    ];

    /**
     * Rôles-cibles de la recette (liste ordonnée). Chaque rôle :
     * `{key, label, maille, group_type, access, cardinality}`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function roles(): array
    {
        return $this->roles_spec ?? [];
    }

    /**
     * Le rôle dont la `key` correspond, ou `null`.
     *
     * @return array<string, mixed>|null
     */
    public function role(string $key): ?array
    {
        foreach ($this->roles() as $role) {
            if (($role['key'] ?? null) === $key) {
                return $role;
            }
        }

        return null;
    }

    /**
     * `true` si aucun rôle ne porte une maille hors {@see ALLOWED_ROLE_MAILLES}
     * (invariant WG-montage-seul : une recette ne grant JAMAIS d'ACL sur un parc).
     */
    public function respectsMountOnlyInvariant(): bool
    {
        foreach ($this->roles() as $role) {
            if (! in_array($role['maille'] ?? null, self::ALLOWED_ROLE_MAILLES, true)) {
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // Story 60.2 — la règle de résolution d'un rôle
    // =========================================================================

    /**
     * Garde d'écriture de l'accrochage.
     *
     * Un accrochage invalide ne doit PAS pouvoir être persisté : la donnée
     * d'accrochage n'a pas d'écran (elle est seedée, story 60.5), donc aucune
     * validation de formulaire ne la protège. La garde vit ici, au seul endroit
     * par lequel toute écriture passe. Elle ne se déclenche QUE si un accrochage
     * est effectivement posé : les 4 recettes seedées, non accrochées, ne la
     * rencontrent jamais.
     */
    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            // Casse NORMALISÉE à l'écriture. L'accrochage s'apparie à
            // `user_groups.type`, dont les valeurs sont des littéraux minuscules
            // produits par un mappeur canonique — mais rien n'empêchait jusqu'ici
            // un accrochage saisi ou importé en « Classe » de ne JAMAIS s'apparier
            // à un groupe de type « classe ». Et l'échec aurait été SILENCIEUX :
            // `attachedTo()` rend `null`, qui est l'état normal d'un type sans
            // recette — donc indiscernable d'une absence légitime. La stratégie
            // par motif, elle, compare déjà en minuscules ; on aligne.
            $attached = $template->attachedGroupType();
            if ($attached !== null) {
                $template->attached_group_type = mb_strtolower($attached);
                $template->assertAttachable();
                $template->assertSingleTreeAttachment();
            }

            // La zone est du VOCABULAIRE, pas une chaîne libre : une valeur hors
            // table ferait résoudre le plan dans une zone que la garde de chemin ne
            // sait pas traduire — c'est-à-dire nulle part, mais découvert bien plus
            // tard, à l'exécution.
            $anchor = $template->root_anchor;
            if ($anchor !== null && ! PlanAnchor::isKnown($anchor)) {
                throw InvalidTreeSpecException::make(sprintf(
                    'zone logique inconnue « %s » sur la recette « %s » (attendu : %s).',
                    is_scalar($anchor) ? (string) $anchor : gettype($anchor),
                    (string) $template->key,
                    implode('|', PlanAnchor::values()),
                ));
            }
        });
    }

    /**
     * Story 60.5 — ZONE logique de l'arbre de cette recette, jeton NEUTRE.
     *
     * L'absence vaut la zone par défaut : les recettes qui ne se prononcent pas
     * vivent là où elles ont toujours vécu.
     */
    public function rootAnchor(): PlanAnchor
    {
        $raw = $this->root_anchor;

        return PlanAnchor::isKnown($raw) ? PlanAnchor::from((string) $raw) : PlanAnchor::default();
    }

    /**
     * Un type de groupe n'a qu'UNE recette d'arbre.
     *
     * Sans cette garde, `attachedTo()` devrait choisir entre deux arbres accrochés
     * au même type — et le plan d'un groupe dépendrait de l'ordre d'insertion en
     * base. Une ambiguïté silencieuse sur « quel arbre ce groupe matérialise » est
     * exactement le genre de question dont on ne se pose jamais qu'après.
     *
     * Les recettes PLATES ne sont pas concernées : plusieurs peuvent s'accrocher au
     * même type, puisque leur matérialisation reste un geste manuel et explicite.
     *
     * @throws InvalidTreeSpecException
     */
    public function assertSingleTreeAttachment(): void
    {
        if (! $this->hasTreeSpec()) {
            return;
        }

        $type = $this->attachedGroupType();
        if ($type === null) {
            return;
        }

        $rival = static::query()
            ->whereRaw('LOWER(attached_group_type) = ?', [mb_strtolower($type)])
            ->whereNotNull('path_pattern')
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
            ->when(! $this->exists && is_string($this->key), fn ($q) => $q->where('key', '!=', (string) $this->key))
            ->first();

        if ($rival !== null) {
            throw InvalidTreeSpecException::makeResolution(sprintf(
                'le type de groupe « %s » porte déjà la recette d\'arbre « %s » : un groupe ne matérialise '
                . 'qu\'un seul arbre, et deux candidates rendraient le choix dépendant de l\'ordre de la base.',
                $type,
                (string) $rival->key,
            ));
        }
    }

    /**
     * Règle de résolution d'un rôle, NORMALISÉE, défaut compris.
     *
     * L'absence de clé `resolution` vaut « cible désignée à la matérialisation » —
     * le comportement de la story 34.3, celui des 4 recettes livrées. La clé est
     * ADDITIVE : rien de ce qui existe ne change de sens.
     *
     * @param  array<string, mixed>  $role  un élément de `roles_spec`
     * @return array{strategy:RoleResolutionStrategy,edge_roles:list<string>,pattern:?string}
     *
     * @throws InvalidTreeSpecException règle mal formée
     */
    public function resolutionOf(array $role): array
    {
        $roleKey = is_string($role['key'] ?? null) ? (string) $role['key'] : '?';
        $raw = $role[RoleResolutionStrategy::SPEC_KEY] ?? null;

        if ($raw === null) {
            return ['strategy' => RoleResolutionStrategy::default(), 'edge_roles' => [], 'pattern' => null];
        }

        if (! is_array($raw)) {
            throw InvalidTreeSpecException::makeResolution(sprintf(
                'la règle du rôle « %s » doit être une structure portant au moins « %s ».',
                $roleKey,
                RoleResolutionStrategy::SPEC_STRATEGY_KEY,
            ));
        }

        $strategyRaw = $raw[RoleResolutionStrategy::SPEC_STRATEGY_KEY] ?? null;
        if (! RoleResolutionStrategy::isKnown($strategyRaw)) {
            throw InvalidTreeSpecException::makeResolution(sprintf(
                'stratégie inconnue « %s » sur le rôle « %s » (attendu : %s).',
                is_scalar($strategyRaw) ? (string) $strategyRaw : gettype($strategyRaw),
                $roleKey,
                implode('|', RoleResolutionStrategy::values()),
            ));
        }

        $strategy = RoleResolutionStrategy::from((string) $strategyRaw);

        $unknown = array_diff(array_keys($raw), $strategy->allowedSpecKeys());
        if ($unknown !== []) {
            throw InvalidTreeSpecException::makeResolution(sprintf(
                'champ(s) inconnu(s) « %s » dans la règle du rôle « %s » (vocabulaire fermé pour « %s » : %s).',
                implode(', ', array_map(strval(...), $unknown)),
                $roleKey,
                $strategy->value,
                implode(', ', $strategy->allowedSpecKeys()),
            ));
        }

        if ($strategy->requiresGroupMaille() && ($role['maille'] ?? null) !== UserGroup::class) {
            throw InvalidTreeSpecException::makeResolution(sprintf(
                'la stratégie « %s » du rôle « %s » désigne un GROUPE : elle exige la maille « %s ».',
                $strategy->value,
                $roleKey,
                UserGroup::class,
            ));
        }

        return [
            'strategy' => $strategy,
            'edge_roles' => $strategy === RoleResolutionStrategy::EdgeRole
                ? $this->validEdgeRoles($raw, $roleKey)
                : [],
            'pattern' => $strategy === RoleResolutionStrategy::Pattern
                ? $this->validResolutionPattern($raw, $roleKey)
                : null,
        ];
    }

    /**
     * Règle de résolution du rôle dont la `key` correspond.
     *
     * @return array{strategy:RoleResolutionStrategy,edge_roles:list<string>,pattern:?string}
     *
     * @throws InvalidTreeSpecException rôle absent de la recette, ou règle mal formée
     */
    public function resolutionForRole(string $roleKey): array
    {
        $role = $this->role($roleKey);
        if ($role === null) {
            throw InvalidTreeSpecException::makeResolution(sprintf(
                'le rôle « %s » est absent de la recette « %s ».',
                $roleKey,
                (string) $this->key,
            ));
        }

        return $this->resolutionOf($role);
    }

    /**
     * Valide les règles de résolution de TOUS les rôles.
     *
     * Vit à côté d'{@see assertValidTreeSpec()} et non dedans, parce qu'une
     * recette SANS arbre porte quand même des rôles : la règle est un attribut du
     * rôle, comme sa maille. `assertValidTreeSpec()` l'appelle néanmoins — une
     * recette qu'on s'apprête à résoudre doit être valide des deux côtés.
     *
     * @throws InvalidTreeSpecException
     */
    public function assertValidResolutionSpec(): void
    {
        foreach ($this->roles() as $role) {
            if (is_array($role)) {
                $this->resolutionOf($role);
                $this->assertValidRoleVerbs($role);
            }
        }
    }

    /**
     * Story 62.4 — les verbes portés par un RÔLE de recette (`roles_spec[].verbs`).
     *
     * Deux exigences, et la première est la plus utile : l'ancienne clé `access`
     * est refusée NOMMÉMENT. `roles_spec` n'a pas de vocabulaire de clés fermé
     * (les rôles portent librement `key`, `label`, `maille`, `group_type`,
     * `cardinality`, `resolution`…), donc rien n'aurait signalé une recette non
     * migrée : elle aurait simplement été lue avec les verbes par défaut, et un
     * rôle en écriture serait devenu un rôle en lecture SANS UN MOT. C'est
     * exactement la classe de silence que l'epic traque.
     *
     * L'absence de `verbs`, elle, reste licite et vaut `lire` — c'est le plancher
     * historique d'un rôle qui ne se prononce pas.
     *
     * @param  array<string, mixed>  $role
     *
     * @throws InvalidTreeSpecException
     */
    private function assertValidRoleVerbs(array $role): void
    {
        $what = sprintf(
            'le rôle « %s » de la recette « %s »',
            is_scalar($role['key'] ?? null) ? (string) $role['key'] : '?',
            (string) $this->key,
        );

        if (array_key_exists('access', $role)) {
            throw InvalidTreeSpecException::make(sprintf(
                '%s porte le vocabulaire ABANDONNÉ « access » : les droits se disent désormais en verbes '
                . '(clé « verbs », valeurs %s). Cette recette n\'a pas été migrée.',
                ucfirst($what),
                implode('|', PlanGrant::VERBS),
            ));
        }

        if (array_key_exists('verbs', $role)) {
            self::assertValidVerbList($role['verbs'], $what);
        }
    }

    /**
     * Story 62.4 — garde COMMUNE aux deux endroits où une recette écrit des
     * droits : les verbes d'un rôle et les verbes d'un octroi de nœud.
     *
     * Refuse : le scalaire nu (l'ancien `'rw'` recopié), la liste vide, le verbe
     * inconnu, le doublon. Les messages suivent le patron des autres gardes de
     * recette — ils nomment ce qui est attendu, pas seulement ce qui est faux.
     *
     * @throws InvalidTreeSpecException
     */
    private static function assertValidVerbList(mixed $verbs, string $what): void
    {
        if (! is_array($verbs)) {
            throw InvalidTreeSpecException::make(sprintf(
                '%s ne porte pas une LISTE de verbes (attendu : une liste parmi %s — le plan ne connaît '
                . 'aucun mode système, et un scalaire nu appartient au vocabulaire abandonné).',
                ucfirst($what),
                implode('|', PlanGrant::VERBS),
            ));
        }

        if ($verbs === []) {
            throw InvalidTreeSpecException::make(sprintf(
                '%s ne porte AUCUN verbe : une liste vide n\'est pas un octroi (attendu : au moins un de %s).',
                ucfirst($what),
                implode('|', PlanGrant::VERBS),
            ));
        }

        $seen = [];
        foreach ($verbs as $verb) {
            if (! is_string($verb) || ! in_array($verb, PlanGrant::VERBS, true)) {
                throw InvalidTreeSpecException::make(sprintf(
                    'verbe « %s » inconnu sur %s (attendu : %s).',
                    is_scalar($verb) ? (string) $verb : gettype($verb),
                    $what,
                    implode('|', PlanGrant::VERBS),
                ));
            }
            if (isset($seen[$verb])) {
                throw InvalidTreeSpecException::make(sprintf(
                    'le verbe « %s » est écrit deux fois sur %s — une liste de verbes est un ENSEMBLE.',
                    $verb,
                    $what,
                ));
            }
            $seen[$verb] = true;
        }
    }

    /**
     * `true` si CHAQUE rôle sait trouver sa cible sans qu'on la lui demande.
     *
     * C'est la condition pour qu'un groupe puisse matérialiser son arbre tout
     * seul : un rôle en cible désignée attend une saisie, et il n'y a personne
     * pour saisir à la création d'un groupe.
     *
     * @throws InvalidTreeSpecException règle mal formée
     */
    public function isAutoResolvable(): bool
    {
        foreach ($this->roles() as $role) {
            if (is_array($role) && ! $this->resolutionOf($role)['strategy']->isAutoResolvable()) {
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // Story 60.2 — l'accrochage à un type de groupe
    // =========================================================================

    /**
     * Type de groupe auquel cette recette est accrochée, ou `null` (le cas
     * normal : l'accrochage est l'exception).
     */
    public function attachedGroupType(): ?string
    {
        $type = $this->attached_group_type;

        return is_string($type) && trim($type) !== '' ? trim($type) : null;
    }

    /**
     * La recette d'ARBRE accrochée à `$groupType`, ou `null` si ce type n'en a
     * aucune.
     *
     * `null` est l'état NORMAL de la quasi-totalité des types : ce n'est pas une
     * anomalie, et l'appelant ne doit pas la traiter comme telle.
     *
     * **Pourquoi « d'arbre » est dans le nom du résultat, story 60.5.** Depuis que
     * l'accrochage vaut « sait se résoudre seule », PLUSIEURS recettes peuvent
     * s'accrocher au même type — sur le type `classe`, l'arbre du partage de classe
     * et la recette plate « profs → élèves » y sont toutes les deux. Cette méthode
     * sert la chaîne groupe → arbre : lui laisser rendre « la première venue »
     * aurait fait dépendre le plan d'un ordre d'insertion en base. Elle rend donc
     * la recette d'arbre, et la garde d'écriture ci-dessus interdit qu'il y en ait
     * deux pour un même type. Pour l'éligibilité d'une recette au flux manuel, la
     * question ne se pose pas : on part de la recette, pas du type.
     */
    public static function attachedTo(string $groupType): ?self
    {
        $type = trim($groupType);
        if ($type === '') {
            return null;
        }

        // Appariement INSENSIBLE À LA CASSE, comme la résolution par motif. Les
        // valeurs stockées sont normalisées en minuscules à l'écriture ; on
        // normalise aussi l'aiguille, parce que `user_groups.type` provient de
        // plusieurs chemins d'écriture et qu'un désaccord de casse produirait un
        // « pas de recette » silencieux au lieu d'un accrochage.
        return static::query()
            ->whereRaw('LOWER(attached_group_type) = ?', [mb_strtolower($type)])
            ->whereNotNull('path_pattern')
            ->orderBy('id')
            ->first();
    }

    /**
     * Story 60.5 — `true` si la CRÉATION d'un groupe de ce type doit matérialiser
     * cette recette toute seule.
     *
     * **La propriété des seules recettes d'ARBRE, et c'est délibéré.** Deux
     * recettes s'accrochent au type `classe` depuis cette story. Si la création de
     * groupe matérialisait toute recette accrochée, chaque classe naîtrait avec un
     * partage plat « profs → élèves » que personne n'a demandé — une matérialisation
     * de masse par surprise, sur les 302 classes d'une instance en place.
     *
     * Accrochage = ÉLIGIBILITÉ à l'auto-résolution (le flux manuel n'a plus qu'un
     * groupe à demander) ; matérialisation automatique = propriété de l'arbre.
     */
    public function materializesOnGroupCreation(): bool
    {
        return $this->attachedGroupType() !== null && $this->hasTreeSpec();
    }

    /**
     * Vérifie qu'une recette PEUT s'accrocher à un type de groupe.
     *
     * **Une seule condition depuis la story 60.5 : être AUTO-RÉSOLVABLE** (aucun
     * rôle en cible désignée). L'accrochage dit « à partir d'un groupe de ce type,
     * cette recette sait trouver toutes ses cibles seule » — c'est vrai d'un arbre
     * comme d'un partage plat, et c'est ce qui permet au flux « créer depuis une
     * recette » de ne plus demander qu'UN groupe.
     *
     * **Ce que l'accrochage n'implique plus** : la matérialisation automatique à la
     * création d'un groupe. Elle reste réservée aux recettes d'arbre
     * ({@see materializesOnGroupCreation()}). Exiger un arbre ICI, comme le faisait
     * la story 60.2, revenait à confondre les deux — et interdisait de réparer une
     * recette plate dont les rôles savent pourtant se résoudre.
     *
     * @throws InvalidTreeSpecException
     */
    public function assertAttachable(): void
    {
        $this->assertValidResolutionSpec();

        foreach ($this->roles() as $role) {
            if (! is_array($role)) {
                continue;
            }

            $strategy = $this->resolutionOf($role)['strategy'];
            if (! $strategy->isAutoResolvable()) {
                throw InvalidTreeSpecException::makeResolution(sprintf(
                    'le rôle « %s » de la recette « %s » est en stratégie « %s » : sa cible se choisit à la '
                    . 'matérialisation, donc la création d\'un groupe ne pourrait pas matérialiser son arbre seule.',
                    is_string($role['key'] ?? null) ? (string) $role['key'] : '?',
                    (string) $this->key,
                    $strategy->value,
                ));
            }
        }
    }

    /**
     * Rôles d'arête visés par une règle `edge_role` : liste NON VIDE de rôles tous
     * connus, sans doublon.
     *
     * Une liste vide serait une audience vide écrite en toutes lettres — donc un
     * rôle qui n'octroie jamais rien, ce qui se dit déjà en n'écrivant pas
     * d'octroi. Un doublon émettrait deux fois le même sujet abstrait.
     *
     * @param  array<string, mixed>  $raw
     * @return list<string>
     *
     * @throws InvalidTreeSpecException
     */
    private function validEdgeRoles(array $raw, string $roleKey): array
    {
        $edgeRoles = $raw[RoleResolutionStrategy::SPEC_EDGE_ROLES_KEY] ?? null;

        if (! is_array($edgeRoles) || $edgeRoles === []) {
            throw InvalidTreeSpecException::makeResolution(sprintf(
                'le rôle « %s » en stratégie « %s » doit lister au moins un rôle d\'arête (%s).',
                $roleKey,
                RoleResolutionStrategy::EdgeRole->value,
                implode('|', GroupNameNormalizer::edgeRoles()),
            ));
        }

        $normalized = [];
        foreach ($edgeRoles as $edgeRole) {
            if (! GroupNameNormalizer::isKnownEdgeRole($edgeRole)) {
                throw InvalidTreeSpecException::makeResolution(sprintf(
                    'rôle d\'arête inconnu « %s » sur le rôle « %s » (attendu : %s).',
                    is_scalar($edgeRole) ? (string) $edgeRole : gettype($edgeRole),
                    $roleKey,
                    implode('|', GroupNameNormalizer::edgeRoles()),
                ));
            }
            if (in_array($edgeRole, $normalized, true)) {
                throw InvalidTreeSpecException::makeResolution(sprintf(
                    'le rôle d\'arête « %s » est listé deux fois sur le rôle « %s » — une audience, un sujet.',
                    (string) $edgeRole,
                    $roleKey,
                ));
            }
            $normalized[] = (string) $edgeRole;
        }

        return $normalized;
    }

    /**
     * Motif de nom d'une règle `pattern` : chaîne non vide dont les placeholders
     * appartiennent au vocabulaire FERMÉ {@see RESOLUTION_PATTERN_PLACEHOLDERS}.
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws InvalidTreeSpecException
     */
    private function validResolutionPattern(array $raw, string $roleKey): string
    {
        $pattern = $raw[RoleResolutionStrategy::SPEC_PATTERN_KEY] ?? null;

        if (! is_string($pattern) || trim($pattern) === '') {
            throw InvalidTreeSpecException::makeResolution(sprintf(
                'le rôle « %s » en stratégie « %s » doit porter un motif de nom non vide.',
                $roleKey,
                RoleResolutionStrategy::Pattern->value,
            ));
        }

        preg_match_all('/\{([^{}]*)\}/', $pattern, $matches);
        foreach ($matches[1] as $placeholder) {
            if (! in_array($placeholder, self::RESOLUTION_PATTERN_PLACEHOLDERS, true)) {
                throw InvalidTreeSpecException::makeResolution(sprintf(
                    'placeholder inconnu « {%s} » dans le motif de nom du rôle « %s » (vocabulaire fermé : %s).',
                    $placeholder,
                    $roleKey,
                    implode(', ', array_map(
                        static fn (string $p): string => '{' . $p . '}',
                        self::RESOLUTION_PATTERN_PLACEHOLDERS,
                    )),
                ));
            }
        }

        return $pattern;
    }

    // =========================================================================
    // Story 60.1 — l'arbre : motif de chemin + nœuds
    // =========================================================================

    /**
     * Motif de chemin RELATIF avec substitution, ex. `Classes/Classe_{group.bare_name}`,
     * ou `null` pour une recette sans arbre. Jamais un chemin absolu : la racine
     * réelle est un savoir de backend, pas une donnée de recette.
     */
    public function pathPattern(): ?string
    {
        $pattern = $this->path_pattern;

        return is_string($pattern) && trim($pattern) !== '' ? $pattern : null;
    }

    /**
     * Nœuds de la recette (liste ORDONNÉE, telle qu'écrite). Tableau vide pour une
     * recette sans arbre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function nodes(): array
    {
        $nodes = $this->nodes_spec;

        return is_array($nodes) ? array_values($nodes) : [];
    }

    /**
     * `true` si la recette porte un arbre. Les 4 recettes seedées répondent
     * `false` : elles se matérialisent exactement comme avant.
     */
    public function hasTreeSpec(): bool
    {
        return $this->pathPattern() !== null;
    }

    /**
     * Valide la RECETTE STOCKÉE (motif + nœuds). Ne valide RIEN des données de
     * résolution : un groupe au nom impossible est le problème du résolveur, pas
     * de la recette. Les deux validations ne se mélangent pas.
     *
     * Une recette sans arbre est valide par définition (rien à valider).
     *
     * @throws InvalidTreeSpecException
     */
    public function assertValidTreeSpec(): void
    {
        // Story 60.2 — les deux volets de la MÊME recette : une recette qu'on
        // s'apprête à résoudre doit être valide de l'arbre ET de la règle par
        // laquelle chaque rôle trouve sa cible. Les 4 recettes seedées ne portent
        // aucune règle : elles restent valides sans modification.
        $this->assertValidResolutionSpec();

        $pattern = $this->pathPattern();
        $nodes = $this->nodes();

        if ($pattern === null && $nodes === []) {
            return;
        }
        if ($pattern === null) {
            throw InvalidTreeSpecException::make('des nœuds sont déclarés sans motif de chemin.');
        }

        $this->assertValidPathTemplate($pattern, 'le motif de chemin', memberAllowed: false);

        if (! $this->respectsMountOnlyInvariant()) {
            // Garde-fou : les octrois d'arbre référencent des rôles dont les
            // mailles sont déjà bornées à `User`/`UserGroup`. Si la recette porte
            // une maille de parc, aucun octroi d'arbre ne doit pouvoir en hériter.
            throw InvalidTreeSpecException::make(
                'un rôle porte une maille non autorisée (parc) — un octroi d\'arbre ne peut jamais viser un parc.'
            );
        }

        $roleKeys = [];
        foreach ($this->roles() as $role) {
            $key = $role['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $roleKeys[] = $key;
            }
        }

        $seenPaths = [];
        /** @var array<string, array{nature: PlanNodeNature, edge_role: ?string, grants: array<int, mixed>}> $declared */
        $declared = [];
        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                throw InvalidTreeSpecException::make(sprintf('le nœud #%d n\'est pas une structure.', (int) $index));
            }

            $unknown = array_diff(array_keys($node), self::TREE_NODE_KEYS);
            if ($unknown !== []) {
                throw InvalidTreeSpecException::make(sprintf(
                    'champ(s) inconnu(s) « %s » sur le nœud #%d (vocabulaire fermé : %s). '
                    . 'La clôture d\'un nœud est DÉRIVÉE de ses octrois : elle ne se saisit pas.',
                    implode(', ', array_map(strval(...), $unknown)),
                    (int) $index,
                    implode(', ', self::TREE_NODE_KEYS),
                ));
            }

            $path = (string) ($node['path'] ?? '');
            $label = $node['label'] ?? null;
            $natureRaw = $node['nature'] ?? null;

            if ($path === '') {
                throw InvalidTreeSpecException::make(sprintf('le nœud #%d n\'a pas de chemin.', (int) $index));
            }
            if (! is_string($label) || trim($label) === '') {
                throw InvalidTreeSpecException::make(sprintf('le nœud « %s » n\'a pas de libellé.', $path));
            }
            if (! PlanNodeNature::isKnown($natureRaw)) {
                throw InvalidTreeSpecException::make(sprintf(
                    'nature inconnue « %s » sur le nœud « %s » (attendu : %s).',
                    is_scalar($natureRaw) ? (string) $natureRaw : gettype($natureRaw),
                    $path,
                    implode('|', PlanNodeNature::values()),
                ));
            }

            $nature = PlanNodeNature::from((string) $natureRaw);

            if (array_key_exists('activable', $node) && (bool) $node['activable'] !== ($nature === PlanNodeNature::Activable)) {
                throw InvalidTreeSpecException::make(sprintf(
                    'le drapeau « activable » du nœud « %s » contredit sa nature — la nature est la source unique.',
                    $path,
                ));
            }

            $isRootNode = $path === GroupNameNormalizer::ROOT_NODE_PATH;

            // Story 60.5 — LE JETON RACINE s'ouvre au vocabulaire de recette.
            //
            // Sans lui, la racine d'un arbre n'a aucun octroi exprimable, et le
            // partage de classe historique — dont la racine porte la traversée de
            // l'équipe et de la classe — n'est tout simplement pas dicible. Le
            // jeton reste un JETON : hors de la position racine, « . » demeure
            // interdit partout (le motif de segment le refuse déjà), et un nœud par
            // membre ne peut pas l'être — il doit porter le nom du membre.
            if ($isRootNode) {
                if ($nature === PlanNodeNature::ParMembre) {
                    throw InvalidTreeSpecException::make(sprintf(
                        'le jeton racine « %s » ne peut pas désigner un nœud par membre : la racine d\'un '
                        . 'arbre est UN dossier, pas un dossier par personne.',
                        GroupNameNormalizer::ROOT_NODE_PATH,
                    ));
                }
            } else {
                $this->assertValidPathTemplate(
                    $path,
                    sprintf('le chemin du nœud « %s »', $path),
                    memberAllowed: $nature === PlanNodeNature::ParMembre,
                );
            }

            $hasMemberPlaceholder = str_contains($path, '{' . self::PLACEHOLDER_MEMBER_LOGIN . '}');
            if ($nature === PlanNodeNature::ParMembre && ! $hasMemberPlaceholder) {
                throw InvalidTreeSpecException::make(sprintf(
                    'le nœud par membre « %s » doit porter « {%s} » dans son chemin (un dossier par membre, pas un dossier partagé).',
                    $path,
                    self::PLACEHOLDER_MEMBER_LOGIN,
                ));
            }

            if ($nature === PlanNodeNature::ParMembre) {
                if (! GroupNameNormalizer::isKnownEdgeRole($node['edge_role'] ?? null)) {
                    throw InvalidTreeSpecException::make(sprintf(
                        'le nœud par membre « %s » doit déclarer un rôle d\'arête connu (%s).',
                        $path,
                        implode('|', GroupNameNormalizer::edgeRoles()),
                    ));
                }
            } elseif (array_key_exists('edge_role', $node) && $node['edge_role'] !== null) {
                throw InvalidTreeSpecException::make(sprintf(
                    'le nœud « %s » déclare un rôle d\'arête alors qu\'il n\'énumère aucun membre.',
                    $path,
                ));
            }

            $plafond = $node['plafond'] ?? null;
            if ($plafond !== null && (! is_int($plafond) || $plafond <= 0)) {
                throw InvalidTreeSpecException::make(sprintf(
                    'le plafond du nœud « %s » doit être un nombre d\'octets strictement positif (ou absent).',
                    $path,
                ));
            }

            $this->assertValidNodeGrants($node['grants'] ?? [], $path, $nature, $roleKeys);

            if (isset($seenPaths[$path])) {
                throw InvalidTreeSpecException::make(sprintf('le chemin de nœud « %s » est déclaré deux fois.', $path));
            }
            $seenPaths[$path] = true;

            $declared[$path] = [
                'nature' => $nature,
                'edge_role' => $nature === PlanNodeNature::ParMembre ? (string) $node['edge_role'] : null,
                'grants' => is_array($node['grants'] ?? null) ? $node['grants'] : [],
            ];
        }

        // Story 62.5 — LES RÈGLES PARENT→ENFANT, APRÈS la boucle et jamais avant.
        //
        // Elles ne portent que sur des nœuds DÉJÀ individuellement valides. L'ordre
        // n'est pas cosmétique : une recette qui écrit `depots/{member.login}` sur un
        // nœud partagé doit s'entendre dire que le jeton du membre n'a rien à faire
        // là — pas que son ancêtre « depots » n'est pas déclaré. Le second message
        // est vrai, il est simplement moins utile, et il volerait la place du premier.
        $this->assertReachableTree($declared);
    }

    /**
     * Story 62.5 — **UN OCTROI QUE PERSONNE NE PEUT ATTEINDRE EST REFUSÉ À
     * L'ÉCRITURE.**
     *
     * Le compilateur travaille nœud par nœud : il ne voit jamais l'arbre. Une
     * recette peut donc être parfaitement « conforme » nœud à nœud et décrire un
     * dossier auquel personne n'arrive — parce que la pose de son ancêtre le referme
     * pour tout le monde, ou parce que cet ancêtre n'est gouverné par personne. Le
     * couloir d'accès dérivé
     * ({@see \App\Services\Filesystem\Backend\Posix\PosixTraversalPlanner}) répare
     * le premier cas pour les AUDIENCES ; ces quatre règles ferment tout le reste,
     * STATIQUEMENT, et nomment le chemin fautif.
     *
     * @param  array<string, array{nature: PlanNodeNature, edge_role: ?string, grants: array<int, mixed>}>  $declared
     *
     * @throws InvalidTreeSpecException
     */
    private function assertReachableTree(array $declared): void
    {
        foreach ($declared as $path => $node) {
            // RÈGLE 1 — chaque préfixe strict doit être un nœud DÉCLARÉ.
            //
            // Un ancêtre non déclaré est un dossier HORS CONTRAT : aucune entrée ne
            // peut y vivre, l'inspection ne le regarde pas, et la pose récursive de
            // l'ancêtre déclaré le plus proche le referme pour tout le monde. Le
            // nœud profond est alors soit inatteignable, soit gouverné par personne
            // — les deux sont des mensonges de recette.
            //
            // **Le nœud racine n'est PAS exigé**, et c'est calibré sur l'existant :
            // une recette peut n'avoir que des nœuds de premier niveau, sans jamais
            // se prononcer sur la racine de son propre partage. C'est l'état livré
            // par la story 60.5 — hors contrat, mais pas aggravé ici. La règle ne
            // porte donc que sur les préfixes STRICTS, jamais sur la racine.
            foreach (self::strictAncestorsOf($path) as $ancestor) {
                if (! array_key_exists($ancestor, $declared)) {
                    throw InvalidTreeSpecException::make(sprintf(
                        'le nœud « %s » est inatteignable : son ancêtre « %s » n\'est pas un nœud de la recette.',
                        $path,
                        $ancestor,
                    ));
                }
            }

            // Les ancêtres qui GOUVERNENT ce nœud. La racine, quand elle est
            // déclarée, en fait partie : elle n'est pas un préfixe de chaîne, mais
            // elle est bel et bien le dossier par lequel on passe pour atteindre
            // n'importe quel nœud — et c'est elle qui porte, dans la recette de
            // classe, l'audience par laquelle les élèves atteignent leur dossier
            // personnel. L'oublier ici aurait rendu la règle 4 vide de sens sur la
            // seule recette d'arbre livrée.
            $governing = self::strictAncestorsOf($path);
            if ($path !== GroupNameNormalizer::ROOT_NODE_PATH
                && array_key_exists(GroupNameNormalizer::ROOT_NODE_PATH, $declared)) {
                array_unshift($governing, GroupNameNormalizer::ROOT_NODE_PATH);
            }

            foreach ($governing as $ancestor) {
                // RÈGLE 2 — rien ne se déclare sous un contenu libre.
                if ($declared[$ancestor]['nature'] === PlanNodeNature::ContenuLibre) {
                    throw InvalidTreeSpecException::make(sprintf(
                        'le nœud « %s » vit sous « %s », dont le contenu n\'est pas gouverné par le plan : '
                        . 'y déclarer un nœud est une contradiction.',
                        $path,
                        $ancestor,
                    ));
                }

                // RÈGLE 3 — deux énumérations de membres qui ne parlent pas des
                // mêmes personnes ne s'imbriquent pas.
                //
                // Un nœud NON par membre sous un nœud par membre est déjà impossible
                // par construction (son chemin porterait le jeton du membre, interdit
                // hors d'un nœud par membre) — un test l'épingle plutôt que de le
                // croire. Reste le cas qui, lui, est écrivable : deux nœuds par
                // membre imbriqués visant des rôles d'arête DIFFÉRENTS. Le dossier
                // d'un membre du second n'existe pas chez le premier.
                if ($declared[$ancestor]['nature'] === PlanNodeNature::ParMembre
                    && $node['nature'] === PlanNodeNature::ParMembre
                    && $declared[$ancestor]['edge_role'] !== $node['edge_role']) {
                    throw InvalidTreeSpecException::make(sprintf(
                        'le nœud par membre « %s » (rôle d\'arête « %s ») vit sous le nœud par membre « %s », '
                        . 'qui énumère les membres portant « %s » : l\'ancêtre n\'existe pas pour ces '
                        . 'personnes-là.',
                        $path,
                        (string) $node['edge_role'],
                        $ancestor,
                        (string) $declared[$ancestor]['edge_role'],
                    ));
                }

                // RÈGLE 4 — LA CONTREPARTIE STATIQUE DU « LE NOMINATIF NE DÉRIVE
                // PAS ».
                //
                // Un dossier par membre n'obtient AUCUN couloir dérivé : le dériver
                // poserait une entrée nominative par personne sur chaque ancêtre
                // partagé. L'atteignabilité de ces dossiers repose donc entièrement
                // sur une AUDIENCE qui, par construction, contient les membres visés
                // — le groupe lui-même, ou les porteurs du même rôle d'arête. Sans
                // elle, chaque élève aurait un dossier personnel dont il ne
                // franchirait jamais la porte d'entrée.
                if ($node['nature'] === PlanNodeNature::ParMembre
                    && ! $this->coversEdgeRole($declared[$ancestor]['grants'], (string) $node['edge_role'])) {
                    throw InvalidTreeSpecException::make(sprintf(
                        'le nœud par membre « %s » est inatteignable : son ancêtre « %s » n\'octroie rien à '
                        . 'une audience qui contient les membres portant le rôle d\'arête « %s » (attendu : un '
                        . 'octroi à un rôle résolu par « %s », ou par « %s » listant « %s »).',
                        $path,
                        $ancestor,
                        (string) $node['edge_role'],
                        RoleResolutionStrategy::Itself->value,
                        RoleResolutionStrategy::EdgeRole->value,
                        (string) $node['edge_role'],
                    ));
                }
            }
        }
    }

    /**
     * Les préfixes STRICTS d'un chemin de nœud, du plus proche de la racine au plus
     * proche du nœud, RACINE EXCLUE.
     *
     * Le jeton racine n'est pas un segment : il n'a pas d'ancêtre, et il n'apparaît
     * jamais dans cette liste (voir le pourquoi à la règle 1). Les chemins sont ceux
     * de la RECETTE — placeholders compris — ce qui est exactement la bonne maille :
     * la substitution est déterministe, donc deux chemins de recette qui se
     * préfixent se préfixent aussi une fois résolus.
     *
     * @return list<string>
     */
    private static function strictAncestorsOf(string $path): array
    {
        if ($path === GroupNameNormalizer::ROOT_NODE_PATH) {
            return [];
        }

        $segments = explode('/', $path);
        array_pop($segments);

        $ancestors = [];
        $current = '';
        foreach ($segments as $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;
            $ancestors[] = $current;
        }

        return $ancestors;
    }

    /**
     * Les octrois de ce nœud contiennent-ils une audience qui, PAR CONSTRUCTION,
     * couvre les membres portant ce rôle d'arête ?
     *
     * Deux règles de résolution seulement le garantissent : le groupe LUI-MÊME (tous
     * ses membres, quel que soit leur rôle d'arête) et les PORTEURS DU MÊME rôle
     * d'arête. Un groupe apparenté par motif ou une cible désignée à la
     * matérialisation ne disent rien de qui en fait partie — les accepter reviendrait
     * à valider une atteignabilité qu'on espère au lieu de la garantir. Le jeton du
     * membre énuméré ne compte pas non plus : il n'est pas une audience, il est UNE
     * personne, et il ne vit que sur le nœud par membre lui-même.
     *
     * @param  array<int, mixed>  $grants
     */
    /**
     * L'octroi accorde-t-il la lecture ? (Review 62.5 #2.)
     *
     * L'absence de `verbs` vaut « lire » — plancher historique d'un rôle qui ne se
     * prononce pas, posé en 62.4 et conservé ici sans exception.
     *
     * @param  array<string, mixed>  $grant
     */
    private function grantCarriesRead(array $grant): bool
    {
        if (! array_key_exists('verbs', $grant)) {
            return true;
        }

        return is_array($grant['verbs'])
            && in_array(PlanGrant::VERB_LIRE, $grant['verbs'], true);
    }

    private function coversEdgeRole(array $grants, string $edgeRole): bool
    {
        foreach ($grants as $grant) {
            if (! is_array($grant)) {
                continue;
            }
            $roleKey = $grant['role'] ?? null;
            if (! is_string($roleKey) || $roleKey === self::TREE_ROLE_MEMBER) {
                continue;
            }

            $role = $this->role($roleKey);
            if ($role === null) {
                continue;
            }

            // Review 62.5 #2 — un octroi qui n'accorde pas la LECTURE ne couvre
            // rien. La règle cherchait un rôle couvrant par sa STRATÉGIE, sans
            // jamais regarder ce qu'il accorde : un octroi `supprimer` seul — liste
            // non vide, donc parfaitement valide depuis 62.4 — suffisait à déclarer
            // l'ancêtre couvert. Le dossier personnel en dessous était alors validé
            // « atteignable » et compilait « conforme », en restant un mirage :
            // exactement le défaut que cette story existe pour éliminer, réintroduit
            // par sa propre validation.
            //
            // La règle est énoncée en termes MÉTIER, pas en termes de backend : une
            // audience qui gouverne un ancêtre doit pouvoir l'ouvrir. Elle vaut donc
            // pour tous les backends, et pas seulement parce que POSIX ne rend rien
            // de ce cas précis.
            if (! $this->grantCarriesRead($grant)) {
                continue;
            }

            $resolution = $this->resolutionOf($role);

            if ($resolution['strategy'] === RoleResolutionStrategy::Itself) {
                return true;
            }
            if ($resolution['strategy'] === RoleResolutionStrategy::EdgeRole
                && in_array($edgeRole, $resolution['edge_roles'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Valide un gabarit de chemin : placeholders du vocabulaire FERMÉ uniquement,
     * et structure relative sûre une fois les placeholders remplacés par un
     * segment quelconque (le contenu réel n'est connu qu'à la résolution).
     *
     * @throws InvalidTreeSpecException
     */
    private function assertValidPathTemplate(string $template, string $what, bool $memberAllowed): void
    {
        $allowed = self::TREE_PLACEHOLDERS;
        if ($memberAllowed) {
            $allowed[] = self::PLACEHOLDER_MEMBER_LOGIN;
        }

        preg_match_all('/\{([^{}]*)\}/', $template, $matches);
        foreach ($matches[1] as $placeholder) {
            if (! in_array($placeholder, $allowed, true)) {
                throw InvalidTreeSpecException::make(sprintf(
                    'placeholder inconnu « {%s} » dans %s (vocabulaire fermé : %s).',
                    $placeholder,
                    $what,
                    implode(', ', array_map(static fn (string $p): string => '{' . $p . '}', $allowed)),
                ));
            }
        }

        // Substitution par un segment factice : ce qui reste doit être un chemin
        // relatif dont chaque segment est sûr. Attrape l'accolade orpheline, le
        // chemin absolu, le segment vide, `.` et `..`.
        $probe = preg_replace('/\{[^{}]*\}/', 'X', $template) ?? '';
        if (! GroupNameNormalizer::isSafeRelativePath($probe)) {
            throw InvalidTreeSpecException::make(sprintf(
                '%s n\'est pas un chemin relatif sûr (segments alphanumériques + « . _ - », '
                . 'premier caractère différent de « . », aucun « .. », aucun chemin absolu).',
                ucfirst($what),
            ));
        }
    }

    /**
     * Valide les octrois d'un nœud : rôle connu (ou jeton du membre énuméré sur un
     * nœud par membre), LISTE DE VERBES non vide et sans doublon dans le
     * vocabulaire neutre des quatre verbes, suspendable seulement là où quelque
     * chose peut suspendre.
     *
     * @param  list<string>  $roleKeys
     *
     * @throws InvalidTreeSpecException
     */
    private function assertValidNodeGrants(mixed $grants, string $path, PlanNodeNature $nature, array $roleKeys): void
    {
        if (! is_array($grants)) {
            throw InvalidTreeSpecException::make(sprintf('les octrois du nœud « %s » ne sont pas une liste.', $path));
        }

        $seenRoles = [];
        foreach ($grants as $grant) {
            if (! is_array($grant)) {
                throw InvalidTreeSpecException::make(sprintf('un octroi du nœud « %s » n\'est pas une structure.', $path));
            }

            $unknown = array_diff(array_keys($grant), self::TREE_GRANT_KEYS);
            if ($unknown !== []) {
                throw InvalidTreeSpecException::make(sprintf(
                    'champ(s) inconnu(s) « %s » dans un octroi du nœud « %s » (vocabulaire fermé : %s). '
                    . 'Un octroi est POSITIF : il n\'existe aucun champ d\'interdiction.',
                    implode(', ', array_map(strval(...), $unknown)),
                    $path,
                    implode(', ', self::TREE_GRANT_KEYS),
                ));
            }

            $role = $grant['role'] ?? null;
            $verbs = $grant['verbs'] ?? null;
            $suspendable = (bool) ($grant['suspendable'] ?? false);

            if (! is_string($role) || $role === '') {
                throw InvalidTreeSpecException::make(sprintf('un octroi du nœud « %s » ne référence aucun rôle.', $path));
            }

            self::assertValidVerbList(
                $verbs,
                sprintf('l\'octroi du rôle « %s » sur le nœud « %s »', is_string($role) ? $role : '?', $path),
            );

            if ($role === self::TREE_ROLE_MEMBER) {
                if ($nature !== PlanNodeNature::ParMembre) {
                    throw InvalidTreeSpecException::make(sprintf(
                        'le jeton « %s » n\'a de sens que sur un nœud par membre (nœud « %s »).',
                        self::TREE_ROLE_MEMBER,
                        $path,
                    ));
                }
            } elseif (! in_array($role, $roleKeys, true)) {
                throw InvalidTreeSpecException::make(sprintf(
                    'le nœud « %s » octroie au rôle « %s », absent de la recette.',
                    $path,
                    $role,
                ));
            }

            if ($suspendable && ! $nature->acceptsSuspendableGrants()) {
                throw InvalidTreeSpecException::make(sprintf(
                    'octroi suspendable sur le nœud « %s » de nature « %s » : rien ne pourrait jamais le suspendre.',
                    $path,
                    $nature->value,
                ));
            }

            if (isset($seenRoles[$role])) {
                throw InvalidTreeSpecException::make(sprintf(
                    'le rôle « %s » reçoit deux octrois sur le nœud « %s » — un rôle, une liste de verbes.',
                    $role,
                    $path,
                ));
            }
            $seenRoles[$role] = true;
        }
    }
}
