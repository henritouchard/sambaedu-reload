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
 * Tout est `static` et sans état : aucun accès disque, réseau, base ou shell.
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
        'matiere_classe' => ['Matiere_'],
    ];

    /**
     * Vocabulaire BORNÉ du rôle d'arête (`member|manager|owner`).
     *
     * Recopié plutôt qu'importé, même raison que ci-dessus : le pivot d'arête est
     * un modèle Eloquent, et le plan ne connaît que des identités internes. Le
     * test d'équivalence épingle cette liste sur le vocabulaire stocké réel.
     *
     * @var list<string>
     */
    public const EDGE_ROLES = ['member', 'manager', 'owner'];

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
        $bare = $rawName;

        foreach (self::TYPE_PREFIXES[$type] ?? [] as $prefix) {
            // Le préfixe n'est retiré que s'il RESTE quelque chose : un nom réduit
            // à son seul préfixe n'est pas un préfixe, c'est un nom (dégénéré) —
            // et l'implémentation historique le traite ainsi.
            if (strncasecmp($rawName, $prefix, strlen($prefix)) === 0 && strlen($rawName) > strlen($prefix)) {
                $bare = substr($rawName, strlen($prefix));
                break;
            }
        }

        return self::isSafeSegment($bare) ? $bare : null;
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

    /** `true` si `$role` appartient au vocabulaire borné du rôle d'arête. */
    public static function isKnownEdgeRole(mixed $role): bool
    {
        return is_string($role) && in_array($role, self::EDGE_ROLES, true);
    }
}
