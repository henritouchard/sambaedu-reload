<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Gpo\Services\GpoService;
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
     * Fichiers (basename) whitelistés pour la facade `Process` — utilisations
     * légitimes hors `SambaToolRunner` :
     *
     * - `SambaToolRunner.php` (16.1) : point d'entrée samba-tool.
     * - `GenerateWineImageJob.php` (16.3c) : invoque `make_wine_image.sh` en
     *   mode array (audit §6.F F7). Pas une commande samba-tool, pas le bon
     *   niveau d'abstraction pour `SambaToolRunner`. Garde-fou de mode array
     *   maintenu via `it_uses_process_in_array_mode_in_generate_wine_image_job`.
     * - `ApplicationScriptsGenerator.php` (16.7) : invoque les scripts
     *   `pre-app.sh`/`post-app.sh`/`as-user.sh` (paramètres `$script, $user`)
     *   en mode array — `Process::timeout(10)->run([$script, $user])`. Iso-legacy
     *   `app_$type_${id}_$application.sh`. Pas une commande samba-tool.
     */
    private const SHELL_WHITELIST_FILES = [
        'SambaToolRunner.php',
        'GenerateWineImageJob.php',
        'ApplicationScriptsGenerator.php',
    ];

    /**
     * Fichiers exemptés des règles FORBIDDEN_EVERYWHERE (exec/shell_exec/...).
     * Cas exceptionnel : portage iso-legacy d'une commande shell (avec pipe)
     * non trivialement convertible à `Process::run(array)` ou samba-tool.
     *
     * - `NetworkScriptGenerator.php` : invoque `ssh ... pdbedit -Lw | cut -d: -f4`
     *   pour récupérer la clé machine. Le pipe `|` impose le mode shell. Sera
     *   migré à `samba-tool user getpassword` ou requête LDAP `dBCSPwd` en
     *   Story 16.4 (cf. `@todo` dans le fichier). Vulnérabilité injection
     *   identifiée audit §6.F, mitigée par validation `samAccountName` stricte
     *   (regex amont) + `escapeshellarg` défense en profondeur.
     */
    private const EXEC_WHITELIST_FILES = [
        'NetworkScriptGenerator.php',
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
            // Exception : fichiers explicitement listés dans EXEC_WHITELIST_FILES
            // (portage iso-legacy avec pipe shell, replan story dédiée).
            $isExecWhitelisted = in_array($basename, self::EXEC_WHITELIST_FILES, true);
            if (! $isExecWhitelisted) {
                foreach (self::FORBIDDEN_EVERYWHERE as $rule) {
                    if (preg_match($rule['pattern'], $stripped) === 1) {
                        $violations[] = sprintf(
                            '%s utilise %s — interdit partout dans app/Gpo (utiliser Illuminate\\Support\\Facades\\Process via SambaToolRunner)',
                            $file->getRelativePathname(),
                            $rule['label'],
                        );
                    }
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
        // La classe `[^)]*` tolère `$this->timeout` (etc.) dans l'enchaînement
        // `Process::timeout($this->timeout)->run(...)` — l'ancienne classe
        // `[A-Za-z0-9_:>()\s,.]` excluait le `$` et faisait faux négatif.
        $hasArrayMode = preg_match('/Process::[^)]*\)?\s*(?:->[A-Za-z_]+\([^)]*\)\s*)*->\s*run\s*\(\s*\[/s', $stripped) === 1
            || preg_match('/Process::[^)]*\)?\s*(?:->[A-Za-z_]+\([^)]*\)\s*)*->\s*run\s*\(\s*\$[A-Za-z_]+\s*\)/s', $stripped) === 1;

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

    /**
     * Story 16.5 — AC1.5 / AC6.4.
     *
     * Vérifie que les méthodes d'écriture de `GpoService` (setLink, removeLink,
     * setInheritance, reorderLinks + helpers privés setLinkUnaudited /
     * removeLinkUnaudited) passent **exclusivement** par `SambaToolRunner`
     * (pas de `Process::` direct, pas de `exec`, pas de shell concat).
     *
     * Garde-fou architectural : empêche une régression où un dev contournerait
     * la couche `SambaToolRunner` (mode array + logging + dry-run) pour
     * écrire `gpLink` directement via LdapRecord ou Process facade.
     */
    #[Test]
    public function it_validates_gpo_write_methods_use_samba_tool_runner(): void
    {
        $path = realpath(__DIR__ . '/../../app/Gpo/Services/GpoService.php');
        if ($path === false) {
            self::fail('GpoService.php introuvable.');
        }
        $code = (string) file_get_contents($path);
        // Strip commentaires.
        $stripped = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
        $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;

        // 1. Pas d'import facade Process (déjà couvert par le test global,
        //    mais on re-vérifie pour clarté).
        self::assertSame(
            0,
            preg_match('/use\s+Illuminate\\\\Support\\\\Facades\\\\Process/', $stripped),
            'GpoService ne doit JAMAIS importer Illuminate\\Support\\Facades\\Process — utiliser SambaToolRunner.',
        );

        // 2. Aucune concat string passée à Process / exec / shell_exec.
        //    Pattern ciblé : Process::run/start avec un argument string commençant
        //    par `'samba-tool` ou `"samba-tool` (mode shell concat interdit).
        //    Note : les `sprintf('samba-tool gpo X failed', ...)` dans les messages
        //    d'exception sont OK — ils ne sont pas passés au shell.
        self::assertSame(
            0,
            preg_match('/Process::[A-Za-z_]+\s*\(\s*[\'"]samba-tool/', $stripped),
            'GpoService ne doit JAMAIS appeler Process avec une string "samba-tool ..." — mode array exclusif via SambaToolRunner.',
        );

        // 3. Aucun appel `exec`/`shell_exec` (déjà global mais on s'assure ici aussi).
        foreach (['exec', 'shell_exec', 'passthru', 'proc_open'] as $forbidden) {
            self::assertSame(
                0,
                preg_match('/\b' . preg_quote($forbidden, '/') . '\s*\(/', $stripped),
                sprintf('GpoService ne doit jamais appeler %s() — toujours via SambaToolRunner.', $forbidden),
            );
        }

        // 4. Les méthodes write doivent toutes invoquer `$this->runner->run(`.
        //    On vérifie au moins une occurrence du runner par méthode.
        $writeMethods = ['setLink', 'removeLink', 'setInheritance', 'reorderLinks'];
        foreach ($writeMethods as $method) {
            // Capture le corps de la méthode entre `public function NAME` et la
            // prochaine `public function` ou fin de classe (regex grossière OK
            // pour ce garde-fou).
            $matches = [];
            $pattern = '/public function ' . preg_quote($method, '/') . '\b.*?(?=public function |private function |}\s*$)/s';
            if (preg_match($pattern, $stripped, $matches) !== 1) {
                self::fail(sprintf('Méthode %s::%s introuvable dans GpoService.', GpoService::class, $method));
            }
            $body = $matches[0];

            // setLink/removeLink/setInheritance peuvent déléguer à des helpers
            // (setLinkUnaudited/removeLinkUnaudited) → on accepte aussi cette
            // route, mais elle doit elle-même appeler `$this->runner->run(`.
            $hasRunnerCall = str_contains($body, '$this->runner->run(')
                || str_contains($body, 'setLinkUnaudited(')
                || str_contains($body, 'removeLinkUnaudited(');

            self::assertTrue(
                $hasRunnerCall,
                sprintf('La méthode %s doit invoquer $this->runner->run() (ou déléguer à un helper qui le fait).', $method),
            );
        }
    }

    /**
     * Story 16.6 — AC5.5 (corrigé post-review #3).
     *
     * Le `WpkgGpoSynchronizer` est la **frontière unique** entre la couche
     * native Epic 16 et le shim legacy `import_gpo` (l'appel séparé à
     * `specialise_gpo` a été supprimé : `import_gpo` enchaîne déjà
     * `unzip_gpo → specialise_gpo → sysvol_put` en interne, TD-16.6-1).
     * Garde-fou architectural :
     *  1. n'importe pas `LdapRecord\*` direct.
     *  2. n'utilise pas `exec`/`shell_exec`/`passthru`/`proc_open`.
     *  3. tout appel au shim legacy passe par `app('legacy.import_gpo')`
     *     (jamais `require_once` direct ou `\import_gpo()` FQCN hard-coded au
     *     niveau classe — la résolution dynamique via fonction globale après
     *     `function_exists()` reste tolérée pour le fallback production).
     */
    #[Test]
    public function wpkg_gpo_synchronizer_respects_native_frontier(): void
    {
        $path = realpath(__DIR__ . '/../../app/Gpo/Services/WpkgGpoSynchronizer.php');
        if ($path === false) {
            self::markTestSkipped('WpkgGpoSynchronizer.php pas encore créé');
            return;
        }
        $code = (string) file_get_contents($path);
        $stripped = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
        $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;

        // 1. Pas de Ldap direct.
        self::assertSame(
            0,
            preg_match('/\\bLdapRecord\\\\/', $stripped),
            'WpkgGpoSynchronizer ne doit pas importer LdapRecord directement — passer par GpoService.',
        );

        // 2. Pas d'exec/shell/passthru.
        foreach (['exec', 'shell_exec', 'passthru', 'proc_open'] as $f) {
            self::assertSame(
                0,
                preg_match('/\\b' . preg_quote($f, '/') . '\\s*\\(/', $stripped),
                sprintf('WpkgGpoSynchronizer ne doit JAMAIS appeler %s() — passer par GpoService/SambaToolRunner.', $f),
            );
        }

        // 3. Pas d'import facade Process direct (whitelist limitée à SambaToolRunner).
        self::assertSame(
            0,
            preg_match('/use\\s+Illuminate\\\\Support\\\\Facades\\\\Process/', $stripped),
            'WpkgGpoSynchronizer ne doit pas importer la facade Process.',
        );

        // 4. Le shim doit être invoqué via app('legacy.import_gpo')
        //    (pattern iso 16.3c `legacy.get_wine_shortcuts`). Le fallback `call_user_func`
        //    via fonction globale reste autorisé (production VM avec legacy/bootstrap.php).
        //
        // Note review fix #3 : l'appel séparé à `specialise_gpo` a été supprimé
        // (TD-16.6-1 corrigée) — `import_gpo` enchaîne déjà la spécialisation en
        // interne. On ne vérifie donc plus que le binding `legacy.import_gpo`.
        self::assertSame(
            1,
            preg_match('/legacy\\.import_gpo/', $stripped) > 0 ? 1 : 0,
            'WpkgGpoSynchronizer doit déclarer le binding container `legacy.import_gpo`.',
        );
    }

    /**
     * Story 16.6 — AC5.5 (cumulé). Le shim legacy doit être appelé **uniquement**
     * via le binding container ou la fonction globale après chargement
     * `legacy/bootstrap.php`. Aucun autre fichier de `app/Gpo/` ne doit faire
     * référence à `import_gpo`/`specialise_gpo` directement — seul
     * `WpkgGpoSynchronizer` est autorisé (frontière propre).
     */
    #[Test]
    public function only_wpkg_gpo_synchronizer_references_legacy_import_gpo(): void
    {
        $namespaceRoot = realpath(__DIR__ . '/../../app/Gpo');
        if ($namespaceRoot === false) {
            self::markTestSkipped('app/Gpo introuvable');
            return;
        }
        $finder = (new \Symfony\Component\Finder\Finder())->files()->in($namespaceRoot)->name('*.php');
        $violations = [];
        foreach ($finder as $file) {
            $name = $file->getBasename();
            if ($name === 'WpkgGpoSynchronizer.php') {
                continue;
            }
            $stripped = preg_replace('!/\*.*?\*/!s', '', $file->getContents()) ?? '';
            $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;
            if (preg_match('/\\b(import_gpo|specialise_gpo)\\s*\\(/', $stripped) === 1) {
                $violations[] = $file->getRelativePathname();
            }
        }
        self::assertSame(
            [],
            $violations,
            "Frontière 16.6 cassée — seul WpkgGpoSynchronizer doit appeler `import_gpo`/`specialise_gpo` :\n  - "
            . implode("\n  - ", $violations),
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
