<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubLabelMode;
use App\Exceptions\ControlHub\LabelAssignmentException;
use App\Models\ControlHubContract;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 30.2 — Service de mapping refnum : rattache un label « libre » (`free`)
 * du contrat amont (controlHub) **actif** à un WorkstationGroup, ou l'en détache.
 *
 * Le lien WG↔label est porté par la colonne string nullable
 * `workstation_groups.controlhub_label` (rattachement PAR NOM, cf. décision de
 * design 30.2 — pas de FK dure). L'intégrité référentielle est assurée ICI, à
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
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans ce service, ses méthodes, ses
 *    messages. Vocabulaire imposé : « amont » / `ControlHub*` / `label`. [prd#R3]
 * ⚠️ NFR3 : la lecture du contrat amont n'est injectée que dans le mapping de
 *    label ; sans contrat actif, {@see assignLabel()} refuse, {@see detachLabel()}
 *    reste neutre — le comportement parc standalone est inchangé.
 */
class WorkstationGroupLabelService
{
    /**
     * Assigne un label libre du contrat amont actif à un WorkstationGroup.
     *
     * Matrice de refus (ordre : idempotence AVANT tout — cf. review 30.2 #1/#7) :
     * - groupe déjà labellisé ===  ⇒ no-op idempotent (return, pas d'exception) (AC #4)
     * - aucun contrat actif       ⇒ {@see LabelAssignmentException::noActiveContract()}
     * - label absent du contrat   ⇒ {@see LabelAssignmentException::unknown()} (AC #6)
     * - label `reserved`          ⇒ {@see LabelAssignmentException::reserved()} (AC #5)
     * - groupe déjà labellisé ≠    ⇒ {@see LabelAssignmentException::alreadyLabeled()} (AC #4)
     *
     * ⚠️ Le no-op idempotent est vérifié EN PREMIER (avant la résolution du
     *    contrat) : ré-affirmer le label déjà porté ne doit JAMAIS échouer, même
     *    si ce label est devenu `reserved`/dangling depuis (réconciliation 28.2)
     *    ou si le lien amont a été rompu — sinon éditer un autre champ d'un groupe
     *    labellisé lèverait une fausse erreur (review 30.2 finding #1).
     *
     * @throws LabelAssignmentException
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
        // idempotent du même label a déjà court-circuité plus haut) (AC #4).
        if ($group->hasControlHubLabel()) {
            throw LabelAssignmentException::alreadyLabeled($group, $labelName);
        }

        DB::transaction(function () use ($group, $labelName): void {
            $group->controlhub_label = $labelName;
            $group->save();
        });

        Log::info('WorkstationGroupLabelService: label amont assigné', [
            'group_id' => $group->id,
            'group_name' => $group->name,
            'label' => $labelName,
        ]);
    }

    /**
     * Détache le label de contrat amont porté par un WorkstationGroup (AC #7).
     *
     * No-op si le groupe ne porte aucun label. Le détachement d'un label
     * **réservé** porté par un groupe **imposé** relève de la Story 30.3 (hors
     * scope ici) ; en 30.2 le refnum ne manipule que des labels libres.
     *
     * ⚠️ GARDE-FOU SERVEUR (review 30.2 finding #2) : on refuse de détacher un
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
    }
}
