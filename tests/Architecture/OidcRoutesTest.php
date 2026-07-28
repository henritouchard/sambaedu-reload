<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Story 55.1 — garde-fous architecturaux du fournisseur OIDC.
 *
 * Calque de {@see FederatedRouteTest} (Story 20.1), pour les mêmes raisons :
 *
 *  1. Les 5 routes OIDC (4 en 55.1 + `/oidc/userinfo` en 55.2) sont déclarées
 *     AVANT le catchall legacy `{path}`. En
 *     dessous, le catchall les proxifierait vers le vhost legacy (mort) et
 *     AUCUN client OIDC ne pourrait découvrir SE5.
 *  2. `/oidc/token` est un POST, et uniquement un POST : en GET, le secret du
 *     client et le code d'autorisation atterriraient dans les logs du serveur,
 *     l'historique du navigateur et l'en-tête `Referer` (doctrine D-3 du login
 *     fédéré).
 *  3. Frontière crypto : seul `OidcIdTokenIssuer` importe `Firebase\JWT` dans
 *     `app/Auth/Oidc` — la dépendance ne doit fuir ni dans les contrôleurs, ni
 *     dans les services, ni dans les modèles.
 *  4. `routes/api.php` reste INTACT : le périmètre 55.1 est entièrement en
 *     `routes/web.php` (piège connu du projet : le test d'architecture de
 *     `api.php` n'inspecte qu'une fenêtre de ~1500 caractères).
 */
class OidcRoutesTest extends TestCase
{
    private function routesContent(): string
    {
        $routesFile = realpath(__DIR__.'/../../routes/web.php');
        self::assertNotFalse($routesFile);

        return (string) file_get_contents($routesFile);
    }

    private function catchallOffset(string $content): int
    {
        $catchallPattern = "/Route::match\s*\([^)]*['\"]\\{path\\}['\"]/";
        self::assertSame(
            1,
            preg_match($catchallPattern, $content, $matches, PREG_OFFSET_CAPTURE),
            'Catchall legacy {path} introuvable',
        );

        return (int) $matches[0][1];
    }

