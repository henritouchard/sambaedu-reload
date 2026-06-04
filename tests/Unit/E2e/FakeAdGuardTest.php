<?php

declare(strict_types=1);

namespace Tests\Unit\E2e;

use App\Ldap\Fakes\FakeAdRecorder;
use App\Ldap\Fakes\ThrowingLdapConnection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 21.2 — Tests host SANS I/O réelle (T7a/b partiels).
 *
 * Couvre les invariants purs (aucune base, aucun réseau, aucun process) :
 *  - le garde-fou anti-AD-réel ({@see ThrowingLdapConnection}) LÈVE sur toute
 *    opération LDAP réelle (AC3) ;
 *  - les GUID factices sont DÉTERMINISTES et STABLES (D-3 / AC2).
 */
class FakeAdGuardTest extends TestCase
{
    #[Test]
    public function throwing_connection_leve_sur_connect(): void
    {
        // AC3 : toute tentative d'opération LDAP réelle via la connexion piégée
        // (utilisée comme connexion `default` en e2e) passe par `connect()` —
        // qui lève une exception explicite. Le vrai samba-ad-dc est
        // structurellement inatteignable.
        $connection = new ThrowingLdapConnection([
            'hosts' => ['127.0.0.1'],
            'base_dn' => 'dc=e2e,dc=local',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GARDE-FOU e2e');

        $connection->connect();
    }

    #[Test]
    public function throwing_connection_est_toujours_non_connectee(): void
    {
        // `isConnected()` renvoie toujours false → LdapRecord appelle `connect()`
        // (qui lève) avant toute opération.
        $connection = new ThrowingLdapConnection([
            'hosts' => ['127.0.0.1'],
            'base_dn' => 'dc=e2e,dc=local',
        ]);

        $this->assertFalse($connection->isConnected());
    }

    #[Test]
    public function guid_factice_est_deterministe_et_stable(): void
    {
        $recorder = new FakeAdRecorder();

        // D-3 : même clé → même GUID, à chaque appel (résolution par GUID stable).
        $first = $recorder->guidFor('PC-SALLE-101');
        $second = $recorder->guidFor('PC-SALLE-101');

        $this->assertSame($first, $second);

        // Format GUID canonique 8-4-4-4-12.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $first,
        );

        // Clés distinctes → GUID distincts.
        $this->assertNotSame($first, $recorder->guidFor('PC-SALLE-102'));
    }
}
