<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 1bis.18f — Tests Feature des redirections legacy.
 *
 * Vérifie que LegacyCatchallController::handle() détecte les paths
 *   gpo/no_roam.php
 *   gpo/user_profile_stats.php
 *   gpo/del_roam.php
 * et émet une 302 vers la page native /admin/settings/files?tab=roaming
 * (resp. /admin/gpo/del-roam.sh) **avant** tout pipeline legacy
 * (executeViaBootstrap ou proxy).
 *
 * Couvre AC #9 + AC #10 cas #9.
 */
class LegacyRoamingRedirectsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // On désactive le pipeline blocked_legacy_routes pour s'assurer que la
        // redirection vient bien du early-return en début de handle() (et pas
        // de la table des routes bloquées).
        Config::set('sambaedu.block_migrated_routes', false);
        Config::set('sambaedu.etab_ou', '');
    }

    #[Test]
    public function it_redirects_legacy_no_roam_php_to_native_settings(): void
    {
        $this->get('/gpo/no_roam.php')
            ->assertStatus(302)
            ->assertRedirect('/admin/settings/files?tab=roaming');
    }

    #[Test]
    public function it_redirects_legacy_user_profile_stats_php_to_native_settings(): void
    {
        $this->get('/gpo/user_profile_stats.php')
            ->assertStatus(302)
            ->assertRedirect('/admin/settings/files?tab=roaming');
    }

    #[Test]
    public function it_redirects_legacy_del_roam_php_to_native_endpoint_preserving_query_string(): void
    {
        $response = $this->get('/gpo/del_roam.php?se4_key=secret&extra=foo');
        $response->assertStatus(302);
        // La query string doit être préservée pour ne pas casser
        // l'authentification du logon script (whitelist se4_key).
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/admin/gpo/del-roam.sh', $location);
        $this->assertStringContainsString('se4_key=secret', $location);
        $this->assertStringContainsString('extra=foo', $location);
    }

    #[Test]
    public function it_redirects_legacy_del_roam_php_without_query_string(): void
    {
        $this->get('/gpo/del_roam.php')
            ->assertStatus(302)
            ->assertRedirect('/admin/gpo/del-roam.sh');
    }
}