    #[Test]
    public function the_five_oidc_routes_are_declared_before_the_catchall(): void
    {
        $content = $this->routesContent();
        $catchallOffset = $this->catchallOffset($content);

        $routes = [
            'discovery' => "@Route::get\s*\([^;]*?['\"]/\.well-known/openid-configuration['\"]@",
            'jwks' => "@Route::get\s*\([^;]*?['\"]/oidc/jwks['\"]@",
            'authorize' => "@Route::get\s*\([^;]*?['\"]/oidc/authorize['\"]@",
            'token' => "@Route::post\s*\([^;]*?['\"]/oidc/token['\"]@",
            // Story 55.2 — 5ᵉ route.
            'userinfo' => "@Route::match\s*\([^;]*?['\"]/oidc/userinfo['\"]@",
        ];

        foreach ($routes as $label => $pattern) {
            self::assertSame(
                1,
                preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE),
                sprintf('Route OIDC « %s » non déclarée', $label),
            );

            self::assertLessThan(
                $catchallOffset,
                $matches[0][1],
                sprintf('ORDRE INVALIDE : la route OIDC « %s » doit être AVANT le catchall', $label),
            );
        }
    }

    #[Test]
    public function the_token_endpoint_is_a_post_only(): void
    {
        $content = $this->routesContent();

        self::assertSame(
            0,
            preg_match("@Route::(get|any|match)\s*\([^;]*?['\"]/oidc/token['\"]@", $content),
            'Le token endpoint ne doit exposer NI GET NI ANY (secret et code en query = fuite)',
        );
    }

    /**
     * Story 55.2 — `/oidc/userinfo` expose GET **et** POST, et RIEN d'autre.
     *
     * Les deux méthodes sont imposées par OIDC Core §5.3.1. En revanche
     * `Route::any` ouvrirait PUT/DELETE/PATCH sur un endpoint de lecture — une
     * surface gratuite sur la seule route qui sert des données d'identité.
     */
    #[Test]
    public function the_userinfo_endpoint_exposes_get_and_post_only(): void
    {
        $content = $this->routesContent();

        self::assertSame(
            0,
            preg_match("@Route::any\s*\([^;]*?['\"]/oidc/userinfo['\"]@", $content),
            'Route::any ouvrirait des verbes non prévus sur /oidc/userinfo',
        );

        self::assertSame(
            1,
            preg_match("@Route::match\s*\(\s*\[([^\]]*)\][^;]*?['\"]/oidc/userinfo['\"]@", $content, $matches),
            'Route /oidc/userinfo non déclarée en Route::match',
        );

        $verbs = array_map(
            static fn (string $v): string => strtolower(trim($v, " \t\n\r'\"")),
            explode(',', $matches[1]),
        );
        sort($verbs);

        self::assertSame(['get', 'post'], $verbs);
    }

    #[Test]
    public function the_authorize_route_carries_both_the_auth_guard_and_the_federated_audit(): void
    {
        $content = $this->routesContent();

        self::assertSame(
            1,
            preg_match("@Route::get\s*\([^;]*?['\"]/oidc/authorize['\"](.*?);@s", $content, $matches),
            'Route /oidc/authorize non déclarée',
        );

        $declaration = $matches[1];

        // Le guard est la définition autoritative de « session SE5 active » :
        // un émetteur d'identité moins strict que les pages qu'il protège
        // serait une faille.
        self::assertStringContainsString('sambaedu.auth', $declaration);

        // Invariant `FederatedAuditCoverageTest` : toute route `sambaedu.auth`
        // porte `federated.audit`.
        self::assertStringContainsString('federated.audit', $declaration);

        // ⚠️ Correctif review 55.1 (#1) : déclarer `federated.audit` NE SUFFIT
        // PAS. `AuditExternalAction` n'audite les GET que si le NOM de la route
        // figure dans `federated_auth.audit.sensitive_get_routes` — sans quoi le
        // middleware est un no-op SILENCIEUX.
        //
        // Ce test-ci ne peut PAS le vérifier : c'est un `PHPUnit\TestCase` sans
        // application bootstrapée, donc sans `config()` résolue. Et une assertion
        // sur le texte du fichier de config ne prouverait qu'une chaîne, pas une
        // couverture. La garantie est donc vérifiée là où elle est OBSERVABLE —
        // par l'écriture réelle d'une ligne d'audit :
        // {@see \Tests\Feature\Oidc\OidcFederatedAuditTest}.
    }

    #[Test]
    public function only_the_id_token_issuer_imports_firebase_jwt(): void
    {
        $namespaceDir = realpath(__DIR__.'/../../app/Auth/Oidc');
        self::assertNotFalse($namespaceDir);

        $finder = (new Finder())->files()->in($namespaceDir)->name('*.php');

        // Story 55.2 : ni `OidcClaimsResolver`, ni `OidcAccessTokenValidator`,
        // ni `UserinfoController` n'importent quoi que ce soit de crypto — la
        // frontière est INCHANGÉE malgré trois fichiers de plus.
        $allowed = ['OidcIdTokenIssuer.php'];
        $inspected = 0;
        $importers = [];

        foreach ($finder as $file) {
            $inspected++;

            if (! str_contains((string) $file->getContents(), 'Firebase\\JWT')) {
                continue;
            }

            $importers[] = $file->getFilename();
        }

        // Sanity : sans ça, un namespace déplacé ferait passer le test à vide.
        self::assertGreaterThan(5, $inspected, 'le garde-fou doit inspecter le namespace réel');

        self::assertSame(
            $allowed,
            $importers,
            'Frontière crypto : seul OidcIdTokenIssuer peut importer Firebase\\JWT',
        );
    }

    #[Test]
    public function the_oidc_story_does_not_touch_the_api_routes_file(): void
    {
        $apiRoutes = realpath(__DIR__.'/../../routes/api.php');
        self::assertNotFalse($apiRoutes);

        $content = (string) file_get_contents($apiRoutes);

        // Le périmètre 55.1 est entièrement en `routes/web.php`. Si une story
        // future devait exposer de l'OIDC en API, son bloc devrait être placé
        // APRÈS le groupe 16.12 (fenêtre d'inspection de `ScriptsOsNamespaceTest`).
        self::assertStringNotContainsString('/oidc/', $content);
        self::assertStringNotContainsString('Auth\\Oidc', $content);
    }
}
