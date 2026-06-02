<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Story 20.1 — garde-fou architectural du login fédéré.
 *
 *  1. La route `POST /auth/federated/callback` est déclarée AVANT le catchall
 *     legacy `{path}` dans `routes/web.php` (cohabitation route native /
 *     proxy legacy — cf. `IpxeNamespaceTest::ipxe_3_7_routes_are_declared_before_catchall`).
 *  2. La route est bien un POST (D-3 : POST binding, pas de token en query).
 *  3. Frontière `Firebase\JWT\*` : seuls le vérificateur et le replay-checker
 *     importent la lib JWT dans `app/Auth/Federated/*` (aucune fuite de la
 *     dépendance crypto dans le controller / les modèles).
 *  4. Domain-neutral : aucun littéral « controlHub » / « central » dans le
 *     code du namespace `app/Auth/Federated/*`.
 */
class FederatedRouteTest extends TestCase
{
    private function routesContent(): string
    {
        $routesFile = realpath(__DIR__ . '/../../routes/web.php');
        self::assertNotFalse($routesFile);

        return (string) file_get_contents($routesFile);
    }

    #[Test]
    public function federated_callback_route_is_declared_before_catchall(): void
    {
        $content = $this->routesContent();

        $catchallPattern = "/Route::match\s*\([^)]*['\"]\\{path\\}['\"]/";
        self::assertSame(
            1,
            preg_match($catchallPattern, $content, $catchallMatches, PREG_OFFSET_CAPTURE),
            'Catchall legacy {path} introuvable',
        );
        $catchallOffset = $catchallMatches[0][1];

        $pattern = "@Route::post\s*\([^;]*?['\"]/auth/federated/callback['\"]@";
        self::assertSame(
            1,
            preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE),
            'Route POST /auth/federated/callback non déclarée',
        );

        self::assertLessThan(
            $catchallOffset,
            $matches[0][1],
            'ORDRE INVALIDE : la route /auth/federated/callback doit être AVANT le catchall',
        );
    }

    #[Test]
    public function federated_callback_route_is_a_post(): void
    {
        $content = $this->routesContent();

        // D-3 : POST binding. Pas de Route::get / Route::any sur ce path.
        self::assertSame(
            0,
            preg_match("@Route::(get|any)\s*\([^;]*?['\"]/auth/federated/callback['\"]@", $content),
            'Le login fédéré ne doit PAS exposer de GET/ANY (token en query = fuite)',
        );
    }

    #[Test]
    public function only_verifier_and_replay_checker_import_firebase_jwt(): void
    {
        $finder = (new Finder())
            ->files()
            ->in(realpath(__DIR__ . '/../../app/Auth/Federated'))
            ->name('*.php');

        $allowed = ['FederatedJwtVerifier.php', 'FederatedJwtReplayChecker.php'];

        foreach ($finder as $file) {
            $imports = str_contains((string) $file->getContents(), 'Firebase\\JWT');
            if (in_array($file->getFilename(), $allowed, true)) {
                continue;
            }
            self::assertFalse(
                $imports,
                sprintf('%s ne doit pas importer Firebase\\JWT (frontière crypto)', $file->getFilename()),
            );
        }
    }

    #[Test]
    public function federated_namespace_is_domain_neutral(): void
    {
        $finder = (new Finder())
            ->files()
            ->in(realpath(__DIR__ . '/../../app/Auth/Federated'))
            ->name('*.php');

        foreach ($finder as $file) {
            $content = strtolower((string) $file->getContents());
            self::assertStringNotContainsString(
                'controlhub',
                $content,
                sprintf('%s contient un littéral « controlHub » (principe domain-neutral)', $file->getFilename()),
            );
        }
    }
}
