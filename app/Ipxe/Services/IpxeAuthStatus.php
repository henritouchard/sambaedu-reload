<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

/**
 * Story 4.10 — Issue d'une décision d'autorisation iPXE.
 */
enum IpxeAuthStatus: string
{
    /** Username/password absents du POST iPXE → caller doit demander auth. */
    case MissingCredentials = 'missing_credentials';

    /** Bind LDAP refusé (mauvais mdp, user inconnu, erreur LDAP). */
    case AuthFailed = 'auth_failed';

    /** Auth OK mais permission `computer.install` absente. */
    case PermissionDenied = 'permission_denied';

    /** Auth + permission OK — caller peut servir le menu sensible. */
    case Allowed = 'allowed';

    public function isAllowed(): bool
    {
        return $this === self::Allowed;
    }

    /**
     * Type d'event log → propagation vers SIEM / observabilité.
     */
    public function blockReason(): ?string
    {
        return match ($this) {
            self::Allowed => null,
            default => $this->value,
        };
    }
}
