<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Enum des 5 flows d'enrollment iPXE — utilisé comme :
 *
 *  - libellé pour le logging structuré channel `ipxe` (`ipxe.enrollment.<flow>.*`)
 *  - clé d'identification pour la dispatch éventuelle d'erreurs cross-flow
 *
 * **Anti-pattern** : ne PAS l'utiliser pour dispatcher les 5 routes via un
 * controller unique (`/ipxe/enrollment/{flow}`) — chaque flow a son controller
 * explicite, les paramètres différant d'un flow à l'autre (`new_name`, `room`,
 * `parc`).
 */
enum IpxeEnrollmentFlow: string
{
    case Name = 'name';
    case Byod = 'byod';
    case Room = 'room';
    case ParcAdd = 'parc_add';
    case ParcRemove = 'parc_remove';

    /**
     * Retourne la valeur stockée dans `MachineBootLog.action` (≤ 16 chars,
     * colonne varchar(20) sans CHECK).
     */
    public function machineBootLogAction(): string
    {
        return match ($this) {
            self::Name => 'ipxe_enroll_name',
            self::Byod => 'ipxe_enroll_byod',
            self::Room => 'ipxe_enroll_room',
            self::ParcAdd => 'ipxe_parc_add',
            self::ParcRemove => 'ipxe_parc_remove',
        };
    }
}
