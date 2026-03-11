<?php

declare(strict_types=1);

namespace App\Enums;

enum InstallationStatus: string
{
    case Pending = 'pending';
    case Downloading = 'downloading';
    case Verifying = 'verifying';
    case Installing = 'installing';
    case Success = 'success';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Downloading => 'Téléchargement',
            self::Verifying => 'Vérification',
            self::Installing => 'Installation',
            self::Success => 'Succès',
            self::Failed => 'Échec',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Downloading => 'warning',
            self::Verifying => 'info',
            self::Installing => 'info',
            self::Success => 'success',
            self::Failed => 'error',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Success, self::Failed]);
    }
}
