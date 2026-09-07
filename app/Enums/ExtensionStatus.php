<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * État d'une extension dans le registre local.
 *
 * - `Available`  : présente au catalogue, PAS encore intégrée à l'instance.
 * - `Integrated` : intégrée — sa tuile est éligible au lanceur.
 *
 * ⚠️ La synchro de la source bundled n'écrit JAMAIS la colonne `status`
 * (idempotence). Les transitions (bouton « Intégrer » /
 * « Désinstaller » + journal d'audit) vivent dans
 * {@see \App\Services\Extensions\ExtensionLifecycleService},
 * seul écrivain de cette colonne.
 */
enum ExtensionStatus: string
{
    case Available = 'available';
    case Integrated = 'integrated';

    /** Libellé FR affiché en bibliothèque et sur la fiche. */
    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Integrated => 'Intégrée',
        };
    }

    /** Classe DaisyUI du badge d'état. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Available => 'badge-ghost',
            self::Integrated => 'badge-success',
        };
    }
}
