<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Enums;

/**
 * Story 3.6 — D1 / AC1.3.
 *
 * Enum des statuts d'un téléchargement d'ISO Windows.
 *
 * Cycle de vie :
 *
 *  Pending → Downloading → Extracting → Success      (chemin nominal)
 *                                     \→ Failed       (échec curl OU install-win-iso.sh)
 *                                     \→ Cancelled    (admin annule via UI)
 *
 * - `Pending`     : row créée, Job dispatché, worker pas encore pickup.
 * - `Downloading` : `curl` en cours (Process Symfony).
 * - `Extracting`  : `sudo install-win-iso.sh` en cours.
 * - `Success`     : tout est OK (`/var/sambaedu/unattended/install/os/Win{N}/version` peuplé).
 * - `Failed`      : `curl` OU `install-win-iso.sh` a retourné ≠ 0 (exit_code + error texte).
 * - `Cancelled`   : admin a cliqué "Annuler" — le Process en cours continue mais le Job
 *                   skipera la suite (parité legacy qui ne SIGTERM pas non plus).
 *
 * Helpers UI :
 *  - `label()`      : libellé fr affiché dans la card.
 *  - `badgeClass()` : classe daisyUI pour le badge couleur.
 */
enum WindowsIsoDownloadStatus: string
{
    case Pending = 'pending';
    case Downloading = 'downloading';
    case Extracting = 'extracting';
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * États non terminaux (pour lesquels le polling Livewire reste actif et
     * pour lesquels une annulation est encore possible).
     */
    public function isRunning(): bool
    {
        return match ($this) {
            self::Pending, self::Downloading, self::Extracting => true,
            default => false,
        };
    }

    /**
     * États terminaux (le Job a fini son job — plus de polling, plus de cancel).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Success, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Libellé court fr pour la UI (badge + card).
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending     => 'En attente',
            self::Downloading => 'Téléchargement',
            self::Extracting  => 'Extraction',
            self::Success     => 'Succès',
            self::Failed      => 'Échec',
            self::Cancelled   => 'Annulé',
        };
    }

    /**
     * Classe CSS daisyUI pour le badge associé.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending     => 'badge-ghost',
            self::Downloading => 'badge-info',
            self::Extracting  => 'badge-warning',
            self::Success     => 'badge-success',
            self::Failed      => 'badge-error',
            self::Cancelled   => 'badge-neutral',
        };
    }
}
