<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Enums\RoleResolutionStrategy;
use App\Exceptions\Filesystem\InvalidTreeSpecException;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Models\DirectoryTemplate;
use App\Models\Pivot\UserGroupUserPivot;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\GroupNameNormalizer;
use App\Services\Filesystem\Plan\PlanResolutionContext;
use App\Services\Filesystem\Plan\PlanResolver;
use App\Services\Filesystem\Plan\PlanSubject;

/**
 * Story 60.2 — LA CHAÎNE COMPLÈTE : un groupe réel → son plan de fichiers.
 *
 * Ce service est « l'appelant » que la story 60.1 annonçait. Le résolveur de plan
 * est PUR : il ne requête rien, tout lui arrive assemblé. Assembler, c'est le
 * travail d'ici — et c'est pour cela que ce service vit HORS du namespace du plan.
 * L'y mettre diluerait la propriété qui rend la résolution testable sans base ;
 * l'en sortir sans garde rouvrirait le chemin vers la dérivation des noms
 * système. D'où un test d'architecture ÉTENDU à ce fichier : il ne connaît aucun
 * service d'exécution, ne lance aucun processus, ne touche à aucun fichier. Il
 * requête SQL, et rien d'autre.
 *
 * **SQL seulement, jamais l'annuaire.** Les rôles et les appartenances sont dans
 * PostgreSQL depuis l'Epic 49 ; l'annuaire est une projection. Une résolution qui
 * irait le lire réintroduirait une dépendance réseau dans un calcul qui doit être
 * rejouable et comparable.
 *
 * ---------------------------------------------------------------------------
 * **LA GARDE DE LA MESURE — pourquoi une audience est un sujet ABSTRAIT.**
 *
 * C'est ICI qu'elle se tient, et nulle part ailleurs. Le résolveur pur ne peut pas
 * la tenir : un rôle PEUT légitimement désigner une personne (la recette « d'un
 * utilisateur à un utilisateur » porte deux rôles de maille utilisateur,
 * cardinalité un), donc le type du sujet ne suffit pas à distinguer une
 * désignation nominative légitime d'une audience énumérée à tort. C'est le choix
 * du sujet qui se garde, et le choix du sujet se fait ici.
 *
 * La mesure, faite en ouverture d'epic sur une arborescence de 631 entrées : poser
 * récursivement des entrées d'accès NOMINATIVES coûte 0,026 s à 30 entrées, 0,32 s
 * à 200, **7,16 s à 1 000, 63,07 s à 3 000** — le coût est QUADRATIQUE (chaque
 * entrée réécrit l'attribut étendu entier sur chaque entrée du système de
 * fichiers), et il existe une **limite DURE à 5 457 entrées** (`E2BIG` : plafond
 * de l'attribut étendu, 64 Ko à douze octets l'entrée) au-delà de laquelle l'appel
 * système échoue tout simplement. Le même octroi rendu par un GROUPE DÉRIVÉ coûte
 * 0,349 s, et un changement de rôle n'y coûte AUCUNE réécriture.
 *
 * Conséquence, tenue par le code de ce fichier : la stratégie « les membres
 * portant tel rôle d'arête » produit **UN sujet abstrait par rôle d'arête listé** —
 * « les membres de ce groupe qui portent ce rôle » — et JAMAIS l'énumération de
 * ces membres. Le nombre de sujets est indépendant de l'effectif : trois membres
 * et trois cents membres produisent exactement les mêmes sujets. Le backend
 * compilera cette abstraction comme il veut (groupe dérivé, story 60.4) ; le plan
 * dit QUI, le backend décide COMMENT.
 *
 * L'énumération nominative reste légitime là où elle coûte UNE entrée par nœud :
 * les nœuds par membre du résolveur (le dossier personnel de l'élève). Elle reste
 * légitime aussi pour une cible DÉSIGNÉE de maille utilisateur, cardinalité un —
 * une personne nommément désignée n'est pas une audience.
 *
 * ---------------------------------------------------------------------------
 * **LE FAIT DE TERRAIN QUI FONDE LA STRATÉGIE D'ARÊTE.** Depuis le repliement de
 * la story 4.13, les noms d'annuaire `Classe_X`, `Equipe_X` et `PP_X` donnent UNE
 * SEULE ligne `user_groups`, au nom nu, et le statut de chacun vit sur l'arête
 * d'appartenance (`member` élève, `manager` enseignant, `owner` professeur
 * principal). **L'équipe pédagogique n'a plus de ligne à elle** : « les membres de
 * la classe portant `manager|owner` » est sa SEULE représentation en base. La
 * stratégie d'arête n'est donc pas une commodité parmi quatre — c'est le mécanisme
 * qui remplace les groupes multiples de l'ancien système.
 *
 * ---------------------------------------------------------------------------
 * **AUCUN CONSOMMATEUR DE PRODUCTION, ET C'EST UN CHOIX.** Personne n'appelle ce
 * service en dehors des tests. Le déclencheur « créer un groupe matérialise son
 * arbre » est câblé en 60.5, quand un backend saura exécuter un arbre et que la
 * recette classe sera seedée et accrochée. Le brancher maintenant produirait soit
 * du code mort, soit — pire — un appel à la matérialisation 34.3, qui ne connaît
 * pas les arbres. La chaîne est livrée complète et DORMANTE.
 */
