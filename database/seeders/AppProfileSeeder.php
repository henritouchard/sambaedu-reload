<?php

namespace Database\Seeders;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\WorkstationGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AppProfileSeeder extends Seeder
{
    /**
     * Seed la table app_profiles avec des données de test
     */
    public function run(): void
    {
        DB::statement('TRUNCATE app_profile_application, app_profile_workstation_group, app_profile_workstation, app_profiles RESTART IDENTITY CASCADE');
        $this->command->info('Tables app_profiles vidées.');

        // Créer des profils applicatifs
        $profiles = [
            [
                'name' => 'base-windows',
                'display_name' => 'Applications de base Windows',
                'description' => 'Suite logicielle standard pour tous les postes Windows',
                'apps' => ['firefox', 'libreoffice', 'vlc', '7zip'],
                'groups' => ['windows-all'],
            ],
            [
                'name' => 'dev-tools',
                'display_name' => 'Outils de développement',
                'description' => 'IDE et outils pour les cours de programmation',
                'apps' => ['vscode', 'python3', 'notepadpp'],
                'groups' => ['info1', 'info2'],
            ],
            [
                'name' => 'multimedia',
                'display_name' => 'Suite multimédia',
                'description' => 'Logiciels audio/vidéo',
                'apps' => ['vlc', 'audacity', 'openshot'],
                'groups' => ['info3'],
            ],
            [
                'name' => 'graphisme',
                'display_name' => 'Suite graphisme',
                'description' => 'Logiciels de création graphique',
                'apps' => ['gimp', 'inkscape', 'blender'],
                'groups' => ['techno'],
            ],
            [
                'name' => 'education',
                'display_name' => 'Logiciels éducatifs',
                'description' => 'Applications pédagogiques',
                'apps' => ['scratch', 'geogebra'],
                'groups' => ['cdi', 'physique', 'svt'],
            ],
        ];

        foreach ($profiles as $profileData) {
            $profile = AppProfile::create([
                'name' => $profileData['name'],
                'display_name' => $profileData['display_name'],
                'description' => $profileData['description'],
                'is_active' => true,
            ]);

            // Associer les applications
            $appIds = Application::whereIn('app_id', $profileData['apps'])->pluck('id')->toArray();
            if (!empty($appIds)) {
                $profile->applications()->attach($appIds);
            }

            // Associer les groupes
            $groupIds = WorkstationGroup::whereIn('name', $profileData['groups'])->pluck('id')->toArray();
            if (!empty($groupIds)) {
                $profile->workstationGroups()->attach($groupIds);
            }
        }

        $this->command->info(count($profiles) . ' profils applicatifs créés avec leurs associations.');
    }
}
