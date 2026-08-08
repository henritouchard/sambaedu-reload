<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agent\Releases\ReleaseCreationService;
use App\Services\Agent\Releases\ReleaseOperationException;
use Illuminate\Console\Command;

/**
 * Story 25.1 — Déplacement du pointeur stable (AC1, décision n° 5).
 *
 * La stable est la version servie aux postes SANS ring (AC3 — jamais une
 * canari par accident). Au plus une ligne à true : swap transactionnel dans
 * {@see ReleaseCreationService::promote()}. C'est aussi le rollback du
 * défaut parc avant l'UI 25.5. Commande à la demande (pas d'entrée Kernel).
 */
class AgentReleasePromoteCommand extends Command
{
    protected $signature = 'agent:release:promote
        {version : Version à promouvoir stable (doit exister dans agent_releases)}';

    protected $description = 'Promeut une release agent comme version stable (défaut des postes sans ring)';

    protected $help = <<<'HELP'
    Désigne une version déjà publiée comme la version STABLE de l'agent — celle que
    prennent tous les postes qui n'appartiennent à aucun ring.

    Il y a au plus une stable à la fois : la promotion bascule le pointeur de façon
    transactionnelle, sans état intermédiaire où deux versions seraient stables.

      <info>php artisan agent:release:promote 2.16.0</info>

    C'est aussi le geste de RETOUR ARRIÈRE : promouvoir à nouveau la version
    précédente ramène l'ensemble du parc non ringué sur celle-ci.

    La version doit déjà exister — publiez-la d'abord avec <info>agent:release:create</info>.
    HELP;

    public function handle(ReleaseCreationService $releases): int
    {
        try {
            $release = $releases->promote((string) $this->argument('version'));
        } catch (ReleaseOperationException $e) {
            $this->error(sprintf('Promotion refusée (%s) : %s', $e->reason, $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf('Release %s promue stable (pointeur déplacé).', $release->version));

        return self::SUCCESS;
    }
}
