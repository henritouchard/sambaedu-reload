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
     * `SambaToolRunner` (16.1) et `GenerateWineImageJob` (16.3c) sont
     * autorisés à utiliser `Illuminate\Support\Facades\Process`.
     *
     * `GenerateWineImageJob` invoque `make_wine_image.sh` en mode array
     * (audit §6.F F7 corrigé) — pas une commande samba-tool, pas le bon
     * niveau d'abstraction pour `SambaToolRunner`. Garde-fou maintenu via
     * `it_uses_process_in_array_mode_in_generate_wine_image_job`.
     */
    private const SHELL_WHITELIST_FILES = [
        'SambaToolRunner.php',
        'GenerateWineImageJob.php',
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

    /**
     * Story 16.3c — AC6.9. Vérifie que les fichiers sous `app/Gpo/Jobs/` :
     *  - ne contiennent aucune référence à `LdapRecord\*` (pas d'AD direct
     *    dans les Jobs Wine — `GenerateWineImageJob` est pure FS + Process).
     *  - ne mentionnent ni `samba-tool` ni `samba_tool` (pas d'écriture AD
     *    déclenchée par les Jobs Wine).
     */
    #[Test]
    public function jobs_directory_has_no_ldap_or_samba_tool_references(): void
    {
        $jobsDir = realpath(__DIR__ . '/../../app/Gpo/Jobs');
        if ($jobsDir === false) {
            self::assertTrue(true, 'app/Gpo/Jobs/ pas encore créé — garde-fou inactif');
            return;
        }

        $finder = (new Finder())->files()->in($jobsDir)->name('*.php');
        if (! $finder->hasResults()) {
            self::assertTrue(true);
            return;
        }

        $violations = [];
        foreach ($finder as $file) {
            $code = $file->getContents();
            // Strip commentaires.
            $stripped = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
            $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;

            if (preg_match('/\\bLdapRecord\\\\/', $stripped) === 1) {
                $violations[] = sprintf('%s référence LdapRecord\\* — interdit dans Jobs/', $file->getRelativePathname());
            }
            if (preg_match('/samba[-_]tool/i', $stripped) === 1) {
                $violations[] = sprintf('%s mentionne samba-tool/samba_tool — interdit dans Jobs/', $file->getRelativePathname());
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Epic 16 — Jobs/ pas de side-effect AD :\n  - " . implode("\n  - ", $violations),
        );
    }

    /**
     * Story 16.3c — AC6.9. Vérifie que `GenerateWineImageJob` invoque
     * `Process::run(...)` ou `Process::timeout(...)->run(...)` en mode **array**
     * (pas de concaténation shell — audit §6.F F7 corrigé).
     */
    #[Test]
    public function it_uses_process_in_array_mode_in_generate_wine_image_job(): void
    {
        $path = realpath(__DIR__ . '/../../app/Gpo/Jobs/GenerateWineImageJob.php');
        if ($path === false) {
            self::markTestSkipped('GenerateWineImageJob.php pas encore créé');
            return;
        }

        $code = (string) file_get_contents($path);
        // Strip commentaires pour limiter faux positifs.
        $stripped = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
        $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;

        // 1. Doit contenir un appel `Process::...->run([...])` (mode array).
        $hasArrayMode = preg_match('/Process::[A-Za-z0-9_:>()\\s,.]*run\s*\(\s*\[/s', $stripped) === 1
            || preg_match('/Process::[A-Za-z0-9_:>()\\s,.]*run\s*\(\s*\$[A-Za-z_]+\s*\)/s', $stripped) === 1;

        self::assertTrue(
            $hasArrayMode,
            'GenerateWineImageJob doit invoquer Process::run(...) en mode array (variable list<string> ou littéral [...]).',
        );

        // 2. NE DOIT PAS concaténer le nom de l'application en string shell.
        // Patterns interdits : `Process::*run("...".$this->application)`.
        $forbiddenConcat = preg_match('/Process::[A-Za-z0-9_:>()\\s,.]*run\s*\(\s*["\']/s', $stripped) === 1;
        self::assertFalse(
            $forbiddenConcat,
            'GenerateWineImageJob ne doit pas invoquer Process::run avec une string (concaténation shell interdite).',
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
