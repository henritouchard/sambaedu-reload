<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Nextcloud;

use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;

/**
 * Story 61.3 — LE PLAN, TRADUIT UNE FOIS, DANS LE MODÈLE DU DOSSIER D'ÉQUIPE.
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI DEUX ÉTAGES, ET PAS UN.**
 *
 * Le modèle de permissions d'un dossier d'équipe a deux étages, et ils ne font pas
 * la même chose :
 *
 *  1. la **carte des groupes** du dossier dit qui MONTE le dossier, et avec quel
 *     PLAFOND. C'est un plafond, pas un octroi : rien, plus bas, ne peut donner
 *     davantage ;
 *  2. les **règles de permissions avancées**, posées par chemin, RABAISSENT ce
 *     plafond là où le plan demande moins — et jusqu'à zéro, ce qui est la
 *     clôture.
 *
 * D'où la traduction : le plafond d'un groupe est l'UNION de tout ce que ses
 * membres reçoivent quelque part dans la zone, et chaque nœud porte une règle
 * seulement là où l'effectif doit descendre. Poser une règle partout serait plus
 * bavard sans être plus vrai ; ne poser aucune règle laisserait le plafond partout,
 * c'est-à-dire la fuite que la clôture existe pour fermer.
 *
 * **Une règle d'UTILISATEUR l'emporte sur une règle de groupe** — c'est ce qui rend
 * les dossiers par membre exprimables : la classe est refermée sur le dossier d'un
 * élève, et l'élève y garde son accès par sa propre règle.
 *
 * ---------------------------------------------------------------------------
 * **TROIS ÉTATS D'OCTROI, TROIS TRADUCTIONS DISTINCTES** (aucune ne se confond) :
 *
 *  | état du plan            | traduction                                    |
 *  |-------------------------|-----------------------------------------------|
 *  | octroi ACTIF            | les bits de ses verbes                        |
 *  | octroi SUSPENDU         | une règle à ZÉRO — présente, explicitement vide |
 *  | rôle en CLÔTURE         | une règle à ZÉRO sur les sujets de ce rôle    |
 *
 * Les deux derniers s'écrivent pareil sur le fil, et c'est normal : le disque dit
 * une FORME, le plan dit une INTENTION. C'est la comparaison, au-dessus de la
 * ligne, qui sait laquelle des deux était voulue — et c'est pour cela que
 * l'observation les range dans deux champs différents.
 *
 * **Un sujet à la fois octroyé par un rôle et clos par un autre reste OCTROYÉ** :
 * l'union au plus permissif est la doctrine de l'epic, et la clôture n'a jamais été
 * une interdiction — elle constate qu'un rôle n'a rien reçu.
 */
final class NextcloudPlanProjection
{
    /**
     * @param  array<string, array{type:string,id:string,subject:PlanSubject}>  $principals  clé de principal => principal
     * @param  array<string, string>  $keyBySubject  clé de tri de sujet => clé de principal
     * @param  array<string, int>  $ceilings  nom de groupe distant => plafond
     * @param  array<string, int>  $effectiveCeilings  clé de principal => plafond effectif
     * @param  array<string, array<string, int>>  $desired  chemin de nœud => (clé de principal => bits)
     * @param  array<string, list<PlanSubject>>  $closedSubjects  chemin de nœud => sujets refermés
     * @param  array<string, list<string>>  $nodeDetails  chemin de nœud => causes d'octroi NON ÉCRIT (bloquantes)
     * @param  list<string>  $notices  constats qui n'empêchent AUCUNE écriture
     * @param  array<string, array{subject:PlanSubject,members:list<string>}>  $groups  nom de groupe => appartenance voulue
     */
    private function __construct(
        public readonly array $principals,
        public readonly array $keyBySubject,
        public readonly array $ceilings,
        public readonly array $effectiveCeilings,
        public readonly array $desired,
        public readonly array $closedSubjects,
        public readonly array $nodeDetails,
        public readonly array $groups,
        public readonly array $notices,
    ) {
    }

