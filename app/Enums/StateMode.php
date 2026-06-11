<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mode d'application d'un item d'état cible (`se5.desired-state/v1`) — gap 1.
 *
 * - `Strict` : toute dérive entre l'état réel et l'état cible est réappliquée
 *   par l'agent (la cible fait loi, sans exception).
 * - `Default` : tolère la dérive humaine. L'agent persiste le dernier état
 *   APPLIQUÉ par item ; si `réel ≠ cible` ∧ `dernier-appliqué = cible`, c'est
 *   une dérive humaine volontaire → l'agent ne réapplique PAS et rapporte
 *   `drifted_allowed`. Cf. `docs/agent/contract-v1.md` (règle écrite noir sur
 *   blanc).
 *
 * Identifiant figé (NFR12).
 */
enum StateMode: string
{
    case Strict = 'strict';
    case Default = 'default';
}
