<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Data;

/**
 * Compteurs de la pose des assignations réclamées par le contrat amont.
 *
 * Retourné par {@see \App\Services\ControlHub\ContractAssignmentReconciler::reconcile()}.
 *
 * `attached` / `detached` comptent les assignations à un parc ; `defaults` compte
 * les bascules de défaut d'établissement (cible `instance`). `unresolved` compte
 * les items dont l'objet local est introuvable — une application hors inventaire,
 * un label que nul parc ne porte : le contrat les demande, SE5 ne peut pas encore
 * les servir, et le dire vaut mieux que les taire.
 */
class ContractAssignmentResult
{
    public int $attached = 0;

    public int $detached = 0;

    public int $defaults = 0;

    public int $unresolved = 0;

    /** @var array<int, string> */
    public array $errors = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attached' => $this->attached,
            'detached' => $this->detached,
            'defaults' => $this->defaults,
            'unresolved' => $this->unresolved,
            'errors' => $this->errors,
        ];
    }
}
