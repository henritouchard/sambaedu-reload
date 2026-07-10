<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Garde-fou architectural Epic 38 (Story 38.4 / AC2).
 *
 * Interdit toute réintroduction d'un chemin FS legacy `/var/www/sambaedu` OU
 * d'une consultation de `config('sambaedu.legacy_path')` dans le code serveur
 * (`app/`), le bootstrap legacy (`legacy/bootstrap.php`) et les stubs in-repo
 * (`legacy/stubs/`) — hors liste blanche explicite.
 *
 * Les commentaires/docblocks sont ignorés (on ne teste que le CODE effectif).
 *
 * Liste blanche (frontière refermée d'un cran par 38.5) :
 *  - `LegacyCatchallController` : sert les modules in-repo + dégrade en 404 ;
 *  - `legacy/stubs/config.inc.php` : chaîne `include_path` FPM générée (inerte,
 *    référence documentée « à ne pas toucher »).
 *
 * Story 38.5 : `LegacyEmbedService` (dernière route legacy embarquée) a été
 * SUPPRIMÉ — retiré de la liste blanche (la frontière architecturale s'est
 * refermée : plus aucun couple contrôleur/service embed dédié).
 */
class GpoLegacyIsolationTest extends TestCase
{
    /**
     * Basenames autorisés à contenir un littéral `/var/www/sambaedu` ou une
     * consultation `sambaedu.legacy_path` (frontière catchall/embed/38.5).
     *
     * @var list<string>
     */
    private const WHITELIST = [
        'LegacyCatchallController.php',
        'config.inc.php', // stub : include_path FPM inerte
    ];

    #[Test]
    public function no_var_www_sambaedu_literal_outside_whitelist(): void
    {
        $violations = [];

        foreach ($this->scannedFiles() as $file) {
            $basename = basename($file->getRelativePathname());
            if (in_array($basename, self::WHITELIST, true)) {
                continue;
            }

            $code = $this->stripComments($file->getContents());
            if (str_contains($code, '/var/www/sambaedu')) {
                $violations[] = $file->getRelativePathname();
            }
        }

        self::assertSame(
            [],
            $violations,
            "Chemin FS legacy `/var/www/sambaedu` réintroduit dans du code effectif :\n  - "
                . implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function no_legacy_path_config_consultation_outside_whitelist(): void
    {
        $violations = [];

        foreach ($this->scannedFiles() as $file) {
            $basename = basename($file->getRelativePathname());
            if (in_array($basename, self::WHITELIST, true)) {
                continue;
            }

            $code = $this->stripComments($file->getContents());
            // Cible STRICTEMENT `sambaedu.legacy_path` (pas `shortcut_icons.legacy_path`).
            if (preg_match('/[\'"]sambaedu\.legacy_path[\'"]/', $code) === 1) {
                $violations[] = $file->getRelativePathname();
            }
        }

        self::assertSame(
            [],
            $violations,
            "Consultation runtime de `sambaedu.legacy_path` hors liste blanche :\n  - "
                . implode("\n  - ", $violations),
        );
    }

    /**
     * Fichiers scannés : `app/**`, `legacy/bootstrap.php`, `legacy/stubs/**`.
     *
     * @return iterable<\Symfony\Component\Finder\SplFileInfo>
     */
    private function scannedFiles(): iterable
    {
        $root = realpath(__DIR__ . '/../..');

        $finder = (new Finder())
            ->files()
            ->name('*.php')
            ->in($root . '/app')
            ->in($root . '/legacy/stubs');

        $bootstrap = (new Finder())
            ->files()
            ->depth(0)
            ->name('bootstrap.php')
            ->in($root . '/legacy');

        yield from $finder;
        yield from $bootstrap;
    }

    /** Retire commentaires bloc et ligne pour ne tester que le code effectif. */
    private function stripComments(string $code): string
    {
        $code = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
        $code = preg_replace('/^\s*\/\/.*$/m', '', $code) ?? $code;

        return preg_replace('/^\s*\*.*$/m', '', $code) ?? $code;
    }
}
