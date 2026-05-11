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
 * Garde-fou architectural Epic 16 (Story 16.1 / AC2.2).
 *
 * Vérifie que le namespace `App\Gpo\*` respecte les invariants techniques :
 *
 * 1. **Pas d'import direct de `LdapRecord\*`** — la lecture AD passe par les
 *    shims existants (`App\LdapModels\*`) ou par `samba-tool gpo` via
 *    `GpoService` (= source de vérité GPO). Aucune exception whitelistée
 *    pour Story 16.1.
 *
 * 2. **Pas d'appel `exec()` / `shell_exec()` / `passthru()` / `proc_open()`
 *    direct** dans les fichiers sous `app/Gpo/` autre que `SambaToolRunner`
 *    (seul point autorisé à invoquer le shell, via `Illuminate\Support\Facades\Process`).
 *
 * 3. **Pas d'appel à la fonction PHP globale legacy `sambatool()`** —
 *    uniquement via `GpoService` qui passe par `SambaToolRunner`. Cela
 *    empêche le namespace `App\Gpo` de retomber dans le legacy.
 *
 * Limitations connues (cf. Story 15.1) :
 *
 * - Seuls les `use` statements (Use_, GroupUse) sont scannés pour l'import
 *   LdapRecord. Les FQCN inline (`new \LdapRecord\Connection()`) ne sont
 *   pas détectés. La couverture complète arrivera avec une PHPStan rule.
 * - La détection `exec()`/`shell_exec()`/`sambatool()` se fait via une regex
 *   sur le code source — détecte aussi les commentaires. Acceptable pour ce
 *   garde-fou défensif (limite les faux négatifs au prix de quelques faux
 *   positifs sur commentaires, qu'il faut alors corriger).
 *
 * @todo Migrer vers ArchTest / PHPStan rule lorsqu'un de ces outils sera
 *       introduit dans le projet (ticket tooling séparé hors scope 16.1).
 */
class GpoNamespaceTest extends TestCase
{
    /** Préfixes interdits dans les `use` du namespace `App\Gpo`. */
    private const FORBIDDEN_USE_PREFIXES = [
        'LdapRecord\\',
    ];

    /**
     * Fichiers (basename) whitelistés pour exec/shell_exec/etc. — seul
     * `SambaToolRunner` est autorisé à utiliser `Illuminate\Support\Facades\Process`.
     */
    private const SHELL_WHITELIST_FILES = [
        'SambaToolRunner.php',
    ];

    /**
     * Patterns interdits **partout** dans `app/Gpo/`, y compris dans les
     * fichiers whitelistés (qui ne sont autorisés QUE pour la facade Process,
     * pas pour `exec`/`shell_exec`/`passthru`/`proc_open` bruts).
     *
     * @var list<array{pattern: string, label: string}>
     */
    private const FORBIDDEN_EVERYWHERE = [
        ['pattern' => '/\bexec\s*\(/i', 'label' => 'exec()'],
        ['pattern' => '/\bshell_exec\s*\(/i', 'label' => 'shell_exec()'],
        ['pattern' => '/\bpassthru\s*\(/i', 'label' => 'passthru()'],
        ['pattern' => '/\bproc_open\s*\(/i', 'label' => 'proc_open()'],
    ];

    /**
     * Patterns interdits hors fichiers whitelistés (la facade `Process` est
     * autorisée uniquement dans `SambaToolRunner.php`).
     *
     * @var list<array{pattern: string, label: string}>
     */
    private const FORBIDDEN_EXCEPT_WHITELIST = [
        ['pattern' => '/Illuminate\\\\Support\\\\Facades\\\\Process/i', 'label' => 'facade Process'],
    ];

    /**
     * Patterns interdits PARTOUT (même SambaToolRunner) — appels à la
     * fonction legacy `sambatool()` qui contournerait le runner natif.
     *
     * @var list<array{pattern: string, label: string}>
     */
    private const FORBIDDEN_LEGACY_FUNCTIONS = [
        // \b sambatool \s* \( — détecte sambatool() comme appel de fonction
        // (préfixé éventuellement par `\` ou un espace), pas comme nom de
        // classe ou méthode (sambatool::).
        ['pattern' => '/(?<![A-Za-z0-9_:>])sambatool\s*\(/i', 'label' => 'fonction legacy sambatool()'],
    ];

    #[Test]
    public function no_class_in_gpo_namespace_imports_ldap_record(): void
    {
        $namespaceRoot = realpath(__DIR__ . '/../../app/Gpo');
        if ($namespaceRoot === false) {
            self::fail('Le dossier app/Gpo est introuvable — l\'arborescence du namespace doit exister.');
        }

        $finder = (new Finder())->files()->in($namespaceRoot)->name('*.php');
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
            if ($namespace === null || ! str_starts_with($namespace, 'App\\Gpo')) {
                continue;
            }

            $className = $namespace . '\\' . $file->getBasename('.php');

            foreach ($collector->info['uses'] as $importedFqn) {
                foreach (self::FORBIDDEN_USE_PREFIXES as $forbidden) {
                    if (str_starts_with($importedFqn, $forbidden)) {
                        $violations[] = sprintf('%s importe %s (préfixe interdit : %s)', $className, $importedFqn, $forbidden);
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Epic 16 (LdapRecord direct) :\n  - " . implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function no_shell_execution_outside_samba_tool_runner(): void
    {
        $namespaceRoot = realpath(__DIR__ . '/../../app/Gpo');
        if ($namespaceRoot === false) {
            self::fail('Le dossier app/Gpo est introuvable.');
        }

        $finder = (new Finder())->files()->in($namespaceRoot)->name('*.php');
        if (! $finder->hasResults()) {
            self::assertTrue(true);

            return;
        }

        $violations = [];
        foreach ($finder as $file) {
            $basename = $file->getBasename();
            // README.md exclu (markdown), seuls fichiers .php scannés.
            $isWhitelisted = in_array($basename, self::SHELL_WHITELIST_FILES, true);

            $code = $file->getContents();

            // Suppression naïve des commentaires (// et /* */) pour limiter
            // les faux positifs sur les docblocks qui mentionneraient exec().
            $stripped = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
            $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;

            // Règles interdites PARTOUT (exec/shell_exec/passthru/proc_open) —
            // même SambaToolRunner doit passer exclusivement par la facade Process.
            foreach (self::FORBIDDEN_EVERYWHERE as $rule) {
                if (preg_match($rule['pattern'], $stripped) === 1) {
                    $violations[] = sprintf(
                        '%s utilise %s — interdit partout dans app/Gpo (utiliser Illuminate\\Support\\Facades\\Process via SambaToolRunner)',
                        $file->getRelativePathname(),
                        $rule['label'],
                    );
                }
            }

            // Règles avec whitelist (la facade Process est autorisée
            // uniquement dans SambaToolRunner).
            if ($isWhitelisted) {
                continue;
            }

            foreach (self::FORBIDDEN_EXCEPT_WHITELIST as $rule) {
                if (preg_match($rule['pattern'], $stripped) === 1) {
                    $violations[] = sprintf(
                        '%s utilise %s (whitelist limitée à SambaToolRunner)',
                        $file->getRelativePathname(),
                        $rule['label'],
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Epic 16 (shell execution hors SambaToolRunner) :\n  - " . implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function no_call_to_legacy_sambatool_function(): void
    {
        $namespaceRoot = realpath(__DIR__ . '/../../app/Gpo');
        if ($namespaceRoot === false) {
            self::fail('Le dossier app/Gpo est introuvable.');
        }

        $finder = (new Finder())->files()->in($namespaceRoot)->name('*.php');
        if (! $finder->hasResults()) {
            self::assertTrue(true);

            return;
        }

        $violations = [];
        foreach ($finder as $file) {
            $code = $file->getContents();
            // Strip commentaires : éviter de matcher "use sambatool()" dans un docblock.
            $stripped = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
            $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;

            foreach (self::FORBIDDEN_LEGACY_FUNCTIONS as $rule) {
                if (preg_match($rule['pattern'], $stripped) === 1) {
                    $violations[] = sprintf(
                        '%s appelle %s — passer par GpoService / SambaToolRunner',
                        $file->getRelativePathname(),
                        $rule['label'],
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Epic 16 (appel direct fonction legacy) :\n  - " . implode("\n  - ", $violations),
        );
    }
}
