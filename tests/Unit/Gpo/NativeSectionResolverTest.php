<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Support\NativeSectionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires purs — NativeSectionResolver (Story 16.3a, AC1.3).
 *
 * Test pur PHPUnit : pas de bootstrap Laravel, pas de Spatie, pas de BDD.
 * Couverture : resolve(), hasMatch(), buildUrl() — 6+ cas + piège Décision D8.
 */
class NativeSectionResolverTest extends TestCase
{
    // =========================================================================
    // resolve() — mappings simples (4 sections)
    // =========================================================================

    #[Test]
    public function it_resolves_profils_itinerants_for_redirections_keyword(): void
    {
        $result = NativeSectionResolver::resolve('redirections-roaming-test');

        self::assertArrayHasKey('profils-itinerants', $result);
        self::assertSame('/admin/settings?tab=profils-itinerants', $result['profils-itinerants']['url']);
    }

    #[Test]
    public function it_resolves_wallpapers_for_wallpaper_keyword(): void
    {
        $result = NativeSectionResolver::resolve('wallpaper-default');

        self::assertArrayHasKey('wallpapers', $result);
        self::assertSame('/app/parc-settings/wallpapers', $result['wallpapers']['url']);
    }

    #[Test]
    public function it_resolves_app_customizations_for_firefox_keyword(): void
    {
        $result = NativeSectionResolver::resolve('firefox-policy-2024');

        self::assertArrayHasKey('app-customizations', $result);
        self::assertSame('/app/parc-settings/app-customizations', $result['app-customizations']['url']);
    }

    #[Test]
    public function it_resolves_shortcuts_for_shortcut_keyword(): void
    {
        $result = NativeSectionResolver::resolve('shortcuts-eleves');

        self::assertArrayHasKey('shortcuts', $result);
        self::assertSame('/app/shortcuts', $result['shortcuts']['url']);
    }

    // =========================================================================
    // resolve() — multi-match (AC1.3 cas 5)
    // =========================================================================

    #[Test]
    public function it_resolves_multiple_sections_for_multi_match_display_name(): void
    {
        // 'firefox' → app-customizations, 'wallpaper' → wallpapers, 'redirections' → profils-itinerants
        $result = NativeSectionResolver::resolve('firefox-wallpaper-roaming-2024');

        self::assertArrayHasKey('profils-itinerants', $result, 'profils-itinerants attendu (roaming)');
        self::assertArrayHasKey('wallpapers', $result, 'wallpapers attendu (wallpaper)');
        self::assertArrayHasKey('app-customizations', $result, 'app-customizations attendu (firefox)');
        self::assertCount(3, $result);
    }

    // =========================================================================
    // resolve() — no-match (AC1.3 cas 6)
    // =========================================================================

    #[Test]
    public function it_returns_empty_array_when_no_pattern_matches(): void
    {
        $result = NativeSectionResolver::resolve('gpo-custom-foo-bar');

        self::assertSame([], $result);
    }

    // =========================================================================
    // resolve() — cas piège (Piège 10 — displayName vide)
    // =========================================================================

    #[Test]
    public function it_returns_empty_array_for_empty_display_name(): void
    {
        $result = NativeSectionResolver::resolve('');

        self::assertSame([], $result);
    }

    // =========================================================================
    // resolve() — matching case-insensitive
    // =========================================================================

    #[Test]
    #[DataProvider('caseInsensitiveProvider')]
    public function it_matches_case_insensitively(string $displayName, string $expectedKey): void
    {
        $result = NativeSectionResolver::resolve($displayName);

        self::assertArrayHasKey($expectedKey, $result);
    }

    public static function caseInsensitiveProvider(): array
    {
        return [
            'UPPERCASE Firefox' => ['FIREFOX - CONF', 'app-customizations'],
            'Mixed Wallpaper'   => ['Wallpaper - default', 'wallpapers'],
            'Mixed Redirections' => ['Redirections - users', 'profils-itinerants'],
            'Mixed Shortcuts'   => ['Shortcuts - labo', 'shortcuts'],
        ];
    }

    // =========================================================================
    // hasMatch()
    // =========================================================================

    #[Test]
    public function it_returns_true_when_display_name_matches(): void
    {
        self::assertTrue(NativeSectionResolver::hasMatch('wallpaper-salle-b'));
    }

    #[Test]
    public function it_returns_false_when_no_match(): void
    {
        self::assertFalse(NativeSectionResolver::hasMatch('default-domain-policy'));
    }

    #[Test]
    public function it_returns_false_for_empty_display_name(): void
    {
        self::assertFalse(NativeSectionResolver::hasMatch(''));
    }

    // =========================================================================
    // buildUrl() — sans from_gpo
    // =========================================================================

    #[Test]
    public function it_builds_url_without_from_gpo_param(): void
    {
        $url = NativeSectionResolver::buildUrl('wallpapers');

        self::assertSame('/app/parc-settings/wallpapers', $url);
    }

