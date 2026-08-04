<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Plan;

use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Models\DirectoryTemplate;

/**
 * Story 60.1 — RÉSOLUTION PURE : (recette + appartenances) → plan.
 *
 * **Pure, au sens fort.** Aucun accès disque, aucun processus enfant, aucun appel
 * réseau, aucune élévation de privilège, aucune requête : toutes les entrées
 * arrivent par {@see PlanResolutionContext}, que l'appelant assemble. Le bénéfice
 * est direct : les tests de ce résolveur n'ont besoin ni de base, ni de faux
 * appels système — c'est le dividende de la ligne de coupe, et il ne faut pas le
 * dilapider en injectant un service d'exécution ici.
 *
 * **Ce que ce résolveur ne fait PAS.** Il ne pose aucune permission, ne dérive
 * aucun nom système, n'invente aucune racine absolue. Il produit un plan NEUTRE ;
 * la traduction vers un plan de fichiers concret appartient au contrat de backend
 * (story 60.3) et passe APRÈS cette ligne. En 60.1, ce résolveur n'a pour
 * consommateur que ses tests.
 *
 * **La maille du groupe EST la maille du cloisonnement.** On résout UN groupe :
 * l'isolation vient de l'appartenance, pas de l'arborescence. Deux classes ne se
 * cloisonnent pas parce que leurs dossiers sont voisins, mais parce que leurs
 * groupes diffèrent. Pour les matières, la maille pertinente sera « matière ×
 * classe » (le groupe qui porte réellement l'audience) et jamais « matière » nue.
 * L'ACCROCHAGE d'une recette à un type de groupe est le périmètre de la story
 * suivante — ici, l'invariant est documenté et respecté, pas implémenté.
 *
 * **La forme des octrois est dictée par une mesure.** La pose récursive de
 * permissions nominatives est quadratique et bute sur une limite dure : un octroi
 * d'audience doit donc s'exprimer par un sujet abstrait — un groupe, éventuellement
 * qualifié d'un rôle d'arête — que le backend compile comme il veut.
 *
 * **Ce que ce résolveur garantit, et ce qu'il ne garantit pas.** Il ne dérive
 * JAMAIS lui-même une audience en énumérant les membres du groupe : la seule
 * énumération qu'il pratique est celle des nœuds par membre, où elle vaut UNE
 * entrée par nœud. En revanche, les cibles des rôles de recette lui arrivent
 * toutes faites dans {@see PlanResolutionContext} — et un rôle PEUT
 * légitimement désigner une personne (la recette « d'un utilisateur à un
 * utilisateur » a deux rôles de maille utilisateur, cardinalité un). Ce résolveur
 * ne peut donc pas distinguer, par le seul type du sujet, une désignation
 * nominative légitime d'une audience énumérée à tort. **Choisir le sujet d'une
 * audience est la responsabilité de la couche des stratégies de résolution (story
 * suivante)** ; c'est là que l'arbitrage se garde, pas ici.
 *
 * **Trois états distincts, jamais confondus** :
 *  - octroi ACTIF — le rôle a l'accès ;
 *  - octroi SUSPENDU — l'octroi existe, temporairement vide, données conservées
 *    (nœud activable désactivé) ;
 *  - rôle dans la CLÔTURE — le rôle n'a jamais reçu d'octroi ici.
 *
 * Un nœud activable désactivé reste TOUJOURS dans le plan : rien, dans ce
 * vocabulaire, ne permet d'exprimer la suppression d'un nœud à la désactivation.
 */
final class PlanResolver
{
    /**
     * Résout une recette portant un arbre sur un contexte d'appartenances.
     *
     * @throws \App\Exceptions\Filesystem\InvalidTreeSpecException recette mal formée
     * @throws PlanResolutionException                             données d'entrée non résolvables
     */
    public function resolve(DirectoryTemplate $template, PlanResolutionContext $context): FilePlan
    {
        $template->assertValidTreeSpec();

        $pattern = $template->pathPattern();
        if ($pattern === null) {
            throw PlanResolutionException::make(sprintf(
                'la recette « %s » ne porte aucun arbre — il n\'y a rien à résoudre.',
                (string) $template->key,
            ));
        }

        $groupValues = $this->groupSubstitutions($context);
        $rootPath = $this->substitute($pattern, $groupValues, 'le motif de chemin');

        $roleKeys = $this->recipeRoleKeys($template);

        /** @var array<string, list<PlanSubject>> $roles */
        $roles = [];
        foreach ($roleKeys as $roleKey) {
            $roles[$roleKey] = $context->targetsForRole($roleKey);
        }

        $nodes = [];
        foreach ($template->nodes() as $spec) {
            foreach ($this->resolveNode($spec, $context, $groupValues, $roleKeys) as $node) {
                $nodes[] = $node;
            }
        }

        return new FilePlan((string) $template->key, $rootPath, $roles, $nodes);
    }

