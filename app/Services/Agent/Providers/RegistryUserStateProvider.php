<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\StateScope;
use App\Models\RegistrySetting;

/**
 * Story 27.3 (D-Q2) — provider `registry` de la ruche UTILISATEUR (HKCU).
 *
 * `scope()=Session` : les items sont appliqués par le COMPAGNON de session (la
 * ruche HKCU est celle de l'utilisateur connecté ; effet Explorer immédiat).
 * UNE table catalogue partagée ; ce provider ne lit QUE les réglages `hive=HKCU`.
 * Toute la logique vit dans {@see AbstractRegistryStateProvider}.
 */
final class RegistryUserStateProvider extends AbstractRegistryStateProvider
{
    public function scope(): StateScope
    {
        return StateScope::Session;
    }

    protected function hive(): string
    {
        return RegistrySetting::HIVE_USER;
    }
}
