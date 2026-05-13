<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test Feature route redirect Wine — Story 16.3c AC1.7 / AC4.4.
 *
 * Vérifie que `/gpo/wine.php` (legacy) est redirigé vers `/app/gpo/wine`
 * (native) via le catchall + `blocked_legacy_routes`. Pattern iso 16.2 D5
 * (`gestion_gpo.php`).
 */
class WineLegacyRouteRedirectTest extends TestCase
{
    #[Test]
    public function it_redirects_legacy_wine_php_to_native_app_gpo_wine(): void
    {
        $resp = $this->get('/gpo/wine.php');

        // Pattern legacy catchall : 302 redirect vers /app/gpo/wine.
        $resp->assertRedirect();
        $location = (string) $resp->headers->get('Location');
        $this->assertStringContainsString('/app/gpo/wine', $location);
    }

    #[Test]
    public function it_redirects_legacy_wine_php_with_query_string(): void
    {
        $resp = $this->get('/gpo/wine.php?action=foo');

        $resp->assertRedirect();
        $location = (string) $resp->headers->get('Location');
        $this->assertStringContainsString('/app/gpo/wine', $location);
    }
}
