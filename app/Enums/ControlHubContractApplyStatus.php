<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Verdict d'application d'un item de contrat amont, tel que SE5 le rapporte au
 * canal ③ (`se5-contract-compliance/v1`).
 *
 * Porté par `controlhub_contract_items.apply_status`, écrit par
 * {@see \App\Services\ControlHub\ContractAssignmentReconciler} à chaque passe.
 * `null` = aucun réconciliateur ne revendique ce type d'item : le canal ③ retombe
 * alors sur sa politique d'origine (`locked` → `applied`).
 *
 * La frontière entre `Pending` et `Error` est celle de la réparabilité :
 * - `Pending` : l'ordre est recevable, l'état local n'est pas encore là. Il
 *   s'appliquera de lui-même dès qu'un parc portera le label, que le catalogue
 *   applicatif sera synchronisé ou que le binaire sera tiré. Personne n'a rien à
 *   corriger.
 * - `Error` : l'ordre ne peut pas aboutir en l'état. Payload incomplet côté amont,
 *   ou clé que SE5 ne connaît pas. Sans intervention, la prochaine réception
 *   échouera pareil.
 *
 * Les trois valeurs appartiennent au vocabulaire de statut du canal ③ (`applied` |
 * `pending` | `error` | `overridden`) ; `overridden` reste calculé à l'émission,
 * il décrit un geste local et non un verdict d'application.
 */
enum ControlHubContractApplyStatus: string
{
    case Applied = 'applied';
    case Pending = 'pending';
    case Error = 'error';
}
