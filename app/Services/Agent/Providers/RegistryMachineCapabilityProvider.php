<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\StateScope;
use App\Models\CapabilityProjection;

/**
 * Story 27.12 — provider `registry` CAPABILITY-FIRST de la ruche MACHINE (HKLM).
 *
 * `scope()=Machine` : les items sont appliqués par le SERVICE SYSTEM (le compagnon
 * de session n'a pas les droits HKLM). Ce provider n'émet QUE les clés `hive=HKLM`
 * des projections registry des capacités. SUPERSEDE l'ancien `RegistryMachineStateProvider`.
 * Toute la logique vit dans {@see AbstractCapabilityStateProvider}.
 */
final class RegistryMachineCapabilityProvider extends AbstractCapabilityStateProvider
{
    public function scope(): StateScope
    {
        return StateScope::Machine;
    }

    protected function hive(): string
    {
        return CapabilityProjection::HIVE_MACHINE;
    }
}
