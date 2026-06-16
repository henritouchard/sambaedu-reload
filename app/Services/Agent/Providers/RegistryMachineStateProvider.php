<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\StateScope;
use App\Models\RegistrySetting;

/**
 * Story 27.3 (D-Q2) — provider `registry` de la ruche MACHINE (HKLM).
 *
 * `scope()=Machine` : les items sont appliqués par le SERVICE SYSTEM (le
 * compagnon de session n'a pas les droits HKLM). UNE table catalogue partagée ;
 * ce provider ne lit QUE les réglages `hive=HKLM`. Toute la logique vit dans
 * {@see AbstractRegistryStateProvider}.
 */
final class RegistryMachineStateProvider extends AbstractRegistryStateProvider
{
    public function scope(): StateScope
    {
        return StateScope::Machine;
    }

    protected function hive(): string
    {
        return RegistrySetting::HIVE_MACHINE;
    }
}