final class TreePlanService
{
    public function __construct(private readonly PlanResolver $resolver = new PlanResolver()) {}

    /**
     * Le plan du groupe, d'après la recette accrochée à son TYPE.
     *
     * Rend `null` si aucune recette n'est accrochée à ce type — ce n'est PAS une
     * anomalie : l'absence de recette est l'état normal de la quasi-totalité des
     * types de groupes, et lever ici obligerait chaque appelant à rattraper le cas
     * ordinaire.
     *
     * Tout le reste est un échec EXPLICITE : nom de groupe inexploitable, groupe
     * apparenté introuvable, recette invalide ou non accrochable. Jamais un plan
     * partiel — un plan amputé se comparerait « conforme » à un état incomplet, et
     * la détection d'écart (story 60.4) validerait silencieusement une fuite.
     *
     * @throws InvalidTreeSpecException recette invalide ou non auto-résolvable
     * @throws PlanResolutionException  données de résolution inexploitables
     */
    public function planFor(UserGroup $group): ?FilePlan
    {
        $type = is_string($group->type) ? trim((string) $group->type) : '';
        if ($type === '') {
            return null;
        }

        $template = DirectoryTemplate::attachedTo($type);
        if ($template === null) {
            return null;
        }

        // Une recette accrochée doit savoir se résoudre seule : c'est la création
        // du groupe qui l'appellera (60.5), et il n'y a personne pour saisir une
        // cible à ce moment-là. On le revérifie à la LECTURE, pas seulement à
        // l'écriture : un accrochage peut arriver par un chemin qui ne passe pas
        // par le modèle (import, correction manuelle en base).
        $template->assertAttachable();

        return $this->planUsing($group, $template);
    }

    /**
     * Le plan du groupe d'après une recette DONNÉE, cibles désignées comprises.
     *
     * C'est le pont avec le flux manuel de la story 34.3, qui ne change pas : une
     * recette non accrochée y reste résoluble, ses rôles en cible désignée
     * recevant leurs sujets de l'appelant.
     *
     * @param  array<string, list<PlanSubject>|PlanSubject>  $designatedTargets  par clé de rôle
     * @param  array<string, bool>  $nodeActivation  chemin de nœud TEL QU'ÉCRIT => actif
     *
     * @throws InvalidTreeSpecException
     * @throws PlanResolutionException
     */
    public function planUsing(
        UserGroup $group,
        DirectoryTemplate $template,
        array $designatedTargets = [],
        array $nodeActivation = [],
    ): FilePlan {
        return $this->resolver->resolve(
            $template,
            $this->contextFor($group, $template, $designatedTargets, $nodeActivation),
        );
    }