    // =========================================================================
    // Nœuds
    // =========================================================================

    /**
     * Émet les nœuds de plan d'UNE spécification de nœud : un seul, sauf pour un
     * nœud par membre qui en émet un PAR MEMBRE portant le rôle d'arête visé
     * (zéro membre = zéro nœud, et c'est un plan valide).
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, string>  $groupValues
     * @param  list<string>  $roleKeys
     * @return list<PlanNode>
     */
    private function resolveNode(array $spec, PlanResolutionContext $context, array $groupValues, array $roleKeys): array
    {
        $specPath = (string) $spec['path'];
        $label = (string) $spec['label'];
        $nature = PlanNodeNature::from((string) $spec['nature']);
        $plafond = isset($spec['plafond']) ? (int) $spec['plafond'] : null;
        $grantSpecs = (array) ($spec['grants'] ?? []);

        // L'activation ne concerne QUE la nature activable : ailleurs, un état
        // « inactif » n'aurait rien à suspendre et serait un mensonge de plan.
        $active = $nature === PlanNodeNature::Activable
            ? $context->isNodeActive($specPath)
            : true;

        $closure = $this->closureFor($grantSpecs, $roleKeys);

        if (! $nature->expandsPerMember()) {
            $path = $this->substitute($specPath, $groupValues, sprintf('le chemin du nœud « %s »', $specPath));

            return [new PlanNode(
                $path,
                $label,
                $nature,
                $this->grantsFor($grantSpecs, $context, $active, null),
                $active,
                $plafond,
                $closure,
            )];
        }

        $edgeRole = (string) $spec['edge_role'];
        $nodes = [];

        foreach ($context->membersWithEdgeRole($edgeRole) as $member) {
            $login = (string) $member['login'];
            if (! GroupNameNormalizer::isSafeLogin($login)) {
                throw PlanResolutionException::make(sprintf(
                    'le login « %s » du membre #%d ne peut pas servir de segment de chemin (nœud « %s »).',
                    $login,
                    (int) $member['id'],
                    $specPath,
                ));
            }

            $values = $groupValues + [DirectoryTemplate::PLACEHOLDER_MEMBER_LOGIN => $login];
            $path = $this->substitute($specPath, $values, sprintf('le chemin du nœud « %s »', $specPath));

            $nodes[] = new PlanNode(
                $path,
                $label,
                $nature,
                $this->grantsFor($grantSpecs, $context, $active, (int) $member['id']),
                $active,
                $plafond,
                $closure,
            );
        }

        return $nodes;
    }

    // =========================================================================
    // Octrois et clôture
    // =========================================================================

    /**
     * Octrois d'un nœud. Un octroi d'audience se démultiplie sur les sujets du
     * rôle (zéro cible = zéro octroi) ; le jeton du membre énuméré produit UN
     * octroi nominatif dont le sujet est l'identité interne du membre — jamais son
     * login, qui n'est ici qu'un segment de chemin.
     *
     * Quand le nœud activable est INACTIF, les octrois marqués suspendables
     * passent à l'état SUSPENDU — ils restent émis, sérialisés et comparables. Les
     * autres (l'équipe sur l'espace d'échange) restent actifs.
     *
     * @param  array<int, mixed>  $grantSpecs
     * @return list<PlanGrant>
     */
    private function grantsFor(array $grantSpecs, PlanResolutionContext $context, bool $nodeActive, ?int $memberId): array
    {
        $grants = [];

        foreach ($grantSpecs as $grantSpec) {
            /** @var array<string, mixed> $grantSpec */
            $role = (string) $grantSpec['role'];
            $access = (string) $grantSpec['access'];
            $suspendable = (bool) ($grantSpec['suspendable'] ?? false);

            if ($role === DirectoryTemplate::TREE_ROLE_MEMBER) {
                if ($memberId === null) {
                    throw PlanResolutionException::make(
                        'le jeton du membre énuméré est apparu hors d\'un nœud par membre.'
                    );
                }

                $subjects = [PlanSubject::user($memberId)];
            } else {
                $subjects = $context->targetsForRole($role);
            }

            foreach ($subjects as $subject) {
                $grant = new PlanGrant($role, $subject, $access, $suspendable);

                // Suspendre, ce n'est ni retirer l'octroi ni supprimer le nœud :
                // l'accès reste écrit, il est seulement vidé.
                $grants[] = $nodeActive ? $grant : $grant->suspend();
            }
        }

        return $grants;
    }

