<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GroupType;
use App\Support\GroupTypeCatalog;
use Illuminate\Database\Seeder;

/**
 * Story 62.2 — peuplement du CATALOGUE DE TYPES DE GROUPES avec ses NEUF lignes
 * statiques.
 *
 * Idempotent / non-destructif (patron {@see GroupRoleSeeder}) : `updateOrCreate`
 * sur la clé stable `key`. Un re-seed NE crée PAS de doublon, ne touche à aucun
 * groupe, et resynchronise libellé + icône + rang sur la baseline du code.
 *
 * **Il ne seed QUE les neuf statiques.** Les types DÉCOUVERTS en base par la
 * migration (`class`, `autre`, tout ce que quatre ans de chaîne libre ont produit)
 * ne sont pas de la baseline de code : les recréer à chaque seed ressusciterait un
 * type que l'admin vient de supprimer. La migration les catalogue une fois ; ils
 * vivent leur vie ensuite.
 *
 * **Les libellés sont EXACTEMENT ceux des `match` d'affichage qui meurent avec la
 * story** — la forme la plus riche, celle de la fiche utilisateur, seule à
 * connaître « Rôle » et « Fonction ». C'est la condition de la parité d'affichage.
 *
 * Les types seedés ici ne sont PAS supprimables, même sans usage : leurs clés sont
 * écrites en littéral par du code vivant (voir {@see GroupType::PROTECTED_KEYS}).
 *
 * ⚠️ Pré-déploiement VM : `php artisan db:seed --class=GroupTypeSeeder`.
 */
class GroupTypeSeeder extends Seeder
{
    /**
     * @return array{created: int, updated: int}
     */
    public function run(): array
    {
        $stats = ['created' => 0, 'updated' => 0];

        foreach ($this->types() as $type) {
            $existed = GroupType::where('key', $type['key'])->exists();

            GroupType::updateOrCreate(
                ['key' => $type['key']],
                ['label' => $type['label'], 'icon' => $type['icon'], 'sort_order' => $type['sort_order']],
            );

            $stats[$existed ? 'updated' : 'created']++;
        }

        // La lecture est mémoïsée : sans ce vidage, un `db:seed` suivi d'une
        // écriture dans le MÊME processus (tests, commande artisan enchaînée)
        // continuerait de lire le catalogue d'avant.
        GroupTypeCatalog::flush();

        return $stats;
    }

    /**
     * @return list<array{key: string, label: string, icon: string, sort_order: int}>
     */
    private function types(): array
    {
        return [
            ['key' => 'custom', 'label' => 'Personnalisé', 'icon' => 'fa-solid fa-users', 'sort_order' => 1],
            ['key' => 'classe', 'label' => 'Classe', 'icon' => 'fa-solid fa-graduation-cap', 'sort_order' => 2],
            ['key' => 'cours', 'label' => 'Cours', 'icon' => 'fa-solid fa-book-open', 'sort_order' => 3],
            ['key' => 'matiere', 'label' => 'Matière', 'icon' => 'fa-solid fa-book', 'sort_order' => 4],
            ['key' => 'matiere_classe', 'label' => 'Matière / Classe', 'icon' => 'fa-solid fa-book-bookmark', 'sort_order' => 5],
            ['key' => 'projet', 'label' => 'Projet', 'icon' => 'fa-solid fa-diagram-project', 'sort_order' => 6],
            ['key' => 'equipe', 'label' => 'Équipe', 'icon' => 'fa-solid fa-people-group', 'sort_order' => 7],
            ['key' => 'role', 'label' => 'Rôle', 'icon' => 'fa-solid fa-id-badge', 'sort_order' => 8],
            ['key' => 'function', 'label' => 'Fonction', 'icon' => 'fa-solid fa-briefcase', 'sort_order' => 9],
        ];
    }
}
