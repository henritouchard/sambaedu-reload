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

    protected $help = <<<'HELP'
    Publie une version de l'agent : enregistre en base le binaire déjà déposé dans le
    répertoire des releases, après avoir recalculé son SHA-256 et l'avoir confronté à
    celui que vous fournissez.

    L'option <info>--hash</info> est OBLIGATOIRE : c'est l'empreinte produite par la
    chaîne de compilation. Si elle ne correspond pas au fichier réellement présent,
    la commande REFUSE de publier — aucune ligne n'est écrite. C'est la garantie
    qu'un artefact corrompu ou substitué n'atteint jamais le parc.

      <info>php artisan agent:release:create 2.16.0 sambaedu-agent-2.16.0.exe --hash=<sha256></info>
      <info>php artisan agent:release:create 2.16.0 sambaedu-agent-2.16.0.exe --hash=<sha256> --stable</info>

    <comment>--stable</comment> publie ET désigne la version comme stable dans le même geste ;
    il y a au plus une stable à la fois, le basculement est transactionnel.

    Publier ne déploie rien par soi-même : les postes sans ring prendront la stable,
    les autres la version ciblée sur leur ring.
    HELP;

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
