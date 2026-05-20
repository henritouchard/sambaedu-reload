<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.3 — T1.5.
 *
 * Enum des 5 flows d'enrollment iPXE — utilisé comme :
 *
 *  - libellé pour le logging structuré channel `ipxe` (`ipxe.enrollment.<flow>.*`)
 *  - clé d'identification pour la dispatch éventuelle d'erreurs cross-flow
 *
 * **Anti-pattern** : ne PAS l'utiliser pour dispatcher les 5 routes via un
 * controller unique (`/ipxe/enrollment/{flow}`) — la story 3.3 D2 prescrit
 * 5 controllers fins explicites (les params diffèrent : `new_name`, `room`,
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
     * Retourne la valeur stockée dans `MachineBootLog.action` (parité D12 —
     * ≤16 chars, varchar(20) sans CHECK).
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
