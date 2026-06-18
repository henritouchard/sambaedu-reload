<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\StateScope;
use App\Models\CapabilityProjection;

/**
 * Story 27.12 — provider `registry` CAPABILITY-FIRST de la ruche UTILISATEUR (HKCU).
 *
 * `scope()=Session` : les items sont appliqués par le COMPAGNON de session (ruche
 * HKCU de l'utilisateur connecté ; effet Explorer au logon suivant). Ce provider
 * n'émet QUE les clés `hive=HKCU` des projections registry des capacités.
 * SUPERSEDE l'ancien `RegistryUserStateProvider`. Toute la logique vit dans
 * {@see AbstractCapabilityStateProvider}.
 *
 * Note HKCR (D-piège « onedrive_hidden ») : le handler Go `registry` ne route que
 * HKLM (SYSTEM) / HKCU (compagnon). Les clés HKCR de la `spec` (ex. masquer
 * OneDrive) sont émises en portée SESSION sous HKCU\Software\Classes (vue fusionnée
 * HKCR = HKLM+HKCU\Software\Classes — le compagnon écrit la branche per-user). Le
 * seed transcrit donc ces clés en `hive=HKCU, path=Software\Classes\…`.
 */
final class RegistryUserCapabilityProvider extends AbstractCapabilityStateProvider
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
