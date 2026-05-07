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
 * Garde-fou architectural Epic 15 (Story 15.1 / AC2.1, étendu Story 15.2 / AC7.6,
 * durci Story 15.3 / AC4.1, AC5.4).
 *
 * Vérifie qu'aucune classe sous `App\Wpkg\*` n'importe `LdapRecord\*`,
 * `App\LdapModels\*` ou `App\Services\Ad\*` (rappel garde-fou Epic 15 :
 * *Eloquent first* en chemin critique). La sync AD → Eloquent reste
 * **un outil de remédiation manuelle** (`App\Jobs\SyncAllFromAdJob`,
 * Story 15.3) — déclenché humainement, hors namespace `App\Wpkg\*`.
 *
 * **Whitelist supprimée par 15.3** : la mention de
 * `WpkgAdReconciliationJob` (job périodique entrant initialement prévu)
 * a été retirée. Le job est définitivement abandonné par décision de
 * cadrage 2026-05-05/06 (race silencieuse avec observers `*AdSyncJob`
 * sortants). Aucune exception n'est tolérée par défaut.
 *
 * **Cas exceptionnel chemin froid** : si une classe `App\Wpkg\*` doit
 * légitimement importer un de ces préfixes pour un usage chemin froid,
 * un commentaire `// @chemin-froid: <justification>` doit précéder
 * l'import. Le test ne valide pas ce commentaire automatiquement (couvert
 * en code review) mais signale la classe via le message d'erreur.
 *
 * **Limitation connue** : seuls les `use` statements (Use_, GroupUse) sont
 * scannés. Les usages inline FQCN (`new \LdapRecord\Connection()`,
 * `class_exists('\\LdapRecord\\…')`) ne sont pas détectés. Couverture
 * runtime complémentaire : `EloquentFirstChemiCritiqueTest` (Story 15.3 / T5).
 *
 * @todo Migrer vers ArchTest / PHPStan rule lorsqu'un de ces outils sera
 *       introduit dans le projet (ticket tooling séparé hors scope 15.1).
 */
class WpkgDeploymentNamespaceTest extends TestCase
{
    /**
     * Préfixes interdits dans les `use` du namespace `App\Wpkg\*`.
     *
     * Story 15.3 / AC4.1 — `App\LdapModels\*` ajouté à la liste : les
     * modèles LdapRecord internes (MachineModel, DeviceGroupModel, etc.)
     * sont aussi proscrits en chemin critique.
     */
    private const FORBIDDEN_PREFIXES = [
        'LdapRecord\\',
        'App\\LdapModels\\',
        'App\\Services\\Ad\\',
    ];

    /**
     * Aucune classe whitelistée par défaut (Story 15.3 / AC4.1).
     * La whitelist `WpkgAdReconciliationJob` (Story 15.1 / 15.2) a été
     * supprimée car le job est abandonné — la sync AD → Eloquent reste
     * `App\Jobs\SyncAllFromAdJob`, hors namespace `App\Wpkg\*`.
     */
    private const WHITELISTED_CLASSES = [];

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
