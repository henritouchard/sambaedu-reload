<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agent\Tools\AgentToolException;
use App\Services\Agent\Tools\AgentToolService;
use Illuminate\Console\Command;

/**
 * Story 27.17 — Enregistre les OUTILS AGENT OBLIGATOIRES embarqués dans le dépôt.
 *
 * Aujourd'hui : le portable Rainmeter `resources/agent/tools/sambaedu-rainmeter-*.zip`.
 * Appelée par le provisioning serveur (`install.sh`/`update.sh`) pour garantir
 * la présence de l'outil même sur une instance greenfield où personne n'a uploadé
 * le portable via l'UI.
 *
 * FAIL-SOFT : un échec (source absente, structure invalide, stockage indisponible)
 * NE casse JAMAIS l'install/update — la commande sort en SUCCESS avec un warning.
 * Idempotent : si la clé `rainmeter` existe déjà, no-op (l'admin reste maître du
 * contenu uploadé).
 */
final class AgentToolsRegisterDefaultsCommand extends Command
{
    protected $signature = 'agent:tools:register-defaults
        {--path= : Chemin du portable embarqué (défaut : resources/agent/tools/sambaedu-rainmeter-*.zip)}';

    protected $description = 'Enregistre les outils agent obligatoires embarqués (portable Rainmeter) — idempotent, fail-soft.';

    public function handle(AgentToolService $service): int
    {
        $path = $this->option('path');
        if ($path === null || $path === '') {
            $path = $this->discoverEmbeddedRainmeter();
        }

        if ($path === null) {
            $this->warn('Aucun portable Rainmeter embarqué trouvé (resources/agent/tools/sambaedu-rainmeter-*.zip) — étape ignorée.');

            // FAIL-SOFT : pas de source résolvable ⇒ warning, jamais d'échec.
            return self::SUCCESS;
        }

        $version = $this->extractVersion($path) ?? '0';

        try {
            $tool = $service->registerEmbedded($path, $version);
        } catch (AgentToolException $e) {
            // FAIL-SOFT : on n'échoue pas l'install/update (P : « un required sans
            // source résolvable → warning explicite, JAMAIS échec »).
            $this->warn(sprintf(
                'Enregistrement du portable Rainmeter ignoré (%s) — l\'outil pourra être uploadé via l\'UI.',
                $e->reason,
            ));

            return self::SUCCESS;
        }

        if ($tool === null) {
            $this->info('Portable Rainmeter déjà présent dans le catalogue (clé « rainmeter ») — aucune action.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Portable Rainmeter enregistré : %s (SHA-256 %s, désactivé par défaut — activez-le pour le déployer).',
            $tool->filename,
            substr((string) $tool->sha256, 0, 12),
        ));

        return self::SUCCESS;
    }

    /**
     * Trouve le portable embarqué `resources/agent/tools/sambaedu-rainmeter-*.zip`
     * (le plus récent par tri du nom si plusieurs).
     */
    private function discoverEmbeddedRainmeter(): ?string
    {
        $dir = base_path('resources/agent/tools');
        $matches = glob($dir . DIRECTORY_SEPARATOR . 'sambaedu-rainmeter-*.zip') ?: [];
        if ($matches === []) {
            return null;
        }

        sort($matches);

        return (string) end($matches);
    }

    /**
     * Extrait la version du filename `sambaedu-rainmeter-<version>.zip`.
     */
    private function extractVersion(string $path): ?string
    {
        $base = basename($path);
        if (preg_match('/^sambaedu-rainmeter-([0-9A-Za-z.+~-]+)\.zip$/', $base, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
