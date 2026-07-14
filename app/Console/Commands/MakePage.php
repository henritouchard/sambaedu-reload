<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// php artisan make:page reports
// php artisan make:page admin/settings/reports
//
// Génère une page Livewire 4 SFC (single-file component) conforme au
// filesystem-based router du projet :
//   - la page vit dans resources/views/pages/<chemin-kebab>/index.blade.php ;
//   - c'est un SFC (bloc PHP « new class extends Component … » + un unique
//     élément racine), enveloppé automatiquement par le layout `layouts::app`
//     (config/livewire.php → component_layout) ;
//   - la route est déclarée via la macro Livewire 4
//     `Route::livewire('/uri', 'pages::<chemin.points>.index')->name('<nom>')`
//     dans le groupe de préfixe choisi (app|admin) de routes/web.php.
//
// AUCUN Controller ni route MVC (GET/POST/DELETE `[Controller::class, ...]`)
// n'est généré : l'ancien modèle a été retiré. Un Service métier optionnel
// peut être créé et injecté dans `mount()`.
class MakePage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:page {name? : Le chemin de la page (ex: reports, admin/settings/reports)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crée une page Livewire 4 SFC (single-file component) + sa route livewire, conforme au filesystem-based router';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Assistant de création de page Livewire SFC');
        $this->newLine();

        // Demander le nom / chemin si non fourni.
        $name = $this->argument('name');
        if (! $name) {
            $name = $this->ask('Chemin de la page ? (ex: reports, admin/settings/reports)');
        }

        if (empty($name)) {
            $this->error('❌ Le nom de la page est requis.');

            return Command::FAILURE;
        }

        // Normalisation en segments kebab-case (= dossiers du filesystem-router).
        // Accepte les séparateurs `/` ou `\` et les noms en PascalCase.
        $segments = collect(preg_split('#[/\\\\]+#', trim($name, "/\\ ")))
            ->filter()
            ->map(fn (string $s): string => Str::kebab(Str::studly($s)))
            ->values()
            ->all();

        if (empty($segments)) {
            $this->error('❌ Nom de page invalide.');

            return Command::FAILURE;
        }

        $dirPath = implode('/', $segments);          // admin/settings/reports
        $dotPath = implode('.', $segments);          // admin.settings.reports
        $component = "pages::{$dotPath}.index";       // pages::admin.settings.reports.index
        $lastKebab = end($segments);                  // reports
        $title = Str::headline($lastKebab);           // Reports / Rights Management
        $serviceName = Str::studly($lastKebab);       // Reports → ReportsService

        $this->info("📝 Page : {$dirPath}  →  composant {$component}");
        $this->newLine();

        $createService = $this->confirm('Créer un Service métier optionnel (injecté dans mount) ?', false);
        $addRoute = $this->confirm('Déclarer la route livewire dans web.php ?', true);

        $this->newLine();
        $this->info('🔨 Génération des fichiers...');
        $this->newLine();

        $success = true;

        // Service métier (optionnel) — créé en premier pour être référencé par le SFC.
        if ($createService) {
            if ($this->createService($serviceName)) {
                $this->info("✅ Service créé : App\\Services\\{$serviceName}Service");
            } else {
                $this->error('❌ Erreur lors de la création du Service');
                $success = false;
                $createService = false; // ne pas référencer un service absent
            }
        }

        // Page SFC.
        if ($this->createSfcPage($dirPath, $title, $serviceName, $createService)) {
            $this->info("✅ Page SFC créée : resources/views/pages/{$dirPath}/index.blade.php");
        } else {
            $this->error('❌ Erreur lors de la création de la page SFC');
            $success = false;
        }

        // Route livewire.
        if ($addRoute) {
            $prefix = $this->choice('Préfixe de route ?', ['app', 'admin'], 'app');

            // On retire le segment de préfixe s'il est en tête (le groupe l'ajoute déjà).
            $uriSegments = $segments;
            if (($uriSegments[0] ?? null) === $prefix) {
                array_shift($uriSegments);
            }
            $defaultUri = '/'.implode('/', $uriSegments);
            if ($defaultUri === '/') {
                $defaultUri = '/'.$lastKebab;
            }
            $defaultName = implode('.', $uriSegments) ?: $lastKebab;

            $uri = $this->ask("URI (après le préfixe /{$prefix}) ?", $defaultUri);
            $routeName = $this->ask("Nom de la route (après le préfixe {$prefix}.) ?", $defaultName);

            if ($this->addRoute($prefix, $uri, $component, $routeName)) {
                $this->info("✅ Route livewire ajoutée : {$prefix}.{$routeName} → {$component}");
            } else {
                $this->error("❌ Erreur lors de l'ajout de la route");
                $success = false;
            }
        }

        $this->newLine();
        if ($success) {
            $this->info('✨ Création terminée avec succès !');
            $this->newLine();
            $this->comment('💡 Prochaines étapes :');
            $this->comment("   • Éditez la page : resources/views/pages/{$dirPath}/index.blade.php");
            if ($createService) {
                $this->comment("   • Ajoutez votre logique métier dans : app/Services/{$serviceName}Service.php");
            }
            if ($addRoute) {
                $this->comment('   • Vérifiez la route dans : routes/web.php');
            }
        } else {
            $this->error('❌ Certaines erreurs sont survenues lors de la création.');
        }

        return $success ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Crée la page Livewire SFC dans resources/views/pages/<dirPath>/index.blade.php.
     */
    private function createSfcPage(string $dirPath, string $title, string $serviceName, bool $hasService): bool
    {
        $pageDir = resource_path("views/pages/{$dirPath}");
        $viewPath = "{$pageDir}/index.blade.php";

        if (! File::exists($pageDir)) {
            File::makeDirectory($pageDir, 0755, true);
        }

        if (File::exists($viewPath)) {
            if (! $this->confirm("Le fichier {$viewPath} existe déjà. Voulez-vous le remplacer ?", false)) {
                return false;
            }
        }

        $content = $this->getSfcContent($title, $serviceName, $hasService);

        try {
            File::put($viewPath, $content);

            return true;
        } catch (\Exception $e) {
            $this->error('Erreur lors de la création de la page : '.$e->getMessage());

            return false;
        }
    }

    /**
     * Contenu de la page SFC (single-file component Livewire 4).
     *
     * Construit ligne à ligne : les lignes de code généré (contenant `$items`,
     * `$this`, `$service`…) sont en simples quotes pour rester littérales ;
     * seules les lignes interpolant $title / $serviceName sont en doubles quotes.
     */
    private function getSfcContent(string $title, string $serviceName, bool $hasService): string
    {
        if ($hasService) {
            $stateBlock = [
                '    /** @var array<int, mixed> Données chargées depuis le service. */',
                '    public array $items = [];',
                '',
                "    public function mount({$serviceName}Service \$service): void",
                '    {',
                '        // Injection de service via mount() (pas de constructeur en Livewire).',
                '        $this->items = $service->getAll();',
                '    }',
            ];
            $bodyBlock = [
                '        @forelse ($items as $item)',
                '            <pre class="bg-base-200 p-2 rounded text-xs">{{ json_encode($item, JSON_PRETTY_PRINT) }}</pre>',
                '        @empty',
                '            <p class="text-base-content/70">Aucune donnée.</p>',
                '        @endforelse',
            ];
        } else {
            $stateBlock = [
                '    public function mount(): void',
                '    {',
                "        // Garde d'accès si besoin, ex :",
                "        // if (! \\Illuminate\\Support\\Facades\\Gate::allows('server.admin')) { abort(403); }",
                '    }',
            ];
            $bodyBlock = [
                "            <p class=\"text-base-content/70\">Contenu de la page {$title}.</p>",
            ];
        }

        $lines = ['<?php', ''];
        if ($hasService) {
            $lines[] = "use App\\Services\\{$serviceName}Service;";
        }
        $lines[] = 'use Livewire\\Attributes\\Title;';
        $lines[] = 'use Livewire\\Component;';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = " * Page Livewire SFC — {$title}.";
        $lines[] = ' *';
        $lines[] = ' * Single-file component enveloppé par le layout `layouts::app`';
        $lines[] = ' * (config/livewire.php → component_layout). Un unique élément racine requis.';
        $lines[] = ' */';
        $lines[] = "new #[Title('{$title}')] class extends Component {";
        $lines = array_merge($lines, $stateBlock);
        $lines[] = '};';
        $lines[] = '?>';
        $lines[] = '';
        $lines[] = "<x-organisms.page title=\"{$title}\">";
        $lines[] = '    <div class="card bg-base-100 shadow-sm border border-base-200">';
        $lines[] = '        <div class="card-body">';
        $lines = array_merge($lines, $bodyBlock);
        $lines[] = '        </div>';
        $lines[] = '    </div>';
        $lines[] = '</x-organisms.page>';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Crée un Service métier optionnel.
     */
    private function createService(string $name): bool
    {
        $servicePath = app_path("Services/{$name}Service.php");

        if (File::exists($servicePath)) {
            if (! $this->confirm("Le fichier {$servicePath} existe déjà. Voulez-vous le remplacer ?", false)) {
                return false;
            }
        }

        $serviceContent = implode("\n", [
            '<?php',
            '',
            'namespace App\\Services;',
            '',
            'use Illuminate\\Support\\Facades\\Log;',
            '',
            '/**',
            " * Service de gestion de {$name}.",
            ' */',
            "class {$name}Service",
            '{',
            '    /**',
            '     * Récupère toutes les ressources.',
            '     *',
            '     * @return array<int, mixed>',
            '     */',
            '    public function getAll(): array',
            '    {',
            '        try {',
            '            // Implémentez votre logique ici.',
            '            return [];',
            "        } catch (\\Exception \$e) {",
            "            Log::error('{$name}Service getAll error: '.\$e->getMessage());",
            '',
            '            return [];',
            '        }',
            '    }',
            '}',
            '',
        ]);

        try {
            File::put($servicePath, $serviceContent);

            return true;
        } catch (\Exception $e) {
            $this->error('Erreur : '.$e->getMessage());

            return false;
        }
    }

    /**
     * Déclare une route livewire dans le groupe de préfixe (app|admin) de web.php.
     *
     * Localise l'ouverture du groupe par son `->name('<prefix>.')->group(function (…) {`
     * — insensible à la forme du middleware (string ou tableau) — puis insère la
     * route juste avant l'accolade fermante correspondante. Si le groupe est
     * introuvable ou mal formé, la route est imprimée pour insertion manuelle
     * (repli non bloquant).
     */
    private function addRoute(string $prefix, string $uri, string $component, string $routeName): bool
    {
        $webPath = base_path('routes/web.php');

        if (! File::exists($webPath)) {
            $this->error("Le fichier routes/web.php n'existe pas.");

            return false;
        }

        $uri = '/'.ltrim($uri, '/');
        $line = "    Route::livewire('{$uri}', '{$component}')->name('{$routeName}');";

        $content = File::get($webPath);

        // Localiser l'ouverture du groupe : ->name('<prefix>.')->group(function (...) {
        $pattern = "/->name\(['\"]".preg_quote($prefix, '/')."\.['\"]\)->group\(function\s*\([^)]*\)\s*\{/";

        if (! preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
            $this->warn("⚠️  Groupe de préfixe '{$prefix}' introuvable dans web.php. Ajoutez la route manuellement :");
            $this->line($line);

            return true;
        }

        // Position juste après l'accolade ouvrante du groupe.
        $start = $m[0][1] + strlen($m[0][0]);

        // Trouver l'accolade fermante correspondante par comptage de niveaux.
        $depth = 1;
        $i = $start;
        $len = strlen($content);
        while ($i < $len && $depth > 0) {
            $ch = $content[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
            }
            $i++;
        }

        if ($depth !== 0) {
            $this->warn("⚠️  Fin du groupe '{$prefix}' introuvable dans web.php. Ajoutez la route manuellement :");
            $this->line($line);

            return true;
        }

        $closePos = $i - 1; // position de l'accolade fermante du groupe
        $before = rtrim(substr($content, 0, $closePos));
        $after = substr($content, $closePos);
        $content = $before."\n\n".$line."\n".$after;

        try {
            File::put($webPath, $content);

            return true;
        } catch (\Exception $e) {
            $this->error('Erreur : '.$e->getMessage());

            return false;
        }
    }
}
