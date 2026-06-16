<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            WorkstationSeeder::class,
            DepotSeeder::class,
            DepotApplicationSeeder::class,
            AppStoreInstallSeeder::class,
            AppProfileSeeder::class,
            ShortcutSeeder::class,
            // Story 27.3bis — reproduction des associations de fichiers legacy
            // (default.xml si lisible, sinon baseline figée) : à la bascule, les
            // défauts sont déjà en base. Idempotent/rejouable.
            FileAssociationSeeder::class,
            WpkgReportSeeder::class,
        ]);
    }
}
