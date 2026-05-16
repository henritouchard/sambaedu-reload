<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesGpoService;
use Tests\TestCase;

/**
 * Tests Feature — Story 16.9 redirections 301 des anciennes routes /app/gpo/*
 * vers /admin/settings/gpo/*.
 *
 * Garde-fou anti-régression : les anciens bookmarks doivent continuer de
 * fonctionner pendant toute la Phase 2 (D3). 301 permanent car aucun retour
 * arrière prévu.
 *
 * Pattern `withoutMiddleware('sambaedu.auth')` iso-Story 16.8 commit 0a4609c :
 * les redirections sont indépendantes de l'auth, on teste la mécanique pure 301.
 *
 * Defensive testing iso-`GpoDetailRouteValidationTest` : `expectNoCalls` sur
 * GpoService garantit qu'aucune closure de redirection n'instancie le service
 * (régression silencieuse possible si un dev ajoute un appel service par
 * inadvertance dans une closure).
 */
class LegacyAppGpoRoutesRedirectTest extends TestCase
{
    private const VALID_GUID = '{8625C81D-89B0-4502-9DC5-7BFD7B8C7C42}';

    protected function setUp(): void
    {
        parent::setUp();

        FakesGpoService::make()->expectNoCalls()->bind($this->app);
    }

    #[Test]
    public function it_redirects_app_gpo_index_to_admin_settings_gpo(): void
    {
        $response = $this->withoutMiddleware('sambaedu.auth')->get('/app/gpo');

        $response->assertRedirect('/admin/settings/gpo');
        $response->assertStatus(301);
    }

    #[Test]
    public function it_redirects_app_gpo_show_to_admin_settings_gpo_show_with_guid(): void
    {
        $response = $this->withoutMiddleware('sambaedu.auth')
            ->get('/app/gpo/' . rawurlencode(self::VALID_GUID));

        $response->assertStatus(301);
        // Assertion stricte : le GUID complet (avec accolades préservées par la
        // closure de redirection) doit apparaître dans le header Location.
        $response->assertHeader('Location', '/admin/settings/gpo/' . self::VALID_GUID);
    }

    #[Test]
    public function it_redirects_app_gpo_links_to_admin_settings_gpo_links_with_guid(): void
    {
        $response = $this->withoutMiddleware('sambaedu.auth')
            ->get('/app/gpo/' . rawurlencode(self::VALID_GUID) . '/links');

        $response->assertStatus(301);
        $response->assertHeader('Location', '/admin/settings/gpo/' . self::VALID_GUID . '/links');
    }

    #[Test]
    public function it_redirects_app_gpo_wine_to_admin_settings_gpo_wine(): void
    {
        $response = $this->withoutMiddleware('sambaedu.auth')->get('/app/gpo/wine');

        $response->assertRedirect('/admin/settings/gpo/wine');
        $response->assertStatus(301);
    }

    #[Test]
    public function it_redirects_app_gpo_wpkg_deployment_to_admin_settings_gpo_wpkg_deployment(): void
    {
        $response = $this->withoutMiddleware('sambaedu.auth')->get('/app/gpo/wpkg-deployment');

        $response->assertRedirect('/admin/settings/gpo/wpkg-deployment');
        $response->assertStatus(301);
    }

    /**
     * Anti open-redirect : la regex GUID (iso 16.2 fix #9) doit bloquer
     * toute valeur arbitraire — la closure de redirection ne doit jamais
     * construire un Location vers `/admin/settings/gpo/<INPUT_NON_GUID>`.
     *
     * Couverture par classes d'équivalence : texte simple sans hex / sans
     * tirets, GUID hex incomplet (longueur du dernier segment fausse),
     * lettres non-hex (Z), URL-encoded scheme (anti-redirect vers domain
     * externe).
     */
    #[Test]
    #[DataProvider('invalidGuidProvider')]
    public function it_returns_404_on_malformed_guid_redirect(string $maliciousInput): void
    {
        $response = $this->withoutMiddleware('sambaedu.auth')->get('/app/gpo/' . $maliciousInput);

        $response->assertStatus(404);
    }

    public static function invalidGuidProvider(): array
    {
        return [
            'plain text injection' => ['INJECTION'],
            'incomplete hex guid' => ['12345678-1234-1234-1234-12345678'],
            'non-hex letter Z' => ['ZZZZZZZZ-89B0-4502-9DC5-7BFD7B8C7C42'],
            'url-encoded scheme' => ['http%3A%2F%2Fevil.com'],
        ];
    }
}
