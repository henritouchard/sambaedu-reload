<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ipxe\Contracts\IpxeAuthorizes;
use App\Ipxe\Services\IpxeAuthOutcome;
use App\Ipxe\Services\IpxeAuthService;
use App\Ipxe\Services\IpxeAuthStatus;
use App\Services\AuthenticationService;
use Illuminate\Http\Request;

/**
 * Story 4.10 — Helper de test pour les endpoints iPXE sensibles.
 *
 * Avant la story 4.10, les endpoints `/ipxe/admin`, `/ipxe/maintenance`,
 * `/ipxe/action/*`, `/ipxe/installation-*`, `/ipxe/clonezilla-menu` et
 * `/ipxe/enrollment/*` ne vérifiaient pas l'auth. La story restaure le
 * contrôle via {@see IpxeAuthService::authorize()} (bind LDAP + permission
 * Spatie `computer.install`). Les tests Feature existants POSTent sans
 * `username/password` — ce trait permet de stub l'auth pour préserver le
 * scope de chaque test.
 *
 * **Correctif review #12** : on stubbe désormais via l'interface
 * {@see IpxeAuthorizes} (et non plus via une sous-classe anonyme de la
 * classe concrète, qui exigeait de retirer `final`). La classe stub
 * `StubIpxeAuthService` n'a aucune dépendance LDAP/Spatie.
 */
trait IpxeAuthTestHelper
{
    /**
     * Remplace l'implémentation de `IpxeAuthorizes` par un stub qui retourne
     * TOUJOURS `Allowed`. À appeler en `setUp()` des tests qui ne ciblent PAS
     * la story 4.10 (auth).
     */
    protected function bypassIpxeAuth(): void
    {
        $this->app->instance(IpxeAuthorizes::class, new StubIpxeAuthService());
    }

    /**
     * Stub `AuthenticationService::validateAdCredentials` pour simuler un
     * bind LDAP qui réussit/échoue selon `$expectSuccess`. Utile pour
     * tester `IpxeAuthService` lui-même sans LDAP réel.
     */
    protected function stubAdAuth(bool $expectSuccess): void
    {
        $mock = $this->createMock(AuthenticationService::class);
        $mock->method('validateAdCredentials')->willReturn($expectSuccess);
        $this->app->instance(AuthenticationService::class, $mock);

        // Re-bind IpxeAuthService + contrat pour qu'ils utilisent le mock
        // fraîchement injecté.
        $service = new IpxeAuthService($mock);
        $this->app->instance(IpxeAuthService::class, $service);
        $this->app->instance(IpxeAuthorizes::class, $service);
    }
}

/**
 * Story 4.10 (correctif review #12) — Stub iPXE auth « always allow ».
 *
 * Implémente {@see IpxeAuthorizes} sans aucune dépendance (pas de LDAP,
 * pas de Spatie). À utiliser exclusivement en tests via
 * {@see IpxeAuthTestHelper::bypassIpxeAuth()}.
 */
final class StubIpxeAuthService implements IpxeAuthorizes
{
    public function authorize(Request $request, string $context): IpxeAuthOutcome
    {
        return new IpxeAuthOutcome(
            status: IpxeAuthStatus::Allowed,
            username: 'test-admin',
            user: null,
        );
    }
}
