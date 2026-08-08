<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WorkstationGroup;
use App\Services\AdSync\AdSyncService;
use Illuminate\Console\Command;

/**
 * Commande artisan pour synchroniser tous les WorkstationGroups vers l'AD
 */
class SyncWorkstationGroupsToAd extends Command
{
    protected $signature = 'ad:sync-workstation-groups 
                            {--dry-run : Prévisualise les changements sans les appliquer}
                            {--force : Force la synchronisation même si déjà synchronisé}
                            {--id= : Synchronise uniquement le groupe avec cet ID}';

    protected $description = 'Synchronise les WorkstationGroups de la base SQL vers l\'Active Directory';

    protected $help = <<<'HELP'
    Projette vers l'Active Directory les groupes de postes (salles physiques et parcs
    logiques) qui vivent en base SQL, et mémorise en retour l'identifiant d'annuaire
    (<info>ad_guid</info>) et le DN de chaque objet créé.

    Par défaut, seuls les groupes JAMAIS synchronisés sont traités (ceux dont
    l'<info>ad_guid</info> est vide). Utilisez <info>--force</info> pour rejouer la
    projection sur tous les groupes, y compris ceux déjà présents dans l'annuaire.

      <info>php artisan ad:sync-workstation-groups --dry-run</info>   liste ce qui serait projeté
      <info>php artisan ad:sync-workstation-groups --id=42</info>     un seul groupe
      <info>php artisan ad:sync-workstation-groups --force</info>     rejoue tout

    Les échecs sont comptés et détaillés groupe par groupe sans interrompre la boucle :
    un annuaire partiellement injoignable ne fait pas perdre le travail déjà fait.
    HELP;

    public function __construct(
        private AdSyncService $adSyncService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $specificId = $this->option('id');

        if ($dryRun) {
            $this->info('🔍 Mode dry-run : aucune modification ne sera effectuée');
        }

        $query = WorkstationGroup::query();

        if ($specificId) {
            $query->where('id', $specificId);
        }

        if (!$force) {
            $query->whereNull('ad_guid');
        }

        $groups = $query->get();

        if ($groups->isEmpty()) {
            $this->info('✅ Aucun groupe à synchroniser');
            return Command::SUCCESS;
        }

        $this->info("📋 {$groups->count()} groupe(s) à synchroniser");

        $successCount = 0;
        $errorCount = 0;

        $this->withProgressBar($groups, function (WorkstationGroup $group) use ($dryRun, &$successCount, &$errorCount) {
            if ($dryRun) {
                $this->newLine();
                $this->line("  → [{$group->id}] {$group->name} (is_physical: " . ($group->is_physical ? 'yes' : 'no') . ")");
                $successCount++;
                return;
            }

            $result = $this->adSyncService->createWorkstationGroup($group);

            if ($result['success']) {
                $group->ad_guid = $result['guid'];
                if ($result['dn']) {
                    $group->ad_dn = $result['dn'];
                }
                $group->save();
                $successCount++;
            } else {
                $this->newLine();
                $this->error("  ✗ [{$group->id}] {$group->name}: {$result['error']}");
                $errorCount++;
            }
        });

        $this->newLine(2);

        if ($errorCount > 0) {
            $this->warn("⚠️  Terminé avec {$errorCount} erreur(s)");
            $this->info("✅ {$successCount} groupe(s) synchronisé(s)");
            return Command::FAILURE;
        }

        $this->info("✅ {$successCount} groupe(s) synchronisé(s) avec succès");
        return Command::SUCCESS;
    }
}
