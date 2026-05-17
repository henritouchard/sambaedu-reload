<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use App\Doctor\Level;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Orchestrateur des checks d'environnement read-only.
 *
 *     sambaedu:doctor                     # tous les checks
 *     sambaedu:doctor --tag=gpo           # filtre par tag
 *     sambaedu:doctor --tag=gpo,cache     # multi-tags (CSV)
 *     sambaedu:doctor --json              # sortie JSON (utile dans install.sh)
 *
 * Auto-discovery : scan récursif de `app/Doctor/Checks/<Tag>/*Check.php`,
 * instanciation via container Laravel (DI possible).
 *
 * Pour étendre : créer une nouvelle classe `<Something>Check` qui implémente
 * `App\Doctor\EnvironmentCheck` dans `app/Doctor/Checks/<Tag>/`. Aucun
 * registry à modifier.
 *
 * Exit codes : 0 (tout OK), 1 (warnings seulement), 2 (au moins une erreur).
 */
final class SambaEduDoctorCommand extends Command
{
    protected $signature = 'sambaedu:doctor
        {--tag= : Liste CSV de tags à exécuter (ex: gpo,cache). Par défaut : tous.}
        {--json : Sortie au format JSON au lieu du rapport texte.}';

    protected $description = 'Diagnostique les pré-requis environnementaux SambaEdu (read-only).';

    public function handle(): int
    {
        $tags = $this->parseTags();
        $checks = $this->discoverChecks($tags);

        if ($checks === []) {
            $this->error('Aucun check trouvé' . ($tags !== [] ? sprintf(' pour les tags : %s', implode(',', $tags)) : '.'));

            return 2;
        }

        $results = [];
        foreach ($checks as $check) {
            try {
                $results[] = [
                    'tag' => $check->tag(),
                    'name' => $check->name(),
                    'result' => $check->run(),
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'tag' => $check->tag(),
                    'name' => $check->name(),
                    'result' => CheckResult::error(
                        sprintf('Exception : %s', $e->getMessage()),
                        sprintf('Bug dans le check `%s`. Voir trace : %s', $check::class, $e->getFile() . ':' . $e->getLine()),
                    ),
                ];
            }
        }

        return $this->option('json')
            ? $this->renderJson($results)
            : $this->renderText($results);
    }

    /**
     * @return list<string>
     */
    private function parseTags(): array
    {
        $raw = (string) ($this->option('tag') ?? '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @param  list<string>  $tags
     * @return list<EnvironmentCheck>
     */
    private function discoverChecks(array $tags): array
    {
        $dir = app_path('Doctor/Checks');
        if (! is_dir($dir)) {
            return [];
        }

        $checks = [];
        foreach (Finder::create()->files()->in($dir)->name('*Check.php') as $file) {
            $relative = substr($file->getPathname(), strlen(app_path()) + 1, -4); // strip ext .php
            $class = 'App\\' . str_replace('/', '\\', $relative);

            if (! class_exists($class)) {
                continue;
            }
            if (! is_subclass_of($class, EnvironmentCheck::class)) {
                continue;
            }

            /** @var EnvironmentCheck $instance */
            $instance = app()->make($class);
            if ($tags !== [] && ! in_array($instance->tag(), $tags, true)) {
                continue;
            }
            $checks[] = $instance;
        }

        // Ordre stable : par tag puis par nom de classe.
        usort($checks, function (EnvironmentCheck $a, EnvironmentCheck $b): int {
            return [$a->tag(), $a::class] <=> [$b->tag(), $b::class];
        });

        return $checks;
    }

    /**
     * @param  list<array{tag: string, name: string, result: CheckResult}>  $results
     */
    private function renderText(array $results): int
    {
        $this->line('');
        $this->line(sprintf(
            '<options=bold>sambaedu:doctor</> — running as <comment>%s</comment> (uid=%d, sapi=%s)',
            get_current_user(),
            posix_geteuid(),
            PHP_SAPI,
        ));
        $this->line('');

        $currentTag = null;
        foreach ($results as $entry) {
            if ($entry['tag'] !== $currentTag) {
                $currentTag = $entry['tag'];
                $this->line(sprintf('<options=bold>[%s]</>', $currentTag));
            }
            $this->renderResultLine($entry['name'], $entry['result']);
        }

        return $this->printSummary($results);
    }

    private function renderResultLine(string $name, CheckResult $result): void
    {
        [$glyph, $color] = match ($result->level) {
            Level::Ok => ['✓', 'green'],
            Level::Warn => ['⚠', 'yellow'],
            Level::Error => ['✗', 'red'],
        };

        $this->line(sprintf('  <fg=%s>%s</> %s — %s', $color, $glyph, $name, $result->detail));
        if ($result->fix !== null) {
            $this->line(sprintf('    <fg=gray>→ %s</>', $result->fix));
        }
    }

    /**
     * @param  list<array{tag: string, name: string, result: CheckResult}>  $results
     */
    private function renderJson(array $results): int
    {
        $payload = [
            'user' => get_current_user(),
            'uid' => posix_geteuid(),
            'sapi' => PHP_SAPI,
            'checks' => array_map(fn ($e) => [
                'tag' => $e['tag'],
                'name' => $e['name'],
                'level' => $e['result']->level->value,
                'detail' => $e['result']->detail,
                'fix' => $e['result']->fix,
            ], $results),
            'summary' => $this->countByLevel($results),
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->exitCodeFromCounts($payload['summary']);
    }

    /**
     * @param  list<array{tag: string, name: string, result: CheckResult}>  $results
     */
    private function printSummary(array $results): int
    {
        $counts = $this->countByLevel($results);
        $this->line('');
        $this->line(sprintf(
            '<options=bold>Bilan :</> <fg=green>%d OK</> · <fg=yellow>%d warn</> · <fg=red>%d erreur(s)</>',
            $counts['ok'],
            $counts['warn'],
            $counts['error'],
        ));
        $this->line('');

        return $this->exitCodeFromCounts($counts);
    }

    /**
     * @param  list<array{tag: string, name: string, result: CheckResult}>  $results
     * @return array{ok: int, warn: int, error: int}
     */
    private function countByLevel(array $results): array
    {
        $counts = ['ok' => 0, 'warn' => 0, 'error' => 0];
        foreach ($results as $entry) {
            $counts[$entry['result']->level->value]++;
        }

        return $counts;
    }

    /**
     * @param  array{ok: int, warn: int, error: int}  $counts
     */
    private function exitCodeFromCounts(array $counts): int
    {
        return match (true) {
            $counts['error'] > 0 => 2,
            $counts['warn'] > 0 => 1,
            default => self::SUCCESS,
        };
    }
}
