<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Plan;

/**
 * Story 60.1 — normalisation PURE des noms, dans le namespace du plan.
 *
 * **Pourquoi une ré-implémentation et non une réutilisation.** Le dé-préfixage
 * d'un nom de groupe existe déjà, une classe plus loin, dans le chemin figé du
 * partage classe (5.2). Mais cette classe-là tire tout le savoir POSIX derrière
 * elle (dérivation des noms de groupes Unix, service d'ACL, exécution de
 * commandes). Le namespace du plan doit rester AU-DESSUS de la ligne de contrat :
 * il n'importe aucun service d'exécution — c'est verrouillé par un test
 * d'architecture, pas par de la discipline.
 *
 * La logique dé-préfixée est du NOMMAGE, pas du POSIX : la recopier ici coûte
 * quinze lignes, l'importer coûterait la coupe. L'équivalence de comportement
 * avec l'implémentation historique est ÉPINGLÉE PAR TEST
 * ({@see \Tests\Unit\Services\Filesystem\Plan\GroupNameNormalizerTest}) : si l'une
 * des deux dérive, le test tombe.
 *
 * Tout est `static` : aucun accès disque, réseau, base ou shell.
 *
 * **Une seule exception, et elle est nommée** (story 62.1) : le vocabulaire de
 * rôle d'arête n'est plus une constante recopiée, c'est un catalogue
 * administrable. Il ENTRE ici par injection ({@see self::useEdgeRoles()}), posée
 * au démarrage de l'application ; ce fichier ne va jamais le chercher. En
 * l'absence d'injection — les tests purs de ce namespace — le repli littéral des
 * trois clés historiques s'applique. La propriété statique du résolveur est le
 * seul état de la classe, et c'est le prix exact de la coupe : sans elle, il
 * faudrait interroger une table depuis un namespace qui n'en a pas le droit.
 */
final class GroupNameNormalizer
{
    /**
     * Motif d'un segment de chemin SÛR : alphanum + `._-`, premier caractère ≠ `.`.
     *
     * COPIE LITTÉRALE de la constante `DIRECTORY_NAME_PATTERN` du provisioning
     * générique 34.1 (épinglée par test d'équivalence). Refuse par construction :
     * `/`, l'espace, tout métacaractère de shell, `..` et `.` (le point seul ne
     * peut pas être premier caractère), et le segment vide.
     */
    public const SEGMENT_PATTERN = '/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/';

    /**
     * Préfixes de type portés par les CN d'annuaire, par type de groupe SE5.
     *
     * Le dé-préfixage est INSENSIBLE À LA CASSE (l'annuaire hérité stocke des CN
     * en minuscules) mais la casse du nom court est PRÉSERVÉE : `Classe_3emeA`
     * donne `3emeA`, pas `3emea`. La forme minuscule est une projection
     * d'implémentation des groupes Unix — elle reste SOUS la ligne de contrat et
     * n'a rien à faire dans un plan.
     *
     * Un SEUL préfixe est retiré : `Classe_Classe_X` donne `Classe_X`. C'est
     * volontaire — le motif de chemin re-préfixe ensuite UNE fois, ce qui évite
     * le double préfixe quand le nom stocké l'est déjà, sans jamais éroder un nom
     * qui contiendrait réellement le mot.
     *
     * @var array<string, list<string>>
     */
    public const TYPE_PREFIXES = [
        'classe' => ['Classe_'],
        'equipe' => ['Equipe_', 'PP_'],
        'cours' => ['Cours_'],
        'projet' => ['Projet_'],
        'matiere' => ['Matiere_'],
        self::TYPE_MATIERE_CLASSE => ['Matiere_'],
    ];

    /**
     * Story 60.2 — type de groupe dont le nom porte DEUX mailles, séparées par un
     * « @ ».
     *
     * `matiere_classe` est le type détecté à l'import pour un nom d'annuaire de la
     * forme `Matiere_<matière>@<classe>` — la matière DANS une classe. Il est
     * distinct de `matiere` nu (les enseignants d'une discipline tous niveaux
     * confondus), même si l'annuaire les projette sous le même préfixe.
     */
    public const TYPE_MATIERE_CLASSE = 'matiere_classe';

    /**
     * Story 62.1 — REPLI du vocabulaire de rôle d'arête : les trois clés
     * historiques, en littéraux locaux.
     *
     * Il sert exactement dans un cas : quand aucun résolveur n'a été installé,
     * c'est-à-dire hors application démarrée — les tests PURS de ce namespace. Au
     * runtime, le fournisseur de services installe TOUJOURS le résolveur
     * ({@see self::useEdgeRoles()}), et c'est le catalogue administrable qui parle.
     *
     * Le repli n'élargit rien : il RESTREINT au vocabulaire historique. Une clé
     * nouvelle du catalogue est refusée tant que le résolveur n'est pas là — ce
     * qui, hors application démarrée, est le comportement correct.
     *
     * @var list<string>
     */
    private const FALLBACK_EDGE_ROLES = ['member', 'manager', 'owner'];