    public static function compile(FilePlan $plan, NextcloudSubjectProjector $projector): self
    {
        $principals = [];
        $keyBySubject = [];
        $groups = [];
        $unresolved = [];
        $notices = [];
        $memberOfGroup = [];

        // --- 1. Les principaux, une fois pour toutes -------------------------
        foreach ($projector->subjectsOf($plan) as $subject) {
            if ($subject->type === PlanSubject::TYPE_USER) {
                $id = $projector->nextcloudUserIdFor($subject);
                if ($id === null) {
                    $unresolved[$subject->sortKey()] = $projector->identityRemediation($projector->loginFor($subject));

                    continue;
                }
                $key = NextcloudAclRule::TYPE_USER . ':' . $id;
                $principals[$key] = ['type' => NextcloudAclRule::TYPE_USER, 'id' => $id, 'subject' => $subject];
                $keyBySubject[$subject->sortKey()] = $key;

                continue;
            }

            $name = $projector->groupNameFor($subject);
            if ($name === null) {
                $unresolved[$subject->sortKey()] = sprintf(
                    'aucun nom de groupe distant n\'est dérivable du groupe interne #%d : son octroi n\'a pas '
                    . 'été écrit.',
                    $subject->id,
                );

                continue;
            }

            $key = NextcloudAclRule::TYPE_GROUP . ':' . $name;
            $principals[$key] = ['type' => NextcloudAclRule::TYPE_GROUP, 'id' => $name, 'subject' => $subject];
            $keyBySubject[$subject->sortKey()] = $key;

            $membership = $projector->membersFor($subject);
            $groups[$name] = ['subject' => $subject, 'members' => $membership['members']];

            foreach ($membership['members'] as $memberId) {
                $memberOfGroup[$memberId][$name] = true;
            }

            if ($membership['missing'] !== []) {
                // **UN CONSTAT, PAS UN ÉCHEC DE NŒUD.** L'octroi du groupe EST écrit ;
                // ce qui manque, c'est le COMPTE de certaines personnes — un état du
                // provisionnement des utilisateurs, pas de la traduction du plan.
                // Le rendre bloquant peindrait en rouge tout partage d'une instance
                // dont le balayage de comptes n'a pas encore tourné, et l'exploitant
                // cesserait de lire des rouges qui ne disent rien de la zone. Il est
                // donc DIT — jamais tu — sans empêcher quoi que ce soit.
                $notices[] = sprintf(
                    '%d membre(s) du groupe interne #%d n\'ont pas encore de compte Nextcloud connu (%s…) : '
                    . 'l\'octroi du groupe est en place, mais ces personnes n\'y accéderont qu\'une fois '
                    . 'leur compte provisionné (nextcloud:provision).',
                    count($membership['missing']),
                    $subject->id,
                    $membership['missing'][0],
                );
            }
        }

        // --- 2. Les bits voulus, nœud par nœud -------------------------------
        $desired = [];
        $closedSubjects = [];
        $nodeDetails = [];

        foreach ($plan->nodes as $node) {
            $desired[$node->path] = [];
            $closedSubjects[$node->path] = [];
            $nodeDetails[$node->path] = [];

            foreach ($node->grants as $grant) {
                $key = $keyBySubject[$grant->subject->sortKey()] ?? null;
                if ($key === null) {
                    $detail = $unresolved[$grant->subject->sortKey()] ?? null;
                    if ($detail !== null && ! in_array($detail, $nodeDetails[$node->path], true)) {
                        $nodeDetails[$node->path][] = $detail;
                    }

                    continue;
                }

                $bits = $grant->isActive() ? NextcloudPermissionBits::fromVerbs($grant->verbs) : 0;
                // Union au plus permissif : deux rôles qui pointent le même sujet
                // ne se retirent rien l'un à l'autre.
                $desired[$node->path][$key] = ($desired[$node->path][$key] ?? 0) | $bits;
            }

            foreach ($node->closure as $role) {
                foreach ($plan->roles[$role] ?? [] as $subject) {
                    $key = $keyBySubject[$subject->sortKey()] ?? null;
                    if ($key === null) {
                        continue;
                    }
                    // Un sujet déjà octroyé ici n'est pas refermé : l'octroi gagne.
                    if (array_key_exists($key, $desired[$node->path])) {
                        continue;
                    }
                    $desired[$node->path][$key] = 0;
                    $closedSubjects[$node->path][$key] = $subject;
                }
            }

            $closedSubjects[$node->path] = array_values($closedSubjects[$node->path]);
        }

        // --- 3. Les plafonds : l'union de tout ce qu'un membre peut recevoir --
        $ceilings = [];
        foreach (array_keys($groups) as $name) {
            $ceilings[$name] = 0;
        }

        foreach ($desired as $byPrincipal) {
            foreach ($byPrincipal as $key => $bits) {
                $principal = $principals[$key];

                if ($principal['type'] === NextcloudAclRule::TYPE_GROUP) {
                    $ceilings[$principal['id']] = ($ceilings[$principal['id']] ?? 0) | $bits;

                    continue;
                }

                // Un octroi NOMINATIF doit tenir sous le plafond du groupe par
                // lequel son titulaire monte le dossier : sans cela, le dossier
                // personnel d'un élève serait plafonné par ce que la classe reçoit
                // ailleurs, et l'élève n'écrirait pas chez lui.
                foreach (array_keys($memberOfGroup[$principal['id']] ?? []) as $groupName) {
                    $ceilings[$groupName] = ($ceilings[$groupName] ?? 0) | $bits;
                }
            }
        }

        // --- 4. Le plafond EFFECTIF d'un principal ---------------------------
        $effectiveCeilings = [];
        foreach ($principals as $key => $principal) {
            if ($principal['type'] === NextcloudAclRule::TYPE_GROUP) {
                $effectiveCeilings[$key] = $ceilings[$principal['id']] ?? 0;

                continue;
            }

            $bits = 0;
            foreach (array_keys($memberOfGroup[$principal['id']] ?? []) as $groupName) {
                $bits |= $ceilings[$groupName] ?? 0;
            }
            $effectiveCeilings[$key] = $bits;
        }

        return new self(
            $principals,
            $keyBySubject,
            $ceilings,
            $effectiveCeilings,
            $desired,
            $closedSubjects,
            array_map(static fn (array $d): array => array_values($d), $nodeDetails),
            $groups,
            array_values(array_unique($notices)),
        );
    }

