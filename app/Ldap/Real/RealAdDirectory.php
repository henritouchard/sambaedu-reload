<?php

declare(strict_types=1);

namespace App\Ldap\Real;

use App\Contracts\Ad\AdDirectory;
use App\LdapModels\LdapUser;

/**
 * Implémentation RÉELLE de {@see AdDirectory} (Story 21.2).
 *
 * Délègue à `LdapUser::findByLogin()` (canal A — LdapRecord Container). Bindée
 * PAR DÉFAUT dans tous les environnements : la résolution d'identité réelle de
 * dev/prod/testing est strictement inchangée (AC5). Ce n'est qu'un point
 * d'indirection : aucun comportement modifié.
 */
class RealAdDirectory implements AdDirectory
{
    public function findUserByLogin(string $login): ?LdapUser
    {
        return LdapUser::findByLogin($login);
    }
}
