<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\LegacyCatchallLog;
use App\Services\LegacyGpoNeutralizationInspector;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Story 38.6 — Logique partagée des commandes d'extinction `se4:*`.
 *
 * Regroupe l'état de la bascule (vhost legacy, dossiers), le rapport
 * d'observation sur `legacy_catchall_logs` et le verdict GO/NO-GO.
 * Le critère GO ne compte que les hits du canal client legacy : `source`
 * non-tombstone sur un endpoint `.php` sous un répertoire racine du legacy
 * (allowlist ci-dessous). Les hits `source='tombstone'` sont des réponses
 * natives inertes (38.2) et ne bloquent pas ; le reste (404 de navigation
 * SE5, sondes de scanners type /wp-login.php) est du bruit — listé dans le
 * rapport pour contrôle humain, mais hors verdict.
 */
trait InteractsWithSe4Extinction
{
    /**
     * Répertoires racine du FS legacy (constatés sur le checkout SE4) sous
     * lesquels vivent les endpoints du canal client. Un `.php` hors de ces
     * répertoires ne peut pas être un hit du troupeau.
     */
    private const LEGACY_CHANNEL_DIRS = [
        'acls', 'admin', 'annu', 'annu2', 'api', 'api2', 'bbb', 'cas',
        'central', 'cloud', 'config', 'dhcp', 'display', 'dossier_echange',
        'elements', 'google', 'gpo', 'includes', 'infos', 'ipxe', 'metrics',
        'oauth2', 'parcs', 'parcs2', 'partages', 'printers', 'sso', 'stats',
        'user', 'visio', 'wpkg',
    ];

    /**
     * Scorie `.env` post-38.2 : la clé config a été supprimée, la ligne ne
     * doit plus exister sur les instances (trompeuse).
     */
    private const LEGACY_ENV_SCORIE_KEY = 'LEGACY_CONFIG_CHANNEL_ENABLED';

    /**
     * Seam de test : force le résultat de la garde root (null = euid réel).
     */
    public static ?bool $assumeRoot = null;

    /**
     * Seam de test : chemin `.env` (null = `base_path('.env')`) — patron
     * ManagesScriptLoggingFlag, JAMAIS le `.env` réel dans les tests.
     */
    public static ?string $envPath = null;

    /**
     * Seam de test : fichiers du vhost SER inspectés par le préflight
     * (null = les chemins système). Voir {@see serVhostPaths()}.
     *
     * @var list<string>|null
     */
    public static ?array $serVhostPaths = null;

    /**
     * Vhost SER, dans les DEUX emplacements. `sites-enabled/sambaedu.conf`
     * n'est pas toujours un symlink vers `sites-available` : setupApache.sh
     * gère les deux cas et setupXsendfile.sh ne patche que le fichier
     * *enabled*. Les deux peuvent donc diverger, et c'est le *enabled* qu'Apache
     * sert réellement — inspecter l'un sans l'autre laisse passer le cas.
     *
     * @return list<string>
     */
    protected function serVhostPaths(): array
    {
        return static::$serVhostPaths ?? [
            '/etc/apache2/sites-enabled/sambaedu.conf',
            '/etc/apache2/sites-available/sambaedu.conf',
        ];
    }

    protected function ensureRoot(): bool
    {
        $isRoot = static::$assumeRoot
            ?? (function_exists('posix_geteuid') && posix_geteuid() === 0);

        if (! $isRoot) {
            $this->error('Cette commande doit être exécutée en root (a2dissite/systemctl/mv sur le système).');
        }

        return $isRoot;
    }

    protected function legacyPath(): string
    {
        return rtrim((string) config('sambaedu.legacy_path'), '/');
    }

    protected function offPath(): string
    {
        return $this->legacyPath() . '.off';
    }

    /**
     * Garde : `sambaedu.legacy_path` doit être configuré et absolu, sinon
     * `offPath()` deviendrait un chemin relatif au cwd.
     */
    protected function ensureLegacyPathConfigured(): bool
    {
        $path = $this->legacyPath();

        if ($path === '' || ! str_starts_with($path, '/')) {
            $this->error('Config sambaedu.legacy_path vide ou non absolue — abandon.');

            return false;
        }

        return true;
    }

    /**
     * Le vhost legacy est-il activé ? (`a2query -s` sort 0 si le site est
     * enabled.) Null si l'état est indéterminable : `a2query` introuvable
     * (exit 127) — à distinguer d'un site désactivé, sinon une extinction
     * partirait en croyant le vhost déjà inactif.
     */
    protected function vhostEnabled(): ?bool
    {
        $result = Process::run('a2query -s sambaedu-legacy');

        if ($result->exitCode() === 127) {
            return null;
        }

        return $result->successful();
    }

