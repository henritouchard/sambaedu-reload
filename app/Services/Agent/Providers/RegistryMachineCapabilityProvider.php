<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\StateScope;
use App\Models\CapabilityProjection;

/**
 * Story 27.12 — provider `registry` CAPABILITY-FIRST de la ruche MACHINE (HKLM).
 *
 * `scope()=Machine` : les items sont appliqués par le SERVICE SYSTEM (le compagnon
 * de session n'a pas les droits HKLM). Ce provider émet les clés `hive=HKLM`
 * **et `hive=HKU`** (Story 35.3) des projections registry des capacités.
 * SUPERSEDE l'ancien `RegistryMachineStateProvider`. Toute la logique vit dans
 * {@see AbstractCapabilityStateProvider}.
 *
 * **Ruche `HKU` (Story 35.3).** Une clé `hive: 'HKU'` est une cible LOGIQUE de
 * portée machine : le service SYSTEM (seul à pouvoir écrire les ruches des
 * autres utilisateurs) la FAN-OUT vers `HKU\.DEFAULT` (écran de logon) + chaque
 * ruche utilisateur chargée (`HKU\<SID>`), à chaque cycle — fan-out interne au
 * handler agent, invisible du contrat (l'item reste UN item, hash inchangé par
 * le nombre de sessions). JAMAIS émise par le provider Session : « pas de
 * ciblage par utilisateur » est STRUCTUREL (le service fetch son state sans
 * `?user` → les overrides UserGroup/User n'atteignent pas ces items).
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

    /**
     * HKLM (comportement historique) + HKU (Story 35.3) — SEULE surcharge du
     * prédicat : tous les autres providers gardent le défaut byte-identique.
     */
    protected function handlesHive(string $hive): bool
    {
        return strcasecmp($hive, CapabilityProjection::HIVE_MACHINE) === 0
            || strcasecmp($hive, CapabilityProjection::HIVE_USERS) === 0;
    }
}
