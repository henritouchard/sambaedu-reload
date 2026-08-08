<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ExtensionInstallException;
use App\Models\ExtensionInstallRun;
use App\Services\Extensions\ExtensionInstallService;
use Illuminate\Console\Command;

/**
 * Story 56.2 (AC2, AR1) — `php artisan ext:install <key> [--source=]`.
 *
 * Façade CLI du moteur {@see ExtensionInstallService}. Elle n'a AUCUNE logique
 * propre : elle résout les arguments, délègue, et met en forme. L'UI de la
 * Story 56.3 sera une seconde façade sur le MÊME moteur — il n'existera jamais
 * deux chemins d'installation (doctrine AR1, patron `ext:sources:sync`).
 *
 * L'acteur est `null` : la commande s'exécute sans utilisateur connecté, et
 * l'audit la journalise sous l'acteur conventionnel `system` (convention posée
 * par 56.1 pour la synchro planifiée, reconduite ici).
 *
 * ⚠️ **NFR3 — aucun secret n'est affiché.** Le `client_secret` du client OIDC
 * de l'extension n'existe en clair que le temps d'être poussé sur le stdin du
 * helper : ni la sortie de cette commande, ni les journaux, ni le tableau
 * retourné par le moteur ne le portent. Un secret dans l'historique du terminal
 * est un secret perdu.
 *
 * Codes retour : `0` succès ou no-op signalé, `1` refus ou échec.
 */
class ExtensionInstall extends Command
{
    /** @var string */
    protected $signature = 'ext:install
        {key : Clé de l\'extension à installer (l\'`id` de son manifest)}
        {--source= : Clé de la source, si la même clé est publiée par plusieurs sources}';

    /** @var string */
    protected $description = "Installe une extension de type « app » (paquet vérifié, client OIDC, service, exposition Apache).";

    /** @var string */
    protected $help = <<<'HELP'
    Installe une extension applicative : vérification puis pose du paquet, création
    de son client d'authentification, mise en service, et exposition derrière Apache.

      <info>php artisan ext:install monoutil</info>
      <info>php artisan ext:install monoutil --source=depot-academique</info>

    <comment>--source</comment> ne sert qu'à lever une ambiguïté : si plusieurs sources publient la
    même clé, il faut désigner celle qu'on veut.

    ⚠️ <comment>Aucun secret n'est affiché.</comment> Le secret du client d'authentification ne
    transite que vers le composant qui en a besoin : il n'apparaît ni en sortie, ni
    dans les journaux. C'est délibéré — un secret passé dans l'historique d'un
    terminal est un secret perdu.

    Codes de retour : <info>0</info> succès, ou refus signalé sans effet · <info>1</info> échec.
    HELP;

    public function handle(ExtensionInstallService $installer): int
    {
        $key = (string) $this->argument('key');
        $source = $this->option('source');
        $source = is_string($source) && $source !== '' ? $source : null;

        try {
            $result = $installer->install($key, $source, null);
        } catch (ExtensionInstallException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['error'] !== '') {
            $this->renderSteps($result['steps']);
            $this->error('Installation refusée : '.$result['error']);
            $this->line('  Aucun état partiel n\'a été laissé : les étapes déjà faites ont été compensées.');
            $this->line('  L\'échec est consigné au journal d\'audit des extensions (action « install_failed »).');

            return self::FAILURE;
        }

        if (! $result['changed']) {
            $this->info("L'extension « {$key} » est déjà installée — aucune action.");
            if ($result['port'] !== null) {
                $this->line('  port backend : '.$result['port']);
            }
            $this->line('  Pour la retirer : php artisan ext:remove '.$key);

            return self::SUCCESS;
        }

        $this->renderSteps($result['steps']);
        $this->line('');
        $this->info('Extension « '.$key.' » installée.');
        $this->line('  exposition   : /ext/'.$key);
        $this->line('  port backend : '.($result['port'] ?? '—').' (assigné par SE5, jamais par le manifest)');
        $this->line('  unité        : sambaedu-ext-'.$key.'.service');
        $this->line('');
        $this->warn('Le secret du client OIDC n\'est PAS affiché : il n\'existe que dans '
            .'/etc/sambaedu/extensions/'.$key.'.env (0600 root) — NFR3.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $steps
     *
     * ⚠️ Story 56.3 — la map de libellés a QUITTÉ cette classe pour
     * {@see ExtensionInstallService::stepLabels()} : l'UI en avait besoin, et
     * la dupliquer aurait garanti la divergence (leçon review 56.1 #3). Les
     * libellés sont repris verbatim — la sortie de cette commande est
     * inchangée, un test le verrouille.
     */
    private function renderSteps(array $steps): void
    {
        $labels = ExtensionInstallService::stepLabels(ExtensionInstallRun::OPERATION_INSTALL);

        foreach ($steps as $step) {
            $this->line('  ✔ '.($labels[$step] ?? $step));
        }
    }
}
