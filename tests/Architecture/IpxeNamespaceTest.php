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

    /* ------------------------------------------------------------------
     * Story 3.2 — AC6.1 / AC8.3 — extension du garde-fou architectural
     * ------------------------------------------------------------------ */

    /**
     * Vérifie que les 3 routes natives 3.2 (`/ipxe/admin`,
     * `/ipxe/maintenance`, `/ipxe/action/{action}`) sont déclarées AVANT le
     * catchall legacy `{path}` dans `routes/web.php`. Si elles sont après,
     * le catchall capture les requêtes et les routes natives deviennent
     * inaccessibles.
     */
    #[Test]
    public function ipxe_3_2_routes_are_declared_before_catchall(): void
    {
        $routesFile = realpath(__DIR__ . '/../../routes/web.php');
        self::assertNotFalse($routesFile);
        $content = (string) file_get_contents($routesFile);

        $catchallPattern = "/Route::match\s*\([^)]*['\"]\\{path\\}['\"]/";
        self::assertSame(
            1,
            preg_match($catchallPattern, $content, $catchallMatches, PREG_OFFSET_CAPTURE),
            'Catchall legacy {path} introuvable',
        );
        $catchallOffset = $catchallMatches[0][1];

        $routes = [
            ['needle' => "['\"]/ipxe/admin['\"]", 'name' => '/ipxe/admin'],
            ['needle' => "['\"]/ipxe/maintenance['\"]", 'name' => '/ipxe/maintenance'],
            ['needle' => "['\"]/ipxe/action/\\{action\\}['\"]", 'name' => '/ipxe/action/{action}'],
        ];

        foreach ($routes as $route) {
            // Délimiteur `@` (pas `/`) — les `needle` contiennent `/ipxe/...`
            // qui sinon fermerait prématurément la regex et laisserait
            // `ipxe/admin['"]` comme modifiers invalides (preg_match → false).
            $pattern = '@Route::(?:match|get|post)\s*\([^;]*?' . $route['needle'] . '@';
            self::assertSame(
                1,
                preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE),
                'Route 3.2 ' . $route['name'] . ' non déclarée',
            );
            self::assertLessThan(
                $catchallOffset,
                $matches[0][1],
                'ORDRE INVALIDE : la route 3.2 ' . $route['name'] . ' doit être AVANT le catchall',
            );
        }

        // Vérification middleware par route : chaque déclaration 3.2 doit
        // porter `auth.v1.lan-only`.
        $statements = explode(';', $content);
        $ipxeStatements = array_values(array_filter(
            $statements,
            static fn (string $stmt): bool => preg_match(
                "@['\"]/ipxe/(admin|maintenance|action/\\{action\\})['\"]@",
                $stmt,
            ) === 1,
        ));

        self::assertGreaterThanOrEqual(
            3,
            count($ipxeStatements),
            'Les 3 routes 3.2 doivent être déclarées',
        );

        foreach ($ipxeStatements as $stmt) {
            self::assertMatchesRegularExpression(
                '/auth\.v1\.lan-only/',
                $stmt,
                'Chaque route 3.2 doit attacher auth.v1.lan-only — bloc fautif : '
                . substr(trim($stmt), 0, 200),
            );
        }

        // Filtre regex sur `{action}` (rejette caractères dangereux).
        self::assertMatchesRegularExpression(
            "/->where\(\s*['\"]action['\"]\s*,\s*['\"]\[a-z_\]\+['\"]/",
            $content,
            'La route /ipxe/action/{action} doit avoir le filtre regex `[a-z_]+` '
            . 'pour bloquer les caractères dangereux (`/`, `..`, `;`, `&`, etc.).',
        );
    }

    #[Test]
    public function it_lists_all_ipxe_3_2_controllers_under_correct_namespace(): void
    {
        $root = realpath(__DIR__ . '/../../app/Ipxe');
        self::assertNotFalse($root);

        $required = [
            $root . '/Http/Controllers/IpxeAdminController.php',
            $root . '/Http/Controllers/IpxeMaintenanceController.php',
            $root . '/Http/Controllers/IpxeActionController.php',
            $root . '/Http/Requests/IpxeAdminRequest.php',
            $root . '/Http/Requests/IpxeMaintenanceRequest.php',
            $root . '/Http/Requests/IpxeActionRequest.php',
            $root . '/Services/IpxeActionResolver.php',
            $root . '/Enums/IpxeAdminAction.php',
        ];

        foreach ($required as $file) {
            self::assertFileExists($file, "Fichier 3.2 manquant : {$file}");
        }

        $routesContent = (string) file_get_contents(__DIR__ . '/../../routes/web.php');
        self::assertStringContainsString('App\\Ipxe\\Http\\Controllers\\IpxeAdminController', $routesContent);
        self::assertStringContainsString('App\\Ipxe\\Http\\Controllers\\IpxeMaintenanceController', $routesContent);
        self::assertStringContainsString('App\\Ipxe\\Http\\Controllers\\IpxeActionController', $routesContent);
    }

    #[Test]
    public function ipxe_admin_action_enum_has_exactly_nineteen_cases_in_story_3_5(): void
    {
        // D9 — Story 3.2 — élargir si nouvelle action (3.4 / 3.5 / 3.7).
        // Story 3.5 : whitelist élargie à 19 cases (3 historiques + 9
        // install_* Linux + 7 install_win* Windows). Tout élargissement
        // futur doit être documenté.
        $cases = \App\Ipxe\Enums\IpxeAdminAction::cases();
        self::assertCount(
            19,
            $cases,
            'Story 3.5 — la whitelist IpxeAdminAction doit contenir exactement 19 cases '
            . '(rescuecd, winpe, factory_reset + 9 install_* + 7 install_win*). '
            . 'Tout élargissement doit être documenté.',
        );

        $values = array_map(static fn ($c) => $c->value, $cases);
        sort($values);
        self::assertSame([
            'factory_reset',
            'install_deb_base',
            'install_deb_cinnamon',
            'install_deb_gnome',
            'install_deb_kde',
            'install_deb_lxde',
            'install_deb_mate',
            'install_deb_xfce',
            'install_nird',
            'install_ubuntu64',
            'install_win10',
            'install_win10_debug',
            'install_win10_disk',
            'install_win10_perso',
            'install_win11',
            'install_win11_disk',
            'install_win11_perso',
            'rescuecd',
            'winpe',
        ], $values);
    }

    /* ------------------------------------------------------------------
     * Story 3.3 — AC9.3 / AC8.1 — extension du garde-fou architectural
     * ------------------------------------------------------------------ */

    /**
     * Vérifie que les 5 routes natives 3.3 (`/ipxe/enrollment/{name,byod,room,
     * parc-add,parc-remove}`) sont déclarées AVANT le catchall legacy.
     */
    #[Test]
    public function ipxe_3_3_enrollment_routes_are_declared_before_catchall(): void
    {
        $routesFile = realpath(__DIR__ . '/../../routes/web.php');
        self::assertNotFalse($routesFile);
        $content = (string) file_get_contents($routesFile);

        $catchallPattern = "/Route::match\s*\([^)]*['\"]\\{path\\}['\"]/";
        self::assertSame(
            1,
            preg_match($catchallPattern, $content, $catchallMatches, PREG_OFFSET_CAPTURE),
            'Catchall legacy {path} introuvable',
        );
        $catchallOffset = $catchallMatches[0][1];

        $routes = [
            ['needle' => "['\"]/ipxe/enrollment/name['\"]", 'name' => '/ipxe/enrollment/name'],
            ['needle' => "['\"]/ipxe/enrollment/byod['\"]", 'name' => '/ipxe/enrollment/byod'],
            ['needle' => "['\"]/ipxe/enrollment/room['\"]", 'name' => '/ipxe/enrollment/room'],
            ['needle' => "['\"]/ipxe/enrollment/parc-add['\"]", 'name' => '/ipxe/enrollment/parc-add'],
            ['needle' => "['\"]/ipxe/enrollment/parc-remove['\"]", 'name' => '/ipxe/enrollment/parc-remove'],
        ];

        foreach ($routes as $route) {
            $pattern = '@Route::(?:match|get|post)\s*\([^;]*?' . $route['needle'] . '@';
            self::assertSame(
                1,
                preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE),
                'Route 3.3 ' . $route['name'] . ' non déclarée',
            );
            self::assertLessThan(
                $catchallOffset,
                $matches[0][1],
                'ORDRE INVALIDE : la route 3.3 ' . $route['name'] . ' doit être AVANT le catchall',
            );
        }

        // Vérification middleware : chaque route 3.3 doit porter
        // `auth.v1.lan-only` (D3).
        $statements = explode(';', $content);
        $ipxeStatements = array_values(array_filter(
            $statements,
            static fn (string $stmt): bool => preg_match(
                "@['\"]/ipxe/enrollment/(name|byod|room|parc-add|parc-remove)['\"]@",
                $stmt,
            ) === 1,
        ));

        self::assertGreaterThanOrEqual(
            5,
            count($ipxeStatements),
            'Les 5 routes 3.3 doivent être déclarées',
        );

        foreach ($ipxeStatements as $stmt) {
            self::assertMatchesRegularExpression(
                '/auth\.v1\.lan-only/',
                $stmt,
                'Chaque route 3.3 doit attacher auth.v1.lan-only — bloc fautif : '
                . substr(trim($stmt), 0, 200),
            );
        }
    }

    #[Test]
    public function it_lists_all_ipxe_3_3_controllers_and_services_under_correct_namespace(): void
    {
        $root = realpath(__DIR__ . '/../../app/Ipxe');
        self::assertNotFalse($root);

        $required = [
            $root . '/Http/Controllers/IpxeEnrollmentNameController.php',
            $root . '/Http/Controllers/IpxeEnrollmentByodController.php',
            $root . '/Http/Controllers/IpxeEnrollmentRoomController.php',
            $root . '/Http/Controllers/IpxeEnrollmentParcAddController.php',
            $root . '/Http/Controllers/IpxeEnrollmentParcRemoveController.php',
            $root . '/Http/Requests/IpxeEnrollmentNameRequest.php',
            $root . '/Http/Requests/IpxeEnrollmentByodRequest.php',
            $root . '/Http/Requests/IpxeEnrollmentRoomRequest.php',
            $root . '/Http/Requests/IpxeEnrollmentParcRequest.php',
            $root . '/Services/IpxeHostnameSanitizer.php',
            $root . '/Services/IpxeEnrollmentMenuBuilder.php',
            $root . '/Services/IpxeEnrollmentOrchestrator.php',
            $root . '/Services/WorkstationEnrollmentService.php',
            $root . '/Enums/EnrollNameStatus.php',
            $root . '/Enums/IpxeEnrollmentFlow.php',
            $root . '/Support/EnrollNameResult.php',
        ];

        foreach ($required as $file) {
            self::assertFileExists($file, "Fichier 3.3 manquant : {$file}");
        }

        $routesContent = (string) file_get_contents(__DIR__ . '/../../routes/web.php');
        self::assertStringContainsString('App\\Ipxe\\Http\\Controllers\\IpxeEnrollmentNameController', $routesContent);
        self::assertStringContainsString('App\\Ipxe\\Http\\Controllers\\IpxeEnrollmentByodController', $routesContent);
        self::assertStringContainsString('App\\Ipxe\\Http\\Controllers\\IpxeEnrollmentRoomController', $routesContent);
        self::assertStringContainsString('App\\Ipxe\\Http\\Controllers\\IpxeEnrollmentParcAddController', $routesContent);
        self::assertStringContainsString('App\\Ipxe\\Http\\Controllers\\IpxeEnrollmentParcRemoveController', $routesContent);
    }

    /**
     * Story 3.3 — Re-validation : aucun fichier du namespace App\Ipxe 3.3
     * n'importe `LdapRecord\*`. L'accès AD doit passer exclusivement par
     * `App\Ldap\AdMachineManager` (D5 / D14).
     */
    #[Test]
    public function ipxe_3_3_files_do_not_import_ldap_record(): void
    {
        // Hérite du check global `it_does_not_import_ldap_record_in_ipxe_namespace`
        // qui scanne tout app/Ipxe (donc nos 5 controllers + services 3.3 inclus).
        // Re-test explicite pour traçabilité 3.3 dans le rapport phpunit.
        $files = [
            __DIR__ . '/../../app/Ipxe/Services/WorkstationEnrollmentService.php',
            __DIR__ . '/../../app/Ipxe/Services/IpxeEnrollmentOrchestrator.php',
            __DIR__ . '/../../app/Ipxe/Services/IpxeEnrollmentMenuBuilder.php',
            __DIR__ . '/../../app/Ipxe/Services/IpxeHostnameSanitizer.php',
        ];

        foreach ($files as $f) {
            $content = (string) file_get_contents($f);
            self::assertStringNotContainsString(
                'use LdapRecord\\',
                $content,
                basename($f) . ' ne doit pas importer LdapRecord (D5/D14 — passage exclusif par AdMachineManager)',
            );
        }
    }

    /* ------------------------------------------------------------------
     * Story 3.4 — AC8.1 / AC7.4 / T7.5 — extension du garde-fou
     * ------------------------------------------------------------------ */

    /**
     * Vérifie que les 4 routes natives 3.4 (`/ipxe/installation-linux`,
     * `/ipxe/linux/{preseed,action,autorun}`) sont déclarées AVANT le
     * catchall legacy.
     */
    #[Test]
    public function ipxe_3_4_routes_are_declared_before_catchall(): void
    {
        $routesFile = realpath(__DIR__ . '/../../routes/web.php');
        self::assertNotFalse($routesFile);
        $content = (string) file_get_contents($routesFile);

        $catchallPattern = "/Route::match\s*\([^)]*['\"]\\{path\\}['\"]/";
        self::assertSame(
            1,
            preg_match($catchallPattern, $content, $catchallMatches, PREG_OFFSET_CAPTURE),
            'Catchall legacy {path} introuvable',
        );
        $catchallOffset = $catchallMatches[0][1];

        $routes = [
            ['needle' => "['\"]/ipxe/installation-linux['\"]", 'name' => '/ipxe/installation-linux'],
            ['needle' => "['\"]/ipxe/linux/preseed['\"]", 'name' => '/ipxe/linux/preseed'],
            ['needle' => "['\"]/ipxe/linux/action['\"]", 'name' => '/ipxe/linux/action'],
            ['needle' => "['\"]/ipxe/linux/autorun['\"]", 'name' => '/ipxe/linux/autorun'],
        ];

        foreach ($routes as $route) {
            $pattern = '@Route::(?:match|get|post)\s*\([^;]*?' . $route['needle'] . '@';
            self::assertSame(
                1,
                preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE),
                'Route 3.4 ' . $route['name'] . ' non déclarée',
            );
            self::assertLessThan(
                $catchallOffset,
                $matches[0][1],
                'ORDRE INVALIDE : la route 3.4 ' . $route['name'] . ' doit être AVANT le catchall',
            );
        }

        // Vérification middleware : chaque route 3.4 doit porter
        // `auth.v1.lan-only` (D3).
        $statements = explode(';', $content);
        $ipxeStatements = array_values(array_filter(
            $statements,
            static fn (string $stmt): bool => preg_match(
                "@['\"]/ipxe/(installation-linux|linux/(preseed|action|autorun))['\"]@",
                $stmt,
            ) === 1,
        ));

        self::assertGreaterThanOrEqual(
            4,
            count($ipxeStatements),
            'Les 4 routes 3.4 doivent être déclarées',
        );

        foreach ($ipxeStatements as $stmt) {
            self::assertMatchesRegularExpression(
                '/auth\.v1\.lan-only/',
                $stmt,
                'Chaque route 3.4 doit attacher auth.v1.lan-only — bloc fautif : '
                . substr(trim($stmt), 0, 200),
            );
        }
    }

    #[Test]
    public function it_lists_all_ipxe_3_4_controllers_and_services_under_correct_namespace(): void
    {
        $root = realpath(__DIR__ . '/../../app/Ipxe');
        self::assertNotFalse($root);

        $required = [
            $root . '/Http/Controllers/IpxeInstallationLinuxController.php',
            $root . '/Http/Controllers/IpxeLinuxPreseedController.php',
            $root . '/Http/Controllers/IpxeLinuxActionController.php',
            $root . '/Http/Controllers/IpxeLinuxAutorunController.php',
            $root . '/Http/Requests/IpxeInstallationLinuxRequest.php',
            $root . '/Http/Requests/IpxeLinuxPreseedRequest.php',
            $root . '/Http/Requests/IpxeLinuxActionRequest.php',
            $root . '/Http/Requests/IpxeLinuxAutorunRequest.php',
            $root . '/Services/LinuxPreseedService.php',
            $root . '/Services/LinuxInstallMenuBuilder.php',
            $root . '/Services/LinuxPostInstallTracker.php',
            $root . '/Enums/LinuxDistribution.php',
            $root . '/Enums/LinuxDesktopVariant.php',
            $root . '/Support/PreseedPlaceholders.php',
            $root . '/Exceptions/PreseedGenerationException.php',
        ];

        foreach ($required as $file) {
            self::assertFileExists($file, "Fichier 3.4 manquant : {$file}");
        }
    }

    /**
     * Story 3.4 — AC7.4 — Tous les templates Blade `resources/views/ipxe/`
     * respectent les conventions ASCII strict + pas de balises PHP +
     * newline final.
     *
     * Post-review #M6 — scan dynamique via `Finder` au lieu d'une liste
     * hardcodée. Tout nouveau template ajouté sous `resources/views/ipxe/`
     * est automatiquement couvert par ce test.
     */
    #[Test]
    public function story_3_4_templates_are_ascii_strict_and_no_php(): void
    {
        $viewsRoot = realpath(__DIR__ . '/../../resources/views/ipxe');
        self::assertNotFalse($viewsRoot, 'Dossier resources/views/ipxe introuvable');

        $finder = Finder::create()
            ->files()
            ->in($viewsRoot)
            ->name('*.blade.php');

        $violations = [];
        $count = 0;
        foreach ($finder as $file) {
            $count++;
            $content = (string) $file->getContents();
            $rel = $file->getRelativePathname();

            // Pas de balise PHP.
            if (preg_match('/<\?php|<\?=|\?>/', $content) === 1) {
                $violations[] = $rel . ' contient des balises PHP';
            }

            // ASCII strict (sauf TAB + newline). Tolère un \r éventuel pour
            // Windows line endings.
            if (preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $content) === 1) {
                $violations[] = $rel . ' contient des chars non-ASCII';
            }

            // Newline final.
            if (! str_ends_with($content, "\n")) {
                $violations[] = $rel . ' ne se termine pas par un newline';
            }
        }

        self::assertGreaterThan(0, $count, 'Aucun template trouvé sous resources/views/ipxe — finder cassé ?');
        self::assertSame(
            [],
            $violations,
            "Violations conventions templates ipxe (scan dynamique) :\n  - " . implode("\n  - ", $violations),
        );
    }

    /* ------------------------------------------------------------------
     * Story 3.5 — AC8.1 / AC9.3 — extension du garde-fou
     * ------------------------------------------------------------------ */

    /**
     * Vérifie que les 6 routes natives 3.5 (`/ipxe/installation-windows`,
     * `/ipxe/windows/{install.bat,unattend.xml,diskpart.txt,sysprep.xml,action}`)
     * sont déclarées AVANT le catchall legacy.
     */
    #[Test]
    public function ipxe_3_5_routes_are_declared_before_catchall(): void
    {
        $routesFile = realpath(__DIR__ . '/../../routes/web.php');
        self::assertNotFalse($routesFile);
        $content = (string) file_get_contents($routesFile);

        $catchallPattern = "/Route::match\s*\([^)]*['\"]\\{path\\}['\"]/";
        self::assertSame(
            1,
            preg_match($catchallPattern, $content, $catchallMatches, PREG_OFFSET_CAPTURE),
            'Catchall legacy {path} introuvable',
        );
        $catchallOffset = $catchallMatches[0][1];

        $routes = [
            ['needle' => "['\"]/ipxe/installation-windows['\"]", 'name' => '/ipxe/installation-windows'],
            ['needle' => "['\"]/ipxe/windows/install\\.bat['\"]", 'name' => '/ipxe/windows/install.bat'],
            ['needle' => "['\"]/ipxe/windows/unattend\\.xml['\"]", 'name' => '/ipxe/windows/unattend.xml'],
            ['needle' => "['\"]/ipxe/windows/diskpart\\.txt['\"]", 'name' => '/ipxe/windows/diskpart.txt'],
            ['needle' => "['\"]/ipxe/windows/sysprep\\.xml['\"]", 'name' => '/ipxe/windows/sysprep.xml'],
            ['needle' => "['\"]/ipxe/windows/action['\"]", 'name' => '/ipxe/windows/action'],
        ];

        foreach ($routes as $route) {
            $pattern = '@Route::(?:match|get|post)\s*\([^;]*?' . $route['needle'] . '@';
            self::assertSame(
                1,
                preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE),
                'Route 3.5 ' . $route['name'] . ' non déclarée',
            );
            self::assertLessThan(
                $catchallOffset,
                $matches[0][1],
                'ORDRE INVALIDE : la route 3.5 ' . $route['name'] . ' doit être AVANT le catchall',
            );
        }

        // Vérification middleware : chaque route 3.5 doit porter
        // `auth.v1.lan-only` (D3).
        $statements = explode(';', $content);
        $ipxeStatements = array_values(array_filter(
            $statements,
            static fn (string $stmt): bool => preg_match(
                "@['\"]/ipxe/(installation-windows|windows/(install\\.bat|unattend\\.xml|diskpart\\.txt|sysprep\\.xml|action))['\"]@",
                $stmt,
            ) === 1,
        ));

        self::assertGreaterThanOrEqual(
            6,
            count($ipxeStatements),
            'Les 6 routes 3.5 doivent être déclarées',
        );

        foreach ($ipxeStatements as $stmt) {
            self::assertMatchesRegularExpression(
                '/auth\.v1\.lan-only/',
                $stmt,
                'Chaque route 3.5 doit attacher auth.v1.lan-only — bloc fautif : '
                . substr(trim($stmt), 0, 200),
            );
        }
    }

    #[Test]
    public function it_lists_all_ipxe_3_5_controllers_and_services_under_correct_namespace(): void
    {
        $root = realpath(__DIR__ . '/../../app/Ipxe');
        self::assertNotFalse($root);

        $required = [
            $root . '/Http/Controllers/IpxeInstallationWindowsController.php',
            $root . '/Http/Controllers/IpxeWindowsInstallBatController.php',
            $root . '/Http/Controllers/IpxeWindowsUnattendController.php',
            $root . '/Http/Controllers/IpxeWindowsDiskpartController.php',
            $root . '/Http/Controllers/IpxeWindowsSysprepController.php',
            $root . '/Http/Controllers/IpxeWindowsActionController.php',
            $root . '/Http/Requests/IpxeInstallationWindowsRequest.php',
            $root . '/Http/Requests/IpxeWindowsInstallBatRequest.php',
            $root . '/Http/Requests/IpxeWindowsUnattendRequest.php',
            $root . '/Http/Requests/IpxeWindowsDiskpartRequest.php',
            $root . '/Http/Requests/IpxeWindowsSysprepRequest.php',
            $root . '/Http/Requests/IpxeWindowsActionRequest.php',
            $root . '/Services/WindowsUnattendBuilder.php',
            $root . '/Services/WindowsInstallBatBuilder.php',
            $root . '/Services/WindowsInstallMenuBuilder.php',
            $root . '/Services/WindowsPostInstallTracker.php',
            $root . '/Enums/WindowsVersion.php',
            $root . '/Enums/WindowsInstallStep.php',
            $root . '/Support/WindowsXmlPlaceholders.php',
            $root . '/Exceptions/UnattendGenerationException.php',
        ];

        foreach ($required as $file) {
            self::assertFileExists($file, "Fichier 3.5 manquant : {$file}");
        }
    }

    private function stripComments(string $code): string
    {
        $code = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
        $code = preg_replace('/^\s*\/\/.*$/m', '', $code) ?? $code;

        return $code;
    }
}
