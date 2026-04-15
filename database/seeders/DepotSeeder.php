<?php

namespace Database\Seeders;

use App\Models\Depot;
use App\Models\Application;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepotSeeder extends Seeder
{
    /**
     * Seed les tables depots et applications avec des données de test
     */
    public function run(): void
    {
        DB::statement('TRUNCATE applications, depots RESTART IDENTITY CASCADE');
        $this->command->info('Tables depots et applications vidées.');

        // Créer les dépôts
        $depots = [
            [
                'name' => 'SambaEdu Stable',
                'url' => 'http://deb.sambaedu.org/wpkg/xml/packages.xml',
                'is_primary' => true,
                'is_active' => true,
            ],
            [
                'name' => 'SambaEdu Dev',
                'url' => 'http://deb.sambaedu.org/wpkg/xml/packages_dev.xml',
                'is_primary' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Dépôt Local',
                'url' => 'file:///var/se4/wpkg/local/',
                'is_primary' => false,
                'is_active' => false,
            ],
        ];

        $createdDepots = [];
        foreach ($depots as $depot) {
            $createdDepots[] = Depot::create($depot);
        }
        $this->command->info(count($depots) . ' dépôts créés.');

        // Créer des applications de test
        $applications = [
            ['app_id' => 'firefox', 'name' => 'Mozilla Firefox', 'category' => 'Navigateurs', 'version' => '121.0', 'branch' => 'stable'],
            ['app_id' => 'chrome', 'name' => 'Google Chrome', 'category' => 'Navigateurs', 'version' => '120.0', 'branch' => 'stable'],
            ['app_id' => 'libreoffice', 'name' => 'LibreOffice', 'category' => 'Bureautique', 'version' => '7.6.4', 'branch' => 'stable'],
            ['app_id' => 'vlc', 'name' => 'VLC Media Player', 'category' => 'Multimédia', 'version' => '3.0.20', 'branch' => 'stable'],
            ['app_id' => 'gimp', 'name' => 'GIMP', 'category' => 'Graphisme', 'version' => '2.10.36', 'branch' => 'stable'],
            ['app_id' => 'inkscape', 'name' => 'Inkscape', 'category' => 'Graphisme', 'version' => '1.3.2', 'branch' => 'stable'],
            ['app_id' => 'audacity', 'name' => 'Audacity', 'category' => 'Multimédia', 'version' => '3.4.2', 'branch' => 'stable'],
            ['app_id' => 'notepadpp', 'name' => 'Notepad++', 'category' => 'Développement', 'version' => '8.6.2', 'branch' => 'stable'],
            ['app_id' => 'vscode', 'name' => 'Visual Studio Code', 'category' => 'Développement', 'version' => '1.85.1', 'branch' => 'stable'],
            ['app_id' => '7zip', 'name' => '7-Zip', 'category' => 'Utilitaires', 'version' => '23.01', 'branch' => 'stable'],
            ['app_id' => 'python3', 'name' => 'Python 3', 'category' => 'Développement', 'version' => '3.12.1', 'branch' => 'stable'],
            ['app_id' => 'scratch', 'name' => 'Scratch Desktop', 'category' => 'Éducation', 'version' => '3.29.1', 'branch' => 'stable'],
            ['app_id' => 'geogebra', 'name' => 'GeoGebra', 'category' => 'Éducation', 'version' => '6.0.818', 'branch' => 'stable'],
            ['app_id' => 'openshot', 'name' => 'OpenShot Video Editor', 'category' => 'Multimédia', 'version' => '3.1.1', 'branch' => 'stable'],
            ['app_id' => 'blender', 'name' => 'Blender', 'category' => 'Graphisme', 'version' => '4.0.2', 'branch' => 'stable'],
        ];

        // Ajouter les applications au dépôt principal
        $primaryDepot = $createdDepots[0];
        foreach ($applications as $app) {
            Application::create([
                'depot_id' => $primaryDepot->id,
                'app_id' => $app['app_id'],
                'name' => $app['name'],
                'category' => $app['category'],
                'version' => $app['version'],
                'branch' => $app['branch'],
                'compatibility' => 'Windows 7+',
            ]);
        }
        $this->command->info(count($applications) . ' applications créées.');
    }
}