    #[Test]
    public function it_builds_url_without_from_gpo_when_null(): void
    {
        $url = NativeSectionResolver::buildUrl('shortcuts', null);

        self::assertSame('/app/shortcuts', $url);
    }

    // =========================================================================
    // buildUrl() — avec from_gpo (AC1.3 — test dédié buildUrl + piège D8)
    // =========================================================================

    #[Test]
    public function it_appends_from_gpo_with_question_mark_for_url_without_existing_query(): void
    {
        $url = NativeSectionResolver::buildUrl('wallpapers', '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}');

        self::assertStringStartsWith('/app/parc-settings/wallpapers?from_gpo=', $url);
        self::assertStringContainsString('from_gpo=', $url);
    }

    #[Test]
    public function it_appends_from_gpo_with_ampersand_for_profils_itinerants_url(): void
    {
        // L'URL '/admin/settings?tab=profils-itinerants' contient déjà un '?'
        // → le paramètre doit être ajouté avec '&', pas '?' (Piège 8 / AC1.3).
        $url = NativeSectionResolver::buildUrl('profils-itinerants', '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}');

        // Vérifie que le '&' est utilisé et non un second '?'
        self::assertStringStartsWith('/admin/settings?tab=profils-itinerants&from_gpo=', $url);
        self::assertStringNotContainsString('?from_gpo=', $url); // pas de double '?'
    }

    #[Test]
    public function it_url_encodes_the_guid_in_from_gpo_param(): void
    {
        // Les accolades {} doivent être encodées (%7B / %7D)
        $guid = '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}';
        $url = NativeSectionResolver::buildUrl('wallpapers', $guid);

        self::assertStringContainsString('%7B', $url);
        self::assertStringContainsString('%7D', $url);
    }

    // =========================================================================
    // buildUrl() — section inconnue
    // =========================================================================

    #[Test]
    public function it_throws_invalid_argument_for_unknown_section_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Section inconnue/');

        NativeSectionResolver::buildUrl('unknown-section');
    }

    // =========================================================================
    // resolve() — tous les patterns des 4 sections (robustesse)
    // =========================================================================

    #[Test]
    #[DataProvider('allPatternMappingsProvider')]
    public function it_resolves_each_pattern_to_its_section(string $displayName, string $expectedSectionKey): void
    {
        $result = NativeSectionResolver::resolve($displayName);

        self::assertArrayHasKey($expectedSectionKey, $result);
    }

    public static function allPatternMappingsProvider(): array
    {
        return [
            // profils-itinerants
            'roaming pattern'       => ['roaming-users-2024', 'profils-itinerants'],
            'profil pattern'        => ['profil-eleve', 'profils-itinerants'],
            'no_roam pattern'       => ['no_roam-policy', 'profils-itinerants'],
            // wallpapers
            'fond-ecran pattern'    => ['fond-ecran-default', 'wallpapers'],
            'fond_ecran pattern'    => ['fond_ecran_salle_a', 'wallpapers'],
            'lockscreen pattern'    => ['lockscreen-cfg', 'wallpapers'],
            // app-customizations
            'thunderbird pattern'   => ['thunderbird-conf', 'app-customizations'],
            'app-custom pattern'    => ['app-custom-local', 'app-customizations'],
            'applications pattern'  => ['applications-policy', 'app-customizations'],
            // shortcuts
            'raccourci pattern'     => ['raccourci-bureau', 'shortcuts'],
            // wine (Story 16.3c — T6.1 / D10)
            'wine pattern simple'   => ['wine', 'wine'],
            'wine pattern se4_'     => ['se4_wine', 'wine'],
            'wine pattern complex'  => ['se4-wine-image', 'wine'],
        ];
    }

    /**
     * Story 16.3c — T6.1 / D10 : enrichissement `NativeSectionResolver::MAPPING`
     * pour Wine. Le GPO `se4_wine` (et toute GPO contenant `wine`) doit
     * pointer vers `/admin/settings/gpo/wine` (renommé par Story 16.9 D8).
     */
    #[Test]
    public function it_matches_wine_gpo_to_native_admin_settings_gpo_wine(): void
    {
        $result = NativeSectionResolver::resolve('se4_wine');
        self::assertArrayHasKey('wine', $result);
        self::assertSame('/admin/settings/gpo/wine', $result['wine']['url']);
        self::assertSame('fa-wine-glass', $result['wine']['icon']);
    }

    #[Test]
    public function it_builds_native_url_with_from_gpo_param_for_wine(): void
    {
        $url = NativeSectionResolver::buildUrl('wine', '{ABCD1234-5678-9012-3456-789012345678}');
        self::assertStringContainsString('/admin/settings/gpo/wine?from_gpo=', $url);
    }
}
