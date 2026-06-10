<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Deployment\Http;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.6 / AC2.2 / AC2.3 / AC2.4 / AC6.3 — EnsureLocalRequest avec allowlist DB.
 *
 * Vérifie que :
 *   - IP couverte par CIDR DB → autorisée (200/réponse normale)
 *   - IP hors allowlist DB + env vide → 403
 *   - 127.0.0.1/::1 toujours autorisés même allowlist DB vide (AC2.2)
 *   - Override s'applique identiquement sur winget_out ET linux_out (AC2.3)
 *   - Effet immédiat sans config:cache (AC2.4)
 *   - Non-régression : sans clé DB → comportement identique à l'existant
 */
#[Group('wpkg-deploy')]
#[Group('story-15-6')]
class EnsureLocalRequestSettingsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        Cache::flush();
        SystemSetting::query()->whereIn('key', ['wpkg.allowed_ips', 'wpkg.winget_enabled'])->delete();
        // Activer winget pour ne pas obtenir 400 au lieu de 403.
        Config::set('sambaedu.wpkg.winget_enabled', true);
        SystemSetting::set('wpkg.winget_enabled', true);
    }

    protected function tearDown(): void
    {
        SystemSetting::query()->whereIn('key', ['wpkg.allowed_ips', 'wpkg.winget_enabled'])->delete();
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    /**
     * Fait une requête POST sur winget_out depuis une IP spécifique (REMOTE_ADDR).
     */
    private function postWingetFromIp(string $ip): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/wpkg/winget_out.php',
            parameters: ['machine' => 'PC-TEST', 'list' => '[]', 'action' => 'list'],
            server: ['REMOTE_ADDR' => $ip],
        );
    }

    /**
     * Fait une requête GET sur linux_out depuis une IP spécifique.
     */
    private function getLinuxOutFromIp(string $ip): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'GET',
            '/wpkg/linux_out.php',
            server: ['REMOTE_ADDR' => $ip],
        );
    }

    /**
     * AC2.2 — localhost 127.0.0.1 toujours autorisé même si allowlist DB vide.
     */
    #[Test]
    public function localhost_ipv4_always_allowed_even_with_empty_db_allowlist(): void
    {
        SystemSetting::set('wpkg.allowed_ips', []);
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', []);

        // 127.0.0.1 = ALWAYS_ALLOWED en dur → ne doit jamais être bloqué.
        // Params valides (machine + list + action) → 200 (winget activé en setUp).
        $response = $this->postWingetFromIp('127.0.0.1');
        $response->assertOk();
    }

    /**
     * AC2.2 — ::1 toujours autorisé même si allowlist DB vide.
     */
    #[Test]
    public function localhost_ipv6_always_allowed_even_with_empty_db_allowlist(): void
    {
        SystemSetting::set('wpkg.allowed_ips', []);
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', []);

        // Params valides → 200.
        $response = $this->postWingetFromIp('::1');
        $response->assertOk();
    }

    /**
     * AC2.2 — IP LAN dans CIDR DB → autorisée.
     */
    #[Test]
    public function ip_covered_by_db_cidr_is_allowed_on_winget_out(): void
    {
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', []);
        SystemSetting::set('wpkg.allowed_ips', ['192.168.122.0/24']);

        // Params valides (machine + list + action) → 200.
        $response = $this->postWingetFromIp('192.168.122.50');
        $response->assertOk();
    }

    /**
     * AC2.2 — IP hors allowlist DB et env vide → 403.
     */
    #[Test]
    public function ip_not_in_db_or_env_is_rejected_403_on_winget_out(): void
    {
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', []);
        SystemSetting::set('wpkg.allowed_ips', ['192.168.1.0/24']);

        $response = $this->postWingetFromIp('10.0.0.1');
        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * AC2.3 — Override s'applique aussi sur linux_out (même middleware).
     */
    #[Test]
    public function db_cidr_applies_to_linux_out_route(): void
    {
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', []);
        SystemSetting::set('wpkg.allowed_ips', ['192.168.122.0/24']);

        // linux_out sans `id` valide retourne 200 body vide (iso-legacy : pas de 400).
        $response = $this->getLinuxOutFromIp('192.168.122.50');
        $response->assertOk();
    }

    /**
     * AC2.3 — IP hors allowlist DB → 403 sur linux_out aussi.
     */
    #[Test]
    public function ip_not_in_db_is_rejected_on_linux_out(): void
    {
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', []);
        SystemSetting::set('wpkg.allowed_ips', ['192.168.1.0/24']);

        $response = $this->getLinuxOutFromIp('8.8.8.8');
        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * AC1.3 / Non-régression — Sans clé DB, env contrôle l'accès (env = IP autorisée → 200).
     */
    #[Test]
    public function no_db_key_falls_back_to_env_allowed_ips(): void
    {
        // Pas de clé DB.
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', ['192.168.122.0/24']);

        // Params valides (machine + list + action) + winget activé → 200.
        $response = $this->postWingetFromIp('192.168.122.50');
        $response->assertOk();
    }

    /**
     * Non-régression — Sans clé DB, env vide → 403 pour IP externe.
     */
    #[Test]
    public function no_db_key_env_empty_rejects_external_ip(): void
    {
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', []);

        $response = $this->postWingetFromIp('8.8.8.8');
        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Correction post-review #3 — Fail-closed : DB polluée 0.0.0.0/0 → IP externe reste 403.
     *
     * Même si un administrateur insère directement `0.0.0.0/0` en DB (hors UI qui le bloque),
     * allowedIps() doit l'écarter silencieusement → l'IP externe ne passe pas.
     */
    #[Test]
    public function db_polluted_with_deny_all_cidr_still_rejects_external_ip(): void
    {
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', []);
        // Insertion directe en DB contournant la validation UI.
        SystemSetting::set('wpkg.allowed_ips', ['0.0.0.0/0']);

        $response = $this->postWingetFromIp('203.0.113.5');
        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * AC2.4 — Effet immédiat : modifier SystemSetting dans le même cycle → reflète immédiatement.
     * Test dans le même processus PHP, sans cache intermédiaire.
     */
    #[Test]
    public function effect_is_immediate_without_config_cache(): void
    {
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', []);

        // Avant : IP externe non autorisée → 403.
        SystemSetting::set('wpkg.allowed_ips', []);
        $before = $this->postWingetFromIp('192.168.122.99');
        self::assertSame(403, $before->getStatusCode());

        // Mise à jour DB.
        SystemSetting::set('wpkg.allowed_ips', ['192.168.122.0/24']);

        // Après (même cycle, même requête) : l'IP est autorisée → 200.
        $after = $this->postWingetFromIp('192.168.122.99');
        $after->assertOk();
    }
}
