<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\V1;

use App\Auth\V1\Http\Middleware\InjectBootstrapFragment;
use App\Auth\V1\Models\WorkstationMigrationStatus;
use Illuminate\Http\Response;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.11 — AC5.2 / T5.7.
 *
 * Tests Feature d'intégration : le middleware `inject.bootstrap-fragment`
 * (alias `InjectBootstrapFragment`) appliqué via routes Laravel modifie bien
 * le body des réponses legacy. On définit des routes fake légères (pour ne
 * pas dépendre du contexte APCu legacy qui requiert une chaîne complète
 * `applications.php` → APCu posée → endpoint out qui le lit), et on vérifie
 * que le middleware s'applique conformément à AC5.1.
 *
 * Garde-fou complémentaire : les tests Feature 16.3-16.7 existants doivent
 * être adaptés avec `withoutMiddleware('inject.bootstrap-fragment')` s'ils
 * assertent un body iso-bytes strict — c'est documenté dans le Dev Agent
 * Record + cf. file list section "Tests Feature 16.3-16.7 à adapter".
 */
class InjectBootstrapFragmentIntegrationTest extends TestCase
{
    use IssuesWorkstationJwt;

    private const TEST_UUID = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
        InjectBootstrapFragment::clearFragmentCache();
        config([
            'sambaedu.se4fs_name' => 'se4fs-test001',
            'sambaedu.domain' => 'lab.local',
            'auth_v1.server.host_suffix' => 'lab.local',
        ]);

        // Routes fake instrumentées avec le middleware sous test.
        Route::middleware('inject.bootstrap-fragment')->group(function (): void {
            Route::match(['GET', 'POST'], '/test-inject/text-plain', fn () => response('#!/bin/bash\necho legacy', 200)
                ->header('Content-Type', 'text/plain; charset=utf-8'))
                ->name('test.text-plain');

            Route::match(['GET', 'POST'], '/test-inject/text-json', fn () => response('{"data":"x"}', 200)
                ->header('Content-Type', 'text/json'))
                ->name('test.text-json');

            Route::match(['GET', 'POST'], '/test-inject/error', fn () => response('error happened', 400)
                ->header('Content-Type', 'text/plain; charset=utf-8'))
                ->name('test.error');
        });

        // ⚠ routes/web.php termine par un catchall `{path}` (regex `.*`)
        // qui est registered AVANT les routes ci-dessus (ajoutées dynamiquement
        // en setUp). Le RouteCollection matche dans l'ordre d'insertion donc
        // /test-inject/* serait happé par le catchall (404). On reconstruit le
        // collection avec les routes test-inject en tête.
        $this->moveTestRoutesAhead();
    }

    private function moveTestRoutesAhead(): void
    {
        $router = $this->app['router'];
        $head = [];
        $tail = [];
        foreach ($router->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'test-inject')) {
                $head[] = $route;
            } else {
                $tail[] = $route;
            }
        }
        $new = new RouteCollection();
        foreach ([...$head, ...$tail] as $route) {
            $new->add($route);
        }
        $router->setRoutes($new);
    }

    #[Test]
    public function fragment_inject_for_non_migrated_windows_workstation(): void
    {
        $res = $this->get('/test-inject/text-plain?uuid=' . self::TEST_UUID . '&os=windows');
        $res->assertStatus(200);

        $body = $res->getContent();
        $this->assertStringContainsString('@echo off', $body);
        $this->assertStringContainsString('SambaEdu auto-bootstrap', $body);
        $this->assertStringEndsWith('#!/bin/bash\necho legacy', $body);
    }

    #[Test]
    public function fragment_skip_for_migrated_workstation(): void
    {
        // Seed du status migration.
        WorkstationMigrationStatus::factory()->forUuid(self::TEST_UUID)->create();

        $res = $this->get('/test-inject/text-plain?uuid=' . self::TEST_UUID . '&os=windows');
        $res->assertStatus(200);

        // Body strictement identique au legacy original.
        $this->assertSame('#!/bin/bash\necho legacy', $res->getContent());
    }

    #[Test]
    public function fragment_skip_for_json_content_type(): void
    {
        $res = $this->get('/test-inject/text-json?uuid=' . self::TEST_UUID . '&os=windows');
        $res->assertStatus(200);

        $this->assertSame('{"data":"x"}', $res->getContent());
    }

    #[Test]
    public function fragment_skip_for_4xx_response(): void
    {
        $res = $this->get('/test-inject/error?uuid=' . self::TEST_UUID . '&os=windows');
        $res->assertStatus(400);

        $this->assertSame('error happened', $res->getContent());
    }

    #[Test]
    public function fragment_skip_when_no_uuid_in_request(): void
    {
        $res = $this->get('/test-inject/text-plain?os=windows');
        $res->assertStatus(200);

        $this->assertSame('#!/bin/bash\necho legacy', $res->getContent());
    }

    #[Test]
    public function fragment_inject_for_non_migrated_linux_workstation(): void
    {
        $res = $this->get('/test-inject/text-plain?uuid=' . self::TEST_UUID . '&os=linux');
        $res->assertStatus(200);

        $body = $res->getContent();
        $this->assertStringContainsString('/var/lib/sambaedu/auth.json', $body);
        $this->assertStringEndsWith('#!/bin/bash\necho legacy', $body);
    }

    #[Test]
    public function fragment_substitutes_server_base_url(): void
    {
        $res = $this->get('/test-inject/text-plain?uuid=' . self::TEST_UUID . '&os=windows');
        $body = (string) $res->getContent();

        $this->assertStringContainsString('https://se4fs-test001.lab.local', $body);
    }
}
