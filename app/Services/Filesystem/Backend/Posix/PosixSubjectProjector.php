<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\Filesystem\ShareService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Story 60.4 — la TRADUCTION des sujets, dans les deux sens.
 *
 * Le plan nomme des identités internes ; une liste d'accès POSIX nomme des
 * comptes et des groupes système. Ce service est le seul endroit du dépôt où les
 * deux se rencontrent — c'est ce qui rend la ligne de coupe tenable : au-dessus,
 * personne n'a besoin de savoir ce qu'est un nom de groupe système.
 *
 * ---------------------------------------------------------------------------
 * **LE MAPPAGE D'ARÊTE EST UN MAPPAGE DE COMPATIBILITÉ, PAS UNE GÉNÉRALISATION.**
 *
 * Un sujet de plan peut porter un rôle d'arête (`member|manager|owner`) : « les
 * membres de ce groupe qui portent ce rôle ». L'annuaire ne connaît un tel
 * découpage que pour les classes et les équipes, où la synchronisation
 * historique entretient un TRIO de groupes. On recopie ce trio, et rien de plus :
 *
 *  | rôle d'arête | groupe système, types `classe`/`equipe` |
 *  |--------------|------------------------------------------|
 *  | `member`     | `classe_<base>`                          |
 *  | `manager`    | `equipe_<base>`                          |
 *  | `owner`      | `pp_<base>`                              |
 *
 * **Approximation ASSUMÉE** : le groupe des enseignants d'une classe contient les
 * professeurs principaux — `equipe_` est donc un SURENSEMBLE du rôle strict de
 * gestionnaire. C'est la doctrine additive de l'epic : jamais un sous-ensemble
 * silencieux (qui retirerait un accès sans le dire), toujours un surensemble
 * nommé.
 *
 * Pour tout AUTRE type de groupe, le rôle `member` se projette sur la cible
 * primaire — le collectif entier, surensemble assumé lui aussi (les gestionnaires
 * sont aussi membres). Les rôles `manager` et `owner`, eux, N'ONT PAS de groupe
 * système : l'octroi n'est pas écrit et le nœud rend une DETTE (`non_implemente`)
 * nommant le rôle ET le type. Le mécanisme existe — la synchronisation d'annuaire
 * le prouve pour les classes — c'est SE5 qui ne le projette pas ailleurs.
 * Répondre « non exprimable » ici affirmerait une limite permanente du modèle
 * POSIX, ce qui serait faux, et l'affichage grisé/masqué s'en trouverait inversé.
 *
 * Pourquoi ne pas étendre l'annuaire à des groupes par rôle pour toutes les
 * verticales ? Parce que POSIX est une ÉTAPE (décision Q-D) : ce serait un
 * investissement d'annuaire à durée de vie limitée et coûteux à défaire. Le trio
 * suffit au seul cas que la story suivante doit rendre iso.
 *
 * ---------------------------------------------------------------------------
 * **AUCUN NOM DE GROUPE N'EST INVENTÉ.** Avant d'écrire un octroi de groupe, on
 * vérifie que le nom se résout côté système ({@see groupExists()} — lecture NSS,
 * sans élévation de privilège, exactement le mécanisme déjà en service pour les
 * partages de classe). Nom introuvable ⇒ octroi NON écrit et échec nommant le
 * groupe attendu. C'est l'incident mesuré du groupe d'équipe sans suffixe
 * d'établissement : l'ancienne séquence posait la ligne, l'outil échouait avec
 * « argument invalide », et la seule trace était une entrée de journal. Pire
 * encore aurait été une ligne posée sans effet.
 *
 * Le nom attendu figure dans le `detail` du rapport, à dessein : c'est
 * l'information qui rend l'incident réparable. C'est la seule dérogation à la
 * neutralité des rapports, et elle est délibérée.
 */
final class PosixSubjectProjector
{
    /**
     * Mémo des résolutions d'annuaire du passage courant : une même audience
     * revient sur plusieurs nœuds d'un même arbre, et interroger le système une
     * fois par nœud n'apprendrait rien de plus.
     *
     * Seuls les verdicts FERMES y entrent (résolu / absent) : un doute est
     * transitoire et se redemande.
     *
     * @var array<string, string>
     */
    private array $groupExistence = [];

