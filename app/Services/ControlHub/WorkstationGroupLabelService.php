<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubLabelMode;
use App\Exceptions\ControlHub\LabelAssignmentException;
use App\Exceptions\ControlHub\UpstreamLockCollisionException;
use App\Models\ControlHubContract;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\ControlHub\Resolution\UpstreamLockCollisionDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service de mapping refnum : rattache un label « libre » (`free`)
 * du contrat amont (controlHub) **actif** à un WorkstationGroup, ou l'en détache.
 *
 * Le lien WG↔label est porté par la colonne string nullable
 * `workstation_groups.controlhub_label` (rattachement PAR NOM, cf. décision de
 * design — pas de FK dure). L'intégrité référentielle est assurée ICI, à
 * l'assignation : le label doit être un label `free` réellement déclaré par le
 * contrat actif. L'invariant « 1 label max » est applicatif (refus d'un 2e label
 * différent), pas une contrainte DB.
 *
 * Patron : {@see \App\Services\Parc\WorkstationGroupService::updateGroup()}
 * (résolution + validation + `DB::transaction` + `Log::info`). ⚠️ On ne route
 * **PAS** l'écriture du label via `updateGroup()` : celui-ci lève une
 * `RuntimeException` si le groupe est `locked` (verrou AD), ce qui n'a aucun sens
 * pour un label libre — concern distinct. Écriture ciblée sur la seule colonne
 * `controlhub_label`.
 *
 * Écrire le label ne suffit pas : ce qu'un item de contrat destine au label doit
 * atteindre le parc. Chaque écriture rejoue donc la pose du
 * {@see ContractAssignmentReconciler} — {@see reapplyContractAssignments()}.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans ce service, ses méthodes, ses
 *    messages. Vocabulaire imposé : « amont » / `ControlHub*` / `label`.
 * ⚠️ La lecture du contrat amont n'est injectée que dans le mapping de
 *    label ; sans contrat actif, {@see assignLabel()} refuse, {@see detachLabel()}
 *    reste neutre — le comportement parc standalone est inchangé.
 */
class WorkstationGroupLabelService
{
    /**
     * @param ?UpstreamLockCollisionDetector $collisionDetector
     *         garde prédictive verrou/verrou. Nullable + résolu paresseusement via
     *         le conteneur pour préserver l'instanciation directe `new
     *  WorkstationGroupLabelService()` (tests) sans dépendance dure.
     */
    public function __construct(
        private readonly ?UpstreamLockCollisionDetector $collisionDetector = null,
    ) {}

    /**
     * Assigne un label libre du contrat amont actif à un WorkstationGroup.
     *
     * Matrice de refus (l'idempotence est évaluée AVANT tout) :
     * - groupe déjà labellisé avec CE label ⇒ no-op idempotent (return, pas d'exception)
     * - aucun contrat actif ⇒ {@see LabelAssignmentException::noActiveContract()}
     * - label absent du contrat ⇒ {@see LabelAssignmentException::unknown()}
     * - label `reserved` ⇒ {@see LabelAssignmentException::reserved()}
     * - groupe déjà labellisé avec un AUTRE label ⇒ {@see LabelAssignmentException::alreadyLabeled()}
     *
     * ⚠️ Le no-op idempotent est vérifié EN PREMIER (avant la résolution du
     *    contrat) : ré-affirmer le label déjà porté ne doit JAMAIS échouer, même
     *    si ce label est devenu `reserved` ou orphelin depuis, ou si le lien amont
     *    a été rompu — sinon éditer un autre champ d'un groupe labellisé lèverait
     *    une fausse erreur.
     *
     * @throws LabelAssignmentException
     * @throws UpstreamLockCollisionException — l'assignation
     *         introduirait une collision verrou/verrou insoluble (refus avant écriture)
     */
    public function assignLabel(WorkstationGroup $group, string $labelName): void
    {
        if ($group->controlhub_label === $labelName) {
            return; // idempotent : le groupe porte déjà CE label, rien à écrire.
        }

        $contract = ControlHubContract::active();

        if ($contract === null) {
            throw LabelAssignmentException::noActiveContract();
        }

        /** @var \App\Models\ControlHubContractLabel|null $label */
        $label = $contract->labels()->where('name', $labelName)->first();

        if ($label === null) {
            throw LabelAssignmentException::unknown($labelName);
        }

        if ($label->mode === ControlHubLabelMode::Reserved) {
            throw LabelAssignmentException::reserved($labelName);
        }

        // Invariant « 1 label max » : un 2e label DIFFÉRENT est refusé (le no-op
        // idempotent du même label a déjà court-circuité plus haut).
        if ($group->hasControlHubLabel()) {
            throw LabelAssignmentException::alreadyLabeled($group, $labelName);
        }

        // Garde prédictive : refuse AVANT toute écriture si
        // l'assignation introduit une collision verrou/verrou insoluble.
        $this->guardUpstreamLockCollision($group, $labelName);

        DB::transaction(function () use ($group, $labelName): void {
            $group->controlhub_label = $labelName;
            $group->save();
        });

        Log::info('WorkstationGroupLabelService: label amont assigné', [
            'group_id' => $group->id,
            'group_name' => $group->name,
            'label' => $labelName,
        ]);

        $this->reapplyContractAssignments($group, $labelName);
    }