    /**
     * `systemctl reload apache2`, avec remontée d'erreur. Les commandes de
     * bascule l'exécutent SYSTÉMATIQUEMENT (y compris quand le vhost semble
     * déjà dans l'état cible) : un run précédent interrompu entre
     * a2dissite/a2ensite et le reload laisse le symlink à jour mais Apache
     * sur l'ancienne config — seul un reload inconditionnel converge.
     */
    protected function reloadApache(): bool
    {
        $result = Process::run('systemctl reload apache2');

        if (! $result->successful()) {
            $this->error('Échec systemctl reload apache2 : ' . trim($result->errorOutput() ?: $result->output()));

            return false;
        }

        return true;
    }

    /**
     * Directives du vhost SER qui pointent ENCORE dans l'arbre legacy.
     *
     * C'est le garde-fou qui manquait : jusqu'à la Story 38.1 le vhost SER
     * portait `Alias /ipxe <legacy>/ipxe`. Sur une instance dont le vhost date
     * d'avant, déplacer le FS legacy fait disparaître la cible de l'Alias ET le
     * bloc `<Directory>` qui portait le `FallbackResource /index.php` : ce ne
     * sont pas seulement les statiques iPXE qui tombent, ce sont TOUTES les
     * routes Laravel `/ipxe/*` (boot, admin, enrollment), l'Alias
     * court-circuitant le DocumentRoot avant que Laravel soit atteint. Résultat
     * silencieux et maximal : plus aucun poste du parc ne démarre en PXE, et
     * `se4:replug` répare « par accident », ce qui masque la cause.
     *
     * Piège de la regex : `sambaedu-reload` a le même préfixe que `sambaedu`.
     * Le `(?![\w.-])` refuse le `-` (et `.`, `_`, alphanum) pour ne matcher que
     * l'arbre legacy. Lignes commentées ignorées : les vhosts générés
     * commentent abondamment les anciens chemins.
     *
     * @return list<string> Lignes « fichier:numéro: directive », vide si sain.
     */
    protected function serVhostLegacyDirectives(): array
    {
        $legacyPath = $this->legacyPath();

        if ($legacyPath === '') {
            return [];
        }

        $pattern = '#^\s*(?:Alias|AliasMatch|ScriptAlias|ScriptAliasMatch|DocumentRoot|<Directory)\s.*'
            . preg_quote($legacyPath, '#')
            . '(?![\w.-])#i';

        $found = [];

        foreach ($this->serVhostPaths() as $path) {
            if (! File::exists($path) || ! is_file($path)) {
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', File::get($path)) ?: [];

            foreach ($lines as $index => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $found[] = sprintf('%s:%d: %s', $path, $index + 1, trim($line));
                }
            }
        }

        return $found;
    }

    protected function envFilePath(): string
    {
        return static::$envPath ?? base_path('.env');
    }

    protected function legacyEnvScoriePresent(): bool
    {
        $path = $this->envFilePath();

        if (! File::exists($path)) {
            return false;
        }

        return preg_match('/^' . self::LEGACY_ENV_SCORIE_KEY . '=/m', File::get($path)) === 1;
    }

    /**
     * Retire la ligne scorie du `.env` (non destructif, ancré ligne par
     * ligne — le reste du fichier est préservé byte-pour-byte).
     */
    protected function removeLegacyEnvScorie(): bool
    {
        $path = $this->envFilePath();
        $contents = File::get($path);
        $cleaned = preg_replace('/^' . self::LEGACY_ENV_SCORIE_KEY . '=[^\r\n]*\r?\n?/m', '', $contents, 1);

        if ($cleaned === null) {
            return false; // erreur PCRE — ne JAMAIS écrire un .env vidé
        }

        File::put($path, $cleaned);

        return true;
    }

    /**
     * Migrations en attente (affichage pré-GO ; jamais bloquant — hygiène de
     * déploiement, pas une condition d'extinction).
     *
     * @return list<string>
     */
    protected function pendingMigrations(): array
    {
        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return [];
            }

            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();

