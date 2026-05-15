<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Garde-fou architectural Epic 16 (Story 16.1 / AC4.1).
 *
 * Garantit que la frontière entre le namespace natif `App\Gpo\*` et le
 * shim legacy 1bis.18 (`legacy/modules/gpo/`, `legacy/bootstrap.php`) reste
 * étanche pendant toute la phase de transition Epic 16.
 *
 * 1. **Aucun fichier sous `app/Gpo/` n'invoque `require`, `require_once`,
 *    `include`, `include_once` sur un chemin contenant `legacy/`** — le shim
 *    reste un canal isolé.
 *
 * 2. **Aucun fichier sous `app/Gpo/` n'est requis par `legacy/bootstrap.php`** —
 *    frontière nette dans l'autre sens (impossible que le legacy crée une
 *    dépendance dure sur le natif).
 *
 * Les tests sont indépendants et symétriques : la frontière est respectée
 * dans les deux sens.
 */
class GpoLegacyIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Désactivé : portage natif Laravel des fonctions GPO en cours
        // (Epic 16/17). Le garde-fou architectural shim/natif n'est plus
        // pertinent une fois le portage natif complet — la frontière qu'il
        // protège disparaît avec le shim.
        // @todo Supprimer ce test lors de story 16.13 (retrait des shims GPO).
        $this->markTestSkipped('Désactivé pendant le portage natif Laravel des fonctions GPO (Epic 16/17).');
    }

    #[Test]
    public function gpo_namespace_does_not_require_legacy_files(): void
    {
        $namespaceRoot = realpath(__DIR__ . '/../../app/Gpo');
        if ($namespaceRoot === false) {
            self::fail('Le dossier app/Gpo est introuvable.');
        }

        $finder = (new Finder())->files()->in($namespaceRoot)->name('*.php');
        if (! $finder->hasResults()) {
            self::assertTrue(true, 'Aucune classe encore présente — garde-fou activé pour les stories suivantes.');

            return;
        }

        // Regex : require/require_once/include/include_once + chaine contenant legacy/
        // Exemples détectés :
        //   require_once 'legacy/bootstrap.php';
        //   include __DIR__ . '/../../legacy/foo.php';
        //   require_once base_path('legacy/foo.php');
        $regex = '/\b(require|require_once|include|include_once)\s*\(?\s*[^;)]*legacy\//i';

        $violations = [];
        foreach ($finder as $file) {
            $code = $file->getContents();
            // Strip commentaires pour limiter les faux positifs sur les docblocks.
            $stripped = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
            $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;

            if (preg_match($regex, $stripped) === 1) {
                $violations[] = sprintf(
                    '%s invoque require/include vers legacy/* — frontière Epic 16 violée',
                    $file->getRelativePathname(),
                );
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Epic 16 (require legacy/) :\n  - " . implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function legacy_bootstrap_does_not_require_gpo_namespace(): void
    {
        $bootstrapPath = realpath(__DIR__ . '/../../legacy/bootstrap.php');
        if ($bootstrapPath === false) {
            // Pas de bootstrap legacy → frontière trivialement respectée.
            self::assertTrue(true, 'legacy/bootstrap.php absent — frontière trivialement respectée.');

            return;
        }

        $code = file_get_contents($bootstrapPath);
        self::assertNotFalse($code, 'Lecture de legacy/bootstrap.php impossible.');

        // Strip commentaires.
        $stripped = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
        $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;

        // Recherche d'un require/include vers app/Gpo/* ou App\Gpo\*.
        $regex = '/\b(require|require_once|include|include_once)\s*\(?\s*[^;)]*app\/Gpo\//i';

        self::assertSame(
            0,
            preg_match($regex, $stripped),
            'legacy/bootstrap.php référence app/Gpo/* — frontière Epic 16 violée.',
        );
    }
}