    /**
     * Assemble les ENTRÉES de la résolution : le groupe, ses membres avec leur
     * rôle d'arête, et les sujets de chaque rôle de la recette.
     *
     * @param  array<string, list<PlanSubject>|PlanSubject>  $designatedTargets
     * @param  array<string, bool>  $nodeActivation
     *
     * @throws InvalidTreeSpecException
     * @throws PlanResolutionException
     */
    public function contextFor(
        UserGroup $group,
        DirectoryTemplate $template,
        array $designatedTargets = [],
        array $nodeActivation = [],
    ): PlanResolutionContext {
        $groupId = (int) $group->id;
        if ($groupId <= 0) {
            throw PlanResolutionException::make('le groupe de matérialisation doit être persisté (identité interne).');
        }

        $template->assertValidResolutionSpec();

        $roleTargets = [];
        foreach ($template->roles() as $role) {
            if (! is_array($role)) {
                continue;
            }
            $roleKey = $role['key'] ?? null;
            if (! is_string($roleKey) || $roleKey === '') {
                continue;
            }

            $roleTargets[$roleKey] = $this->subjectsFor($group, $template, $role, $designatedTargets);
        }

        return new PlanResolutionContext(
            groupId: $groupId,
            groupName: (string) $group->name,
            groupType: is_string($group->type) ? (string) $group->type : null,
            members: $this->membersOf($group),
            roleTargets: $roleTargets,
            nodeActivation: $nodeActivation,
        );
    }

    // =========================================================================
    // Les quatre stratégies
    // =========================================================================

    /**
     * Sujets d'UN rôle de recette, selon sa stratégie de résolution.
     *
     * @param  array<string, mixed>  $role
     * @param  array<string, list<PlanSubject>|PlanSubject>  $designatedTargets
     * @return list<PlanSubject>
     *
     * @throws InvalidTreeSpecException
     * @throws PlanResolutionException
     */
    private function subjectsFor(
        UserGroup $group,
        DirectoryTemplate $template,
        array $role,
        array $designatedTargets,
    ): array {
        $roleKey = (string) $role['key'];
        $resolution = $template->resolutionOf($role);

        return match ($resolution['strategy']) {
            // Le groupe de matérialisation, EN ENTIER : aucun rôle d'arête ne
            // qualifie ce sujet.
            RoleResolutionStrategy::Itself => [PlanSubject::group((int) $group->id)],

            // UN sujet ABSTRAIT par rôle d'arête listé. C'est la garde de la
            // mesure : ces sujets ne dépendent pas de l'effectif du groupe, et
            // aucune ligne d'ici ne lit ses membres.
            RoleResolutionStrategy::EdgeRole => array_map(
                static fn (string $edgeRole): PlanSubject => PlanSubject::group((int) $group->id, $edgeRole),
                $resolution['edge_roles'],
            ),

            RoleResolutionStrategy::Pattern => [
                $this->relatedGroupSubject($group, (string) $resolution['pattern'], $roleKey),
            ],

            RoleResolutionStrategy::Designated => $this->designatedSubjects($role, $designatedTargets),
        };
    }

