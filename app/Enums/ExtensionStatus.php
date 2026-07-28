<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 54.1 — État d'une extension dans le registre local.
 *
 * - `Available`  : présente au catalogue, PAS encore intégrée à l'instance.
 * - `Integrated` : intégrée — sa tuile est éligible au lanceur (Story 54.3).
 *
 * ⚠️ La synchro de la source bundled n'écrit JAMAIS la colonne `status`
 * (idempotence AC2 de 54.1). Les transitions (bouton « Intégrer » /
 * « Désinstaller » + journal d'audit) vivent dans
 * {@see \App\Services\Extensions\ExtensionLifecycleService} (Story 54.2),
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