    /**
     * Les règles à poser sur un nœud : celles, et celles-là seulement, où l'effectif
     * doit CHANGER par rapport à ce qui DESCEND déjà sur ce nœud.
     *
     * **Ce qui descend n'est pas le plafond, c'est l'ancêtre.** Une règle posée sur
     * un dossier se PROPAGE à son sous-arbre — c'est la propriété même qui a fait
     * naître la clôture calculée, et elle vaut aussi pour les octrois. Comparer au
     * seul plafond ferait donc omettre la règle d'un sous-dossier PLUS permissif que
     * son parent : l'espace d'échange resterait en lecture seule parce que la racine
     * l'est, sans que rien ne le dise. La référence est donc la valeur EFFECTIVE du
     * plus proche ancêtre présent au plan, et le plafond seulement à la racine.
     *
     * Là où l'héritage donne déjà exactement ce que le plan veut, aucune règle n'est
     * nécessaire — en poser une ne changerait rien à l'état et ajouterait un objet à
     * gouverner.
     *
     * @return list<NextcloudAclRule>
     */
    public function rulesFor(string $nodePath): array
    {
        $rules = [];
        $inherited = $this->inheritedEffectiveFor($nodePath);

        foreach ($this->desired[$nodePath] ?? [] as $key => $bits) {
            if (($inherited[$key] ?? null) === $bits) {
                continue;
            }

            $principal = $this->principals[$key];
            $rules[] = new NextcloudAclRule(
                $principal['type'],
                $principal['id'],
                NextcloudPermissionBits::CLOSURE_MASK,
                $bits,
            );
        }

        usort($rules, static fn (NextcloudAclRule $a, NextcloudAclRule $b): int => strcmp($a->principalKey(), $b->principalKey()));

        return $rules;
    }

    /**
     * Ce qui DESCEND sur ce nœud, par principal : la valeur effective du plus proche
     * ancêtre présent au plan, ou le plafond quand il n'y a pas d'ancêtre.
     *
     * @return array<string, int>
     */
    private function inheritedEffectiveFor(string $nodePath): array
    {
        if ($nodePath === PlanNode::ROOT_PATH) {
            return $this->effectiveCeilings;
        }

        $ancestor = dirname($nodePath);
        $path = ($ancestor === '.' || $ancestor === '' || $ancestor === '/')
            ? PlanNode::ROOT_PATH
            : $ancestor;

        // Un niveau intermédiaire que le plan ne décrit pas ne gouverne rien : on
        // remonte jusqu'à celui qu'il décrit.
        while ($path !== PlanNode::ROOT_PATH && ! array_key_exists($path, $this->desired)) {
            $next = dirname($path);
            $path = ($next === '.' || $next === '' || $next === '/') ? PlanNode::ROOT_PATH : $next;
        }

        return $this->effectiveAt($path);
    }

    /**
     * La valeur EFFECTIVE de chaque principal sur un nœud : ce que le plan y dit,
     * complété par ce qui y descend.
     *
     * @return array<string, int>
     */
    public function effectiveAt(string $nodePath): array
    {
        return ($this->desired[$nodePath] ?? []) + $this->inheritedEffectiveFor($nodePath);
    }

    /**
     * Les bits EFFECTIFS voulus d'un principal sur un nœud, `null` si le plan ne dit
     * rien de lui à cet endroit.
     */
    public function desiredBits(string $nodePath, string $principalKey): ?int
    {
        return $this->desired[$nodePath][$principalKey] ?? null;
    }

    /** Les nœuds du plan, dans l'ordre où ils doivent être CRÉÉS : parents d'abord. */
    public static function creationOrder(FilePlan $plan): array
    {
        $paths = array_values(array_filter(
            $plan->nodePaths(),
            static fn (string $p): bool => $p !== PlanNode::ROOT_PATH,
        ));

        // Le protocole ne crée pas les parents : un niveau à la fois, du plus haut
        // au plus bas. Trier par profondeur rend l'ordre indépendant de l'ordre
        // canonique du plan, qui est alphabétique et ne le garantit pas.
        usort($paths, static function (string $a, string $b): int {
            $depth = substr_count($a, '/') <=> substr_count($b, '/');

            return $depth !== 0 ? $depth : strcmp($a, $b);
        });

        return $paths;
    }
}
