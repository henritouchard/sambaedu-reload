<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GroupTypeRole;
use App\Support\RoleCatalog;
use Illuminate\Database\Seeder;

/**
 * Story 62.3 — peuplement des SEPT DÉCLARATIONS de reprise : quels rôles ont un
 * sens dans `classe`, `projet` et `equipe`, et comment ils s'y disent.
 *
 * Idempotent / non-destructif (patron {@see GroupTypeSeeder}) : `updateOrCreate`
 * sur la PAIRE. Un re-seed ne crée aucun doublon, ne touche aucune appartenance,
 * et resynchronise les libellés locaux sur la baseline du code.
 *
 * **Ce seeder ne porte PAS la parité d'affichage** — c'est la MIGRATION qui pose
 * ces sept lignes, et le docblock de
 * `2026_08_08_180000_create_group_type_roles_table` explique pourquoi :
 * `RefreshDatabase` rejoue les migrations, pas les seeders, et les tests de parité
 * de 62.1 épinglent « Élève »/« Porteur »/« Référent » en littéraux sans avoir à
 * seeder quoi que ce soit. Ce fichier sert à une instance EN PLACE qu'on veut
 * ramener à la baseline (ou à `db:seed` complet), pas à tenir les tests.
 *
 * **Il ne seed QUE les trois types historiquement traduits.** Les déclarations
 * qu'un administrateur pose depuis l'écran des types ne sont pas de la baseline de
 * code : les recréer à chaque seed ressusciterait une déclaration qu'il vient de
 * retirer. Symétriquement, un type sans ligne ici reste en régime de REPLI (tout
 * le catalogue de rôles lui est disponible) — c'est un état légitime, pas un
 * manque.
 *
 * ⚠️ Pré-déploiement VM : `php artisan db:seed --class=GroupTypeRoleSeeder`.
 */
class GroupTypeRoleSeeder extends Seeder
{
    /**
     * @return array{created: int, updated: int}
     */
    public function run(): array
    {
        $stats = ['created' => 0, 'updated' => 0];

        foreach ($this->declarations() as $declaration) {
            $existed = GroupTypeRole::where('group_type_key', $declaration['group_type_key'])
                ->where('group_role_key', $declaration['group_role_key'])
                ->exists();

            GroupTypeRole::updateOrCreate(
                [
                    'group_type_key' => $declaration['group_type_key'],
                    'group_role_key' => $declaration['group_role_key'],
                ],
                ['label' => $declaration['label']],
            );

            $stats[$existed ? 'updated' : 'created']++;
        }

        // La résolution est mémoïsée : sans ce vidage, un `db:seed` suivi d'une
        // lecture dans le MÊME processus continuerait de lire la carte d'avant.
        RoleCatalog::flush();

        return $stats;
    }

    /**
     * @return list<array{group_type_key: string, group_role_key: string, label: ?string}>
     */
    private function declarations(): array
    {
        return [
            ['group_type_key' => 'classe', 'group_role_key' => 'member', 'label' => 'Élève'],
            ['group_type_key' => 'classe', 'group_role_key' => 'manager', 'label' => 'Enseignant'],
            ['group_type_key' => 'classe', 'group_role_key' => 'owner', 'label' => 'Professeur principal'],
            // `member` DÉCLARÉ sans surcharge : sans lui, le rôle par défaut de
            // tout rattachement deviendrait inattribuable dans un projet.
            ['group_type_key' => 'projet', 'group_role_key' => 'member', 'label' => null],
            ['group_type_key' => 'projet', 'group_role_key' => 'manager', 'label' => 'Porteur'],
            ['group_type_key' => 'equipe', 'group_role_key' => 'member', 'label' => null],
            ['group_type_key' => 'equipe', 'group_role_key' => 'manager', 'label' => 'Référent'],
        ];
    }
}
