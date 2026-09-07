<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoSummary;
use App\Gpo\Services\GpoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesGpoService;
use Tests\TestCase;

/**
 * Tests Feature — Composant Blade `<x-molecules.gpo-back-link />`.
 *
 * Stratégie :
 * tester le composant en isolation via Blade::render(), sans passer par les pages
 * cibles. Évite la chaîne de permissions (`wallpaper.manage`, `app.customize`)
 * hors sujet ici.
 *
 * On simule la query string en injectant un Request fake dans le container,
 * et on couvre aussi le fallback Referer (re-render Livewire).
 */
class GpoBackLinkComponentTest extends TestCase
{
    private const VALID_GUID = '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}';

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeGpoSummary(string $displayName = 'redirections'): GpoSummary
    {
        return new GpoSummary(
            name: self::VALID_GUID,
            displayName: $displayName,
            versionNumber: 3,
            dn: 'CN=' . self::VALID_GUID . ',CN=Policies,CN=System,DC=example,DC=org',
            path: '\\\\example.org\\sysvol\\example.org\\Policies\\' . self::VALID_GUID,
        );
    }

    /**
     * Rend le composant en isolation en injectant une Request fake avec
     * la query string fournie et optionnellement un Referer (pour Livewire).
     */
    private function renderInIsolation(?string $fromGpoQuery, ?string $refererUrl = null): string
    {
        $url = '/test-component';
        if ($fromGpoQuery !== null) {
            $url .= '?from_gpo=' . rawurlencode($fromGpoQuery);
        }

        $request = Request::create($url, 'GET');
        if ($refererUrl !== null) {
            $request->headers->set('referer', $refererUrl);
        }

        $this->app->instance('request', $request);
        URL::setRequest($request);

        return Blade::render('<x-molecules.gpo-back-link />');
    }

    #[Test]
    public function it_renders_full_back_link_when_from_gpo_is_present_and_gpo_found(): void
    {
        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary('redirections'))
            ->bind($this->app);

        $html = $this->renderInIsolation(self::VALID_GUID);

        $this->assertStringContainsString('Retour à la GPO', $html);
        $this->assertStringContainsString('redirections', $html);
        $this->assertStringContainsString('fa-arrow-left', $html);
        $this->assertStringContainsString('/admin/settings/gpo/', $html);
    }

    #[Test]
    public function it_renders_generic_fallback_when_gpo_service_returns_null(): void
    {
        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, null)
            ->bind($this->app);

        $html = $this->renderInIsolation(self::VALID_GUID);

        $this->assertStringContainsString('Retour à la liste des GPOs', $html);
        $this->assertStringContainsString('fa-arrow-left', $html);
        $this->assertStringNotContainsString('Retour à la GPO «', $html);
    }

    #[Test]
    public function it_renders_nothing_when_from_gpo_query_param_is_absent(): void
    {
        FakesGpoService::make()->expectNoCalls()->bind($this->app);

        $html = $this->renderInIsolation(fromGpoQuery: null);

        $this->assertStringNotContainsString('Retour à la GPO', $html);
        $this->assertStringNotContainsString('Retour à la liste des GPOs', $html);
        $this->assertSame('', trim($html));
    }

    #[Test]
    public function it_renders_generic_fallback_when_gpo_service_throws(): void
    {
        FakesGpoService::make()
            ->withGetThrowing(self::VALID_GUID, new \RuntimeException('samba-tool down'))
            ->bind($this->app);

        $html = $this->renderInIsolation(self::VALID_GUID);

        $this->assertStringContainsString('Retour à la liste des GPOs', $html);
    }

    #[Test]
    public function it_renders_nothing_when_from_gpo_is_an_array(): void
    {
        FakesGpoService::make()->expectNoCalls()->bind($this->app);

        $request = Request::create('/test-component?from_gpo[]=foo&from_gpo[]=bar', 'GET');
        $this->app->instance('request', $request);
        URL::setRequest($request);

        $html = Blade::render('<x-molecules.gpo-back-link />');

        $this->assertSame('', trim($html));
    }

    #[Test]
    public function it_falls_back_to_referer_query_string_when_request_has_no_from_gpo(): void
    {
        // Simule le contexte d'un wire:click : la requête courante n'a pas de
        // from_gpo dans la query (POST /livewire/update), mais le Referer pointe
        // vers la page hôte qui a from_gpo dans son URL.
        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary('redirections-from-referer'))
            ->bind($this->app);

        $encodedGuid = rawurlencode(self::VALID_GUID);
        $html = $this->renderInIsolation(
            fromGpoQuery: null,
            refererUrl: "https://example.test/app/parc-settings/wallpapers?from_gpo={$encodedGuid}",
        );

        $this->assertStringContainsString('Retour à la GPO', $html);
        $this->assertStringContainsString('redirections-from-referer', $html);
    }
}
