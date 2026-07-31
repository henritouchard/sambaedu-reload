<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ExtensionInstallException;
use App\Models\ExtensionInstallRun;
use App\Services\Extensions\ExtensionInstallService;
use Illuminate\Console\Command;

/**
 * Story 56.2 (AC5, FR10 volet `app`) — `php artisan ext:remove <key>`.
 *
 * Façade CLI de {@see ExtensionInstallService::remove()}. Strictement l'ordre
 * inverse de l'installation, chaque étape tolérante à l'absent : la commande
 * est donc à la fois la désinstallation nominale ET l'outil de nettoyage d'un
 * état dégradé — elle se rejoue sans risque.
 *
 * ⚠️ Une extension de type `link` est REFUSÉE avec un message qui pointe la
 * bibliothèque : le volet `link` de FR10 est déjà livré par la Story 54.2, et
 * le dupliquer ici créerait deux chemins d'audit pour le même acte
 * (décision 56.2 #4).
 *
 * Codes retour : `0` succès ou no-op signalé, `1` refus ou échec.
 */
class ExtensionRemove extends Command
{
    /** @var string */
    protected $signature = 'ext:remove
        {key : Clé de l\'extension « app » à désinstaller}';

    /** @var string */
    protected $description = "Désinstalle une extension de type « app » (service, Apache, paquet, environnement, client OIDC).";

    public function handle(ExtensionInstallService $installer): int
    {
        $key = (string) $this->argument('key');

        try {
            $result = $installer->remove($key, null);
        } catch (ExtensionInstallException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['error'] !== '') {
            $this->renderSteps($result['steps']);
            $this->error('Désinstallation interrompue : '.$result['error']);
            $this->line('  L\'extension reste marquée installée — relancez la commande, '
                .'chaque étape tolère un composant déjà absent.');

            return self::FAILURE;
        }

        if (! $result['changed']) {
            $this->info("L'extension « {$key} » n'est pas installée — aucune action.");

            return self::SUCCESS;
        }

        $this->renderSteps($result['steps']);
        $this->line('');
        $this->info('Extension « '.$key.' » désinstallée : /ext/'.$key.' n\'est plus exposé, '
            .'la tuile disparaît du lanceur.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $steps
     *
     * ⚠️ Story 56.3 — map remontée dans {@see ExtensionInstallService::stepLabels()},
     * libellés verbatim : sortie inchangée (leçon review 56.1 #3).
     */
    private function renderSteps(array $steps): void
    {
        $labels = ExtensionInstallService::stepLabels(ExtensionInstallRun::OPERATION_REMOVE);

        foreach ($steps as $step) {
            $this->line('  ✔ '.($labels[$step] ?? $step));
        }
    }
}