    /**
     * Source INJECTÉE du vocabulaire de rôle d'arête.
     *
     * **Pourquoi une injection et pas une lecture directe.** Ce namespace est
     * AU-DESSUS de la ligne de contrat : il n'interroge rien, ne charge aucun
     * modèle d'identité, et c'est verrouillé par un test d'architecture dont le
     * scan est textuel. Le catalogue, lui, est une table. La seule couture qui
     * concilie les deux est celle-ci : le vocabulaire ENTRE dans le plan, le plan
     * ne va jamais le chercher. La mémoïsation vit du côté qui a le droit de lire.
     *
     * @var (callable(): list<string>)|null
     */
    private static $edgeRoleResolver = null;

    /**
     * Installe (ou retire, avec `null`) la source du vocabulaire de rôle d'arête.
     *
     * Appelée une fois au démarrage de l'application. Les tests qui veulent
     * exercer le repli la remettent à `null`.
     *
     * @param  (callable(): list<string>)|null  $resolver
     */
    public static function useEdgeRoles(?callable $resolver): void
    {
        self::$edgeRoleResolver = $resolver;
    }

    /**
     * Le vocabulaire de rôle d'arête EN VIGUEUR.
     *
     * Une source qui rendrait une liste vide est traitée comme absente : un
     * vocabulaire vide ne refuserait pas seulement les rôles nouveaux, il
     * refuserait `member` — donc toute arête, donc tout plan.
     *
     * @return list<string>
     */
    public static function edgeRoles(): array
    {
        $resolver = self::$edgeRoleResolver;

        if ($resolver === null) {
            return self::FALLBACK_EDGE_ROLES;
        }

        $roles = array_values(array_filter($resolver(), 'is_string'));

        return $roles === [] ? self::FALLBACK_EDGE_ROLES : $roles;
    }

    /**
     * Nom court d'un groupe : préfixe de type retiré (insensible à la casse,
     * casse du reste préservée) puis validation de segment sûr.
     *
     * `$type` est le type SE5 du groupe (`classe`, `equipe`, …). Un type inconnu
     * ou `null` ne dé-préfixe RIEN — on ne devine pas : un nom qui commencerait
     * par `Classe_` sur un groupe qui n'est pas une classe est un nom, pas un
     * préfixe.
     *
     * @return string|null `null` si le nom résultant n'est pas un segment sûr.
     */
    public static function bareName(string $rawName, ?string $type = null): ?string
    {
        $bare = self::stripTypePrefix($rawName, $type);

        return self::isSafeSegment($bare) ? $bare : null;
    }

    /**
     * Story 60.2 — DÉCOMPOSITION du nom d'un groupe « matière × classe » en ses
     * deux segments : `Matiere_Math@3emeA` donne `['matiere' => 'Math',
     * 'classe' => '3emeA']`.
     *
     * **Pourquoi décomposer plutôt que normaliser ou exclure.** Le nom d'un tel
     * groupe n'est PAS un segment de chemin sûr : le « @ » ne fait pas partie du
     * motif de segment, qui est la copie épinglée de celui du provisioning. Trois
     * issues étaient possibles, deux sont des impasses :
     *
     *  1. Élargir le motif de segment pour admettre le « @ » : le plan accepterait
     *     alors des chemins que la création du répertoire réseau refusera plus
     *     tard. Une impasse simplement DIFFÉRÉE, et découverte au pire moment.
     *  2. Remplacer le « @ » par un tiret ou un souligné : projection AVEC PERTE.
     *     `Math@6A` collisionnerait avec un groupe réellement nommé `Math-6A`, et
     *     la relecture d'état ne saurait plus remonter du chemin au groupe.
     *  3. Décomposer : SANS perte. Le plan porte l'identité interne du groupe, le
     *     chemin n'a jamais besoin d'être ré-analysé, et un motif du genre
     *     `Matieres/{classe}/{matière}` retrouve exactement la structure de
     *     l'ancien système.
     *
     * **Exactement UN « @ », jamais zéro, jamais deux.** La validation de nom à la
     * création autorise `[A-Za-z0-9._@-]+` : un nom à plusieurs « @ » est donc
     * possible en théorie. On refuse au lieu de deviner — découper `A@B@C` demande
     * de choisir un côté, et ce choix serait arbitraire.
     *
     * Helper PUR : aucun accès base, disque ou réseau. Le TYPE du groupe n'est pas
     * vérifié ici — c'est l'appelant qui sait qu'il tient un `matiere_classe` ;
     * cette fonction ne fait que décomposer.
     *
     * @return array{matiere:string,classe:string}|null `null` si le nom ne se
     *                                                  décompose pas en deux segments SÛRS.
     */
    public static function matiereClasseParts(string $rawName): ?array
    {
        $bare = self::stripTypePrefix($rawName, self::TYPE_MATIERE_CLASSE);

        $parts = explode('@', $bare);
        if (count($parts) !== 2) {
            return null;
        }

        [$matiere, $classe] = $parts;

        if (! self::isSafeSegment($matiere) || ! self::isSafeSegment($classe)) {
            return null;
        }

        return ['matiere' => $matiere, 'classe' => $classe];
    }

