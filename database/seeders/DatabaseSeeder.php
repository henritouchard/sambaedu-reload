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
            // Référentiel curé des applications natives Win32
            // (built-ins du composer d'associations) : Source 2 du dropdown,
            // ProgId canoniques connus. Idempotent/rejouable. Seedé AVANT les
            // associations (le composer y puise).
            NativeApplicationSeeder::class,
            // Reproduction des associations de fichiers legacy
            // (default.xml si lisible, sinon baseline figée) : à la bascule, les
            // défauts sont déjà en base. Idempotent/rejouable.
            FileAssociationSeeder::class,
            WpkgReportSeeder::class,
            // Catalogue des templates de répertoire (4 recettes
            // d'échange préfabriquées). Idempotent/rejouable. ⚠️ Pré-déploiement
            // VM : `db:seed --class=DirectoryTemplateSeeder`.
            DirectoryTemplateSeeder::class,
            // Catalogue des rôles d'arête (`member`/`manager`/
            // `owner`, les trois clés historiques). Idempotent/rejouable, ne
            // touche aucune arête. ⚠️ Pré-déploiement VM :
            // `db:seed --class=GroupRoleSeeder`.
            GroupRoleSeeder::class,
            // Catalogue des types de groupes (les neuf clés
            // statiques recensées : custom/classe/cours/matiere/matiere_classe/
            // projet/equipe/role/function). Idempotent/rejouable, ne touche aucun
            // groupe et ne ressuscite JAMAIS un type découvert en base par la
            // migration. ⚠️ Pré-déploiement VM :
            // `db:seed --class=GroupTypeSeeder`.
            GroupTypeSeeder::class,
            // Les DÉCLARATIONS (type × rôle → libellé local) n'ont
            // PAS de seeder, et c'est délibéré. Déclarer des rôles sur un type le
            // FERME (seuls les rôles déclarés y restent attribuables), et les
            // libellés « Élève »/« Professeur principal » sont du vocabulaire
            // SCOLAIRE : ni l'une ni l'autre de ces décisions n'a sa place dans un
            // `db:seed` joué sans y penser sur une instance multi-vertical. Le
            // profil scolaire s'installe à la demande :
            // `php artisan college:seed:role-x-type`
            // ({@see \App\Console\Commands\CollegeSeedRoleXTypeCommand}).
            // Registre d'extensions : source « embarquée » +
            // chargement des manifests du dépôt (`resources/extensions/*`),
            // dont la tuile Documentation (`/doc`). Idempotent/rejouable :
            // n'écrit jamais la colonne `status` (une extension intégrée n'est
            // jamais dé-intégrée par un re-seed).
            BundledExtensionSeeder::class,
        ]);
    }
}
