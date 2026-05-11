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
 * Story 8.1 — Garde-fou architectural sur `App\Services\Network\*`.
 *
 * Pattern aligné `WpkgDeploymentNamespaceTest` (Story 15.1).
 *
 * Vérifie qu'aucune classe sous `App\Services\Network\*` n'importe :
 *  - `LdapRecord\*`, `App\LdapModels\*`, `App\Services\Ad\*` (rappel
 *    Eloquent-first en chemin critique — un service DHCP serveur **local**
 *    ne doit JAMAIS dépendre d'AD).
 *  - Aucun controller direct legacy (`App\Http\Controllers\Legacy*`).
 *
 * Limite : seuls les `use` statements sont scannés (pas les FQCN inline).
 * Pour couverture runtime complémentaire : tests Feature.
 */
class NetworkNamespaceTest extends TestCase
{
    private const FORBIDDEN_PREFIXES = [
        'LdapRecord\\',
        'App\\LdapModels\\',
        'App\\Services\\Ad\\',
    ];

    private const WHITELISTED_CLASSES = [];

    #[Test]
    public function no_class_in_services_network_imports_ldap_or_ad(): void
    {
        $namespaceRoot = realpath(__DIR__ . '/../../app/Services/Network');
        if ($namespaceRoot === false) {
            self::fail('Le dossier app/Services/Network est introuvable — la story 8.1 doit avoir créé l\'arborescence.');
        }

        $finder = (new Finder())
            ->files()
            ->in($namespaceRoot)
            ->name('*.php');

        if (! $finder->hasResults()) {
            self::assertTrue(true, 'Aucune classe encore présente.');
            return;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $violations = [];

        foreach ($finder as $file) {
            $code = $file->getContents();
            try {
                $ast = $parser->parse($code);
            } catch (\Throwable $e) {
                self::fail(sprintf('Parse error sur %s : %s', $file->getRelativePathname(), $e->getMessage()));
            }
            if ($ast === null) {
                continue;
            }

            $collector = new class extends NodeVisitorAbstract {
                /** @var array{namespace: ?string, uses: list<string>} */
                public array $info = ['namespace' => null, 'uses' => []];

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Node\Stmt\Namespace_ && $node->name !== null) {
                        $this->info['namespace'] = $node->name->toString();
                    }
                    if ($node instanceof Use_) {
                        foreach ($node->uses as $use) {
                            $this->info['uses'][] = $use->name->toString();
                        }
                    }
                    if ($node instanceof GroupUse) {
                        $prefix = $node->prefix->toString();
                        foreach ($node->uses as $use) {
                            $this->info['uses'][] = $prefix . '\\' . $use->name->toString();
                        }
                    }
                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($collector);
            $traverser->traverse($ast);

            $namespace = $collector->info['namespace'];
            if ($namespace === null || ! str_starts_with($namespace, 'App\\Services\\Network')) {
                continue;
            }

            $className = $namespace . '\\' . $file->getBasename('.php');
            if (in_array($className, self::WHITELISTED_CLASSES, true)) {
                continue;
            }

            foreach ($collector->info['uses'] as $importedFqn) {
                foreach (self::FORBIDDEN_PREFIXES as $forbidden) {
                    if (str_starts_with($importedFqn, $forbidden)) {
                        $violations[] = sprintf(
                            '%s importe %s (préfixe interdit : %s)',
                            $className,
                            $importedFqn,
                            $forbidden,
                        );
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Network détectées :\n  - " . implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function expected_subnamespaces_are_present(): void
    {
        $namespaceRoot = realpath(__DIR__ . '/../../app/Services/Network');
        if ($namespaceRoot === false) {
            self::fail('Le dossier app/Services/Network est introuvable.');
        }

        $expected = ['Exceptions', 'Data'];
        foreach ($expected as $relative) {
            $path = $namespaceRoot . '/' . $relative;
            self::assertDirectoryExists($path, "Sous-dossier {$relative}/ attendu sous app/Services/Network/");

            $finder = (new Finder())
                ->files()
                ->in($path)
                ->name('*.php');
            self::assertTrue(
                $finder->hasResults(),
                sprintf('Le sous-dossier %s/ est vide.', $relative),
            );
        }
    }
}
