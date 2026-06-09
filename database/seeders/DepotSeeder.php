<?php

namespace Database\Seeders;

use App\Models\Depot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepotSeeder extends Seeder
{
    /**
     * Seed la table depots.
     *
     * Le catalogue local `applications` n'est PAS seedé ici : il est alimenté
     * par de vraies installations via AppStoreInstallSeeder (téléchargement
     * réel depuis le dépôt), sinon profiles.xml référencerait des package-ids
     * absents de packages.xml et le client WPKG ne pourrait rien installer.
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

        foreach ($depots as $depot) {
            Depot::create($depot);
        }
        $this->command->info(count($depots) . ' dépôts créés.');
    }
}
