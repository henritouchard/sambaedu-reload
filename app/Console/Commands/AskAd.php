<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupModel;
use App\LdapModels\DeviceGroupTagModel;
use App\LdapModels\LdapUser;
use App\LdapModels\SambaEduGroup;
use App\Models\WorkstationGroup;
use LdapRecord\Models\Model as LdapModel;

class AskAd extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ask:ad 
                            {search : Le terme à rechercher}
                            {--type=all : Type de recherche (computers|parcs|people|groups|classes|equipes|cours|projets|matieres|equipements|sql|all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recherche un terme dans les différents OUs de l\'AD et en SQL';

    private LdapDnHelper $dnHelper;

    public function __construct(LdapDnHelper $dnHelper)
    {
        parent::__construct();
        $this->dnHelper = $dnHelper;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $search = $this->argument('search');
        $type = $this->option('type');

        $this->info("Recherche de: {$search}");
        $this->line(str_repeat('=', 60));
        $this->newLine();

        $foundAny = false;

        // Recherche dans OU=Computers
        if (in_array($type, ['computers', 'all'])) {
            $foundAny = $this->searchInComputers($search) || $foundAny;
        }

        // Recherche dans OU=Parcs
        if (in_array($type, ['parcs', 'all'])) {
            $foundAny = $this->searchInParcs($search) || $foundAny;
        }

        // Recherche dans OU=Utilisateurs (people)
        if (in_array($type, ['people', 'all'])) {
            $foundAny = $this->searchInPeople($search) || $foundAny;
        }

        // Recherche dans OU=Groups
        if (in_array($type, ['groups', 'all'])) {
            $foundAny = $this->searchInGroups($search) || $foundAny;
        }

        // Recherche dans OU=Classes
        if (in_array($type, ['classes', 'all'])) {
            $foundAny = $this->searchInClasses($search) || $foundAny;
        }

        // Recherche dans OU=Equipes
        if (in_array($type, ['equipes', 'all'])) {
            $foundAny = $this->searchInEquipes($search) || $foundAny;
        }

        // Recherche dans OU=Cours
        if (in_array($type, ['cours', 'all'])) {
            $foundAny = $this->searchInCours($search) || $foundAny;
        }

        // Recherche dans OU=Projets
        if (in_array($type, ['projets', 'all'])) {
            $foundAny = $this->searchInProjets($search) || $foundAny;
        }

        // Recherche dans OU=matieres
        if (in_array($type, ['matieres', 'all'])) {
            $foundAny = $this->searchInMatieres($search) || $foundAny;
        }

        // Recherche dans OU=Equipements
        if (in_array($type, ['equipements', 'all'])) {
            $foundAny = $this->searchInEquipements($search) || $foundAny;
        }

        // Recherche en SQL
        if (in_array($type, ['sql', 'all'])) {
            $foundAny = $this->searchInSql($search) || $foundAny;
        }

        $this->newLine();
        $this->line(str_repeat('=', 60));

        if (!$foundAny) {
            $this->warn('Aucun résultat trouvé.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function searchInComputers(string $search): bool
    {
        $computersDn = $this->dnHelper->computers();
        $this->comment("Recherche dans OU=Computers ({$computersDn})...");

        try {
            $groupsComputers = DeviceGroupModel::in($computersDn)->get();
            $foundInComputers = $groupsComputers->filter(function ($g) use ($search) {
                return stripos($g->getGroupName(), $search) !== false;
            });

            if ($foundInComputers->count() > 0) {
                $this->info("✓ Trouvé {$foundInComputers->count()} résultat(s) dans OU=Computers:");
                foreach ($foundInComputers as $g) {
                    $this->line("  - " . $g->getGroupName());
                    $this->line("    DN: " . $g->getDn());
                    $this->line("    Description: " . ($g->getGroupDescription() ?? 'N/A'));
                }
                $this->newLine();
                return true;
            } else {
                $this->warn("✗ Aucun résultat dans OU=Computers");
                $this->newLine();
                return false;
            }
        } catch (\Exception $e) {
            $this->error("Erreur lors de la recherche dans OU=Computers: " . $e->getMessage());
            $this->newLine();
            return false;
        }
    }

    private function searchInParcs(string $search): bool
    {
        $parcsDn = $this->dnHelper->parcs();
        $this->comment("Recherche dans OU=Parcs ({$parcsDn})...");

        try {
            $groupsParcs = DeviceGroupTagModel::in($parcsDn)->get();
            $foundInParcs = $groupsParcs->filter(function ($p) use ($search) {
                return stripos($p->getParcName(), $search) !== false;
            });

            if ($foundInParcs->count() > 0) {
                $this->info("✓ Trouvé {$foundInParcs->count()} résultat(s) dans OU=Parcs:");
                foreach ($foundInParcs as $p) {
                    $this->line("  - " . $p->getParcName());
                    $this->line("    DN: " . $p->getDn());
                    $this->line("    Description: " . ($p->getDescription() ?? 'N/A'));
                }
                $this->newLine();
                return true;
            } else {
                $this->warn("✗ Aucun résultat dans OU=Parcs");
                $this->newLine();
                return false;
            }
        } catch (\Exception $e) {
            $this->error("Erreur lors de la recherche dans OU=Parcs: " . $e->getMessage());
            $this->newLine();
            return false;
        }
    }

    private function searchInSql(string $search): bool
    {
        $this->comment("Recherche en SQL...");

        try {
            $sqlGroups = WorkstationGroup::where('name', 'LIKE', "%{$search}%")->get();

            if ($sqlGroups->count() > 0) {
                $this->info("✓ Trouvé {$sqlGroups->count()} résultat(s) en SQL:");
                foreach ($sqlGroups as $g) {
                    $this->line("  - {$g->name} (ID: {$g->id})");
                    $this->line("    Type: " . ($g->is_physical ? 'Physique' : 'Logique'));
                    $this->line("    AD GUID: " . ($g->ad_guid ?? 'N/A'));
                }
                $this->newLine();
                return true;
            } else {
                $this->warn("✗ Aucun résultat en SQL");
                $this->newLine();
                return false;
            }
        } catch (\Exception $e) {
            $this->error("Erreur lors de la recherche en SQL: " . $e->getMessage());
            $this->newLine();
            return false;
        }
    }

    private function searchInPeople(string $search): bool
    {
        return $this->searchInGenericOu(
            'OU=Utilisateurs',
            $this->dnHelper->people(false),
            $search,
            LdapUser::class,
            ['cn', 'sn', 'givenname', 'samaccountname']
        );
    }

    private function searchInGroups(string $search): bool
    {
        return $this->searchInGenericOu(
            'OU=Groups',
            $this->dnHelper->groups(false),
            $search,
            SambaEduGroup::class,
            ['cn', 'samaccountname']
        );
    }

    private function searchInClasses(string $search): bool
    {
        return $this->searchInGenericOu(
            'OU=Classes',
            $this->dnHelper->classes(false),
            $search,
            SambaEduGroup::class,
            ['cn', 'samaccountname']
        );
    }

    private function searchInEquipes(string $search): bool
    {
        return $this->searchInGenericOu(
            'OU=Equipes',
            $this->dnHelper->equipes(false),
            $search,
            SambaEduGroup::class,
            ['cn', 'samaccountname']
        );
    }

    private function searchInCours(string $search): bool
    {
        return $this->searchInGenericOu(
            'OU=Cours',
            $this->dnHelper->cours(false),
            $search,
            SambaEduGroup::class,
            ['cn', 'samaccountname']
        );
    }

    private function searchInProjets(string $search): bool
    {
        return $this->searchInGenericOu(
            'OU=Projets',
            $this->dnHelper->projets(false),
            $search,
            SambaEduGroup::class,
            ['cn', 'samaccountname']
        );
    }

    private function searchInMatieres(string $search): bool
    {
        return $this->searchInGenericOu(
            'OU=matieres',
            $this->dnHelper->matieres(),
            $search,
            SambaEduGroup::class,
            ['cn', 'samaccountname']
        );
    }

    private function searchInEquipements(string $search): bool
    {
        return $this->searchInGenericOu(
            'OU=Equipements',
            $this->dnHelper->equipements(false),
            $search,
            \LdapRecord\Models\ActiveDirectory\Entry::class,
            ['cn', 'name']
        );
    }

    /**
     * Recherche générique dans un OU LDAP
     */
    private function searchInGenericOu(string $ouName, string $dn, string $search, string $modelClass, array $attributes): bool
    {
        $this->comment("Recherche dans {$ouName} ({$dn})...");

        try {
            $query = (new $modelClass)->setDn($dn)->newQuery();
            $results = $query->listing()->get();

            $found = $results->filter(function ($item) use ($search, $attributes) {
                foreach ($attributes as $attr) {
                    $value = $item->getFirstAttribute($attr);
                    if ($value && stripos($value, $search) !== false) {
                        return true;
                    }
                }
                return false;
            });

            if ($found->count() > 0) {
                $this->info("✓ Trouvé {$found->count()} résultat(s) dans {$ouName}:");
                foreach ($found as $item) {
                    $name = $item->getFirstAttribute('cn') ?? $item->getFirstAttribute('name') ?? 'N/A';
                    $this->line("  - {$name}");
                    $this->line("    DN: " . $item->getDn());
                    $description = $item->getFirstAttribute('description');
                    if ($description) {
                        $this->line("    Description: {$description}");
                    }
                }
                $this->newLine();
                return true;
            } else {
                $this->warn("✗ Aucun résultat dans {$ouName}");
                $this->newLine();
                return false;
            }
        } catch (\Exception $e) {
            $this->error("Erreur lors de la recherche dans {$ouName}: " . $e->getMessage());
            $this->newLine();
            return false;
        }
    }
}
