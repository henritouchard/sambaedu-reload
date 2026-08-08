<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GroupRole;
use App\Support\RoleCatalog;
use Illuminate\Database\Seeder;

/**
 * Story 62.1 — peuplement du CATALOGUE DE RÔLES avec ses trois lignes
 * HISTORIQUES.
 *
 * Idempotent / non-destructif (patron {@see DirectoryTemplateSeeder}) :
 * `updateOrCreate` sur la clé stable `key`. Un re-seed NE crée PAS de doublon, ne
 * touche à aucune arête, et resynchronise libellé + rang sur la baseline du code.
 *
 * **Les libellés sont EXACTEMENT ceux du repli générique qui meurt avec
 * la table de libellés supprimée** : « Membre », « Gestionnaire », « Propriétaire ». C'est la
 * condition de la parité d'affichage exigée par la story — la suppression de la
 * classe ne doit rien changer à l'écran.
 *
 * Les rôles seedés ici ne sont PAS supprimables, même sans usage : leurs clés sont
 * écrites en littéral par du code vivant (voir {@see GroupRole::PROTECTED_KEYS}).
 *
 * ⚠️ Pré-déploiement VM : `php artisan db:seed --class=GroupRoleSeeder`.
 */
class GroupRoleSeeder extends Seeder
{
    /**
     * @return array{created: int, updated: int}
     */
    public function run(): array
    {
        $stats = ['created' => 0, 'updated' => 0];

        foreach ($this->roles() as $role) {
            $existed = GroupRole::where('key', $role['key'])->exists();

            GroupRole::updateOrCreate(
                ['key' => $role['key']],
                ['label' => $role['label'], 'sort_order' => $role['sort_order']],
            );

            $stats[$existed ? 'updated' : 'created']++;
        }

        // La lecture est mémoïsée : sans ce vidage, un `db:seed` suivi d'une
        // écriture dans le MÊME processus (tests, commande artisan enchaînée)
        // continuerait de lire le catalogue d'avant.
        RoleCatalog::flush();

        return $stats;
    }

    /**
     * @return list<array{key: string, label: string, sort_order: int}>
     */
    private function roles(): array
    {
        return [
            ['key' => 'member', 'label' => 'Membre', 'sort_order' => 1],
            ['key' => 'manager', 'label' => 'Gestionnaire', 'sort_order' => 2],
            ['key' => 'owner', 'label' => 'Propriétaire', 'sort_order' => 3],
        ];
    }
}