    /**
     * Le groupe APPARENTÉ désigné par un motif de nom, en sujet.
     *
     * **Dette connue, assumée et documentée.** Cet appariement est de la
     * comparaison de CHAÎNES : il reproduit la fragilité de l'ancien système, où
     * les préfixes de nom étaient des littéraux disséminés dans le code. Le progrès
     * retenu ici est modeste mais réel — le préfixe devient une DONNÉE de recette,
     * lisible et modifiable au même endroit que le reste. Une vraie relation
     * explicite entre groupes (une arête groupe→groupe) reste un chantier futur.
     *
     * Rappel de portée : cette stratégie ne peut PAS retrouver l'équipe
     * pédagogique d'une classe, puisque le repliement 4.13 lui a retiré sa ligne.
     * C'est la stratégie d'arête qui porte ce cas, et c'est pour cela que
     * l'apparenté par motif reste hors du chemin critique.
     *
     * **Introuvable = échec EXPLICITE nommant l'attendu**, jamais un plan
     * silencieusement amputé — même doctrine que le pré-contrôle du chemin figé,
     * qui refuse de provisionner plutôt que de provisionner à moitié.
     *
     * @throws PlanResolutionException
     */
    private function relatedGroupSubject(UserGroup $group, string $pattern, string $roleKey): PlanSubject
    {
        $expected = $this->substituteGroupName($group, $pattern, $roleKey);

        $related = UserGroup::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($expected)])
            ->first();

        if ($related === null) {
            throw PlanResolutionException::make(sprintf(
                'le rôle « %s » attend le groupe apparenté « %s » (motif « %s » appliqué à « %s ») : il n\'existe pas.',
                $roleKey,
                $expected,
                $pattern,
                (string) $group->name,
            ));
        }

        return PlanSubject::group((int) $related->id);
    }

    /**
     * Sujets d'un rôle en cible DÉSIGNÉE : ceux que l'appelant fournit.
     *
     * Aucune cible n'est une situation valide — un rôle sans cible n'octroie rien,
     * et il reste néanmoins un rôle du plan (sa clôture est structurelle, pas
     * dérivée de l'effectif). La CARDINALITÉ du rôle, elle, est vérifiée :
     * `one` refuse une seconde cible.
     *
     * @param  array<string, mixed>  $role
     * @param  array<string, list<PlanSubject>|PlanSubject>  $designatedTargets
     * @return list<PlanSubject>
     *
     * @throws PlanResolutionException
     */
    private function designatedSubjects(array $role, array $designatedTargets): array
    {
        $roleKey = (string) $role['key'];
        $raw = $designatedTargets[$roleKey] ?? [];
        $subjects = $raw instanceof PlanSubject ? [$raw] : array_values((array) $raw);

        foreach ($subjects as $subject) {
            if (! $subject instanceof PlanSubject) {
                throw PlanResolutionException::make(sprintf(
                    'la cible désignée du rôle « %s » doit être un sujet de plan (identité interne), pas un nom.',
                    $roleKey,
                ));
            }
        }

        if (($role['cardinality'] ?? null) === 'one' && count($subjects) > 1) {
            throw PlanResolutionException::make(sprintf(
                'le rôle « %s » n\'admet qu\'une seule cible, %d fournies.',
                $roleKey,
                count($subjects),
            ));
        }

        /** @var list<PlanSubject> $subjects */
        return $subjects;
    }

    // =========================================================================
    // Lecture SQL
    // =========================================================================

    /**
     * Membres du groupe avec leur RÔLE D'ARÊTE.
     *
     * Une seule source : `user_group_user.role`. Le drapeau booléen de professeur
     * principal est mort depuis la story 42.2 — il n'est plus écrit, donc plus
     * jamais lu ici.
     *
     * Un rôle d'arête vide ou hors vocabulaire (donnée héritée) est ramené à
     * `member`. Ce n'est pas un relâchement de la doctrine « jamais de plan
     * partiel » : c'est la MÊME normalisation que celle des écrans de groupes
     * depuis la story 42.3, et `member` est déjà le défaut applicatif du
     * rattachement. Refuser tout le plan pour une ligne héritée rendrait la chaîne
     * inutilisable sur les instances en place, sans rien protéger — le membre
     * apparaît de toute façon, avec le rôle le moins doté.
     *
     * **LES COMPTES FÉDÉRÉS SONT EXCLUS.** `source = 'federated'` désigne un
     * technicien provisionné par le login fédéré : il n'est JAMAIS synchronisé vers
     * l'annuaire, n'a donc ni identité d'annuaire ni compte système. Or ces membres
     * ne servent QU'À l'expansion des nœuds par membre — c'est-à-dire à fabriquer
     * un dossier personnel et un octroi nominatif. En laisser passer un
     * produirait, à l'exécution, une entrée d'accès visant un compte qui n'existe
     * pas côté système : l'appel échoue en *argument invalide*, exactement le mode
     * de rupture déjà rencontré sur un nom de groupe mal suffixé.
     *
     * Le filtre reprend le motif canonique du dépôt (`source = 'ad'` OU `NULL`),
     * déjà employé par la réconciliation des départs et par les profils de droits,
     * pour la même raison écrite au même endroit : ces comptes n'existent pas dans
     * l'annuaire. La tolérance au `NULL` est de la parité de motif, pas un cas
     * atteignable : la colonne est non nullable et porte le défaut `'ad'` depuis sa
     * création — aucun test ne l'exerce donc, et il n'y aurait rien à exercer.
     *
     * NB : l'écran de groupe laisse aujourd'hui rattacher un compte fédéré à une
     * classe — le picker ne filtre pas la source. Ce n'est donc pas hypothétique,
     * seulement sans conséquence tant que rien n'exécute un plan. On ferme ici ce
     * que ce service contrôle ; assainir le picker est un autre chantier.
     *
     * @return list<array{id:int,login:string,edge_role:string}>
     */
    private function membersOf(UserGroup $group): array
    {
        $members = [];

        foreach ($group->users as $user) {
            $source = $user->source ?? null;
            if ($source !== null && $source !== 'ad') {
                continue;
            }

            $edgeRoleRaw = (string) ($user->pivot->role ?? '');
            $edgeRole = GroupNameNormalizer::isKnownEdgeRole($edgeRoleRaw)
                ? $edgeRoleRaw
                : UserGroupUserPivot::ROLE_MEMBER;

            $members[] = [
                'id' => (int) $user->id,
                'login' => (string) $user->login,
                'edge_role' => $edgeRole,
            ];
        }

        return $members;
    }

    /**
     * Substitue un motif de NOM DE GROUPE (vocabulaire fermé).
     *
     * Distinct de la substitution de CHEMIN du résolveur, et c'est volontaire : un
     * nom de groupe n'est pas un segment de chemin. Il peut légitimement porter un
     * « @ » (la matière dans une classe), que le motif de segment refuse. Valider
     * ici comme un chemin rendrait irrésolvables des noms parfaitement valides.
     *
     * @throws PlanResolutionException
     */
    private function substituteGroupName(UserGroup $group, string $pattern, string $roleKey): string
    {
        $values = [DirectoryTemplate::PLACEHOLDER_GROUP_NAME => (string) $group->name];

        $bare = GroupNameNormalizer::bareName(
            (string) $group->name,
            is_string($group->type) ? (string) $group->type : null,
        );
        if ($bare !== null) {
            $values[DirectoryTemplate::PLACEHOLDER_GROUP_BARE_NAME] = $bare;
        }

        $resolved = preg_replace_callback(
            '/\{([^{}]*)\}/',
            static function (array $m) use ($values, $roleKey): string {
                if (! array_key_exists($m[1], $values)) {
                    throw PlanResolutionException::make(sprintf(
                        'placeholder « {%s} » non résolvable dans le motif de nom du rôle « %s » '
                        . '(vocabulaire disponible : %s).',
                        $m[1],
                        $roleKey,
                        implode(', ', array_map(static fn (string $k): string => '{' . $k . '}', array_keys($values))),
                    ));
                }

                return $values[$m[1]];
            },
            $pattern,
        ) ?? '';

        if (trim($resolved) === '') {
            throw PlanResolutionException::make(sprintf(
                'le motif de nom du rôle « %s » se résout en une chaîne vide.',
                $roleKey,
            ));
        }

        return $resolved;
    }
}
