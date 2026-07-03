<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\StateScope;
use App\Models\CapabilityProjection;

/**
 * Story 35.2 — provider `registry_list` de la ruche MACHINE (HKLM).
 *
 * `scope()=Machine` : les conteneurs sont réconciliés par le SERVICE SYSTEM
 * (le compagnon de session n'a pas les droits HKLM). Ce provider n'émet QUE
 * les conteneurs `hive=HKLM` des projections registry_list des capacités
 * (ex. Forcelist Chrome/Edge de `pix_extension_forced`). Toute la logique vit
 * dans {@see AbstractRegistryListCapabilityProvider} — même casier que
 * {@see RegistryMachineCapabilityProvider}.
 */
final class RegistryListMachineCapabilityProvider extends AbstractRegistryListCapabilityProvider
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
