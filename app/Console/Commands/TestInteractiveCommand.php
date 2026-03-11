<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\note;

class TestInteractiveCommand extends Command
{
    protected $signature = 'test:interactive {--quick : Lancer rapidement tous les tests de comparaison}';
    protected $description = 'Console interactive pour lancer les tests SambaEdu';

    private array $comparisonTests = [
        '1' => 'Créer un WorkstationGroup',
        '2' => 'Renommer un WorkstationGroup',
        '3' => 'Supprimer un WorkstationGroup',
        '4' => 'Déplacer un WorkstationGroup',
        '5' => 'Ajouter une Workstation à un groupe',
        '6' => 'Retirer une Workstation d\'un groupe',
        '7' => 'Déplacer une Workstation',
    ];

    public function handle(): int
    {
        $this->displayBanner();

        // Mode rapide : lancer tous les tests de comparaison directement
        if ($this->option('quick')) {
            info('🚀 Mode rapide : lancement de tous les tests de comparaison...');
            $this->newLine();
            $this->call('compare:legacy-laravel', ['action' => 'all']);
            return 0;
        }

        while (true) {
            $choice = select(
                label: '🧪 Que souhaitez-vous tester ?',
                options: [
                    'comparison' => '🔄 Tests de comparaison Legacy/Laravel',
                    'unit' => '🧩 Tests unitaires (PHPUnit)',
                    'feature' => '🎯 Tests fonctionnels (PHPUnit)',
                    'ad_check' => '🔍 Vérifier la synchronisation AD',
                    'db_check' => '🗄️  Vérifier la cohérence base de données',
                    'all_comparison' => '🚀 Lancer TOUS les tests de comparaison',
                    'quit' => '❌ Quitter',
                ],
                default: 'comparison'
            );

            switch ($choice) {
                case 'comparison':
                    $this->runComparisonMenu();
                    break;
                case 'unit':
                    $this->runUnitTests();
                    break;
                case 'feature':
                    $this->runFeatureTests();
                    break;
                case 'ad_check':
                    $this->runAdCheck();
                    break;
                case 'db_check':
                    $this->runDbCheck();
                    break;
                case 'all_comparison':
                    $this->runAllComparisonTests();
                    break;
                case 'quit':
                    info('👋 À bientôt !');
                    return 0;
            }

            $this->newLine();
        }
    }

