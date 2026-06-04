<?php

declare(strict_types=1);

namespace App\SystemStatus;

/**
 * Distros installables via le boot iPXE — whitelist fermée.
 *
 * Chaque case connaît :
 *  - ses marqueurs filesystem de disponibilité (relatifs à la racine
 *    `ipxe.iso_management.deployed_os_base_path`) ;
 *  - le script de provisioning `/usr/share/sambaedu/scripts/install-*.sh`
 *    à exécuter pour la rendre disponible (null pour Windows : le
 *    téléchargement ISO Windows a déjà son orchestrateur dédié, page
 *    admin/ipxe/iso-windows).
 *
 * SÉCURITÉ : c'est CETTE enum qui borne ce que
 * {@see Jobs\RunDistroInstallScriptJob} peut exécuter — ne jamais accepter
 * un chemin de script venant de l'extérieur.
 */
enum Distro: string
{
    case Win10 = 'win10';
    case Win11 = 'win11';
    case Debian = 'debian';
    case Ubuntu = 'ubuntu';
    case PrimTux = 'primtux';
    case Nird = 'nird';

    public function label(): string
    {
        return match ($this) {
            self::Win10 => 'Windows 10',
            self::Win11 => 'Windows 11',
            self::Debian => 'Debian (netboot)',
            self::Ubuntu => 'Ubuntu (netboot)',
            self::PrimTux => 'PrimTux',
            self::Nird => 'NIRD',
        };
    }

    /**
     * Fichiers/dossiers (relatifs à la racine os/) qui doivent TOUS exister
     * pour considérer la distro disponible à l'installation.
     *
     * Sources : arborescences produites par les scripts install-*-iso.sh
     * (relevé VM 2026-06-04) + assets servis par les actions iPXE.
     *
     * @return array<int, string>
     */
    public function availabilityMarkers(): array
    {
        return match ($this) {
            // Windows : version déployée + image WinPE servie par wimboot.
            self::Win10 => ['Win10/version', 'Win10/sources/boot.wim'],
            self::Win11 => ['Win11/version', 'Win11/sources/boot.wim'],
            // Debian/Ubuntu : kernel + initrd extraits par install-*-64-iso.sh
            // sous os/<distro>-installer/amd64/ (consommes par install_deb_*).
            self::Debian => ['debian-installer/amd64/linux', 'debian-installer/amd64/initrd.gz'],
            self::Ubuntu => ['ubuntu-installer/amd64/linux', 'ubuntu-installer/amd64/initrd.gz'],
            // PrimTux / NIRD : marqueur de version posé en fin de script.
            self::PrimTux => ['primtux/version_primtux_se4.txt'],
            self::Nird => ['nird/version_nird_se4.txt'],
        };
    }

    /**
     * Script de provisioning exécutable par le job async — null si la
     * distro s'installe par un autre canal (Windows → page ISO dédiée).
     */
    public function installScriptPath(): ?string
    {
        return match ($this) {
            self::Win10, self::Win11 => null,
            self::Debian => '/usr/share/sambaedu/scripts/install-debian-64-iso.sh',
            self::Ubuntu => '/usr/share/sambaedu/scripts/install-ubuntu-64-iso.sh',
            self::PrimTux => '/usr/share/sambaedu/scripts/install-primtux-iso.sh',
            self::Nird => '/usr/share/sambaedu/scripts/install-nird-iso.sh',
        };
    }
}
