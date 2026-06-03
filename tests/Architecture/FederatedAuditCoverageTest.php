<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Http\Middleware\Auth\AuditExternalAction;
use App\Http\Middleware\Auth\SambaEduAuthGuard;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 20.4 — garde-fou architectural (post-review P-5).
 *
 * INVARIANT : toute route dont la pile de middleware (résolue, groupes inclus)
 * contient `sambaedu.auth` DOIT aussi contenir `federated.audit`.
 *
 * Raison d'être : un acteur fédéré actif (Story 20.1) peut atteindre TOUTE route
 * protégée par `sambaedu.auth` (le guard valide l'identité externe au lieu du
 * LDAP). Si une telle route ne porte pas `federated.audit`, ses actions
 * mutantes / GET sensibles échappent au journal d'imputabilité (20.4). Ce test
 * ferme l'angle mort de façon TOTALE (au-delà du seul `/test-auth` corrigé).
 *
 * Implémentation : on inspecte la pile RÉELLE via `Route::gatherMiddleware()`
 * (qui fusionne middleware de route + middleware de groupe) sur le routeur
 * booté — plus fiable qu'un parsing textuel de `routes/web.php` (qui raterait
 * l'héritage de groupe). On tolère l'alias court (`sambaedu.auth` /
 * `federated.audit`) ET la classe pleinement qualifiée, car `gatherMiddleware()`
 * peut renvoyer l'une ou l'autre forme selon la déclaration.
 *
 * ALLOWLIST D'EXCEPTIONS CONNUES ({@see self::KNOWN_UNAUDITED_ROUTES}) : deux
 * routes pré-existantes portent `sambaedu.auth` SANS `federated.audit`. Elles
 * NE sont PAS du même registre que `/test-auth` (debug trivial) et touchent des
 * chemins sensibles (registration programmatique Livewire / API privée) → leur
 * correction relève d'une DÉCISION (hors scope de ce correctif P-5). Elles sont
 * explicitement listées ici pour que :
 *   1. l'invariant reste vert sans masquer la dette ;
 *   2. TOUTE NOUVELLE route `sambaedu.auth` sans audit fasse échouer le test ;
 *   3. la liste serve de TODO traçable (à vider quand la décision est prise).
 */
class FederatedAuditCoverageTest extends TestCase
{
    /**
     * Routes `sambaedu.auth` SANS `federated.audit` connues et acceptées
     * temporairement (clé = `uri`). NE PAS étendre sans décision explicite.
     *
     *  - `livewire/update` : endpoint POST Livewire enregistré
     *    programmatiquement dans `AppServiceProvider::boot()` via
     *    `Livewire::setUpdateRoute()` (+ variante préfixée proxy). Véritable
     *    canal mutant des composants → vraie lacune d'audit, mais correction
     *    sensible (impacte tout le front Livewire) à arbitrer.
     *  - `api/v1/health/detailed` : route API privée legacy (`routes/api.php`,
     *    lecture seule health check) sous `sambaedu.auth`. GET non sensible.
     *
     * @var list<string>
     */
    private const KNOWN_UNAUDITED_ROUTES = [
        'livewire/update',
        'api/v1/health/detailed',
    ];

    #[Test]
    public function every_sambaedu_auth_route_also_carries_federated_audit(): void
    {
        /** @var \Illuminate\Routing\RouteCollectionInterface $routes */
        $routes = app('router')->getRoutes();

        $offenders = [];

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            if (! $this->hasSambaEduAuth($middleware)) {
                continue;
            }

            if ($this->isKnownException($route->uri())) {
                continue;
            }

            if (! $this->hasFederatedAudit($middleware)) {
                $offenders[] = sprintf(
                    '%s [%s] (name=%s)',
                    $route->uri(),
                    implode('|', $route->methods()),
                    $route->getName() ?? '—',
                );
            }
        }

        self::assertSame(
            [],
            $offenders,
            "INVARIANT 20.4 (P-5) VIOLÉ : des routes portent `sambaedu.auth` SANS "
            . "`federated.audit` → leurs actions fédérées échapperaient au journal "
            . "d'audit. Routes fautives :\n  - " . implode("\n  - ", $offenders),
        );
    }

    /**
     * Sanity : le test détecte bien AU MOINS une route `sambaedu.auth` (sinon le
     * garde-fou passerait à vide en cas de régression du routing/booting).
     */
    #[Test]
    public function guard_inspects_at_least_one_sambaedu_auth_route(): void
    {
        /** @var \Illuminate\Routing\RouteCollectionInterface $routes */
        $routes = app('router')->getRoutes();

        $count = 0;
        foreach ($routes as $route) {
            if ($this->hasSambaEduAuth($route->gatherMiddleware())) {
                $count++;
            }
        }

        self::assertGreaterThan(
            0,
            $count,
            'Aucune route `sambaedu.auth` détectée — le garde-fou tournerait à vide.',
        );
    }

    /**
     * Une route est-elle dans l'allowlist d'exceptions connues ? On tolère la
     * variante préfixée proxy de l'endpoint Livewire (`{prefix}/livewire/update`,
     * cf. `AppServiceProvider::setUpdateRoute`) en plus du chemin nu.
     */
    private function isKnownException(string $uri): bool
    {
        foreach (self::KNOWN_UNAUDITED_ROUTES as $known) {
            if ($uri === $known || str_ends_with($uri, '/' . $known)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $middleware
     */
    private function hasSambaEduAuth(array $middleware): bool
    {
        return in_array('sambaedu.auth', $middleware, true)
            || in_array(SambaEduAuthGuard::class, $middleware, true);
    }

    /**
     * @param  array<int,string>  $middleware
     */
    private function hasFederatedAudit(array $middleware): bool
    {
        return in_array('federated.audit', $middleware, true)
            || in_array(AuditExternalAction::class, $middleware, true);
    }
}
