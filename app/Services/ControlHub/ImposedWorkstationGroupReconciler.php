<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\LockReason;
use App\Models\ControlHubContract;
use App\Models\WorkstationGroup;
use App\Services\ControlHub\Data\ImposedGroupReconciliationResult;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 30.3 — Réconciliation « désir d'état » des groupes imposés par le contrat
 * amont (controlHub).
 *
 * Garantit que, pour chaque groupe imposé du contrat amont **actif** :
 *  - s'il est absent → il est CRÉÉ via le chemin parc existant
 *    ({@see WorkstationGroupService::createGroup()} → observer → `WorkstationGroupAdSyncJob`,
 *    AD réel `OU=Parcs`, `is_physical = false`) avec son label réservé, le flag
 *    `managed_by_control_hub` et le verrou de suppression `locked = control_hub` ;
 *  - s'il existe déjà → il est CONFIRMÉ de façon idempotente par écriture **ciblée**
 *    (jamais via `updateGroup()` qui throw sur `isLocked()`), sans doublon (résolution
 *    par nom), sans écraser un verrou de plus forte priorité (ex. `root`).
 *
 * Les WorkstationGroup précédemment imposés mais **plus** listés par le contrat actif
 * voient leur verrou amont LEVÉ (sans suppression — la rupture totale du lien relève
 * d'Epic 32).
 *
 * Invariants (mémoires projet / PRD) :
 *  - NFR3 — standalone : sans contrat amont actif, `reconcile()` est un no-op total ;
 *    aucune autre table n'est lue.
 *  - NFR4 — idempotence : un `save()` n'est émis que si un champ a effectivement changé
 *    (`isDirty()`), pour ne pas réveiller l'observer `updated()` (dispatch AD parasite).
 *  - R3 — vocabulaire : terme prohibé proscrit ; vocabulaire « amont » / `ControlHub*` /
 *    `imposed` / `label`. [Source: prd-contrat-manage-se5.md#R3]
 */
class ImposedWorkstationGroupReconciler
{
    public function __construct(
        private readonly WorkstationGroupService $workstationGroupService,
    ) {
    }

    /**
     * Réconcilie l'état des WorkstationGroup avec les groupes imposés du contrat amont actif.
     *
     * @return ImposedGroupReconciliationResult Compteurs created/confirmed/adopted/released + erreurs.
     */
    public function reconcile(): ImposedGroupReconciliationResult
    {
        $result = new ImposedGroupReconciliationResult();

        // NFR3 — Standalone : sans contrat amont actif, no-op total. On ne lit
        // AUCUNE autre table (ni WorkstationGroup, ni les groupes imposés).
        $contract = ControlHubContract::active();
        if ($contract === null) {
            return $result;
        }

        $imposed = $contract->imposedGroups()->get();

        // Noms imposés par le contrat actif (pour la levée du verrou des non-imposés).
        $imposedNames = $imposed->pluck('name')->all();

        // Boucle de garantie : chaque groupe est traité dans un try/catch isolé —
        // l'échec d'un groupe n'abandonne pas toute la boucle (patron importFromAd()).
        foreach ($imposed as $group) {
            $name = (string) $group->name;
            // Normalisation unique du label imposé : '' → null partout (création ET
            // confirmation), pour éviter toute incohérence de traitement.
            $labelName = ($group->label_name !== null && $group->label_name !== '')
                ? (string) $group->label_name
                : null;

            try {
                $existing = WorkstationGroup::findByName($name);

                if ($existing === null) {
                    $this->createImposedGroup($name, $labelName);
                    $result->created++;
                } else {
                    $this->confirmImposedGroup($existing, $labelName, $result);
                }
            } catch (\InvalidArgumentException | \Illuminate\Database\QueryException $e) {
                // Race check-then-act : une création concurrente a pu insérer le
                // groupe entre notre findByName() et le createGroup() (nom dupliqué →
                // InvalidArgumentException de validateGroupData() ou violation
                // d'unicité QueryException). Si le groupe existe DÉSORMAIS, la création
                // concurrente a réussi : on traite ça comme une confirmation
                // idempotente bénigne (confirmed), surtout PAS comme une erreur.
                if (WorkstationGroup::findByName($name) !== null) {
                    $result->confirmed++;

                    continue;
                }

                $result->errors[] = "Groupe imposé '{$name}': ".$e->getMessage();
                Log::error('[ImposedWorkstationGroupReconciler] Échec de réconciliation d\'un groupe imposé', [
                    'group_name' => $name,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $result->errors[] = "Groupe imposé '{$name}': ".$e->getMessage();
                Log::error('[ImposedWorkstationGroupReconciler] Échec de réconciliation d\'un groupe imposé', [
                    'group_name' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // AC #6 — Levée du verrou des groupes non-imposés par le contrat actif.
        $this->releaseDeImposedGroups($imposedNames, $result);

        Log::info('[ImposedWorkstationGroupReconciler] Réconciliation terminée', [
            'contract_id' => $contract->id,
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    /**
     * Crée un WorkstationGroup imposé absent via le chemin parc existant
     * (observer → `WorkstationGroupAdSyncJob`, AD réel `OU=Parcs`).
     */
    private function createImposedGroup(string $name, ?string $labelName): void
    {
        $this->workstationGroupService->createGroup([
            'name' => $name,
            'is_physical' => false,
            'is_active' => true,
            'managed_by_control_hub' => true,
            'locked' => LockReason::CONTROL_HUB->value,
            // $labelName est déjà normalisé en amont ('' → null) : pas de `?: null` ici.
            'controlhub_label' => $labelName,
        ]);
    }

    /**
     * Confirme un WorkstationGroup imposé existant par écriture CIBLÉE (idempotente).
     *
     * N'utilise PAS {@see WorkstationGroupService::updateGroup()} (qui throw sur
     * `isLocked()`). N'écrit (`save()`) que si un champ a changé (`isDirty()`), pour
     * préserver l'idempotence (NFR4) et ne pas réveiller l'observer `updated()`.
     */
    private function confirmImposedGroup(
        WorkstationGroup $group,
        ?string $labelName,
        ImposedGroupReconciliationResult $result,
    ): void {
        // Capture du verrou pré-existant pour comptabiliser une « adoption »
        // (verrou plus fort préservé, ex. root).
        $hadStrongerLock = $group->isLocked()
            && $group->locked !== LockReason::CONTROL_HUB->value;

        // Aligner le label réservé sur celui imposé (poser si vide/différent).
        if ($labelName !== null && $group->controlhub_label !== $labelName) {
            $group->controlhub_label = $labelName;
        }

        // Marquer « imposé ».
        if ($group->managed_by_control_hub !== true) {
            $group->managed_by_control_hub = true;
        }

        // Poser le verrou de suppression UNIQUEMENT si aucun verrou plus fort n'est
        // présent (verrou vide ou déjà control_hub) — ne JAMAIS écraser un `root`.
        if (! $group->isLocked()) {
            $group->locked = LockReason::CONTROL_HUB->value;
        }

        if (! $group->isDirty()) {
            // No-op idempotent : rien à écrire (préserve AC #3, évite l'observer updated()).
            return;
        }

        DB::transaction(static function () use ($group): void {
            $group->save();
        });

        $result->confirmed++;
        if ($hadStrongerLock) {
            $result->adopted++;
        }
    }

    /**
     * AC #6 — Lève le verrou amont des WorkstationGroup non listés par le contrat actif.
     *
     * Pour chaque groupe marqué `managed_by_control_hub` ou verrouillé `control_hub`
     * dont le nom n'est PLUS imposé : `locked = null` **uniquement** s'il valait
     * `control_hub` (un `root` est préservé), `managed_by_control_hub = false`.
     * Ne supprime jamais le groupe (rupture totale du lien = Epic 32).
     *
     * @param array<int, string> $imposedNames Noms imposés par le contrat actif.
     */
    private function releaseDeImposedGroups(array $imposedNames, ImposedGroupReconciliationResult $result): void
    {
        $candidates = WorkstationGroup::query()
            ->where(function ($query): void {
                $query->where('managed_by_control_hub', true)
                    ->orWhere('locked', LockReason::CONTROL_HUB->value);
            })
            ->get();

        foreach ($candidates as $group) {
            if (in_array($group->name, $imposedNames, true)) {
                continue; // Toujours imposé : ne pas lever.
            }

            // Try/catch PAR GROUPE (symétrique à la boucle principale) : l'échec de
            // la levée d'un groupe n'abandonne pas toute la boucle.
            try {
                // Lever le verrou seulement s'il s'agit du verrou amont (préserver root & co).
                if ($group->locked === LockReason::CONTROL_HUB->value) {
                    $group->locked = null;
                }

                if ($group->managed_by_control_hub !== false) {
                    $group->managed_by_control_hub = false;
                }

                if (! $group->isDirty()) {
                    continue;
                }

                DB::transaction(static function () use ($group): void {
                    $group->save();
                });

                $result->released++;
            } catch (\Throwable $e) {
                $result->errors[] = "Groupe non-imposé '{$group->name}': ".$e->getMessage();
                Log::error('[ImposedWorkstationGroupReconciler] Échec de levée du verrou d\'un groupe non-imposé', [
                    'group_name' => $group->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
