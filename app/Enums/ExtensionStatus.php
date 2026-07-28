<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 54.1 — État d'une extension dans le registre local.
 *
 * - `Available`  : présente au catalogue, PAS encore intégrée à l'instance.
 * - `Integrated` : intégrée — sa tuile est éligible au lanceur (Story 54.3).
 *
 * ⚠️ PÉRIMÈTRE 54.1 : cet état est **affiché** uniquement. AUCUN chemin de la
 * story ne le mute — la synchro de la source bundled n'écrit JAMAIS la colonne
 * `status` (idempotence AC2). Les transitions (bouton « Intégrer » /
 * « Désinstaller » + journal d'audit) arrivent en Story 54.2.
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
