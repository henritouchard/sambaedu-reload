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
 * Garde-fou architectural Epic 15 (Story 15.1 / AC2.1).
 *
 * Vérifie qu'aucune classe sous `App\Wpkg\Deployment\*` n'importe `LdapRecord\*`
 * ni `App\Services\Ad\*` (rappel garde-fou Epic 15 : *Eloquent first*).
 * La synchro AD → Eloquent est un job périodique (Story 15.3) et constitue
 * la seule exception whitelistée.
 *
 * **Limitation connue** : seuls les `use` statements (Use_, GroupUse) sont
 * scannés. Les usages inline FQCN (`new \LdapRecord\Connection()`,
 * `\App\Services\Ad\AdService::call()`, `class_exists('\\LdapRecord\\…')`)
 * ne sont pas détectés. Idem pour un alias `use Foo as Bar; new Bar();`
 * détecté côté `use` mais pas côté usage. La couverture complète est
 * prévue avec une PHPStan rule dédiée (ticket tooling — voir @todo).
 *
 * @todo Migrer vers ArchTest / PHPStan rule lorsqu'un de ces outils sera
 *       introduit dans le projet (ticket tooling séparé hors scope 15.1).
 */
class WpkgDeploymentNamespaceTest extends TestCase
{
    /**
     * Préfixes interdits dans les `use` du namespace `App\Wpkg\Deployment`.
     */
    private const FORBIDDEN_PREFIXES = [
        'LdapRecord\\',
        'App\\Services\\Ad\\',
    ];

    /**
     * Classes autorisées à enfreindre la règle (FQN complet).
     * Justification : sync AD → Eloquent périodique hors hot path.
     */
    private const WHITELISTED_CLASSES = [
        'App\\Wpkg\\Deployment\\Jobs\\WpkgAdReconciliationJob',
    ];

    #[Test]
    public function no_class_in_wpkg_deployment_imports_ad_or_ldap_record(): void
    {
        $namespaceRoot = realpath(__DIR__ . '/../../app/Wpkg/Deployment');
        if ($namespaceRoot === false) {
            self::fail('Le dossier app/Wpkg/Deployment est introuvable — l\'arborescence du namespace doit exister.');
        }

        $finder = (new Finder())
            ->files()
            ->in($namespaceRoot)
            ->name('*.php');

        if (! $finder->hasResults()) {
            self::assertTrue(true, 'Aucune classe encore présente — garde-fou activé pour les stories suivantes.');

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

            $collector = new class extends NodeVisitorAbstract
            {
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
            if ($namespace === null || ! str_starts_with($namespace, 'App\\Wpkg\\Deployment')) {
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
            "Violations garde-fou Eloquent first détectées :\n  - " . implode("\n  - ", $violations),
        );
    }

    /**
     * Story 15.2 / AC7.6 — extension : vérifie que le scan couvre bien les
     * sous-namespaces livrés par 15.2 (Services, Http\Controllers, Generators,
     * Listeners) en plus de Events / Models / Jobs / Support.
     *
     * On vérifie que pour CHAQUE sous-dossier déclaré dans le namespace au
     * moins un fichier `.php` est trouvé par `Symfony\Finder` — sinon le
     * garde-fou ne couvrirait pas effectivement ces classes.
     */
    #[Test]
    public function scan_covers_all_15_2_subnamespaces(): void
    {
        $namespaceRoot = realpath(__DIR__ . '/../../app/Wpkg/Deployment');
        if ($namespaceRoot === false) {
            self::fail('Le dossier app/Wpkg/Deployment est introuvable.');
        }

        $expectedSubdirs = [
            'Services',
            'Http/Controllers',
            'Generators',
            'Listeners',
            'Events',
            'Models',
        ];

        foreach ($expectedSubdirs as $relative) {
            $finder = (new Finder())
                ->files()
                ->in($namespaceRoot . '/' . $relative)
                ->name('*.php');

            self::assertTrue(
                $finder->hasResults(),
                sprintf(
                    'Sous-dossier %s/ vide : test archi ne le couvre pas (Story 15.2 / AC7.6).',
                    $relative,
                ),
            );
        }
    }
}
