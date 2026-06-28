<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Data;

/**
 * Story 30.3 — DTO de résultat de la réconciliation des groupes imposés par le
 * contrat amont (controlHub).
 *
 * Retourné par {@see \App\Services\ControlHub\ImposedWorkstationGroupReconciler::reconcile()}.
 * Sert aux assertions de test et au `Log::info` final / à l'affichage de la commande artisan.
 *
 * Compteurs :
 *  - `created`   : WorkstationGroup absents créés via le chemin parc (observer → AD).
 *  - `confirmed` : WorkstationGroup existants ré-alignés (label/flags/verrou) — write effectif.
 *  - `adopted`   : sous-ensemble de `confirmed` où un verrou pré-existant (ex. `root`) a été préservé.
 *  - `released`  : WorkstationGroup non-imposés dont le verrou amont a été levé (sans suppression).
 *  - `errors`    : messages d'échec par groupe (la boucle n'abandonne pas).
 *
 * ⚠️ GARDE-FOU R3 : vocabulaire « amont » exclusivement, terme prohibé proscrit. [Source: prd-contrat-manage-se5.md#R3]
 */
class ImposedGroupReconciliationResult
{
    public int $created = 0;

    public int $confirmed = 0;

    public int $adopted = 0;

    public int $released = 0;

    /** @var array<int, string> */
    public array $errors = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'confirmed' => $this->confirmed,
            'adopted' => $this->adopted,
            'released' => $this->released,
            'errors' => $this->errors,
        ];
    }
}
