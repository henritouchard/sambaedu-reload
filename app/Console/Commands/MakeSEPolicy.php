<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeSEPolicy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:SEpolicy {name : Le nom de la policy (ex: User, Machine, Group)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crée une policy avec enregistrement automatique des gates et méthodes CRUD';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->argument('name');
        $name = Str::studly($name); // PascalCase
        $nameLower = Str::lower($name);
        $nameKebab = Str::kebab($name);

        $policyPath = app_path("Policies/{$name}Policy.php");

        // Vérifier si la policy existe déjà
        if (file_exists($policyPath)) {
            $this->error("La policy {$name}Policy existe déjà !");
            return Command::FAILURE;
        }

        // Générer le contenu de la policy
        $content = $this->generatePolicyContent($name, $nameLower, $nameKebab);

        // Créer le fichier
        if (!is_dir(dirname($policyPath))) {
            mkdir(dirname($policyPath), 0755, true);
        }

        file_put_contents($policyPath, $content);

        $this->info("✅ Policy créée : app/Policies/{$name}Policy.php");
        $this->newLine();
        $this->comment('💡 Prochaines étapes :');
        $this->comment("   • Ajoutez {$name}Policy::registerGates() dans AuthServiceProvider.php");
        $this->comment("   • Personnalisez les méthodes de vérification des droits");
        $this->comment("   • Utilisez Gate::allows('{$nameKebab}-view') dans vos composants");
        $this->newLine();
        $this->table(
            ['Gate', 'Méthode'],
            [
                ["viewAny-{$nameKebab}", 'viewAny()'],
                ["view-{$nameKebab}", 'view()'],
                ["create-{$nameKebab}", 'create()'],
                ["update-{$nameKebab}", 'update()'],
                ["delete-{$nameKebab}", 'delete()'],
                ["manage-{$nameKebab}s", 'viewAny()'],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Génère le contenu de la policy
     */
    private function generatePolicyContent(string $name, string $nameLower, string $nameKebab): string
    {
        return <<<PHP
<?php

namespace App\Policies;

use App\Models\User;
use App\Config\SambaEduConfig;
use App\Policies\Traits\RegistersGates;
use Illuminate\Support\Facades\Log;

/**
 * Policy pour la gestion des {$nameLower}s
 * 
 * Utilise le trait RegistersGates pour l'enregistrement automatique des gates.
 */
class {$name}Policy
{
    use RegistersGates;

    /**
     * Définition des gates pour cette policy
     */
    protected static array \$gates = [
        'viewAny-{$nameKebab}' => 'viewAny',
        'view-{$nameKebab}' => 'view',
        'create-{$nameKebab}' => 'create',
        'update-{$nameKebab}' => 'update',
        'delete-{$nameKebab}' => 'delete',
        'manage-{$nameKebab}s' => 'viewAny',
    ];

    public function __construct(
        private SambaEduConfig \$configService
    ) {
    }

    /**
     * Vérifie si l'utilisateur peut voir la liste
     */
    public function viewAny(?User \$user): bool
    {
        // TODO: Implémenter la vérification des droits
        // return \$this->hasAdminRights(\$user);
        return true;
    }

    /**
     * Vérifie si l'utilisateur peut voir un élément
     */
    public function view(?User \$user): bool
    {
        return true;
    }

    /**
     * Vérifie si l'utilisateur peut créer
     */
    public function create(?User \$user): bool
    {
        return true;
    }

    /**
     * Vérifie si l'utilisateur peut modifier
     */
    public function update(?User \$user): bool
    {
        return true;
    }

    /**
     * Vérifie si l'utilisateur peut supprimer
     */
    public function delete(?User \$user): bool
    {
        return true;
    }

    /**
     * Vérifie si l'utilisateur a les droits d'administration
     * 
     * @param User|null \$user L'utilisateur Laravel
     */
    private function hasAdminRights(?User \$user): bool
    {
        try {
            // Récupérer le login depuis l'utilisateur Laravel ou la session
            \$login = \$user?->getLogin() ?? \$_SESSION['login'] ?? null;

            if (!\$login) {
                return false;
            }

            \$config = \$this->configService->legacy()->getConfig();

            if (!isset(\$config['bind']) || \$config['bind'] === null) {
                Log::warning('{$name}Policy: Pas de connexion LDAP');
                return false;
            }

            if (!function_exists('have_right') || !function_exists('search_user')) {
                Log::warning('{$name}Policy: Fonctions legacy non disponibles');
                return false;
            }

            \$ldapUser = search_user(\$config, \$login);

            if (empty(\$ldapUser)) {
                return false;
            }

            // TODO: Définir la constante de droit appropriée
            // Exemples: SE_USER_ADMIN (0xEE00), SE_COMPUTER_ADMIN (0xEF00)
            \$adminRight = 0xEE00;

            return have_right(\$config, \$adminRight, \$ldapUser, true);

        } catch (\Exception \$e) {
            Log::error('{$name}Policy error: ' . \$e->getMessage());
            return false;
        }
    }
}
PHP;
    }
}
