<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// php artisan make:page maNouvellePage
class MakePage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:page {name? : Le nom de la page (en PascalCase)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crée une page complète avec vues Blade, Controller, Service et Routes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Assistant de création de page Laravel');
        $this->newLine();

        // Demander le nom si non fourni
        $name = $this->argument('name');
        if (!$name) {
            $name = $this->ask('Quel est le nom de votre page ? (ex: Products, Users, Dashboard)');
        }

        if (empty($name)) {
            $this->error('❌ Le nom de la page est requis.');
            return Command::FAILURE;
        }

        // Normaliser le nom
        $name = Str::studly($name);
        $nameSnake = Str::snake($name);
        $nameKebab = Str::kebab($name);
        $nameLower = strtolower($name);

        $this->info("📝 Création de la page : {$name}");
        $this->newLine();

        // Questions interactives
        $createView = $this->confirm('Voulez-vous créer une vue Blade ?', true);
        $createController = $this->confirm('Voulez-vous créer un Controller ?', true);
        $createService = $this->confirm('Voulez-vous créer un Service ?', true);
        $addComments = $this->confirm('Voulez-vous ajouter des commentaires avec exemples dans le Controller ?', false);
        $addRoute = $this->confirm('Voulez-vous ajouter une route dans web.php ?', true);

