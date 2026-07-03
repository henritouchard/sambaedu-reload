<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\StateScope;
use App\Models\CapabilityProjection;

/**
 * Story 35.2 — provider `registry_list` de la ruche UTILISATEUR (HKCU).
 *
 * `scope()=Session` : les conteneurs sont réconciliés par le COMPAGNON de
 * session (ruche HKCU de l'utilisateur connecté ; les clés lues par l'Explorer
 * — ex. `DisallowRun` — prennent effet au logon SUIVANT, mémoire projet
 * « clés HKCU Explorer au logon d'après » : ce n'est pas un bug de
 * convergence). Ce provider n'émet QUE les conteneurs `hive=HKCU` des
 * projections registry_list (ex. `…\Policies\Explorer\DisallowRun` de
 * `blocked_executables`). Toute la logique vit dans
 * {@see AbstractRegistryListCapabilityProvider} — même casier que
 * {@see RegistryUserCapabilityProvider}.
 */
final class RegistryListUserCapabilityProvider extends AbstractRegistryListCapabilityProvider
{
    public function scope(): StateScope
    {
        return StateScope::Session;
    }

    protected function hive(): string
    {
        return CapabilityProjection::HIVE_USER;
    }
}
