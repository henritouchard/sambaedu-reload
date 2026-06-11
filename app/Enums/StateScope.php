<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Portée d'application d'un item d'état cible (`se5.desired-state/v1`).
 *
 * - `Machine` : s'applique au poste, indépendamment de la session.
 * - `Session` : s'applique à la session ouverte (utilisateur courant).
 * - `MachineUser` : s'applique au couple poste × utilisateur (persistant
 *   par poste pour un utilisateur donné).
 *
 * Les valeurs `string` sont aussi les **clés de l'enveloppe JSON**
 * (`machine`, `session`, `machine_user`). Identifiants figés (NFR12).
 */
enum StateScope: string
{
    case Machine = 'machine';
    case Session = 'session';
    case MachineUser = 'machine_user';
}
