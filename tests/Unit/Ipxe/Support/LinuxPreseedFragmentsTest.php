<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Support;

use App\Ipxe\Support\PreseedPlaceholders;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.4 — T1.5.
 *
 * Tests d'intégrité des fragments preseed copiés depuis
 * `sambaedu/ipxe/linux/*.cfg` vers `resources/ipxe/linux/*.cfg`.
 *
 *  1. Tous les fragments listés sont présents et lisibles.
 *  2. Chaque fragment est non vide.
 *  3. Chaque placeholder `###_<KEY>_###` trouvé dans les fragments est
 *     soit dans le catalogue `PreseedPlaceholders::catalog()`, soit dans
 *     la liste blanche des placeholders par-poste (HOSTNAME, UUID,
 *     ETAB_OU, etc.) injectés par `LinuxPreseedService::generate()`.
 */
class LinuxPreseedFragmentsTest extends TestCase
{
    private const REQUIRED_FRAGMENTS = [
        'debian.cfg',
        'debian_base.cfg',
        'debian_gnome.cfg',
        'debian_lxde.cfg',
        'debian_kde.cfg',
        'debian_mate.cfg',
        'debian_xfce.cfg',
        'debian_cinnamon.cfg',
        'debian_perso.cfg',
        'sambaedu.cfg',
        'simple_boot.cfg',
        'aptcache.cfg',
        'nocache.cfg',
        'proxy.cfg',
        'commande_fin.cfg',
        'ubuntu.cfg',
    ];

    /**
     * Placeholders par-poste injectés par
     * {@see \App\Ipxe\Services\LinuxPreseedService::generate()} — donc PAS
     * dans `PreseedPlaceholders::catalog()`. Ces clés doivent rester
     * autorisées dans les fragments.
     *
     * Per-poste placeholders injectés dynamiquement par
     * {@see \App\Ipxe\Services\LinuxPreseedService::generate()} (cf. tableau
     * $perPostParams).
     */
    private const PER_POSTE_PLACEHOLDERS = ['HOSTNAME', 'UUID'];

    private function fragmentPath(string $name): string
    {
        return resource_path('ipxe/linux/' . $name);
    }

    #[Test]
    public function it_lists_all_required_fragments(): void
    {
        foreach (self::REQUIRED_FRAGMENTS as $name) {
            self::assertFileExists(
                $this->fragmentPath($name),
                "Fragment manquant : resources/ipxe/linux/{$name}",
            );
        }
    }

    #[Test]
    public function it_each_fragment_is_readable_and_non_empty(): void
    {
        foreach (self::REQUIRED_FRAGMENTS as $name) {
            $path = $this->fragmentPath($name);
            self::assertTrue(is_readable($path), "{$name} not readable");
            $content = (string) file_get_contents($path);
            // commande_fin.cfg est documentaire (4 lignes commentaires) — accept
            // non-vide y compris si juste un commentaire.
            self::assertGreaterThan(0, strlen($content), "{$name} is empty");
        }
    }

    #[Test]
    public function it_each_placeholder_in_fragments_is_known_in_catalog(): void
    {
        $catalogKeys = array_keys(PreseedPlaceholders::catalog());
        $allowedKeys = array_merge($catalogKeys, self::PER_POSTE_PLACEHOLDERS);

        $orphans = [];
        foreach (self::REQUIRED_FRAGMENTS as $name) {
            $content = (string) file_get_contents($this->fragmentPath($name));
            foreach (PreseedPlaceholders::extractKeys($content) as $key) {
                if (! in_array($key, $allowedKeys, true)) {
                    $orphans[] = "{$name}: ###_{$key}_###";
                }
            }
        }

        self::assertSame(
            [],
            $orphans,
            "Placeholders orphelins (non listés dans PreseedPlaceholders::catalog() "
            . "ni dans PER_POSTE_PLACEHOLDERS):\n  - "
            . implode("\n  - ", $orphans),
        );
    }
}
