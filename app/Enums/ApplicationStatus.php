<?php

declare(strict_types=1);

namespace App\Enums;

enum ApplicationStatus: string
{
    case Available = 'available';
    case Downloading = 'downloading';
    case Installed = 'installed';
    case UpdateAvailable = 'update_available';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Downloading => 'Téléchargement...',
            self::Installed => 'Installée',
            self::UpdateAvailable => 'Mise à jour disponible',
            self::Error => 'Erreur',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Available => 'info',
            self::Downloading => 'warning',
            self::Installed => 'success',
            self::UpdateAvailable => 'accent',
            self::Error => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Available => 'fa-solid fa-cloud-arrow-down',
            self::Downloading => 'fa-solid fa-spinner fa-spin',
            self::Installed => 'fa-solid fa-circle-check',
            self::UpdateAvailable => 'fa-solid fa-arrow-up-from-bracket',
            self::Error => 'fa-solid fa-triangle-exclamation',
        };
    }
}
