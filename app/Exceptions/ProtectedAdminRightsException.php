<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Levée lorsqu'une opération tente de retirer un rôle ou une permission au
 * compte d'administration protégé (`User::PROTECTED_ADMIN_LOGIN`).
 *
 * Invariant métier : le compte `admin` porte l'INTÉGRALITÉ des droits existants
 * et aucun ne peut lui être retiré. Sa déchéance serait irréversible — plus
 * aucun acteur ne détiendrait `user.assign.right` pour le restaurer.
 */
class ProtectedAdminRightsException extends RuntimeException
{
    public static function cannotRemove(string $login): self
    {
        return new self(
            "Le compte d'administration « {$login} » est protégé : "
            . 'ses rôles et permissions ne peuvent pas être retirés.'
        );
    }
}
