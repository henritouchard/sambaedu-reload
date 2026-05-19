<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.1 — T1.6.
 *
 * Plateforme du firmware iPXE appelant. Pour l'instant non utilisé
 * directement par le rendu Blade (le helper `boot_disk` discrimine en
 * iPXE script via `iseq ${platform} efi`), mais documente la convention
 * pour Stories 3.4/3.5 qui devront router les preseeds/wim selon UEFI vs
 * legacy.
 */
enum IpxePlatform: string
{
    case Legacy = 'legacy';
    case Uefi = 'uefi';
}
