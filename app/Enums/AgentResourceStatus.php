<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut de conformité d'un item, rapporté par l'agent via
 * `POST /api/v1/agent/report` (schéma `se5.desired-state/v1`).
 *
 * - `Compliant` : l'état réel correspond à la cible, rien à faire.
 * - `Drift` : dérive détectée et réappliquée — la cible fait TOUJOURS loi
 *   (comportement STRICT inconditionnel).
 * - `Error` : l'application a échoué ; `detail` documente la cause.
 *
 * Le statut `DriftedAllowed` (dérive humaine tolérée en
 * `mode = default`) est RETIRÉ — le mécanisme strict/default est supprimé,
 * l'agent réapplique toujours.
 *
 * Identifiant figé : une valeur publiée ne se renomme jamais.
 */
enum AgentResourceStatus: string
{
    case Compliant = 'compliant';
    case Drift = 'drift';
    case Error = 'error';
}