    /**
     * Rejoue la pose des assignations du contrat après un changement de label.
     *
     * Le lien label↔parc est la SEULE donnée que
     * {@see ContractAssignmentReconciler} ne relit pas de lui-même : sa passe est
     * déclenchée par la réception d'un contrat, jamais par le parc. Un parc
     * étiqueté après la dernière réception restait donc `pending` avec le motif
     * « Aucun parc ne porte le label » — le contrat le destinait à ce parc, et rien
     * n'était posé jusqu'à la réception suivante.
     *
     * La passe est globale et idempotente. Elle suit le commit du label : un échec
     * ici ne doit pas défaire un rattachement valide — il est tracé, et la
     * prochaine réception rattrape.
     */
    private function reapplyContractAssignments(WorkstationGroup $group, ?string $label): void
    {
        try {
            $result = app(ContractAssignmentReconciler::class)->reconcile();

            Log::info('WorkstationGroupLabelService: assignations du contrat rejouées', [
                'group_id' => $group->id,
                'label' => $label,
            ] + $result->toArray());
        } catch (Throwable $e) {
            Log::error('WorkstationGroupLabelService: échec de la pose déclenchée par un changement de label', [
                'group_id' => $group->id,
                'label' => $label,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Garde prédictive verrou/verrou. Refuse l'assignation de
     * `$labelName` à `$group` si elle INTRODUIRAIT, pour au moins un poste membre,
     * une collision insoluble : deux items amont `locked` imposant des valeurs
     * contradictoires sur la MÊME `exclusiveKey`.
     *
     * Court-circuit AVANT tout eager-load de la population : si aucun
     * item label `locked` (ou pas de contrat actif), on ne charge JAMAIS
     * `$group->workstations` (assignation strictement inchangée).
     *
     * @throws UpstreamLockCollisionException
     */
    private function guardUpstreamLockCollision(WorkstationGroup $group, string $labelName): void
    {
        $detector = $this->collisionDetector ?? app(UpstreamLockCollisionDetector::class);

        // Court-circuit EN TÊTE : zéro requête population sans item label locked.
        if (! $detector->hasLockedLabelItems()) {
            return;
        }

        $workstations = $group->workstations()->get();
        if ($workstations->isEmpty()) {
            return; // population vide ⇒ aucune collision possible.
        }

        // Labels portés par chaque poste HORS le slot de `$group` (il portait au
        // plus 1 label, remplacé par `$labelName`).
        $existingLabels = $detector->carriedLabelsExcludingGroups(
            $workstations->pluck('id')->all(),
            [(int) $group->id],
        );

        $collisions = $detector->collisionsFromLabelGainedBy(
            $workstations,
            $labelName,
            fn (Workstation $workstation): array => $existingLabels[(int) $workstation->getKey()] ?? [],
        );

        if ($collisions !== []) {
            throw UpstreamLockCollisionException::fromCollisions($collisions);
        }
    }

    /**
     * Détache le label de contrat amont porté par un WorkstationGroup.
     *
     * No-op si le groupe ne porte aucun label. Un label **réservé** porté par un
     * groupe **imposé** n'est pas détachable ici : le refnum ne manipule que des
     * labels libres.
     *
     * ⚠️ GARDE-FOU SERVEUR : on refuse de détacher un
     *    label `reserved` du contrat actif. La propriété Livewire pilotant le
     *    détachement est forgeable côté client ; l'invariant « le refnum ne
     *    manipule que des labels libres » DOIT donc être tenu ici, pas dans l'UI.
     *
     * @throws LabelAssignmentException si le label porté est `reserved` (contrat actif)
     */
    public function detachLabel(WorkstationGroup $group): void
    {
        if (! $group->hasControlHubLabel()) {
            return; // déjà détaché : rien à faire.
        }

        $previous = $group->controlhub_label;

        $contract = ControlHubContract::active();

        if ($contract !== null) {
            /** @var \App\Models\ControlHubContractLabel|null $label */
            $label = $contract->labels()->where('name', $previous)->first();

            if ($label !== null && $label->mode === ControlHubLabelMode::Reserved) {
                throw LabelAssignmentException::reserved($previous);
            }
        }

        DB::transaction(function () use ($group): void {
            $group->controlhub_label = null;
            $group->save();
        });

        Log::info('WorkstationGroupLabelService: label amont détaché', [
            'group_id' => $group->id,
            'group_name' => $group->name,
            'previous_label' => $previous,
        ]);

        // Le prune du reconciler retire ce que le parc ne doit plus recevoir : sans
        // cette passe, un parc détaché gardait les assignations du label perdu.
        $this->reapplyContractAssignments($group, $previous);
    }
}
