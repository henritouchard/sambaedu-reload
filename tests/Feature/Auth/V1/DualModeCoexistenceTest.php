<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\V1;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.10 — D8 (dual-mode legacy + nouvelles routes /api/v1/agent/*).
 *
 * Vérifie statiquement (par lookup du Router) que :
 *
 *  1. Les routes nouvelles `/api/v1/agent/{enroll,refresh,ping}` sont
 *     enregistrées avec les bons noms et middlewares.
 *  2. Les routes legacy `/gpo/*_out.php` (D8) sont **toujours** enregistrées
 *     dans le router (= pas supprimées par effet de bord 16.10).
 *  3. Aucun conflit de namespace entre `/api/v1/agent/*` (auth.v1.workstation)
 *     et `/api/v1/snapshot` etc. (controlhub.auth).
 *
 * On ne fait pas d'appel HTTP réel sur les routes legacy (elles dépendent
 * de samba-tool, LDAP, etc.) — c'est le boulot des suites Feature de 4.7,
 * 4.8, 16.3b/c, 16.7. On vérifie juste leur **présence**.
 */
class DualModeCoexistenceTest extends TestCase
{
    #[Test]
    public function new_agent_routes_are_registered(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $enroll = $routes->first(fn ($r) => $r->getName() === 'agent.v1.enroll');
        $refresh = $routes->first(fn ($r) => $r->getName() === 'agent.v1.refresh');
        $ping = $routes->first(fn ($r) => $r->getName() === 'agent.v1.ping');

        $this->assertNotNull($enroll, 'Route agent.v1.enroll missing');
        $this->assertNotNull($refresh, 'Route agent.v1.refresh missing');
        $this->assertNotNull($ping, 'Route agent.v1.ping missing');

        $this->assertSame('api/v1/agent/enroll', $enroll->uri());
        $this->assertSame('api/v1/agent/refresh', $refresh->uri());
        $this->assertSame('api/v1/agent/ping', $ping->uri());
    }

    #[Test]
    public function agent_routes_have_correct_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $enroll = $routes->first(fn ($r) => $r->getName() === 'agent.v1.enroll');
        $refresh = $routes->first(fn ($r) => $r->getName() === 'agent.v1.refresh');
        $ping = $routes->first(fn ($r) => $r->getName() === 'agent.v1.ping');

        $this->assertContains('auth.v1.bootstrap', $enroll->gatherMiddleware());
        $this->assertContains('throttle:10,1', $enroll->gatherMiddleware());

        $this->assertContains('auth.v1.refresh', $refresh->gatherMiddleware());
        $this->assertContains('throttle:30,1', $refresh->gatherMiddleware());

        $this->assertContains('auth.v1.workstation', $ping->gatherMiddleware());
    }

    #[Test]
    public function legacy_out_routes_are_extinguished(): void
    {
        // Story 27.14 — le canal de config legacy `gpo/*_out.php` a été ÉTEINT
        // EN BLOC (pas d'état transitoire legacy/agent). Ce test garde l'invariant
        // « le canal reste mort » : aucune de ces routes ne doit réapparaître.
        $routes = collect(Route::getRoutes()->getRoutes());

        $uris = $routes->map(fn ($r) => $r->uri())->all();

        foreach ([
            'gpo/wallpaper_out.php',     // ex-4.7
            'gpo/firefox_out.php',       // ex-4.8
            'gpo/thunderbird_out.php',   // ex-4.8
            'gpo/shortcuts_out.php',     // ex-16.3a / 1bis.18e
            'gpo/network_out.php',       // ex-16.3b
            'gpo/veyon_out.php',         // ex-16.3b
            'gpo/associations_out.php',  // ex-16.3c
            'gpo/applications.php',      // ex-16.7
        ] as $extinguished) {
            $this->assertNotContains($extinguished, $uris, "{$extinguished} should be extinguished (story 27.14)");
        }
    }

    #[Test]
    public function controlhub_v1_routes_still_use_controlhub_auth_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $snapshot = $routes->first(fn ($r) => $r->getName() === 'snapshot');
        $this->assertNotNull($snapshot, 'controlHub snapshot route missing');
        $this->assertContains('controlhub.auth', $snapshot->gatherMiddleware());
        // Et surtout PAS notre auth.v1.* (pas de collision)
        $this->assertNotContains('auth.v1.workstation', $snapshot->gatherMiddleware());
    }
}