            return array_values(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{status: string, detail: string}
     */
    protected function gpoApplicationsStatus(): array
    {
        return app(LegacyGpoNeutralizationInspector::class)->inspect();
    }

    /**
     * Agrège la fenêtre d'observation et classe chaque ligne.
     *
     * @return array{
     *     legacy: list<object>,
     *     tombstone: list<object>,
     *     noise: list<object>,
     *     go: bool,
     * }
     */
    protected function observationReport(int $days): array
    {
        $rows = LegacyCatchallLog::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('source, path, ip, COUNT(*) as hits, MAX(created_at) as last_seen')
            ->groupBy('source', 'path', 'ip')
            ->orderByDesc('last_seen')
            ->get();

        $report = ['legacy' => [], 'tombstone' => [], 'noise' => []];

        foreach ($rows as $row) {
            $report[$this->classifyHit($row->source, $row->path)][] = $row;
        }

        $report['go'] = $report['legacy'] === [];

        return $report;
    }

    /**
     * @return 'legacy'|'tombstone'|'noise'
     */
    protected function classifyHit(?string $source, string $path): string
    {
        if ($source === 'tombstone') {
            return 'tombstone';
        }

        $pattern = sprintf(
            '#^(%s)/.+\.php$#i',
            implode('|', array_map(preg_quote(...), self::LEGACY_CHANNEL_DIRS)),
        );

        return preg_match($pattern, ltrim($path, '/')) === 1 ? 'legacy' : 'noise';
    }

    /**
     * Affiche l'état de la bascule + les checks pré-GO + le rapport
     * d'observation, et retourne le verdict GO (true) / NO-GO (false).
     * `$vhostState` et `$gpoStatus` évitent une double interrogation quand
     * l'appelant connaît déjà l'état.
     *
     * @param  array{status: string, detail: string}|null  $gpoStatus
     */
    protected function renderStatus(int $days, ?bool $vhostState = null, ?array $gpoStatus = null): bool
    {
        $legacyPath = $this->legacyPath();
        $offPath = $this->offPath();
        $vhost = $vhostState ?? $this->vhostEnabled();
        $gpo = $gpoStatus ?? $this->gpoApplicationsStatus();

        $this->line('État de la bascule :');
        $this->line(sprintf('  vhost sambaedu-legacy : %s', match ($vhost) {
            true => 'ACTIF',
            false => 'inactif',
            null => 'INDÉTERMINÉ (a2query introuvable)',
        }));
        $this->line(sprintf('  %s : %s', $legacyPath, is_dir($legacyPath) ? 'présent' : 'absent'));
        $this->line(sprintf('  %s : %s', $offPath, is_dir($offPath) ? 'présent (extinction à blanc en place)' : 'absent'));
        $this->newLine();

        $pending = $this->pendingMigrations();

        $vhostLegacyDirectives = $this->serVhostLegacyDirectives();

        $this->line('Checks pré-GO :');
        $this->line(sprintf('  migrations en attente : %s', $pending === [] ? 'aucune' : count($pending) . ' — lancer php artisan migrate'));
        $this->line(sprintf(
            '  vhost SER pointant dans le legacy : %s',
            $vhostLegacyDirectives === []
                ? 'aucune directive'
                : count($vhostLegacyDirectives) . ' — vhost antérieur à la Story 38.1',
        ));

        foreach ($vhostLegacyDirectives as $directive) {
            $this->line('    ' . $directive);
        }

        if ($vhostLegacyDirectives !== []) {
            $this->line(sprintf('    Réparer : bash %s', base_path('scripts/setupApache.sh')));
        }

        $this->line(sprintf('  scorie .env %s : %s', self::LEGACY_ENV_SCORIE_KEY, $this->legacyEnvScoriePresent() ? 'PRÉSENTE (retirée par se4:unplug)' : 'absente'));
        $this->line(sprintf('  GPO domaine « applications » : %s — %s', match ($gpo['status']) {
            LegacyGpoNeutralizationInspector::STATUS_NEUTRALIZED => 'neutralisée pour ce collège',
            LegacyGpoNeutralizationInspector::STATUS_ABSENT => 'absente du domaine',
            LegacyGpoNeutralizationInspector::STATUS_APPLIES => 'S\'APPLIQUE ENCORE',
            default => 'INDÉTERMINÉ',
        }, $gpo['detail']));
        $this->newLine();

        $report = $this->observationReport($days);

        $this->line(sprintf('Observation sur %d jour(s) :', $days));
        $this->renderHitGroup('Hits legacy (bloquants)', $report['legacy']);
        $this->renderHitGroup('Hits tombstone (inertes, non bloquants)', $report['tombstone']);
        $this->renderHitGroup('Bruit (404 navigation SE5, scanners — non bloquant)', $report['noise']);
        $this->newLine();

        if ($report['go']) {
            $this->info(sprintf('Verdict : GO — aucun hit legacy non-tombstone sur %d jour(s).', $days));
        } else {
            $this->error(sprintf(
                'Verdict : NO-GO — %d route(s) legacy encore appelée(s) sur %d jour(s).',
                count($report['legacy']),
                $days,
            ));
        }

        return $report['go'];
    }

    /**
     * @param  list<object>  $rows
     */
    private function renderHitGroup(string $label, array $rows): void
    {
        $this->line(sprintf('  %s : %d', $label, array_sum(array_map(fn ($r) => (int) $r->hits, $rows))));

        foreach ($rows as $row) {
            $this->line(sprintf(
                '    %s  ×%d  ip=%s  dernier=%s',
                $row->path,
                $row->hits,
                $row->ip,
                $row->last_seen,
            ));
        }
    }
}
