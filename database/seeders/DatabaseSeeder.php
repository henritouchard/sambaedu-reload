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
            // Story 27.11 — référentiel curé des applications natives Win32
            // (built-ins du composer d'associations) : Source 2 du dropdown,
            // ProgId canoniques connus. Idempotent/rejouable. Seedé AVANT les
            // associations (le composer y puise).
            NativeApplicationSeeder::class,
            // Story 27.3bis — reproduction des associations de fichiers legacy
            // (default.xml si lisible, sinon baseline figée) : à la bascule, les
            // défauts sont déjà en base. Idempotent/rejouable.
            FileAssociationSeeder::class,
            WpkgReportSeeder::class,
            // Story 34.3 — catalogue des templates de répertoire (4 recettes
            // d'échange préfabriquées). Idempotent/rejouable. ⚠️ Pré-déploiement
            // VM : `db:seed --class=DirectoryTemplateSeeder`.
            DirectoryTemplateSeeder::class,
            // Story 62.1 — catalogue des rôles d'arête (`member`/`manager`/
            // `owner`, les trois clés historiques). Idempotent/rejouable, ne
            // touche aucune arête. ⚠️ Pré-déploiement VM :
            // `db:seed --class=GroupRoleSeeder`.
            GroupRoleSeeder::class,
            // Story 62.2 — catalogue des types de groupes (les neuf clés
            // statiques recensées : custom/classe/cours/matiere/matiere_classe/
            // projet/equipe/role/function). Idempotent/rejouable, ne touche aucun
            // groupe et ne ressuscite JAMAIS un type découvert en base par la
            // migration. ⚠️ Pré-déploiement VM :
            // `db:seed --class=GroupTypeSeeder`.
            GroupTypeSeeder::class,
            // Story 54.1 — registre d'extensions : source « embarquée » +
            // chargement des manifests du dépôt (`resources/extensions/*`),
            // dont la tuile Documentation (`/doc`). Idempotent/rejouable :
            // n'écrit jamais la colonne `status` (une extension intégrée n'est
            // jamais dé-intégrée par un re-seed).
            BundledExtensionSeeder::class,
        ]);
    }
}