    public function __construct(private readonly ShareService $shareService)
    {
    }

    // =========================================================================
    // Projection AVANT (plan → sujet d'ACL)
    // =========================================================================

    /**
     * Nom de groupe système d'un {@see UserGroup} — MAPPAGE HISTORIQUE, repris
     * mot pour mot du provisionnement 34.1 :
     *  - type `classe` → `classe_<localPart>` ;
     *  - type `equipe` → `equipe_<localPart>` ;
     *  - sinon → `<localPart>` : le collectif dont le nom système est son propre
     *    nom court.
     *
     * `localPart` = nom court en minuscules + suffixe d'établissement fédéré. Un
     * préfixe `classe_`/`equipe_` déjà présent est retiré avant re-préfixage
     * (anti double-préfixe). `null` si le nom n'est pas dérivable.
     *
     * C'est ce mappage que le référentiel figé de la story verrouille, et c'est
     * lui que la reprojection inverse rejoue en avant pour construire son index.
     */
    public function unixGroupForGroup(UserGroup $group): ?string
    {
        $local = $this->shareService->aclGroupLocalPart($group);
        if ($local === null) {
            // Nom refusé par le durcissement : repli sur le nom nu en minuscules
            // s'il est sûr, abandon sinon. On N'AJOUTE PAS de préfixe ici — ce
            // chemin n'est atteint que pour un nom déjà rejeté, et le motif
            // ci-dessous ne laisse passer que des noms sûrs.
            $fallback = strtolower((string) $group->name);

            return preg_match('/^[a-z0-9._-]+$/', $fallback) === 1 ? $fallback : null;
        }

        $bare = $this->stripAclPrefix($local);

        return match ($group->type) {
            'classe' => 'classe_' . $bare,
            'equipe' => 'equipe_' . $bare,
            default => $local,
        };
    }

    /**
     * Traduit un sujet de plan en sujet d'ACL, ou dit pourquoi il ne s'écrit pas.
     *
     * La vérification d'existence côté annuaire fait partie de la traduction :
     * un nom qu'on ne peut pas résoudre n'est pas une traduction, c'est une
     * invention.
     */
    public function project(PlanSubject $subject): PosixSubjectProjection
    {
        if ($subject->type === PlanSubject::TYPE_USER) {
            return $this->projectUser($subject);
        }

        return $this->projectGroup($subject);
    }

    private function projectUser(PlanSubject $subject): PosixSubjectProjection
    {
        $user = User::find($subject->id);
        if (! $user instanceof User) {
            return PosixSubjectProjection::echec(sprintf(
                'le compte interne #%d n\'existe plus : son octroi n\'a pas été écrit.',
                $subject->id,
            ));
        }

        $login = (string) $user->login;
        if ($login === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $login) !== 1) {
            return PosixSubjectProjection::echec(sprintf(
                'le compte interne #%d n\'a pas de nom d\'ouverture de session utilisable côté système : '
                . 'son octroi n\'a pas été écrit.',
                $subject->id,
            ));
        }