    /**
     * CLÔTURE d'un nœud : les rôles de la recette qui ne reçoivent AUCUN octroi
     * écrit sur ce nœud.
     *
     * Entièrement DÉRIVÉE des octrois de la recette — jamais lue depuis la
     * spécification, jamais exposée à l'auteur, jamais modifiable autrement qu'en
     * écrivant ou en retirant un octroi. Le jeton du membre énuméré n'étant pas un
     * rôle de recette, un octroi nominatif ne décharge aucun rôle : sur le dossier
     * personnel d'un élève, la classe reste dans la clôture — ce qui est
     * exactement l'intention.
     *
     * @param  array<int, mixed>  $grantSpecs
     * @param  list<string>  $roleKeys
     * @return list<string>
     */
    private function closureFor(array $grantSpecs, array $roleKeys): array
    {
        $granted = [];
        foreach ($grantSpecs as $grantSpec) {
            /** @var array<string, mixed> $grantSpec */
            $granted[] = (string) $grantSpec['role'];
        }

        return array_values(array_diff($roleKeys, $granted));
    }

    /**
     * Clés de rôle de la recette, dans l'ordre où elle les déclare.
     *
     * @return list<string>
     */
    private function recipeRoleKeys(DirectoryTemplate $template): array
    {
        $keys = [];
        foreach ($template->roles() as $role) {
            $key = $role['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    // =========================================================================
    // Substitution
    // =========================================================================

    /**
     * Valeurs de substitution dérivées du groupe de cloisonnement.
     *
     * `{group.bare_name}` retire le préfixe de TYPE du nom (insensible à la casse,
     * casse préservée). Sans lui, un motif qui re-préfixe produirait le double
     * préfixe bien connu sur les groupes dont le nom stocké porte déjà le préfixe.
     *
     * **Story 60.2 — les deux moitiés d'un nom « matière × classe ».**
     * `{group.matiere}` et `{group.classe}` ne sont fournis que pour un groupe de
     * ce type, dont le nom (`Matiere_Maths@6A`) porte deux mailles et n'est donc
     * pas un segment de chemin sûr. Un placeholder NON FOURNI fait échouer la
     * substitution avec son message dédié — c'est le comportement de la story
     * 60.1, et c'est exactement ce qu'on veut ici : `{group.bare_name}` sur un
     * groupe matière×classe continue d'échouer explicitement, le « @ » n'entrant
     * jamais dans un segment de chemin.
     *
     * Le nom reste inexploitable SEULEMENT s'il ne donne ni segment sûr ni
     * décomposition : là, l'échec est immédiat et nomme le groupe, plutôt que de
     * se manifester plus tard comme un placeholder mystérieusement absent.
     *
     * @return array<string, string>
     */
    private function groupSubstitutions(PlanResolutionContext $context): array
    {
        $values = [DirectoryTemplate::PLACEHOLDER_GROUP_NAME => $context->groupName];

        $bare = GroupNameNormalizer::bareName($context->groupName, $context->groupType);
        if ($bare !== null) {
            $values[DirectoryTemplate::PLACEHOLDER_GROUP_BARE_NAME] = $bare;
        }

        if ($context->groupType === GroupNameNormalizer::TYPE_MATIERE_CLASSE) {
            $parts = GroupNameNormalizer::matiereClasseParts($context->groupName);
            if ($parts !== null) {
                $values[DirectoryTemplate::PLACEHOLDER_GROUP_MATIERE] = $parts['matiere'];
                $values[DirectoryTemplate::PLACEHOLDER_GROUP_CLASSE] = $parts['classe'];
            }
        }

        if ($bare === null && ! isset($values[DirectoryTemplate::PLACEHOLDER_GROUP_MATIERE])) {
            throw PlanResolutionException::make(sprintf(
                'le nom du groupe « %s » ne donne pas un segment de chemin sûr (type « %s »).',
                $context->groupName,
                $context->groupType ?? '—',
            ));
        }

        return $values;
    }

    /**
     * Substitue les placeholders d'un gabarit et VALIDE le chemin obtenu.
     *
     * Vocabulaire FERMÉ : tout placeholder hors des valeurs fournies fait échouer
     * la résolution. Un segment non sûr après substitution (nom de groupe exotique,
     * login inattendu) fait échouer la résolution aussi — jamais un plan partiel en
     * silence : un plan amputé se comparerait « conforme » à un état incomplet.
     *
     * @param  array<string, string>  $values
     */
    private function substitute(string $template, array $values, string $what): string
    {
        $resolved = preg_replace_callback(
            '/\{([^{}]*)\}/',
            static function (array $m) use ($values, $what): string {
                $key = $m[1];
                if (! array_key_exists($key, $values)) {
                    throw PlanResolutionException::make(sprintf(
                        'placeholder « {%s} » non résolvable dans %s (vocabulaire disponible : %s).',
                        $key,
                        $what,
                        implode(', ', array_map(static fn (string $k): string => '{' . $k . '}', array_keys($values))),
                    ));
                }

                return $values[$key];
            },
            $template,
        ) ?? '';

        if (! GroupNameNormalizer::isSafeRelativePath($resolved)) {
            throw PlanResolutionException::make(sprintf(
                '%s se résout en « %s », qui n\'est pas un chemin relatif sûr.',
                ucfirst($what),
                $resolved,
            ));
        }

        return $resolved;
    }
}