    /**
     * Retire le préfixe de TYPE d'un nom, SANS valider le résultat.
     *
     * Extrait de {@see bareName()} pour être partagé avec la décomposition
     * matière × classe, dont le nom dé-préfixé n'est justement PAS un segment sûr
     * (il porte encore son « @ »). La validation reste à la charge de l'appelant.
     */
    private static function stripTypePrefix(string $rawName, ?string $type): string
    {
        foreach (self::TYPE_PREFIXES[$type] ?? [] as $prefix) {
            // Le préfixe n'est retiré que s'il RESTE quelque chose : un nom réduit
            // à son seul préfixe n'est pas un préfixe, c'est un nom (dégénéré) —
            // et l'implémentation historique le traite ainsi.
            if (strncasecmp($rawName, $prefix, strlen($prefix)) === 0 && strlen($rawName) > strlen($prefix)) {
                return substr($rawName, strlen($prefix));
            }
        }

        return $rawName;
    }

    /** `true` si `$segment` est un segment de chemin sûr (motif ci-dessus). */
    public static function isSafeSegment(string $segment): bool
    {
        return $segment !== '' && preg_match(self::SEGMENT_PATTERN, $segment) === 1;
    }

    /**
     * `true` si `$path` est un chemin RELATIF dont chaque segment est sûr.
     *
     * Refuse donc : le chemin absolu (segment initial vide), le chemin vide, les
     * doubles séparateurs, `..` et `.`. Le plan ne porte QUE du relatif — la
     * racine absolue est un savoir de backend.
     */
    public static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if (! self::isSafeSegment($segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Story 60.3 — jeton du nœud RACINE d'un plan, et source unique de sa valeur
     * ({@see PlanNode::ROOT_PATH} en est l'alias).
     *
     * Il désigne « la racine du plan elle-même », relativement à cette racine. Ce
     * n'est PAS un segment de chemin : {@see SEGMENT_PATTERN} le refuse (un
     * segment ne peut pas commencer par un point), et c'est exactement ce qu'on
     * veut — hors de la position racine, un « . » reste interdit partout.
     */
    public const ROOT_NODE_PATH = '.';

    /**
     * `true` si `$path` peut être le chemin d'un NŒUD de plan : le jeton racine
     * ENTIER, ou un chemin relatif sûr.
     *
     * **Pourquoi un prédicat séparé plutôt qu'un élargissement de
     * {@see isSafeRelativePath()}.** Le sondage d'ouverture d'epic a mesuré, sur
     * une instance réelle, qu'une relecture d'état « avec les sous-chemins » rend
     * les enfants MAIS PAS la racine : un backend qui traite la racine à part
     * finit par l'omettre, et l'omission d'un nœud est précisément le silence que
     * cette story rend impossible. La racine doit donc être un nœud comme les
     * autres. Mais la RACINE D'UN PLAN, elle, ne doit surtout pas pouvoir valoir
     * « . » : un plan enraciné sur « le dossier courant » n'a aucun sens et serait
     * un chemin non résolu déguisé. Deux besoins, deux prédicats — élargir
     * l'existant aurait ouvert le second en ouvrant le premier.
     *
     * Refuse donc, comme avant : `./x`, `a/./b`, `..`, l'absolu, le vide, et « . »
     * en simple segment d'un chemin plus long.
     */
    public static function isSafeNodePath(string $path): bool
    {
        return $path === self::ROOT_NODE_PATH || self::isSafeRelativePath($path);
    }

    /**
     * `true` si `$login` peut servir de SEGMENT DE CHEMIN.
     *
     * Un login n'est JAMAIS un sujet d'octroi dans un plan (les sujets sont des
     * identités internes) : il n'apparaît que comme segment de chemin d'un nœud
     * par membre, schéma de nommage hérité. On lui applique donc exactement la
     * garde de segment, ni plus ni moins.
     */
    public static function isSafeLogin(string $login): bool
    {
        return self::isSafeSegment($login);
    }

    /** `true` si `$role` appartient au vocabulaire de rôle d'arête en vigueur. */
    public static function isKnownEdgeRole(mixed $role): bool
    {
        return is_string($role) && in_array($role, self::edgeRoles(), true);
    }
}
