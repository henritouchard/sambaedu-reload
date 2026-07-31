<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.1 — Issues possibles d'un test de connexion, **distinguées**.
 *
 * L'AC2 exige un « retour explicite ». Un booléen ne l'est pas : « ça ne marche
 * pas » ne dit pas à l'administrateur s'il s'est trompé d'URL, de secret, ou si
 * son serveur est éteint — trois corrections différentes.
 */
enum ConnectionStatus: string
{
    /** URL joignable ET secret accepté : la requête signée est passée. */
    case Ok = 'ok';

    /** Rien au bout du fil : hôte inconnu, port fermé, TLS refusé, délai dépassé. */
    case Unreachable = 'unreachable';

    /** Le serveur a répondu `FAILED` / `checksumError` : le secret est faux. */
    case InvalidSecret = 'invalid_secret';

    /** Quelque chose a répondu, mais ce n'est pas une API BigBlueButton. */
    case InvalidResponse = 'invalid_response';
}
