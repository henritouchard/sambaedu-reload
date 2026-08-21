<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Data;

/**
 * Compteurs de la matérialisation des raccourcis imposés par le contrat amont.
 *
 * Retourné par {@see \App\Services\ControlHub\ShortcutContractReconciler::reconcile()} ;
 * sert aux assertions de test, au log de fin et à l'affichage de la commande jumelle.
 *
 * `skipped` compte les items qu'aucune cible ne rend posables (ni `spec.windows_link`
 * ni `value`) : ils restent au contrat, mais ne produisent aucun raccourci — un
 * raccourci sans cible ne mène nulle part.
 */
class ShortcutMaterializationResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $skipped = 0;

    public int $removed = 0;

    /** @var array<int, string> */
    public array $errors = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'skipped' => $this->skipped,
            'removed' => $this->removed,
            'errors' => $this->errors,
        ];
    }
}
