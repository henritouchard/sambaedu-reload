<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agent\Releases\ReleaseCreationService;
use App\Services\Agent\Releases\ReleaseOperationException;
use Illuminate\Console\Command;

/**
 * Story 25.1 — Publication d'une release agent (AC1, décision n° 1 :
 * création = commande artisan, l'UI rings/releases = 25.5).
 *
 * Le `--hash` est OBLIGATOIRE : c'est la valeur produite par le pipeline de
 * build (24.5, `sha256sum agent/build/dist/sambaedu-agent-<v>.exe`) que le
 * serveur contre-vérifie sur le fichier réel de
 * `config('agent.releases_path')`. Toute incohérence = refus, AUCUNE ligne
 * écrite, exit ≠ 0 ({@see ReleaseCreationService::create()}).
 *
 * Commande à la demande (pas d'entrée Kernel), mince autour du service.
 */
class AgentReleaseCreateCommand extends Command
{
    protected $signature = 'agent:release:create
        {version : Version publiée (ex. 2.1.2 — shared/version.go)}
        {filename : Nom du binaire déposé dans storage/agent/releases/ (sambaedu-agent-<version>.exe)}
        {--hash= : SHA-256 attendu du binaire (sortie du pipeline de build) — OBLIGATOIRE}
        {--stable : Marque la release stable (au plus une — swap transactionnel)}';

    protected $description = 'Publie une release agent après contre-vérification du SHA-256 du binaire (refus si artefact incohérent)';

    public function handle(ReleaseCreationService $releases): int
    {
        $hash = trim((string) $this->option('hash'));
        if ($hash === '') {
            $this->error('--hash est obligatoire : SHA-256 produit par le pipeline de build, contre-vérifié sur le fichier réel.');

            return self::FAILURE;
        }

        try {
            $release = $releases->create(
                (string) $this->argument('version'),
                (string) $this->argument('filename'),
                $hash,
                (bool) $this->option('stable'),
            );
        } catch (ReleaseOperationException $e) {
            $this->error(sprintf('Release refusée (%s) : %s', $e->reason, $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Release %s publiée (%s, hash vérifié%s).',
            $release->version,
            $release->filename,
            $release->is_stable ? ', stable' : '',
        ));

        return self::SUCCESS;
    }
}
