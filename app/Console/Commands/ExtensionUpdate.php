<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ExtensionStatus;
use App\Exceptions\ExtensionInstallException;
use App\Models\ExtensionInstallRun;
use App\Services\Extensions\ExtensionInstallService;
use Illuminate\Console\Command;

/**
 * Story 56.3 (AC3, AR1) — `php artisan ext:update <key>`.
 *
 * Troisième façade CLI sur le MÊME moteur ({@see ExtensionInstallService}),
 * strictement sans logique propre : elle résout l'argument, délègue, met en
 * forme. La doctrine AR1 est tenue de bout en bout — l'UI de la Story 56.3
 * appelle exactement `update()`, il n'existe pas deux chemins de mise à jour.
 *
 * Ce que la commande met à jour, c'est **le paquet et le service**, rien
 * d'autre : le port, le fragment Apache, le fichier d'environnement et le
 * client OIDC sont des invariants de la clé, pas de la version (cf. le docblock
 * de {@see ExtensionInstallService::update()}).
 *
 * ⚠️ **NFR3 — aucun secret n'est affiché.** Rien n'est régénéré, donc rien à
 * afficher : contrairement à `ext:install`, la mise à jour ne touche même pas
 * au client OIDC.
 *
 * Codes retour : `0` succès ou no-op signalé, `1` refus ou échec.
 */
class ExtensionUpdate extends Command
{
    /** @var string */
    protected $signature = 'ext:update
        {key : Clé de l\'extension « app » à mettre à jour (l\'`id` de son manifest)}';

    /** @var string */
    protected $description = "Met à jour une extension « app » installée vers la version publiée par sa source.";

    public function handle(ExtensionInstallService $installer): int
    {
        $key = (string) $this->argument('key');

        try {
            $result = $installer->update($key, null);
        } catch (ExtensionInstallException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['error'] !== '') {
            $this->renderSteps($result['steps']);
            $this->error('Mise à jour refusée : '.$result['error']);

            // ⚠️ Review 56.3 #1 — cette ligne était inconditionnelle : elle
            // affirmait le rétablissement même quand le rollback venait
            // d'échouer. Un message rassurant qui peut être faux est pire que
            // pas de message : c'est celui-là qu'un opérateur croit.
            if ($result['error'] === ExtensionInstallService::ERROR_ROLLBACK_FAILED) {
                $this->line('  ⚠️  Le service peut être ARRÊTÉ. Vérifier : systemctl status sambaedu-ext-'.$key);
            } else {
                $this->line('  La version précédente a été rétablie : le service tourne toujours.');
            }

            $this->line('  L\'échec est consigné au journal d\'audit des extensions (action « update_failed »).');

            return self::FAILURE;
        }

        if (! $result['changed']) {
            // Deux no-op distincts, tous deux légitimes et tous deux sans acte :
            // « rien d'installé » et « déjà à jour ». Le statut retourné par le
            // moteur les sépare, sans que la commande ait à réinterroger la base.
            if ($result['status'] !== ExtensionStatus::Integrated->value) {
                $this->info("L'extension « {$key} » n'est pas installée — rien à mettre à jour.");
                $this->line('  Pour l\'installer : php artisan ext:install '.$key);

                return self::SUCCESS;
            }

            $this->info("L'extension « {$key} » est déjà à la version publiée par sa source — aucune action.");
            $this->line('  Synchronisez les sources si vous attendez une version plus récente : '
                .'php artisan ext:sources:sync');

            return self::SUCCESS;
        }

        $this->renderSteps($result['steps']);
        $this->line('');
        $this->info('Extension « '.$key.' » mise à jour.');
        $this->line('  exposition   : /ext/'.$key.' (inchangée)');
        $this->line('  port backend : '.($result['port'] ?? '—').' (inchangé — invariant de la clé, pas de la version)');

        return self::SUCCESS;
    }

    /** @param  list<string>  $steps */
    private function renderSteps(array $steps): void
    {
        $labels = ExtensionInstallService::stepLabels(ExtensionInstallRun::OPERATION_UPDATE);

        foreach ($steps as $step) {
            $this->line('  ✔ '.($labels[$step] ?? $step));
        }
    }
}
