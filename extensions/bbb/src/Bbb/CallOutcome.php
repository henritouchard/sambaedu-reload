<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.2 — Issues possibles d'un appel SORTANT vers un serveur
 * BigBlueButton, distinguées.
 *
 * Mêmes quatre cas que le test de connexion de 57.1 ({@see ConnectionStatus}),
 * pour la même raison : « ça n'a pas marché » n'apprend rien à la personne qui
 * fait cours dans dix secondes. Le secret refusé, le serveur éteint et l'adresse
 * qui répond autre chose sont trois pannes différentes, avec trois destinataires
 * différents.
 *
 * ⚠️ Aucun message construit à partir de ces valeurs ne porte jamais le secret
 * partagé, ni l'URL signée qui l'atteste, ni un mot de passe de salon.
 */
enum CallOutcome: string
{
    /** L'appel signé est passé et le serveur a répondu ce qu'on attendait. */
    case Ok = 'ok';

    /** Rien au bout du fil : hôte inconnu, port fermé, TLS refusé, délai dépassé. */
    case Unreachable = 'unreachable';

    /** `FAILED` / `checksumError` : le secret enregistré ne vaut plus. */
    case InvalidSecret = 'invalid_secret';

    /** Quelque chose répond, mais ce n'est pas l'API attendue. */
    case InvalidResponse = 'invalid_response';
}
