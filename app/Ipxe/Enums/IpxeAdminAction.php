<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.2 — D9 / AC1.1.
 *
 * Whitelist stricte des actions iPXE admin disponibles via la route native
 * `GET|POST /ipxe/action/{action}`.
 *
 * **Sécurité critique** — l'enum est l'UNIQUE source de vérité des actions
 * autorisées. Toute valeur reçue côté `IpxeActionController` est validée via
 * `IpxeAdminAction::tryFrom()` :
 *
 *  - `null` retourné  → 404 + log warning `ipxe.action.unknown_action`.
 *  - case retourné    → dispatch vers le template Blade correspondant.
 *
 * **3 cases stricts en 3.2** :
 *
 *  - `Rescuecd`     — boot SystemRescueCD (réparation/diagnostic) — port natif
 *    `sambaedu/ipxe/actions/rescuecd.php`.
 *  - `Winpe`        — boot Windows PE (réparation Windows) — port natif
 *    `sambaedu/ipxe/actions/winpe.php`.
 *  - `FactoryReset` — restauration clonezilla sda2 → sda1 (« factory reset ») —
 *    port natif `sambaedu/ipxe/actions/clz_rest_sda2_sur_sda1.php`.
 *
 * **Anti-pattern** : pas de méthode `execute()` ni `dispatch()` sur l'enum —
 * la résolution + exécution sont portées par
 * {@see \App\Ipxe\Services\IpxeActionResolver}.
 *
 * **Élargissement futur** (Stories 3.4/3.5/3.7) : ajouter de nouveaux cases
 * (installation_linux, installation_windows, clonezilla_live, etc.) au fil
 * de leur implémentation. Le test archi
 * `IpxeNamespaceTest::ipxe_admin_action_enum_has_exactly_three_cases_in_story_3_2`
 * sera **relaxé/renommé** par chaque story qui élargit la whitelist.
 */
enum IpxeAdminAction: string
{
    case Rescuecd = 'rescuecd';
    case Winpe = 'winpe';
    case FactoryReset = 'factory_reset';

    /**
     * Retourne le chemin du template Blade rendu par
     * {@see \App\Ipxe\Services\IpxeActionResolver::resolve()}.
     */
    public function template(): string
    {
        return match ($this) {
            self::Rescuecd => 'ipxe.actions.rescuecd',
            self::Winpe => 'ipxe.actions.winpe',
            self::FactoryReset => 'ipxe.actions.factory_reset',
        };
    }

    /**
     * Retourne la string snake_case utilisée pour le logging structuré
     * (`ipxe.action.dispatched` context `action` + `MachineBootLog.initiated_by`
     * `ipxe:<value>`).
     */
    public function logName(): string
    {
        return $this->value;
    }
}
