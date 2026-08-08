<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CompareLegacyLaravelCommand extends Command
{
    protected $signature = 'compare:legacy-laravel 
                            {action? : Action à tester (1-7, all, ou laissez vide pour le menu)}
                            {--legacy : Exécuter uniquement le test legacy}
                            {--laravel : Exécuter uniquement le test Laravel}
                            {--compare : Exécuter uniquement le script de comparaison}
                            {--show-logs : Afficher tous les logs même en cas de succès}';

    protected $description = 'Compare le comportement Legacy vs Laravel pour les opérations AD';

    protected $help = <<<'HELP'
    Banc de comparaison : rejoue une même opération d'annuaire côté SE4 puis côté SE5,
    et confronte ce que chacun a réellement écrit dans l'Active Directory.

    Sept opérations sont couvertes, portant sur les groupes de postes (création,
    renommage, suppression, déplacement…). Sans argument, un menu les propose.

      <info>php artisan compare:legacy-laravel</info>          menu interactif
      <info>php artisan compare:legacy-laravel 1</info>        l'opération n° 1
      <info>php artisan compare:legacy-laravel all</info>      toutes
      <info>php artisan compare:legacy-laravel 3 --compare</info>   rejouer la seule comparaison

    <comment>--legacy</comment> et <comment>--laravel</comment> n'exécutent qu'un seul des deux côtés ; <comment>--show-logs</comment>
    affiche la sortie complète même quand tout passe.

    ⚠️ Cette commande ÉCRIT dans l'annuaire : elle crée puis supprime de vrais objets.
    Réservez-la à un annuaire de test, jamais à un annuaire de production.
    HELP;

    protected array $actions = [
        '1' => [
            'name' => 'Créer un WorkstationGroup',
            'legacy' => 'tests/Integration/LegacyLaravelComparison/Legacy/legacy_create_salle_test.php',
            'laravel' => 'tests/Integration/LegacyLaravelComparison/WorkstationGroupCreateTest.php',
            'compare' => 'tests/Integration/LegacyLaravelComparison/Legacy/compare_create_salle.php',
        ],
        '2' => [
            'name' => 'Renommer un WorkstationGroup',
            'legacy' => null,
            'laravel' => null,
            'compare' => 'tests/Integration/LegacyLaravelComparison/Legacy/compare_rename_salle.php',
        ],
        '3' => [
            'name' => 'Supprimer un WorkstationGroup',
            'legacy' => 'tests/Integration/LegacyLaravelComparison/Legacy/legacy_delete_parc_test.php',
            'laravel' => 'tests/Integration/LegacyLaravelComparison/WorkstationGroupDeleteTest.php',
            'compare' => 'tests/Integration/LegacyLaravelComparison/Legacy/compare_delete_parc.php',
        ],
        '4' => [
            'name' => 'Déplacer un WorkstationGroup',
            'legacy' => null,
            'laravel' => null,
            'compare' => 'tests/Integration/LegacyLaravelComparison/Legacy/compare_move_salle.php',
        ],
        '5' => [
            'name' => 'Ajouter une Workstation à un groupe',
            'legacy' => 'tests/Integration/LegacyLaravelComparison/Legacy/legacy_add_machine_to_parc_test.php',
            'laravel' => 'tests/Integration/LegacyLaravelComparison/WorkstationMembershipAddTest.php',
            'compare' => 'tests/Integration/LegacyLaravelComparison/Legacy/compare_add_machine_to_parc.php',
        ],
        '6' => [
            'name' => 'Retirer une Workstation d\'un groupe',
            'legacy' => 'tests/Integration/LegacyLaravelComparison/Legacy/legacy_remove_machine_from_parc_test.php',
            'laravel' => 'tests/Integration/LegacyLaravelComparison/WorkstationMembershipRemoveTest.php',
            'compare' => 'tests/Integration/LegacyLaravelComparison/Legacy/compare_remove_machine_from_parc.php',
        ],
        '7' => [
            'name' => 'Déplacer une Workstation',
            'legacy' => null,
            'laravel' => null,
            'compare' => 'tests/Integration/LegacyLaravelComparison/Legacy/compare_move_machine.php',
        ],
    ];

    public function handle()
    {
        $action = $this->argument('action');

        if (!$action) {
            return $this->showMenu();
        }

        if (strtolower($action) === 'all') {
            return $this->runAllTests();
        }

        $action = strtoupper($action);
        if (!isset($this->actions[$action])) {
            $this->error("Action invalide: $action");
            $this->info("Actions disponibles: " . implode(', ', array_keys($this->actions)));
            return 1;
        }

        return $this->runTest($action);
    }

    protected function showMenu()
    {
        $this->info("╔════════════════════════════════════════════════════════════════╗");
        $this->info("║           Tests de Synchronisation AD - Menu                  ║");
        $this->info("╚════════════════════════════════════════════════════════════════╝");
        $this->newLine();

        $this->table(
            ['Code', 'Action', 'Statut'],
            collect($this->actions)->map(function ($action, $code) {
                $status = $action['compare'] ? '✅ Disponible' : '⏳ En cours';
                return [$code, $action['name'], $status];
            })->toArray()
        );

        $this->newLine();
        $this->info("Usage:");
        $this->line("  php artisan compare:legacy-laravel 4               # Comparaison complète 4");
        $this->line("  php artisan compare:legacy-laravel 4 --legacy     # Test legacy uniquement");
        $this->line("  php artisan compare:legacy-laravel 4 --laravel    # Test Laravel uniquement");
        $this->line("  php artisan compare:legacy-laravel 4 --compare    # Script de comparaison");
        $this->line("  php artisan compare:legacy-laravel all             # Tous les tests disponibles");

        return 0;
    }

    protected function runAllTests()
    {
        $this->newLine();
        $this->line('<fg=cyan>╔══════════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=cyan>║</>    <fg=white;options=bold>🧪 Tests de Comparaison Legacy/Laravel</><fg=cyan>                  ║</>');
        $this->line('<fg=cyan>╚══════════════════════════════════════════════════════════════╝</>');
        $this->newLine();

        $results = [];
        $failedOutputs = [];
        $total = count(array_filter($this->actions, fn($a) => $a['compare']));
        $current = 0;

        foreach ($this->actions as $code => $action) {
            if ($action['compare']) {
                $current++;
                
                // Afficher la progression
                $this->output->write("  <fg=gray>[$current/$total]</> <fg=yellow>⏳</> $code - {$action['name']}...");
                
                // Exécuter le test en capturant la sortie
                $result = $this->runTestQuiet($code);
                $results[$code] = $result['success'];
                
                // Effacer la ligne et afficher le résultat
                $this->output->write("\r\033[K");
                
                if ($result['success']) {
                    $this->line("  <fg=gray>[$current/$total]</> <fg=green>✓</> <fg=white>$code</> - {$action['name']}");
                } else {
                    $this->line("  <fg=gray>[$current/$total]</> <fg=red>✗</> <fg=white>$code</> - {$action['name']}");
                    $failedOutputs[$code] = $result['output'];
                }
            }
        }

        // Résumé
        $this->newLine();
        $totalSuccess = count(array_filter($results));
        
        if ($totalSuccess === $total) {
            $this->line('<fg=green;options=bold>══════════════════════════════════════════════════════════════</>');
            $this->line("<fg=green;options=bold>  ✓ Tous les tests passent ($totalSuccess/$total)</>");
            $this->line('<fg=green;options=bold>══════════════════════════════════════════════════════════════</>');
        } else {
            $this->line('<fg=red;options=bold>══════════════════════════════════════════════════════════════</>');
            $this->line("<fg=red;options=bold>  ✗ $totalSuccess/$total tests réussis</>");
            $this->line('<fg=red;options=bold>══════════════════════════════════════════════════════════════</>');
            
            // Afficher les logs des tests échoués
            foreach ($failedOutputs as $code => $output) {
                $this->newLine();
                $this->line("<fg=red;options=bold>─── Détails $code ───────────────────────────────────────────</>");
                $this->line($output);
            }
        }

        $this->newLine();
        return $totalSuccess === $total ? 0 : 1;
    }

    /**
     * Exécute un test en capturant la sortie
     */
    protected function runTestQuiet(string $action): array
    {
        $config = $this->actions[$action];
        $basePath = base_path();
        $fullPath = "$basePath/{$config['compare']}";

        if (!file_exists($fullPath)) {
            return ['success' => false, 'output' => "Script non trouvé: $fullPath"];
        }

        // Exécuter en capturant la sortie
        $output = [];
        $exitCode = 0;
        exec("php -d apc.enable_cli=1 -d error_reporting=0 $fullPath 2>&1", $output, $exitCode);

        return [
            'success' => $exitCode === 0,
            'output' => implode("\n", $output)
        ];
    }

    protected function runTest(string $action)
    {
        $config = $this->actions[$action];

        if (!$config['compare']) {
            $this->warn("Les tests pour $action ne sont pas encore disponibles.");
            return 1;
        }

        $this->info("╔════════════════════════════════════════════════════════════════╗");
        $this->info("║  Test: $action - {$config['name']}");
        $this->info("╚════════════════════════════════════════════════════════════════╝");
        $this->newLine();

        $basePath = base_path();

        // Exécuter selon les options
        if ($this->option('legacy')) {
            return $this->runLegacyTest($basePath, $config['legacy']);
        }

        if ($this->option('laravel')) {
            return $this->runLaravelTest($basePath, $config['laravel']);
        }

        if ($this->option('compare')) {
            return $this->runCompareScript($basePath, $config['compare']);
        }

        // Par défaut, exécuter le script de comparaison complet
        return $this->runCompareScript($basePath, $config['compare']);
    }

    protected function runLegacyTest(string $basePath, string $scriptPath)
    {
        $this->info("→ Exécution du test Legacy...");
        $this->newLine();

        $fullPath = "$basePath/$scriptPath";
        if (!file_exists($fullPath)) {
            $this->error("Script non trouvé: $fullPath");
            return 1;
        }

        // APCu doit être activé en CLI pour le code legacy
        passthru("php -d apc.enable_cli=1 $fullPath", $exitCode);
        return $exitCode;
    }

    protected function runLaravelTest(string $basePath, string $testClass)
    {
        $this->info("→ Exécution du test Laravel (PHPUnit)...");
        $this->newLine();

        $fullPath = "$basePath/$testClass";
        if (!file_exists($fullPath)) {
            $this->error("Test non trouvé: $fullPath");
            return 1;
        }

        // APCu doit être activé en CLI pour le code legacy utilisé dans les tests d'intégration
        // Utiliser phpunit.integration.xml qui ne surcharge pas les variables DB_*
        passthru("php -d apc.enable_cli=1 vendor/bin/phpunit --configuration phpunit.integration.xml $fullPath", $exitCode);
        return $exitCode;
    }

    protected function runCompareScript(string $basePath, string $scriptPath)
    {
        $this->info("→ Exécution du script de comparaison...");
        $this->newLine();

        $fullPath = "$basePath/$scriptPath";
        if (!file_exists($fullPath)) {
            $this->error("Script non trouvé: $fullPath");
            return 1;
        }

        // APCu doit être activé en CLI pour le code legacy utilisé dans les comparaisons
        passthru("php -d apc.enable_cli=1 $fullPath", $exitCode);
        return $exitCode;
    }
}
