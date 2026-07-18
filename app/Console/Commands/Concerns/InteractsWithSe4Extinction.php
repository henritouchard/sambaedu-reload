<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\LegacyCatchallLog;
use Illuminate\Support\Facades\Process;

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
     * Seam de test : force le résultat de la garde root (null = euid réel).
     */
    public static ?bool $assumeRoot = null;

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
     * Affiche l'état de la bascule + le rapport d'observation, et retourne
     * le verdict GO (true) / NO-GO (false). `$vhostState` évite un second
     * a2query quand l'appelant connaît déjà l'état.
     */
    protected function renderStatus(int $days, ?bool $vhostState = null): bool
    {
        $legacyPath = $this->legacyPath();
        $offPath = $this->offPath();
        $vhost = $vhostState ?? $this->vhostEnabled();

        $this->line('État de la bascule :');
        $this->line(sprintf('  vhost sambaedu-legacy : %s', match ($vhost) {
            true => 'ACTIF',
            false => 'inactif',
            null => 'INDÉTERMINÉ (a2query introuvable)',
        }));
        $this->line(sprintf('  %s : %s', $legacyPath, is_dir($legacyPath) ? 'présent' : 'absent'));
        $this->line(sprintf('  %s : %s', $offPath, is_dir($offPath) ? 'présent (extinction à blanc en place)' : 'absent'));
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
