<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Story 3.1 — AC1.1 / AC6.1 / AC8.3 / T1.8 / T6.2.
 *
 * Garde-fou architectural du namespace `App\Ipxe\*` :
 *
 *  1. Tous les fichiers du namespace existent dans `app/Ipxe/`.
 *  2. **Pas d'import de `LdapRecord\*`** — la résolution se fait
 *     EXCLUSIVEMENT via PostgreSQL (architecture.md §"Modèle de Données —
 *     Source de Vérité").
 *  3. **Pas d'inclusion `legacy/*`** depuis `app/Ipxe/*` — frontière legacy
 *     stricte (parité 16.10/16.11/16.12).
 *  4. **Pas d'appel `search_machine()` / `get_action()` legacy** — la
 *     résolution machine est portée par {@see \App\Ipxe\Services\WorkstationLocator}.
 *  5. **Pas d'appel `exec()` / `shell_exec()`** — pas d'exécution de
 *     binaire externe nécessaire en 3.1 (la résolution UEFI vs legacy se
 *     fait côté iPXE via `iseq ${platform} efi`).
 *  6. **La route `/ipxe/boot` est déclarée AVANT le catchall legacy**
 *     dans `routes/web.php`. Test critique pour la cohabitation 3.1 /
 *     legacy proxy.
 */
class IpxeNamespaceTest extends TestCase
{
    /**
     * @var list<array{pattern: string, label: string}>
     */
    private const FORBIDDEN_SHELL_PATTERNS = [
        ['pattern' => '/\bexec\s*\(/i', 'label' => 'exec()'],
        ['pattern' => '/\bshell_exec\s*\(/i', 'label' => 'shell_exec()'],
        ['pattern' => '/\bpassthru\s*\(/i', 'label' => 'passthru()'],
        ['pattern' => '/\bproc_open\s*\(/i', 'label' => 'proc_open()'],
        ['pattern' => '/\bsystem\s*\(/i', 'label' => 'system()'],
    ];

    #[Test]
    public function it_lists_all_ipxe_services_under_correct_namespace(): void
    {
        $root = realpath(__DIR__ . '/../../app/Ipxe');
        self::assertNotFalse($root, 'app/Ipxe introuvable — namespace doit exister.');

        // Vérifie la présence des 3 services principaux + 2 normalizers
        // + controller + form request + provider (logé dans App\Providers).
        $required = [
            $root . '/Services/IpxeService.php',
            $root . '/Services/WorkstationLocator.php',
            $root . '/Services/IpxeMenuRenderer.php',
            $root . '/Http/Controllers/IpxeBootController.php',
            $root . '/Http/Requests/IpxeBootRequest.php',
            $root . '/Support/MacAddressNormalizer.php',
            $root . '/Support/UuidNormalizer.php',
            $root . '/Enums/IpxeMenuKind.php',
            $root . '/Enums/IpxePlatform.php',
        ];

        $missing = [];
        foreach ($required as $file) {
            if (! is_file($file)) {
                $missing[] = $file;
            }
        }

        self::assertSame(
            [],
            $missing,
            "Fichiers obligatoires du namespace App\\Ipxe manquants :\n  - "
            . implode("\n  - ", $missing),
        );

        // Le provider est dans App\Providers (DO-1).
        self::assertFileExists(
            realpath(__DIR__ . '/../../app/Providers') . '/IpxeServiceProvider.php',
            'IpxeServiceProvider doit être dans App\\Providers (DO-1).',
        );
    }

    #[Test]
    public function it_does_not_import_ldap_record_in_ipxe_namespace(): void
    {
        $root = realpath(__DIR__ . '/../../app/Ipxe');
        self::assertNotFalse($root);

        $finder = (new Finder())->files()->in($root)->name('*.php');
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $violations = [];

        foreach ($finder as $file) {
            try {
                $ast = $parser->parse($file->getContents());
            } catch (\Throwable $e) {
                self::fail(sprintf(
                    'Parse error %s : %s',
                    $file->getRelativePathname(),
                    $e->getMessage(),
                ));
            }
            if ($ast === null) {
                continue;
            }

            $collector = new class extends NodeVisitorAbstract
            {
                /** @var list<string> */
                public array $uses = [];

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Use_) {
                        foreach ($node->uses as $use) {
                            $this->uses[] = $use->name->toString();
                        }
                    }
                    if ($node instanceof GroupUse) {
                        $prefix = $node->prefix->toString();
                        foreach ($node->uses as $use) {
                            $this->uses[] = $prefix . '\\' . $use->name->toString();
                        }
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($collector);
            $traverser->traverse($ast);

            foreach ($collector->uses as $imported) {
                if (str_starts_with($imported, 'LdapRecord\\')) {
                    $violations[] = sprintf(
                        '%s importe %s — interdit (PostgreSQL seule source D4)',
                        $file->getRelativePathname(),
                        $imported,
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Story 3.1 (import LdapRecord interdit) :\n  - "
            . implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function it_does_not_include_legacy_files_in_ipxe_namespace(): void
    {
        $root = realpath(__DIR__ . '/../../app/Ipxe');
        self::assertNotFalse($root);

        $finder = (new Finder())->files()->in($root)->name('*.php');
        $violations = [];

        foreach ($finder as $file) {
            $stripped = $this->stripComments($file->getContents());

            // require/include de legacy/*
            if (preg_match('/(?:require|include)(?:_once)?\s*\(?[\'"][^\'"]*legacy\//i', $stripped) === 1) {
                $violations[] = sprintf('%s include legacy/*', $file->getRelativePathname());
            }
            // Appels app('legacy.*')
            if (preg_match('/app\([\'"]legacy\./', $stripped) === 1) {
                $violations[] = sprintf('%s utilise app("legacy.*")', $file->getRelativePathname());
            }
            // Appels search_machine() / get_action() (helpers legacy)
            if (preg_match('/\bsearch_machine\s*\(/', $stripped) === 1) {
                $violations[] = sprintf(
                    '%s appelle search_machine() — réécriture native via WorkstationLocator obligatoire',
                    $file->getRelativePathname(),
                );
            }
            if (preg_match('/\bget_action\s*\(/', $stripped) === 1) {
                $violations[] = sprintf(
                    '%s appelle get_action() legacy — réécriture native obligatoire',
                    $file->getRelativePathname(),
                );
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Story 3.1 (inclusion legacy depuis App\\Ipxe) :\n  - "
            . implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function it_does_not_use_shell_execution_in_ipxe_namespace(): void
    {
        $root = realpath(__DIR__ . '/../../app/Ipxe');
        self::assertNotFalse($root);

        $finder = (new Finder())->files()->in($root)->name('*.php');
        $violations = [];

        foreach ($finder as $file) {
            $stripped = $this->stripComments($file->getContents());

            foreach (self::FORBIDDEN_SHELL_PATTERNS as $rule) {
                if (preg_match($rule['pattern'], $stripped) === 1) {
                    $violations[] = sprintf(
                        '%s utilise %s — interdit en App\\Ipxe (pas d\'exécution de binaire en 3.1)',
                        $file->getRelativePathname(),
                        $rule['label'],
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Story 3.1 (shell exec interdit) :\n  - "
            . implode("\n  - ", $violations),
        );
    }

    /**
     * Story 3.1 — AC6.1 / T6.2.
     *
     * Vérifie que la route native `/ipxe/boot` est déclarée AVANT la route
     * catchall `{path}` dans `routes/web.php`. Sinon le catchall capture
     * tout et la route native est inaccessible (D2 critique).
     *
     * Pattern lecture textuelle iso 16.11 DO-5 (parsing AST trop complexe
     * pour les routes — heuristique simple sur la position des
     * occurrences).
     */
    #[Test]
    public function ipxe_boot_route_is_declared_before_catchall(): void
    {
        $routesFile = realpath(__DIR__ . '/../../routes/web.php');
        self::assertNotFalse($routesFile, 'routes/web.php introuvable');

        $content = (string) file_get_contents($routesFile);

        // Position de la déclaration de /ipxe/boot
        $ipxeBootPattern = "/Route::(?:match|get|post)\s*\([^;]*?['\"]\\/ipxe\\/boot['\"]/";
        self::assertSame(
            1,
            preg_match($ipxeBootPattern, $content, $matches, PREG_OFFSET_CAPTURE),
            'La route /ipxe/boot n\'est pas déclarée dans routes/web.php',
        );
        $ipxeBootOffset = $matches[0][1];

        // Position du catchall
        $catchallPattern = "/Route::match\s*\([^)]*['\"]\\{path\\}['\"]/";
        self::assertSame(
            1,
            preg_match($catchallPattern, $content, $catchallMatches, PREG_OFFSET_CAPTURE),
            'Le catchall legacy {path} n\'est pas déclaré dans routes/web.php',
        );
        $catchallOffset = $catchallMatches[0][1];

        self::assertLessThan(
            $catchallOffset,
            $ipxeBootOffset,
            "ORDRE INVALIDE : la route native /ipxe/boot doit être déclarée AVANT "
            . "le catchall legacy {path}. Actuellement /ipxe/boot est à l'offset "
            . "{$ipxeBootOffset} et le catchall à {$catchallOffset}. Si la route "
            . "native est après le catchall, le catchall capture toutes les "
            . "requêtes /ipxe/* avant qu'elles n'atteignent la route native, "
            . "rendant /ipxe/boot inaccessible.",
        );

        // Sanity check : le controller est référencé.
        self::assertStringContainsString(
            'App\\Ipxe\\Http\\Controllers\\IpxeBootController',
            $content,
            'La route /ipxe/boot doit référencer App\\Ipxe\\Http\\Controllers\\IpxeBootController',
        );

        // Fix review #6 — vérification individuelle middleware par route.
        //
        // L'ancienne assertion `assertMatchesRegularExpression('/auth\.v1\.lan-only/')`
        // se contentait d'une occurrence anywhere : un commit futur retirant
        // le middleware de l'alias `/ipxe/boot.ipxe` aurait laissé le test
        // vert tant que l'autre route le portait. On vérifie maintenant la
        // présence dans le BLOC déclaratif de chaque route (split par `;`).
        $statements = explode(';', $content);
        $ipxeStatements = array_values(array_filter(
            $statements,
            static fn (string $stmt): bool => preg_match("@['\"]/ipxe/boot(?:\.ipxe)?['\"]@", $stmt) === 1,
        ));

        self::assertGreaterThanOrEqual(
            2,
            count($ipxeStatements),
            'routes/web.php doit déclarer au moins 2 routes iPXE (/ipxe/boot + /ipxe/boot.ipxe alias).',
        );

        foreach ($ipxeStatements as $stmt) {
            self::assertMatchesRegularExpression(
                '/auth\.v1\.lan-only/',
                $stmt,
                "Chaque route iPXE doit avoir le middleware auth.v1.lan-only attaché (D3). "
                . "Bloc fautif : " . substr(trim($stmt), 0, 200) . '...',
            );
        }

        // Sanity check : le commentaire ⚠⚠⚠ catchall est préservé.
        self::assertStringContainsString(
            '⚠⚠⚠',
            $content,
            'Le commentaire de garde-fou ⚠⚠⚠ autour du catchall doit rester intact.',
        );
    }

    private function stripComments(string $code): string
    {
        $code = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
        $code = preg_replace('/^\s*\/\/.*$/m', '', $code) ?? $code;

        return $code;
    }
}
