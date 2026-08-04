<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanNodeNature;
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
 * assignations par maille avec le bon `access`.
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
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string|null $description
 * @property array<int, array<string, mixed>> $roles_spec
 * @property string|null $path_pattern
 * @property array<int, array<string, mixed>>|null $nodes_spec
 */
class DirectoryTemplate extends Model
{
    /** Clés stables des 4 recettes seedées (34.3 — `élèves → profs` reporté 34.x). */
    public const KEY_DIRECTION_TO_ALL = 'direction_to_all';
    public const KEY_PROFS_TO_ELEVES = 'profs_to_eleves';
    public const KEY_USER_TO_USER = 'user_to_user';
    public const KEY_GROUP_SPACE = 'group_space';

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
     * Placeholders admis dans le MOTIF de chemin et dans les chemins de nœuds
     * ordinaires. `member.login` en est EXCLU : il n'a de sens que dans un nœud
     * par membre, et sa présence ailleurs est une recette invalide.
     *
     * @var list<string>
     */
    public const TREE_PLACEHOLDERS = [
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
     * @var list<string>
     */
    public const TREE_GRANT_KEYS = ['role', 'access', 'suspendable'];

    protected $table = 'directory_templates';

    protected $fillable = [
        'key',
        'label',
        'description',
        'roles_spec',
        'path_pattern',
        'nodes_spec',
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

            $this->assertValidPathTemplate(
                $path,
                sprintf('le chemin du nœud « %s »', $path),
                memberAllowed: $nature === PlanNodeNature::ParMembre,
            );

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
                        implode('|', GroupNameNormalizer::EDGE_ROLES),
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
        }
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
     * nœud par membre), accès dans le vocabulaire neutre `ro|rw`, suspendable
     * seulement là où quelque chose peut suspendre.
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
            $access = $grant['access'] ?? null;
            $suspendable = (bool) ($grant['suspendable'] ?? false);

            if (! is_string($role) || $role === '') {
                throw InvalidTreeSpecException::make(sprintf('un octroi du nœud « %s » ne référence aucun rôle.', $path));
            }
            if (! in_array($access, PlanGrant::ACCESSES, true)) {
                throw InvalidTreeSpecException::make(sprintf(
                    'accès « %s » inconnu sur le nœud « %s » (attendu : %s — le plan ne connaît aucun mode système).',
                    is_scalar($access) ? (string) $access : gettype($access),
                    $path,
                    implode('|', PlanGrant::ACCESSES),
                ));
            }

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
                    'le rôle « %s » reçoit deux octrois sur le nœud « %s » — un rôle, un accès.',
                    $role,
                    $path,
                ));
            }
            $seenRoles[$role] = true;
        }
    }
}