        if (!$createView && !$createController && !$createService && !$addRoute) {
            $this->warn('⚠️  Aucun élément sélectionné. Arrêt de la commande.');
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('🔨 Génération des fichiers...');
        $this->newLine();

        $success = true;

        // Créer le Service (en premier car le controller en dépend)
        if ($createService) {
            if ($this->createService($name)) {
                $this->info("✅ Service créé : App\\Services\\{$name}Service");
            } else {
                $this->error("❌ Erreur lors de la création du Service");
                $success = false;
            }
        }

        // Créer le Controller
        if ($createController) {
            if ($this->createController($name, $nameSnake, $createService, $addComments)) {
                $this->info("✅ Controller créé : App\\Http\\Controllers\\{$name}Controller");
            } else {
                $this->error("❌ Erreur lors de la création du Controller");
                $success = false;
            }
        }

        // Créer la vue Blade
        if ($createView) {
            if ($this->createBladeView($name, $nameSnake, $createService)) {
                $this->info("✅ Vue Blade créée : resources/views/{$nameSnake}/index.blade.php");
            } else {
                $this->error("❌ Erreur lors de la création de la vue Blade");
                $success = false;
            }
        }

        // Ajouter les routes
        if ($addRoute) {
            $routePrefix = $this->ask('Quel préfixe de route souhaitez-vous ? (ex: app, admin)', 'app');
            $routeName = $this->ask('Quel nom de route souhaitez-vous ? (ex: products, users)', $nameLower);
            
            if ($this->addRoutes($name, $routePrefix, $routeName)) {
                $this->info("✅ Routes ajoutées dans web.php avec le préfixe '{$routePrefix}'");
            } else {
                $this->error("❌ Erreur lors de l'ajout des routes");
                $success = false;
            }
        }

        $this->newLine();
        if ($success) {
            $this->info('✨ Création terminée avec succès !');
            $this->newLine();
            $this->comment('💡 Prochaines étapes :');
            if ($createView) {
                $this->comment("   • Éditez la vue : resources/views/{$nameSnake}/index.blade.php");
            }
            if ($createController) {
                $this->comment("   • Ajoutez vos méthodes dans : app/Http/Controllers/{$name}Controller.php");
            }
            if ($createService) {
                $this->comment("   • Ajoutez votre logique métier dans : app/Services/{$name}Service.php");
            }
            if ($addRoute) {
                $this->comment("   • Vérifiez la route dans : routes/web.php");
            }
        } else {
            $this->error('❌ Certaines erreurs sont survenues lors de la création.');
        }

        return $success ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Crée la vue Blade de base
     */
    private function createBladeView(string $name, string $nameSnake, bool $hasService): bool
    {
        $viewsDir = resource_path("views/{$nameSnake}");
        $viewPath = "{$viewsDir}/index.blade.php";
        
        // Créer le dossier si nécessaire
        if (!File::exists($viewsDir)) {
            File::makeDirectory($viewsDir, 0755, true);
        }

        // Vérifier si le fichier existe déjà
        if (File::exists($viewPath)) {
            if (!$this->confirm("Le fichier {$viewPath} existe déjà. Voulez-vous le remplacer ?", false)) {
                return false;
            }
        }

        $content = $this->getIndexViewContent($name, $nameSnake, $hasService);

        try {
            File::put($viewPath, $content);
            return true;
        } catch (\Exception $e) {
            $this->error("Erreur lors de la création de la vue : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Contenu de la vue index
     */
    private function getIndexViewContent(string $name, string $nameSnake, bool $hasService): string
    {
        // Page Livewire SFC (single-file component), convention filesystem-based
        // router du projet : le composant est automatiquement enveloppé par le
        // layout `layouts::app` (config/livewire.php → component_layout).
        return <<<BLADE
<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — {$name}.
 */
new #[Title('{$name}')] class extends Component {
    public function mount(): void
    {
        //
    }
};
?>

<x-organisms.page title="{$name}">
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body">
            <p class="text-base-content/70">Contenu de la page {$name}</p>
        </div>
    </div>
</x-organisms.page>
BLADE;
    }

    /**
     * Crée un Controller
     */
    private function createController(string $name, string $nameSnake, bool $hasService, bool $addComments): bool
    {
        $controllerPath = app_path("Http/Controllers/{$name}Controller.php");

        // Vérifier si le fichier existe déjà
        if (File::exists($controllerPath)) {
            if (!$this->confirm("Le fichier {$controllerPath} existe déjà. Voulez-vous le remplacer ?", false)) {
                return false;
            }
        }

        $serviceInjection = '';
        $serviceProperty = '';
        $serviceConstructor = '';
        
        if ($hasService) {
            $serviceInjection = "use App\\Services\\{$name}Service;";
            $serviceProperty = "    private {$name}Service \${$nameSnake}Service;";
            $serviceConstructor = <<<PHP
    public function __construct({$name}Service \${$nameSnake}Service)
    {
        \$this->{$nameSnake}Service = \${$nameSnake}Service;
    }
PHP;
        }

        // Générer les commentaires si demandé
        $indexComments = '';
        $storeComments = '';
        $destroyComments = '';
        
        if ($addComments) {
            $indexComments = <<<PHP

        // Exemples d'utilisation de \$request :
        // \$request->input('key')           // Récupère une valeur du formulaire
        // \$request->get('key', 'default')  // Récupère avec valeur par défaut
        // \$request->all()                  // Récupère toutes les données
        // \$request->only(['key1', 'key2']) // Récupère seulement certaines clés
        // \$request->except(['key1'])       // Récupère tout sauf certaines clés
        // \$request->has('key')             // Vérifie si une clé existe
        // \$request->user()                 // Récupère l'utilisateur connecté
        // \$request->ip()                   // Récupère l'adresse IP
        // \$request->header('X-Custom')     // Récupère un header HTTP
        // \$request->method()               // Récupère la méthode HTTP (GET, POST, etc.)
        // \$request->path()                 // Récupère le chemin de l'URL
        // \$request->url()                  // Récupère l'URL complète
        // \$request->query('key')           // Récupère un paramètre de requête (?key=value)
PHP;
            
            $storeComments = <<<PHP

        // Exemples d'utilisation de \$request pour POST :
        // \$request->validate([             // Validation des données
        //     'name' => 'required|string|max:255',
        //     'email' => 'required|email',
        // ]);
        // \$request->input('name')           // Récupère 'name' du formulaire
        // \$request->file('photo')           // Récupère un fichier uploadé
        // \$request->hasFile('photo')       // Vérifie si un fichier a été uploadé
        // \$request->json()                 // Récupère les données JSON (pour API)
        // \$request->bearerToken()          // Récupère le token Bearer (pour API)
        // \$request->header('Authorization') // Récupère le header Authorization
PHP;
            
            $destroyComments = <<<PHP

        // Exemples d'utilisation de \$request pour DELETE :
        // \$request->input('id')             // Récupère l'ID depuis le body
        // \$request->route('id')             // Récupère l'ID depuis la route
        // \$request->query('id')             // Récupère l'ID depuis la query string
        // \$request->header('X-Request-ID') // Récupère un header personnalisé
PHP;
        }
        
        $serviceCall = $hasService ? "\$data = \$this->{$nameSnake}Service->getAll();\n        " : '';
        $dataPass = $hasService ? ", ['data' => \$data]" : '';
        
        $controllerContent = <<<PHP
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
{$serviceInjection}

class {$name}Controller extends Controller
{
{$serviceProperty}
{$serviceConstructor}
    
    /**
     * Affiche la page
     */
    public function index(Request \$request)
    {{$indexComments}
        {$serviceCall}return view('{$nameSnake}.index'{$dataPass});
    }
    
    /**
     * Traite une requête POST
     */
    public function store(Request \$request)
    {{$storeComments}
        // Récupérer les données du formulaire
        // \$data = \$request->input('key');
        
        // Traiter les données...
        
        // Rediriger ou retourner une réponse
        return redirect()->back()->with('success', 'Données enregistrées avec succès');
    }
    
    /**
     * Traite une requête DELETE
     */
    public function destroy(Request \$request, string \$id)
    {{$destroyComments}
        // Récupérer l'ID depuis la route ou le body
        // \$id = \$request->route('id') ?? \$request->input('id');
        
        // Supprimer la ressource...
        
        // Rediriger ou retourner une réponse
        return redirect()->back()->with('success', 'Ressource supprimée avec succès');
    }
}
PHP;

        try {
            File::put($controllerPath, $controllerContent);
            return true;
        } catch (\Exception $e) {
            $this->error("Erreur : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Crée un Service
     */
    private function createService(string $name): bool
    {
        $servicePath = app_path("Services/{$name}Service.php");

        // Vérifier si le fichier existe déjà
        if (File::exists($servicePath)) {
            if (!$this->confirm("Le fichier {$servicePath} existe déjà. Voulez-vous le remplacer ?", false)) {
                return false;
            }
        }

        $serviceContent = <<<PHP
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Service de gestion de {$name}
 */
class {$name}Service
{
    /**
     * Récupère toutes les ressources
     */
    public function getAll(): array
    {
        try {
            // Implémentez votre logique ici
            return [];
        } catch (\Exception \$e) {
            Log::error('{$name}Service getAll error: ' . \$e->getMessage());
            return [];
        }
    }
}
PHP;

        try {
            File::put($servicePath, $serviceContent);
            return true;
        } catch (\Exception $e) {
            $this->error("Erreur : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ajoute les routes (GET, POST, DELETE) dans web.php
     */
    private function addRoutes(string $name, string $prefix, string $routeName): bool
    {
        $webPath = base_path('routes/web.php');
        
        if (!File::exists($webPath)) {
            $this->error("Le fichier routes/web.php n'existe pas.");
            return false;
        }

        $content = File::get($webPath);
        $controllerName = "{$name}Controller";
        $controllerNamespace = "App\\Http\\Controllers\\{$controllerName}";

        // Vérifier si les routes existent déjà
        if (strpos($content, "{$controllerName}::class") !== false) {
            if (!$this->confirm("Des routes pour {$controllerName} existent déjà. Voulez-vous continuer quand même ?", false)) {
                return false;
            }
        }

        // Générer les routes GET, POST et DELETE
        $newRoutes = <<<PHP
    // Routes pour {$name}
    Route::get('/{$routeName}', [{$controllerNamespace}::class, 'index'])->name('{$routeName}');
    Route::post('/{$routeName}', [{$controllerNamespace}::class, 'store'])->name('{$routeName}.store');
    Route::delete('/{$routeName}/{id}', [{$controllerNamespace}::class, 'destroy'])->name('{$routeName}.destroy');
PHP;

        // Ajouter l'import du controller en haut du fichier si nécessaire
        $importStatement = "use {$controllerNamespace};";
        if (strpos($content, $importStatement) === false) {
            // Trouver la dernière ligne d'import
            $lines = explode("\n", $content);
            $lastImportIndex = 0;
            foreach ($lines as $index => $line) {
                if (preg_match('/^use\s+/', $line)) {
                    $lastImportIndex = $index;
                }
            }
            // Insérer l'import après le dernier import
            array_splice($lines, $lastImportIndex + 1, 0, $importStatement);
            $content = implode("\n", $lines);
        }

        // Chercher si le préfixe existe déjà dans un groupe
        $prefixPattern = "/Route::prefix\(['\"]{$prefix}['\"]\)->middleware\(['\"]sambaedu\.auth['\"]\)->name\(['\"]{$prefix}\.['\"]\)->group\(function\s*\(\)\s*\{/";
        
        if (preg_match($prefixPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            // Le préfixe existe déjà, ajouter les routes dans ce groupe
            $matchPos = $matches[0][1];
            $matchLength = strlen($matches[0][0]);
            
            // Trouver la fin du groupe (la parenthèse fermante correspondante)
            $pos = $matchPos + $matchLength;
            $openBraces = 1;
            $endPos = $pos;
            
            while ($openBraces > 0 && $endPos < strlen($content)) {
                if ($content[$endPos] === '{') {
                    $openBraces++;
                } elseif ($content[$endPos] === '}') {
                    $openBraces--;
                }
                $endPos++;
            }
            
            // Insérer la route juste avant la fermeture du groupe
            $beforeClose = substr($content, 0, $endPos - 1);
            $afterClose = substr($content, $endPos - 1);
            
            // Ajouter les routes juste avant la fermeture du groupe
            $insertion = "\n" . $newRoutes . "\n";
            $content = $beforeClose . $insertion . $afterClose;
        } else {
            // Le préfixe n'existe pas, créer un nouveau groupe
            $routesGroup = <<<PHP

// Routes pour {$name}
Route::prefix('{$prefix}')->middleware('sambaedu.auth')->name('{$prefix}.')->group(function () {
{$newRoutes}
});

PHP;
            
            // Ajouter avant la route fallback ou à la fin
            $fallbackPattern = '/\/\*.*?Fallback Route.*?\*\/.*?Route::match\(\[.*?\].*?->where\(.*?\);/s';
            if (preg_match($fallbackPattern, $content)) {
                $content = preg_replace(
                    $fallbackPattern,
                    $routesGroup . "\n" . '$0',
                    $content
                );
            } else {
                // Ajouter avant la dernière ligne si elle existe
                $content = rtrim($content) . "\n" . $routesGroup;
            }
        }

        try {
            File::put($webPath, $content);
            return true;
        } catch (\Exception $e) {
            $this->error("Erreur : " . $e->getMessage());
            return false;
        }
    }
}

