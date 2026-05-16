<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test Feature route redirect Wine — Story 16.3c AC1.7 / AC4.4 (maj Story 16.9 D7).
 *
 * Vérifie que `/gpo/wine.php` (legacy) est redirigé vers
 * `/admin/settings/gpo/wine` (native, renommée par 16.9) via le catchall +
 * `blocked_legacy_routes`. Pattern iso 16.2 D5 (`gestion_gpo.php`).
 */
class WineLegacyRouteRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Désactivé : portage natif Laravel des modules legacy GPO en cours
        // (Epic 16/17). Les redirections testées ici (wine.php, ...) vont
        // disparaître avec le portage natif.
        // @todo Supprimer ce test lors de story 16.13 (retrait des shims GPO).
        $this->markTestSkipped('Désactivé pendant le portage natif Laravel des modules legacy GPO (Epic 16/17).');
    }

    #[Test]
    public function it_redirects_legacy_wine_php_to_native_admin_settings_gpo_wine(): void
    {
        $resp = $this->get('/gpo/wine.php');

        // Pattern legacy catchall : 302 redirect vers /admin/settings/gpo/wine (16.9 D7).
        $resp->assertRedirect();
        $location = (string) $resp->headers->get('Location');
        $this->assertStringContainsString('/admin/settings/gpo/wine', $location);
    }

    #[Test]
    public function it_redirects_legacy_wine_php_with_query_string(): void
    {
        $resp = $this->get('/gpo/wine.php?action=foo');

        $resp->assertRedirect();
        $location = (string) $resp->headers->get('Location');
        $this->assertStringContainsString('/admin/settings/gpo/wine', $location);
    }
}
