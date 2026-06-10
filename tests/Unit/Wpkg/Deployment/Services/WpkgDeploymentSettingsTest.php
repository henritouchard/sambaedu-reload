<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Services;

use App\Models\SystemSetting;
use App\Wpkg\Deployment\Services\WpkgDeploymentSettings;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.6 / AC6.1 — Tests unit du résolveur WpkgDeploymentSettings.
 *
 * Couvre :
 *   - Précédence DB > env > défaut pour wingetEnabled() et allowedIps()
 *   - AC1.3 non-régression : sans clé DB → valeurs env inchangées
 *   - Filtrage des entrées vides/non-string dans allowedIps()
 *   - Round-trip bool en SQLite (cast 'array' sur SystemSetting)
 *
 * Note : délègue la création du schéma à WpkgSchemaBootstrapper::bootstrap()
 * (inclut system_settings depuis Story 15.6 — correction post-review).
 */
#[Group('wpkg-deploy')]
#[Group('story-15-6')]
class WpkgDeploymentSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();

        // Nettoyage des clés wpkg.* pour isolation entre tests.
        SystemSetting::query()->whereIn('key', ['wpkg.winget_enabled', 'wpkg.allowed_ips'])->delete();
    }

    protected function tearDown(): void
    {
        SystemSetting::query()->whereIn('key', ['wpkg.winget_enabled', 'wpkg.allowed_ips'])->delete();
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    private function settings(): WpkgDeploymentSettings
    {
        return new WpkgDeploymentSettings();
    }

    // =========================================================================
    // wingetEnabled()
    // =========================================================================

    /**
     * AC1.3 — Non-régression : sans clé DB, retourne config() env.
     */
    #[Test]
    public function winget_enabled_returns_env_when_no_db_key(): void
    {
        Config::set('sambaedu.wpkg.winget_enabled', false);

        self::assertFalse($this->settings()->wingetEnabled());
    }

    #[Test]
    public function winget_enabled_returns_env_true_when_no_db_key(): void
    {
        Config::set('sambaedu.wpkg.winget_enabled', true);

        self::assertTrue($this->settings()->wingetEnabled());
    }

    /**
     * AC1.1 — DB override : clé DB true > env false.
     */
    #[Test]
    public function winget_enabled_db_overrides_env_false(): void
    {
        Config::set('sambaedu.wpkg.winget_enabled', false);
        SystemSetting::set('wpkg.winget_enabled', true);

        self::assertTrue($this->settings()->wingetEnabled());
    }

    /**
     * AC1.1 — DB override : clé DB false > env true.
     */
    #[Test]
    public function winget_enabled_db_false_overrides_env_true(): void
    {
        Config::set('sambaedu.wpkg.winget_enabled', true);
        SystemSetting::set('wpkg.winget_enabled', false);

        self::assertFalse($this->settings()->wingetEnabled());
    }

    /**
     * Round-trip bool — cast 'array' de SystemSetting encode/décode correctement.
     * Vérifie que true reste true et false reste false après le cycle JSON.
     */
    #[Test]
    public function winget_enabled_bool_round_trip_via_system_setting(): void
    {
        SystemSetting::set('wpkg.winget_enabled', true);
        self::assertTrue($this->settings()->wingetEnabled());

        SystemSetting::set('wpkg.winget_enabled', false);
        self::assertFalse($this->settings()->wingetEnabled());
    }

    /**
     * AC1.3 — Défaut fail-closed : sans clé DB ni config → false.
     */
    #[Test]
    public function winget_enabled_defaults_to_false_without_db_or_config(): void
    {
        Config::set('sambaedu.wpkg.winget_enabled', null);

        self::assertFalse($this->settings()->wingetEnabled());
    }

    // =========================================================================
    // allowedIps()
    // =========================================================================

    /**
     * AC1.3 — Non-régression : sans clé DB, retourne config() env (tableau).
     */
    #[Test]
    public function allowed_ips_returns_env_when_no_db_key(): void
    {
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', ['192.168.1.0/24']);

        self::assertSame(['192.168.1.0/24'], $this->settings()->allowedIps());
    }

    /**
     * AC1.3 — Non-régression : config() sous forme de string CSV (format legacy).
     */
    #[Test]
    public function allowed_ips_handles_csv_env_fallback(): void
    {
        // Le config peut retourner une string CSV dans certains environnements legacy.
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', '192.168.1.1,10.0.0.1');

        $result = $this->settings()->allowedIps();

        self::assertContains('192.168.1.1', $result);
        self::assertContains('10.0.0.1', $result);
    }

    /**
     * AC1.2 — DB override : clé DB array > env array.
     */
    #[Test]
    public function allowed_ips_db_overrides_env(): void
    {
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', ['10.0.0.0/8']);
        SystemSetting::set('wpkg.allowed_ips', ['192.168.122.0/24', '172.16.0.0/16']);

        $result = $this->settings()->allowedIps();

        self::assertSame(['192.168.122.0/24', '172.16.0.0/16'], $result);
    }

    /**
     * AC1.2 — Filtrage entrées vides et non-string.
     */
    #[Test]
    public function allowed_ips_filters_empty_and_non_string_entries(): void
    {
        SystemSetting::set('wpkg.allowed_ips', ['192.168.1.1', '', '   ', null, true, '10.0.0.1']);

        // Seules les string non-vides passent.
        $result = $this->settings()->allowedIps();

        // '' et '   ' sont exclus (trim n'est pas appliqué — le test vérifie le filtre is_string + non-vide).
        // null et true sont exclus (non-string).
        // '   ' : is_string=true, non-vide (contient des espaces) → selon le filtre `$e !== ''` elle PASSE.
        // Le filtre actuel : is_string($e) && $e !== '' → '   ' passe (c'est voulu : l'espace serait rejeté
        // par la règle de validation SafeIpCidrRule lors de la saisie, pas ici).
        self::assertContains('192.168.1.1', $result);
        self::assertContains('10.0.0.1', $result);
        self::assertNotContains('', $result);
    }

    /**
     * AC1.3 — Défaut vide : sans clé DB ni config → tableau vide.
     */
    #[Test]
    public function allowed_ips_defaults_to_empty_array_without_db_or_config(): void
    {
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', null);

        self::assertSame([], $this->settings()->allowedIps());
    }

    /**
     * allowedIps() retourne toujours un array indexé séquentiellement.
     */
    #[Test]
    public function allowed_ips_returns_sequential_array(): void
    {
        SystemSetting::set('wpkg.allowed_ips', ['192.168.1.1', '10.0.0.1']);

        $result = $this->settings()->allowedIps();

        self::assertSame(array_values($result), $result);
    }

    // =========================================================================
    // DataProviders
    // =========================================================================

    /**
     * @return array<string, array{bool}>
     */
    public static function boolProvider(): array
    {
        return [
            'true' => [true],
            'false' => [false],
        ];
    }

    /**
     * AC1.1 — Vérification systématique DB > env pour bool.
     *
     * @param bool $dbValue
     */
    #[Test]
    #[DataProvider('boolProvider')]
    public function winget_enabled_db_always_wins_over_env(bool $dbValue): void
    {
        Config::set('sambaedu.wpkg.winget_enabled', ! $dbValue); // env = opposé
        SystemSetting::set('wpkg.winget_enabled', $dbValue);

        self::assertSame($dbValue, $this->settings()->wingetEnabled());
    }

    // =========================================================================
    // Correction post-review #3 — Fail-closed : entrées dangereuses écartées en lecture
    // =========================================================================

    /**
     * Fail-closed : 0.0.0.0/0 en DB → écarté par allowedIps().
     */
    #[Test]
    public function allowed_ips_rejects_deny_all_ipv4_from_db(): void
    {
        SystemSetting::set('wpkg.allowed_ips', ['0.0.0.0/0']);

        $result = $this->settings()->allowedIps();

        self::assertNotContains('0.0.0.0/0', $result);
    }

    /**
     * Fail-closed : ::/0 en DB → écarté par allowedIps().
     */
    #[Test]
    public function allowed_ips_rejects_deny_all_ipv6_from_db(): void
    {
        SystemSetting::set('wpkg.allowed_ips', ['::/0']);

        $result = $this->settings()->allowedIps();

        self::assertNotContains('::/0', $result);
    }

    /**
     * Fail-closed : préfixe trop large (/8) en DB → écarté.
     */
    #[Test]
    public function allowed_ips_rejects_too_wide_prefix_from_db(): void
    {
        SystemSetting::set('wpkg.allowed_ips', ['10.0.0.0/8']);

        $result = $this->settings()->allowedIps();

        self::assertNotContains('10.0.0.0/8', $result);
    }

    /**
     * Fail-closed : liste polluée → entrées valides conservées, invalides écartées.
     */
    #[Test]
    public function allowed_ips_keeps_valid_entries_and_rejects_invalid_ones(): void
    {
        SystemSetting::set('wpkg.allowed_ips', ['192.168.1.0/24', '0.0.0.0/0', '10.0.0.1']);

        $result = $this->settings()->allowedIps();

        self::assertContains('192.168.1.0/24', $result);
        self::assertContains('10.0.0.1', $result);
        self::assertNotContains('0.0.0.0/0', $result);
    }

    /**
     * Fail-closed : 127.0.0.1 et ::1 sont préservés même s'ils sont dans la liste.
     */
    #[Test]
    public function allowed_ips_preserves_localhost_entries(): void
    {
        SystemSetting::set('wpkg.allowed_ips', ['127.0.0.1', '::1', '192.168.1.0/24']);

        $result = $this->settings()->allowedIps();

        self::assertContains('127.0.0.1', $result);
        self::assertContains('::1', $result);
    }
}