    private function displayBanner(): void
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════════════════════════╗');
        $this->line('║        🧪 SambaEdu - Console de Tests Interactive 🧪         ║');
        $this->line('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    private function runComparisonMenu(): void
    {
        $options = [];
        
        // D'abord les tests P2-P8
        foreach ($this->comparisonTests as $code => $description) {
            $options[$code] = "[$code] $description";
        }
        
        // Puis les options de navigation
        $options['all'] = '🚀 Lancer tous les tests de comparaison';
        $options['back'] = '⬅️  Retour au menu principal';

        $choice = select(
            label: '🔄 Tests de comparaison Legacy/Laravel',
            options: $options,
            default: '1'
        );

        if ($choice === 'back') {
            return;
        }

        if ($choice === 'all') {
            $this->runAllComparisonTests();
            return;
        }

        $this->runSingleComparisonTest($choice);
    }

    private function runSingleComparisonTest(string $testCode): void
    {
        $description = $this->comparisonTests[$testCode] ?? 'Test inconnu';
        
        info("🔄 Lancement du test $testCode : $description");
        $this->newLine();

        $this->call('compare:legacy-laravel', ['action' => $testCode]);
    }

    private function runAllComparisonTests(): void
    {
        if (!confirm('Lancer tous les tests de comparaison ?', true)) {
            return;
        }

        info('🚀 Lancement de tous les tests de comparaison...');
        $this->newLine();

        $this->call('compare:legacy-laravel', ['action' => 'all']);
    }

    private function runUnitTests(): void
    {
        $options = [
            'back' => '⬅️  Retour au menu principal',
            'all' => '🧩 Tous les tests unitaires',
            'services' => '⚙️  Tests des Services',
            'models' => '📦 Tests des Models',
            'repositories' => '🗃️  Tests des Repositories',
            'filter' => '🔎 Filtrer par nom...',
        ];

        $choice = select(
            label: '🧩 Tests unitaires PHPUnit',
            options: $options,
            default: 'back'
        );

        if ($choice === 'back') {
            return;
        }

        $command = ['vendor/bin/phpunit'];
        
        switch ($choice) {
            case 'all':
                $command[] = '--testsuite=Unit';
                break;
            case 'services':
                $command[] = 'tests/Unit/Services';
                break;
            case 'models':
                $command[] = 'tests/Unit/Models';
                break;
            case 'repositories':
                $command[] = 'tests/Unit/Repositories';
                break;
            case 'filter':
                $filter = $this->ask('Entrez le filtre (nom de classe ou méthode)');
                if ($filter) {
                    $command[] = '--filter=' . $filter;
                }
                break;
        }

        info('🧪 Exécution des tests unitaires...');
        $this->newLine();

        $this->runShellCommand(implode(' ', $command));
    }

    private function runFeatureTests(): void
    {
        $options = [
            'back' => '⬅️  Retour au menu principal',
            'all' => '🎯 Tous les tests fonctionnels',
            'ad' => '🌐 Tests Active Directory',
            'workstation' => '💻 Tests Workstation/WorkstationGroup',
            'filter' => '🔎 Filtrer par nom...',
        ];

        $choice = select(
            label: '🎯 Tests fonctionnels PHPUnit',
            options: $options,
            default: 'back'
        );

        if ($choice === 'back') {
            return;
        }

        $command = ['vendor/bin/phpunit'];
        
        switch ($choice) {
            case 'all':
                $command[] = '--testsuite=Feature';
                break;
            case 'ad':
                $command[] = 'tests/Feature/AdSync';
                break;
            case 'workstation':
                $command[] = 'tests/Feature/Workstation';
                break;
            case 'filter':
                $filter = $this->ask('Entrez le filtre (nom de classe ou méthode)');
                if ($filter) {
                    $command[] = '--filter=' . $filter;
                }
                break;
        }

        info('🧪 Exécution des tests fonctionnels...');
        $this->newLine();

        $this->runShellCommand(implode(' ', $command));
    }

    private function runAdCheck(): void
    {
        $options = [
            'back' => '⬅️  Retour au menu principal',
            'full' => '🔍 Vérification complète AD vs SQL',
            'workstations' => '💻 Vérifier les machines',
            'groups' => '📁 Vérifier les groupes/salles',
            'orphans' => '👻 Trouver les orphelins',
        ];

        $choice = select(
            label: '🔍 Vérification synchronisation AD',
            options: $options,
            default: 'back'
        );

        if ($choice === 'back') {
            return;
        }

        info('🔍 Vérification de la synchronisation AD...');
        $this->newLine();

        switch ($choice) {
            case 'full':
                $this->call('ad:check', ['--full' => true]);
                break;
            case 'workstations':
                $this->call('ad:check', ['--type' => 'workstations']);
                break;
            case 'groups':
                $this->call('ad:check', ['--type' => 'groups']);
                break;
            case 'orphans':
                $this->call('ad:check', ['--orphans' => true]);
                break;
        }
    }

    private function runDbCheck(): void
    {
        $options = [
            'back' => '⬅️  Retour au menu principal',
            'integrity' => '🔗 Vérifier l\'intégrité référentielle',
            'duplicates' => '👯 Trouver les doublons',
            'stats' => '📊 Statistiques de la base',
        ];

        $choice = select(
            label: '🗄️ Vérification base de données',
            options: $options,
            default: 'back'
        );

        if ($choice === 'back') {
            return;
        }

        info('🗄️ Vérification de la base de données...');
        $this->newLine();

        switch ($choice) {
            case 'integrity':
                $this->checkDbIntegrity();
                break;
            case 'duplicates':
                $this->checkDbDuplicates();
                break;
            case 'stats':
                $this->showDbStats();
                break;
        }
    }

    private function checkDbIntegrity(): void
    {
        note('Vérification de l\'intégrité référentielle...');
        
        // Vérifier les WorkstationGroups orphelins
        $orphanGroups = \App\Models\WorkstationGroup::whereNotNull('parent_id')
            ->whereDoesntHave('parent')
            ->count();
        
        if ($orphanGroups > 0) {
            warning("⚠️  $orphanGroups WorkstationGroup(s) avec parent_id invalide");
        } else {
            info('✅ Tous les WorkstationGroups ont des parents valides');
        }

        // Vérifier les relations parc_profile
        $this->line('   (Autres vérifications à implémenter selon les besoins)');
    }

    private function checkDbDuplicates(): void
    {
        note('Recherche des doublons...');
        
        // Doublons de noms de parcs
        $duplicateParcs = \DB::table('parc')
            ->select('nom_parc', \DB::raw('COUNT(*) as count'))
            ->groupBy('nom_parc')
            ->having('count', '>', 1)
            ->get();

        if ($duplicateParcs->count() > 0) {
            warning('⚠️  Doublons trouvés dans la table parc :');
            foreach ($duplicateParcs as $dup) {
                $this->line("   - {$dup->nom_parc} ({$dup->count} occurrences)");
            }
        } else {
            info('✅ Aucun doublon dans la table parc');
        }
    }

    private function showDbStats(): void
    {
        note('Statistiques de la base de données...');
        $this->newLine();

        $stats = [
            'WorkstationGroups (salles)' => \App\Models\WorkstationGroup::count(),
            'Workstations (machines)' => \App\Models\Workstation::count(),
            'AppProfiles' => \App\Models\AppProfile::count(),
        ];

        foreach ($stats as $label => $count) {
            $this->line("   📊 $label : $count");
        }
    }

    private function runShellCommand(string $command): void
    {
        $process = proc_open(
            $command,
            [
                0 => STDIN,
                1 => STDOUT,
                2 => STDERR,
            ],
            $pipes,
            base_path()
        );

        if (is_resource($process)) {
            proc_close($process);
        }
    }
}
