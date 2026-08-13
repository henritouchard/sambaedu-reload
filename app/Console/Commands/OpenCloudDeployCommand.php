<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OpenCloud\Deployment\DeploymentOutcome;
use App\Services\OpenCloud\Deployment\OpenCloudDeploymentService;
use Illuminate\Console\Command;

/**
 * LA COMMANDE D'EXPLOITATION de l'instance OpenCloud.
 *
 * **Pourquoi une commande, et pas une procédure.** Une suite de gestes à rejouer
 * à la main sur chaque serveur diverge dès le deuxième — c'est une règle du
 * dépôt : toute opération multi-instance est une commande d'administration. Ici
 * elle porte en plus une garantie que personne ne tiendrait à la main :
 * l'idempotence, et le refus de tout geste destructeur.
 *
 * Les quatre actions sont celles du seam privilégié, et il n'y en aura pas une
 * cinquième nommée « supprimer » : le helper n'a aucun verbe pour cela, donc
 * cette commande ne peut structurellement pas détruire une instance.
 */
final class OpenCloudDeployCommand extends Command
{
    protected $signature = 'opencloud:deploy
        {action=deploy : Action à jouer — deploy, status, stop ou logs}
        {--url= : URL publique de l\'instance, en https (celle que le frontal expose)}
        {--port= : Port d\'écoute local du conteneur (défaut : celui de la configuration)}
        {--lines=100 : Nombre de lignes de journal à afficher (action logs)}
        {--dry-run : N\'exécute rien et affiche ce qui serait fait}';

    protected $description = "Déploie et pilote l'instance OpenCloud en conteneur sur ce serveur";

    protected $help = <<<'HELP'
Monte l'instance OpenCloud dans un conteneur, sur ce serveur, derrière le frontal
web existant : le conteneur écoute en HTTP sur la boucle locale et la terminaison
TLS reste assurée par le frontal.

QUATRE ACTIONS

  deploy   Fait converger l'instance vers l'état voulu. Rejouable sans risque :
           une instance déjà conforme n'est pas recréée et aucune donnée n'est
           touchée. Exige --url.
  status   Affiche l'état lisible : conteneur attendu et présent, état, port,
           sonde de santé, volumes.
  stop     Arrête le conteneur. Les volumes et les données restent en place.
  logs     Affiche les dernières lignes du journal du conteneur (--lines).

CE QUE CETTE COMMANDE NE FAIT JAMAIS

  * elle ne supprime ni conteneur, ni volume, ni donnée : aucune action ne le
    permet, et le script privilégié n'a aucun verbe pour cela ;
  * elle n'active pas l'accès OpenCloud. Une instance déployée ne devient pas
    l'autorité d'écriture d'un répertoire parce qu'elle existe : activez la
    capacité sur Administration › Fichiers, vérifiez la connexion, puis choisissez
    cette autorité à la CRÉATION d'un répertoire ;
  * elle ne crée ni compte, ni groupe, ni partage.

MOT DE PASSE D'ADMINISTRATION

  Il est généré au premier déploiement et rangé chiffré dans le magasin de
  secrets. Il n'est jamais affiché, jamais écrit dans un fichier lisible et
  jamais journalisé. Il n'est utilisé qu'à la toute première initialisation ;
  ensuite, l'instance vit sur sa propre configuration.

L'URL DOIT ÊTRE EN HTTPS

  Même derrière un frontal qui termine le TLS : le service d'identité de
  l'instance refuse de démarrer sinon, et un premier démarrage manqué laisse un
  annuaire interne dont l'instance ne se relève pas.

EXEMPLES

  php artisan opencloud:deploy --url=https://fichiers.mon-etab.fr
  php artisan opencloud:deploy --url=https://fichiers.mon-etab.fr --dry-run
  php artisan opencloud:deploy status
  php artisan opencloud:deploy logs --lines=200
  php artisan opencloud:deploy stop

CODES DE RETOUR

  0  état atteint — « déployé » ou « déjà conforme », la sortie le dit
  2  refus nommé (URL absente ou non https, port déjà pris, instance qui ne
     répond pas, script privilégié absent ou non autorisé)
HELP;

    public function handle(OpenCloudDeploymentService $service): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'deploy' => $this->runDeploy($service),
            'status' => $this->render($service->status()),
            'stop' => $this->render($service->stop()),
            'logs' => $this->renderLogs($service),
            default => $this->refuse(sprintf(
                'Action inconnue « %s » (attendu : deploy, status, stop, logs).',
                $action,
            )),
        };
    }

    private function runDeploy(OpenCloudDeploymentService $service): int
    {
        $url = trim((string) ($this->option('url') ?? ''));

        if ($url === '') {
            return $this->refuse(
                'L\'option --url est obligatoire : elle porte l\'adresse publique sous laquelle le frontal '
                . 'expose l\'instance (exemple : --url=https://fichiers.mon-etab.fr).',
            );
        }

        $port = (int) ($this->option('port') ?: config('opencloud.port', 9200));
        $dryRun = (bool) $this->option('dry-run');

        $report = $service->deploy($port, $url, $dryRun);

        if ($dryRun && ! $report->isFailure()) {
            $this->info('Mode sans écriture — rien n\'a été exécuté. Ce qui serait fait :');
            foreach ($report->steps as $step) {
                $this->line('  · ' . $step);
            }
            $this->newLine();
        }

        return $this->render($report);
    }

    private function renderLogs(OpenCloudDeploymentService $service): int
    {
        $lines = max(1, min(9999, (int) $this->option('lines')));

        foreach ($service->logs($lines) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function render(\App\Services\OpenCloud\Deployment\DeploymentReport $report): int
    {
        if ($report->isFailure()) {
            $this->error($report->message);

            return DeploymentOutcome::Failed->exitCode();
        }

        $report->outcome === DeploymentOutcome::Conforming
            ? $this->line($report->message)
            : $this->info($report->message);

        if ($report->facts !== []) {
            $this->newLine();
            foreach ($report->facts as $key => $value) {
                $this->line(sprintf('  %-16s %s', $key, $value));
            }
        }

        return $report->outcome->exitCode();
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return DeploymentOutcome::Failed->exitCode();
    }
}
