<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendOutcome;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;

/**
 * Story 60.4 — la COMPILATION d'un nœud de plan en entrées de liste d'accès
 * POSIX.
 *
 * C'est le cœur de la descente, et c'est aussi le code qui ne devait surtout pas
 * être réécrit : le jeu canonique de base, l'ordre des entrées d'accès et de leurs
 * miroirs d'héritage, l'échappement du groupe d'annuaire à espace, la
 * correspondance `ro → rx` / `rw → rwx` viennent tels quels du provisionnement
 * 34.1. Un référentiel figé en littéraux, capturé sur le comportement AVANT la
 * descente, verrouille l'ensemble chaîne par chaîne
 * ({@see \Tests\Unit\Services\Filesystem\Backend\Posix\PosixGoldenAclTest}).
 *
 * **Trois états d'octroi, trois traductions** — la distinction que la story 60.1
 * a passé un critère entier à établir, et qu'il aurait été facile d'écraser ici :
 *  - octroi ACTIF → une entrée au niveau demandé (`rx` ou `rwx`) ;
 *  - octroi SUSPENDU → une entrée EXPLICITEMENT VIDE (`---`). L'octroi existe, il
 *    ne donne rien, le dossier et les données restent. C'est la forme matérialisée
 *    de la suspension, et c'est ce qui permet à la comparaison désiré/observé de
 *    ne pas confondre « suspendu » avec « supprimé » ;
 *  - rôle en CLÔTURE → RIEN. Il n'y a pas de refus en POSIX : l'absence d'entrée
 *    EST la fermeture. Le nœud n'écrit donc aucun geste pour sa clôture, et la
 *    comparaison ne lui réclame rien. C'est un backend à propagation qui devra la
 *    matérialiser.
 *
 * **Le parc ne contribue rien**, et il n'a même pas à être exclu ici : le plan ne
 * porte aucun octroi pour lui (invariant du modèle à deux axes, tenu en amont par
 * le projecteur de plan).
 *
 * **PUR au sens des entrées/sorties** : cette classe n'exécute rien. La seule
 * chose qu'elle appelle et qui touche au système est la traduction des sujets,
 * qui doit interroger l'annuaire pour ne jamais inventer un nom.
 */
final class PosixAclCompiler
{
    /**
     * GARDE-FOU D'ÉCHELLE — nombre maximal d'entrées NOMINATIVES sur un même
     * nœud.
     *
     * Ce n'est pas un réglage, c'est la lecture directe d'une mesure faite sur
     * une arborescence de 631 fichiers : la pose récursive coûte 0,026 s à 30
     * entrées nominatives, 0,32 s à 200, **7,16 s à 1 000, 63,07 s à 3 000**, et
     * bute sur une limite dure du système autour de 5 457. Le coût est
     * QUADRATIQUE — chaque entrée réécrit l'attribut étendu entier sur chaque
     * fichier. 200 est le dernier point sous le dixième de seconde ; au-delà, la
     * voie légitime n'est pas d'attendre, c'est le groupe dérivé.
     *
     * Un nœud qui dépasserait n'écrit RIEN et le dit avec ses chiffres — plutôt
     * qu'une minute de pose silencieuse dont personne ne comprendrait la cause.
     *
     * **Les nœuds par membre ne sont pas concernés par construction** : ils
     * portent UNE entrée nominative chacun, jamais une audience entière.
     */
    public const NOMINATIVE_ENTRIES_CEILING = 200;

    /**
     * Jeu canonique de base, identique pour tout nœud géré. Le groupe
     * d'administration garde la main, « les autres » n'ont rien, et chaque entrée
     * a son miroir d'héritage pour que le contenu créé ensuite hérite du même
     * contrat.
     *
     * @var list<string>
     */
    public const BASE_ACLS = [
        'user::rwx',
        'group::---',
        'group:domain\\040admins:rwx',
        'mask::rwx',
        'other::---',
        'default:user::rwx',
        'default:group::---',
        'default:group:domain\\040admins:rwx',
        'default:mask::rwx',
        'default:other::---',
    ];

    /** Niveau d'entrée d'un octroi ACTIF, par niveau d'accès du plan. */
    private const MODES = [
        PlanGrant::ACCESS_RO => 'rx',
        PlanGrant::ACCESS_RW => 'rwx',
    ];

    /** Niveau d'entrée d'un octroi SUSPENDU : présent, et vide. */
    private const MODE_SUSPENDED = '---';

    public function __construct(private readonly PosixSubjectProjector $projector)
    {
    }

    public function compile(PlanNode $node): CompiledNodeAcl
    {
        $acls = self::BASE_ACLS;
        $refusals = [];
        $nominative = 0;

        foreach ($node->grants as $grant) {
            $projection = $this->projector->project($grant->subject);

            if (! $projection->isResolved()) {
                $refusal = new CompiledRefusal(
                    $projection->refusal ?? FileBackendOutcome::Echec,
                    (string) $projection->detail,
                    blocking: $projection->blocking,
                );

                // Un refus BLOQUANT arrête la compilation du nœud sur-le-champ :
                // rendre la liste partielle laisserait au backend une ACL qui a
                // l'air complète, et la pose commence par une purge.
                if ($refusal->blocking) {
                    return new CompiledNodeAcl([], [$refusal]);
                }

                $refusals[] = $refusal;

                continue;
            }

            $mode = $grant->isActive()
                ? (self::MODES[$grant->access] ?? self::MODES[PlanGrant::ACCESS_RO])
                : self::MODE_SUSPENDED;

            if ($projection->type === PosixSubjectProjection::TYPE_USER) {
                $nominative++;
            }

            $acls[] = "{$projection->type}:{$projection->name}:{$mode}";
            $acls[] = "default:{$projection->type}:{$projection->name}:{$mode}";
        }

        if ($nominative > self::NOMINATIVE_ENTRIES_CEILING) {
            return new CompiledNodeAcl([], [new CompiledRefusal(
                FileBackendOutcome::Echec,
                sprintf(
                    'ce nœud produirait %d entrées nominatives, au-delà du plafond de %d : rien n\'a été '
                    . 'écrit. Le coût de la pose est quadratique (mesuré : %d entrées ≈ 7 s, %d ≈ 63 s) et '
                    . 'le système la refuse au-delà de %d environ. La voie prévue pour une audience de cette '
                    . 'taille est un groupe dérivé, pas une énumération des personnes.',
                    $nominative,
                    self::NOMINATIVE_ENTRIES_CEILING,
                    1000,
                    3000,
                    5457,
                ),
                blocking: true,
            )]);
        }

        return new CompiledNodeAcl($acls, $refusals);
    }
}
