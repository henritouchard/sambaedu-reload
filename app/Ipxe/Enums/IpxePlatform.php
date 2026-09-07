<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Plateforme du firmware iPXE appelant. Pour l'instant non utilisé
 * directement par le rendu Blade (le helper `boot_disk` discrimine en
 * iPXE script via `iseq ${platform} efi`), mais documente la convention
 * pour un routage ultérieur des preseeds/wim selon UEFI vs legacy.
 */
enum IpxePlatform: string
{
    case Legacy = 'legacy';
    case Uefi = 'uefi';
}
