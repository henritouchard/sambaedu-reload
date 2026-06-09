<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\DepotApplication;
use App\Services\AppStore\AppStoreService;
use Illuminate\Database\Seeder;

class AppStoreInstallSeeder extends Seeder
{
    /**
     * Apps réellement installées au seed (téléchargement des binaires depuis
     * le dépôt). Liste volontairement courte pour ne pas alourdir la VM :
     * ces app_ids sont réutilisés par AppProfileSeeder sur tous les profils.
     */
    public const SEED_APPS = ['NotepadPlusPlus'];

    /**
     * Installe réellement les apps de test via le flux app store complet
     * (download XML + binaires, vérification SHA, régénération packages.xml).
     *
     * Sans cette étape, profiles.xml référencerait des package-ids absents
     * de packages.xml et le client WPKG des postes ne pourrait rien installer.
     */
    public function run(): void
    {
        $appStore = app(AppStoreService::class);

        foreach (self::SEED_APPS as $appId) {
            $existing = Application::where('app_id', $appId)->first();
            if ($existing?->status === ApplicationStatus::Installed) {
                $this->command->info("{$appId} déjà installée, skip.");
                continue;
            }

            $depotApp = DepotApplication::where('app_id', $appId)
                ->where('branch', 'stable')
                ->orderBy('depot_id')
                ->first();

            if (!$depotApp) {
                $this->command->warn("{$appId} introuvable dans depot_applications (branch stable) — sync du dépôt manquante ?");
                continue;
            }

            $this->command->info("Installation réelle de {$appId} v{$depotApp->version} depuis le dépôt...");

            try {
                $appStore->installApplication($depotApp, 'seeder');
                $this->command->info("{$appId} installée (binaires téléchargés, packages.xml régénéré).");
            } catch (\Exception $e) {
                $this->command->warn("Échec installation {$appId} : {$e->getMessage()} — l'app restera absente de packages.xml.");
            }
        }
    }
}
