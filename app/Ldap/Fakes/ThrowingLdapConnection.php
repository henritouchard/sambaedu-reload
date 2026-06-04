<?php

declare(strict_types=1);

namespace App\Ldap\Fakes;

use LdapRecord\Connection;

/**
 * Connexion LdapRecord PIÉGÉE (Story 21.2, T1 — garde-fou structurel AC3).
 *
 * Enregistrée comme connexion `default` du Container LdapRecord UNIQUEMENT en
 * `e2e` (à la place de la vraie connexion réseau, cf. `LdapRecordServiceProvider`).
 *
 * Toute tentative d'effectuer une opération LDAP RÉELLE force LdapRecord à
 * (re)connecter via `connect()` — qu'on PIÈGE pour lever une exception
 * explicite (`isConnected()` renvoie toujours false → reconnexion forcée).
 * Le vrai `samba-ad-dc` devient donc STRUCTURELLEMENT inatteignable
 * en e2e : une fuite (un chemin de code qui aurait dû passer par le fake mais
 * tape la connexion par défaut) échoue bruyamment au lieu d'écrire dans l'AD
 * réel. Garde-fou = CODE (exception levée), pas config (doctrine 21.1 / D-2).
 *
 * Les chemins LÉGITIMES e2e (auth fake, capture samba-tool fake) n'utilisent PAS
 * cette connexion : ils sont servis par {@see FakeAdDirectory} (hydratation
 * in-memory) et {@see FakeSambaToolRunner} (journal). Cette connexion n'est donc
 * jamais sollicitée par un parcours sain — uniquement par une fuite à bloquer.
 */
class ThrowingLdapConnection extends Connection
{
    /**
     * Message d'erreur explicite du garde-fou.
     */
    public const GUARD_MESSAGE =
        'GARDE-FOU e2e : accès au client AD RÉEL interdit en environnement e2e '
        . '(LdapRecord). Toute lecture/écriture AD doit passer par le fake '
        . '(FakeAdDirectory / FakeSambaToolRunner). Aucune connexion à samba-ad-dc '
        . "n'est autorisée.";

    /**
     * Piège l'établissement de la connexion réseau. Dans LdapRecord, TOUTE
     * opération (query/bind/save/rename) appelle `Connection::run()`, qui
     * s'assure d'être connecté via `connect()` avant d'exécuter — donc la 1re
     * opération réelle déclenche ce `connect()` et lève. Le réseau AD n'est
     * jamais atteint.
     *
     * Signature alignée sur le parent `LdapRecord\Connection::connect(): void`
     * (v3.8.6 du lock) — `void` est INVARIANT en PHP, tout autre type de retour
     * serait un fatal au chargement de classe (review 21-2 P-1/N-1).
     */
    public function connect(?string $username = null, ?string $password = null): void
    {
        throw new \RuntimeException(self::GUARD_MESSAGE);
    }

    /**
     * Toujours « non connecté » → garantit que LdapRecord appelle `connect()`
     * (qui lève) avant toute opération, plutôt que de réutiliser un état
     * « déjà connecté » qui contournerait le piège.
     */
    public function isConnected(): bool
    {
        // Toujours « non connecté » → force LdapRecord à appeler `connect()`
        // (qui lève) avant toute opération.
        return false;
    }
}
