<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 8.4 / AC1 — Garde-fous architecturaux des routes DDNS.
 *
 * Lecture textuelle de `routes/web.php` (patron `LegacyTombstoneRoutesTest`) :
 *   1. Les deux chemins (`/dhcp/dnsupdate` et le legacy `.php`) sont déclarés.
 *   2. Ils sont déclarés AVANT le catchall `{path}` — sinon le catchall
 *      proxifie vers le vhost legacy (mort) et le DDNS reste inopérant.
 *   3. Ils portent `local.request` + `throttle:…` (appel machine LAN).
 *   4. Aucun middleware d'auth (dhcpd n'a pas de session).
 *   5. Ils portent `withoutMiddleware(['web'])` (pas de CSRF/session).
 */
class DhcpDnsUpdateRoutesTest extends TestCase
{
    /** @var array<string, string> */
    private const NEEDLES = [
        'native' => "['\"]/dhcp/dnsupdate['\"]",
        'legacy_path' => "['\"]/dhcp/dnsupdate\\.php['\"]",
    ];

    private function routesContent(): string
    {
        $routesFile = realpath(__DIR__ . '/../../routes/web.php');
        self::assertNotFalse($routesFile, 'routes/web.php introuvable');

        return (string) file_get_contents($routesFile);
    }

    private function catchallOffset(string $content): int
    {
        self::assertSame(
            1,
            preg_match('/Route::match\([^)]*[\'"]\{path\}[\'"]/s', $content, $m, PREG_OFFSET_CAPTURE),
            'Route catchall `{path}` introuvable dans routes/web.php',
        );

        return (int) $m[0][1];
    }

    /**
     * Offset de la déclaration de route pour un path donné.
     */
    private function routeOffset(string $content, string $needle): int
    {
        self::assertSame(
            1,
            preg_match('@' . $needle . '@', $content, $m, PREG_OFFSET_CAPTURE),
            sprintf('Route DDNS non déclarée (motif %s)', $needle),
        );

        return (int) $m[0][1];
    }

    #[Test]
    public function both_ddns_routes_are_declared(): void
    {
        $content = $this->routesContent();

        foreach (self::NEEDLES as $key => $needle) {
            self::assertSame(1, preg_match('@' . $needle . '@', $content), sprintf('Route DDNS `%s` absente', $key));
        }
    }

    #[Test]
    public function ddns_routes_are_declared_before_catchall(): void
    {
        $content = $this->routesContent();
        $catchall = $this->catchallOffset($content);

        foreach (self::NEEDLES as $key => $needle) {
            self::assertLessThan(
                $catchall,
                $this->routeOffset($content, $needle),
                sprintf('La route DDNS `%s` DOIT être déclarée avant le catchall `{path}`', $key),
            );
        }
    }

    /**
     * Bloc de déclaration DDNS : de la première déclaration `Route::post`
     * portant `/dhcp/dnsupdate` jusqu'au catchall.
     */
    private function ddnsBlock(): string
    {
        $content = $this->routesContent();
        $start = $this->routeOffset($content, self::NEEDLES['native']);
        $start = (int) strrpos(substr($content, 0, $start), 'Route::post');

        return substr($content, $start, $this->catchallOffset($content) - $start);
    }

    #[Test]
    public function ddns_routes_carry_server_guard_and_throttle(): void
    {
        $block = $this->ddnsBlock();

        // `dhcp.server.request` (loopback + se4fs_ip) et NON `local.request` :
        // l'allowlist WPKG couvre le parc entier, or cet endpoint supprime des
        // enregistrements DNS sans authentifier l'appelant.
        self::assertSame(
            2,
            substr_count($block, "'dhcp.server.request'"),
            'Les 2 routes DDNS doivent porter la garde serveur dédiée',
        );
        self::assertStringNotContainsString(
            "'local.request'",
            $block,
            'local.request (allowlist parc) est trop permissif pour une primitive de suppression DNS',
        );
        self::assertSame(
            2,
            substr_count($block, "'throttle:ddns'"),
            'Les 2 routes DDNS doivent porter le limiteur nommé dédié',
        );
    }

    /**
     * En GET, une balise `<img>` sur une page hostile ouverte depuis le
     * serveur suffirait à déclencher une suppression DNS.
     */
    #[Test]
    public function ddns_routes_are_post_only(): void
    {
        $block = $this->ddnsBlock();

        self::assertSame(2, substr_count($block, 'Route::post('), 'Les 2 routes DDNS doivent être déclarées en POST');
        self::assertStringNotContainsString("'GET'", $block, 'Aucune route DDNS ne doit accepter GET');
    }

    #[Test]
    public function ddns_routes_have_no_auth_middleware(): void
    {
        $block = $this->ddnsBlock();

        foreach (['auth', 'auth:', 'agent.token', 'auth.v1'] as $forbidden) {
            self::assertStringNotContainsString(
                "'" . $forbidden . "'",
                $block,
                sprintf('Les routes DDNS ne doivent porter aucun middleware d\'auth (%s trouvé)', $forbidden),
            );
        }
    }

    #[Test]
    public function ddns_routes_opt_out_of_web_middleware(): void
    {
        $block = $this->ddnsBlock();

        self::assertSame(
            2,
            substr_count($block, "withoutMiddleware(['web'])"),
            'Les 2 routes DDNS doivent sortir du groupe `web` (appel machine sans session/CSRF)',
        );
    }

    /**
     * `dhcp-dyndns.sh` tourne en ROOT sous dhcpd et reçoit le
     * `client-hostname` annoncé librement par n'importe quel client du LAN.
     * Avec `curl -F`, une valeur commençant par `@` ou `<` est interprétée
     * comme un chemin de fichier à lire : un client nommé `</etc/shadow` ferait
     * poster le contenu du fichier. Seul `--form-string` est acceptable.
     */
    #[Test]
    public function dyndns_script_never_uses_interpolating_form_flag(): void
    {
        $script = (string) file_get_contents(base_path('scripts/system/dhcp-dyndns.sh'));

        self::assertSame(
            4,
            substr_count($script, '--form-string "'),
            'Les 4 champs postés doivent utiliser --form-string',
        );
        self::assertDoesNotMatchRegularExpression(
            '/(^|\s)-F\s/m',
            $script,
            'curl -F interpréterait un client-hostname commençant par @ ou < comme un fichier à lire',
        );
        self::assertStringContainsString(
            'http://127.0.0.1/dhcp/dnsupdate',
            $script,
            'La cible doit être le loopback (dhcpd est co-localisé) — sinon la garde serveur exige une allowlist',
        );
        self::assertStringContainsString('exit 0', $script, 'dhcpd ne doit jamais échouer sur ce chemin');
    }
}
