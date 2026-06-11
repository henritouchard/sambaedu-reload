<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut de conformité d'un item, rapporté par l'agent via
 * `POST /api/v1/agent/report` (schéma `se5.desired-state/v1` — gap 2).
 *
 * - `Compliant` : l'état réel correspond à la cible, rien à faire.
 * - `Drift` : dérive détectée et réappliquée (cas `mode = strict`, ou
 *   `default` avec dérive non humaine).
 * - `DriftedAllowed` : dérive humaine tolérée en `mode = default` — non
 *   réappliquée (`réel ≠ cible` ∧ `dernier-appliqué = cible`).
 * - `Error` : l'application a échoué ; `detail` documente la cause.
 *
 * Identifiant figé (NFR12).
 */
enum AgentResourceStatus: string
{
    case Compliant = 'compliant';
    case Drift = 'drift';
    case DriftedAllowed = 'drifted_allowed';
    case Error = 'error';
}