        return PosixSubjectProjection::user($login);
    }

    private function projectGroup(PlanSubject $subject): PosixSubjectProjection
    {
        $group = UserGroup::find($subject->id);
        if (! $group instanceof UserGroup) {
            return PosixSubjectProjection::echec(sprintf(
                'le groupe interne #%d n\'existe plus : son octroi n\'a pas été écrit.',
                $subject->id,
            ));
        }

        $name = $this->groupNameFor($group, $subject->edgeRole);

        if ($name instanceof PosixSubjectProjection) {
            return $name; // refus déjà formulé (dette de projection)
        }

        if ($name === null) {
            return PosixSubjectProjection::echec(sprintf(
                'aucun nom de groupe système n\'est dérivable du groupe interne #%d : '
                . 'son octroi n\'a pas été écrit.',
                $subject->id,
            ));
        }

        return match ($this->groupExistence($name)) {
            self::EXISTENCE_RESOLVED => PosixSubjectProjection::group($name),

            self::EXISTENCE_ABSENT => PosixSubjectProjection::echec(sprintf(
                'le groupe système attendu « %s » ne se résout pas côté serveur : son octroi n\'a pas été '
                . 'écrit (une entrée posée sur un nom inconnu est refusée par le système, ou pire, reste '
                . 'sans effet).',
                $name,
            )),

            // Ni « il existe » ni « il n'existe pas » : la QUESTION a échoué.
            default => PosixSubjectProjection::echecBloquant(sprintf(
                'impossible de savoir si le groupe système « %s » se résout : l\'interrogation des bases de '
                . 'noms du serveur a échoué. Rien n\'a été écrit NI purgé sur ce nœud — un doute sur la '
                . 'résolution de noms ne doit pas se transformer en retrait d\'accès. Vérifiez la jonction au '
                . 'domaine et le service de résolution, puis relancez la réconciliation.',
                $name,
            )),
        };
    }

    /**
     * Nom de groupe système attendu pour un groupe et un rôle d'arête.
     *
     * Rend une {@see PosixSubjectProjection} de refus quand le rôle n'est pas
     * projeté pour ce type — c'est une DETTE nommée, pas une absence de nom.
     *
     * @return string|PosixSubjectProjection|null
     */
    private function groupNameFor(UserGroup $group, ?string $edgeRole)
    {
        if ($edgeRole === null) {
            return $this->unixGroupForGroup($group);
        }

        $type = strtolower(trim((string) $group->type));

        if ($type === 'classe' || $type === 'equipe') {
            $local = $this->shareService->aclGroupLocalPart($group);
            if ($local === null) {
                return null;
            }
            $bare = $this->stripAclPrefix($local);

            return match ($edgeRole) {
                'member' => 'classe_' . $bare,
                // Surensemble ASSUMÉ : le groupe des enseignants contient aussi
                // les professeurs principaux (doctrine additive — jamais un
                // sous-ensemble silencieux).
                'manager' => 'equipe_' . $bare,
                'owner' => 'pp_' . $bare,
                default => null,
            };
        }

        if ($edgeRole === 'member') {
            // Cible primaire : le collectif entier. Surensemble assumé — les
            // gestionnaires en sont aussi membres.
            return $this->unixGroupForGroup($group);
        }

        return PosixSubjectProjection::nonImplemente(sprintf(
            'le rôle « %s » n\'est pas projeté en groupe système pour un groupe de type « %s » : '
            . 'le mécanisme existe côté annuaire, SE5 ne le pilote pas pour ce type. '
            . 'L\'octroi n\'a pas été écrit.',
            $edgeRole,
            $type === '' ? 'non renseigné' : $type,
        ));
    }

    /** Retire un préfixe `classe_`/`equipe_` de tête (le nom est déjà en minuscules). */
    private function stripAclPrefix(string $local): string
    {
        foreach (['classe_', 'equipe_'] as $prefix) {
            if (str_starts_with($local, $prefix)) {
                return substr($local, strlen($prefix));
            }
        }

        return $local;
    }

    /**
     * Le nom se résout-il côté système (base locale + annuaire via le démon de
     * jonction) ? Lecture seule, SANS élévation de privilège — même mécanisme
     * que le pré-contrôle des partages de classe. Le nom est déjà contraint par
     * la dérivation ; l'échappement d'argument est une défense en profondeur.
     *
     * **TROIS issues, pas deux.** Confondre « la réponse est non » avec « il n'y
     * a pas eu de réponse » est ici une faute aux conséquences physiques : la
     * pose commence par purger les droits étendus du répertoire, donc traiter un
     * silence comme une absence ferait d'une panne de résolution de noms une
     * révocation d'accès. L'outil distingue le cas nominal (code 2, clé
     * introuvable) de tout autre échec — binaire absent du chemin d'exécution,
     * base de noms injoignable, délai dépassé.
     */
    private const EXISTENCE_RESOLVED = 'resolved';

    private const EXISTENCE_ABSENT = 'absent';

    private const EXISTENCE_UNKNOWN = 'unknown';

    /** Code de sortie de l'outil quand la clé demandée est simplement absente. */
    private const GETENT_KEY_NOT_FOUND = 2;

    private function groupExistence(string $name): string
    {
        if (array_key_exists($name, $this->groupExistence)) {
            return $this->groupExistence[$name];
        }

        $result = Process::run('getent group ' . escapeshellarg($name));

        $verdict = match (true) {
            $result->successful() => self::EXISTENCE_RESOLVED,
            $result->exitCode() === self::GETENT_KEY_NOT_FOUND => self::EXISTENCE_ABSENT,
            default => self::EXISTENCE_UNKNOWN,
        };

        // Le doute n'est PAS mémorisé : il est transitoire par nature, et le
        // mémoriser propagerait une panne d'un instant à tout le passage.
        if ($verdict === self::EXISTENCE_UNKNOWN) {
            return $verdict;
        }

        return $this->groupExistence[$name] = $verdict;
    }

    // =========================================================================
    // Projection INVERSE (nom système → sujet de plan)
    // =========================================================================

    /**
     * Index INVERSE `nom de groupe système (minuscules) → sujet de plan`.
     *
     * **Par projection EN AVANT, jamais par retrait de suffixe.** On projette des
     * candidats vers leur nom système et on indexe le résultat : c'est robuste au
     * suffixe d'établissement, que tout découpage de chaîne finirait par écorcher.
     *
     * **Les sujets DU PLAN d'abord.** Un même groupe peut être exprimé de deux
     * façons — nu, ou par un rôle d'arête — et les deux peuvent produire le même
     * nom système (`classe_x` est à la fois le mappage nu d'une classe et son
     * rôle `member`). Indexer le plan en premier lève l'ambiguïté dans le sens
     * qui compte : une entrée relue se reprojette sur le sujet QUE LE PLAN
     * EXPRIME, donc la comparaison désiré/observé compare bien deux fois la même
     * chose. L'index général ne sert qu'aux entrées étrangères au plan.
     *
     * @return array{groups: array<string, PlanSubject>, logins: array<string, PlanSubject>}
     */
    public function reverseIndex(FilePlan $plan): array
    {
        $groups = [];
        $logins = [];

        foreach ($plan->nodes as $node) {
            foreach ($node->grants as $grant) {
                $subject = $grant->subject;

                if ($subject->type === PlanSubject::TYPE_USER) {
                    $user = User::find($subject->id);
                    $login = $user instanceof User ? strtolower((string) $user->login) : '';
                    if ($login !== '' && ! array_key_exists($login, $logins)) {
                        $logins[$login] = $subject;
                    }

                    continue;
                }

                $group = UserGroup::find($subject->id);
                if (! $group instanceof UserGroup) {
                    continue;
                }
                $name = $this->groupNameFor($group, $subject->edgeRole);
                if (! is_string($name)) {
                    continue;
                }
                $key = strtolower($name);
                if (! array_key_exists($key, $groups)) {
                    $groups[$key] = $subject;
                }
            }
        }

        // Index GÉNÉRAL : tout groupe connu de SE5, projeté nu. Il ne sert qu'aux
        // entrées que le plan n'exprime pas — celles qui sont « en trop » sur le
        // disque et qu'il faut pouvoir NOMMER en vocabulaire de plan plutôt que
        // de compter comme inconnues.
        foreach (UserGroup::query()->get() as $group) {
            $name = $this->unixGroupForGroup($group);
            if ($name === null) {
                continue;
            }
            $key = strtolower($name);
            if (array_key_exists($key, $groups)) {
                continue;
            }
            $groups[$key] = PlanSubject::group((int) $group->id);
        }

        return ['groups' => $groups, 'logins' => $logins];
    }

    /**
     * Sujet de plan d'un nom d'ouverture de session relu sur le disque, en
     * consultant d'abord l'index du plan puis la base.
     *
     * @param  array<string, PlanSubject>  $logins
     */
    public function subjectForLogin(string $login, array $logins): ?PlanSubject
    {
        $key = strtolower($login);
        if (array_key_exists($key, $logins)) {
            return $logins[$key];
        }

        $user = User::where('login', $login)->first();
        if (! $user instanceof User) {
            Log::debug('PosixSubjectProjector: nom d\'ouverture de session relu sans correspondance interne');

            return null;
        }

        return PlanSubject::user((int) $user->id);
    }
}
