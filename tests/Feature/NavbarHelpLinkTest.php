<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature — Bouton d'aide dans la navbar (Story 52.8, AC1/AC2/AC3).
 *
 * Stratégie (calquée sur tests/Feature/Gpo/GpoBackLinkComponentTest.php) : rendu
 * du composant `<x-organisms.navbar />` en isolation via Blade::render(), sans
 * page hôte ni chaîne de permissions — le composant ne dépend que de la clé de
 * config `sambaedu.doc.index_file`, surchargée ici pour simuler les deux états
 * (doc publiée / doc absente) sans toucher au filesystem réel de userDoc/dist.
 */
class NavbarHelpLinkTest extends TestCase
{
    private ?string $tempIndexFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->tempIndexFile !== null && file_exists($this->tempIndexFile)) {
            @unlink($this->tempIndexFile);
        }
        $this->tempIndexFile = null;

        parent::tearDown();
    }

    private function renderNavbar(): string
    {
        return Blade::render('<x-organisms.navbar />');
    }

    // =========================================================================
    // AC1/AC2 — Scénario 1 : l'index publié existe → le bouton d'aide est rendu
    // =========================================================================

    #[Test]
    public function it_renders_help_link_when_doc_index_file_exists(): void
    {
        $this->tempIndexFile = tempnam(sys_get_temp_dir(), 'navbar_help_link_test_');
        file_put_contents($this->tempIndexFile, '<html></html>');

        config(['sambaedu.doc.index_file' => $this->tempIndexFile]);

        $html = $this->renderNavbar();

        $this->assertStringContainsString('href="/doc/"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringContainsString('fa-circle-question', $html);
    }

    // =========================================================================
    // AC2/AC3 — Scénario 2 : l'index publié est absent → pas de lien mort,
    // le reste de la navbar (ex. Notifications) reste intact
    // =========================================================================

    #[Test]
    public function it_does_not_render_help_link_when_doc_index_file_is_absent(): void
    {
        config(['sambaedu.doc.index_file' => sys_get_temp_dir() . '/navbar_help_link_test_absent_' . uniqid()]);

        $html = $this->renderNavbar();

        $this->assertStringNotContainsString('href="/doc/"', $html);
        $this->assertStringContainsString('Notifications', $html);
    }
}
