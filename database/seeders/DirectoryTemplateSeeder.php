<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DirectoryTemplate;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Story 34.3 — peuplement PROD des 4 recettes de « templates de répertoire »
 * (Q3 option B, arbitrage Henri 2026-06-30).
 *
 * Idempotent / non-destructif (iso {@see PermissionSeeder}) : `updateOrCreate`
 * sur la clé stable `key`. Un re-seed NE crée PAS de doublon et resynchronise
 * libellé/description/spec sur la baseline canonique du code (les recettes ne
 * sont pas éditables en UI en 34.3, le code reste la source de vérité de la
 * baseline).
 *
 * **4 recettes seedées** (le 5ᵉ pattern `élèves → profs` / casiers est REPORTÉ à
 * 34.x — le socle ne sait pas faire de sous-espace par-élève ; un dépôt partagé
 * serait un faux sens métier dangereux) :
 *
 *  1. `direction_to_all` — direction (RW) publie, destinataires (RO) lisent.
 *  2. `profs_to_eleves`  — devoirs : profs `equipe` (RW) déposent, élèves
 *                          `classe` (RO) lisent.
 *  3. `user_to_user`     — échange bilatéral : deux utilisateurs (RW/RW).
 *  4. `group_space`      — espace commun d'un groupe (RW).
 *
 * INVARIANT (vérifié en test) : aucune recette ne porte de maille
 * `WorkstationGroup` — toutes les ACL portent sur `User`/`UserGroup`.
 *
 * ⚠️ Pré-déploiement VM : `php artisan db:seed --class=DirectoryTemplateSeeder`.
 */
class DirectoryTemplateSeeder extends Seeder
{
    /**
     * @return array{created: int, updated: int}
     */
    public function run(): array
    {
        $stats = ['created' => 0, 'updated' => 0];

        foreach ($this->templates() as $tpl) {
            $existing = DirectoryTemplate::where('key', $tpl['key'])->first();

            DirectoryTemplate::updateOrCreate(
                ['key' => $tpl['key']],
                [
                    'label' => $tpl['label'],
                    'description' => $tpl['description'],
                    'roles_spec' => $tpl['roles_spec'],
                ],
            );

            $existing === null ? $stats['created']++ : $stats['updated']++;
        }

        Log::info('[DirectoryTemplateSeeder] Seed terminé', $stats);

        return $stats;
    }

    /**
     * Baseline canonique des 4 recettes (code = source de vérité, Q3 option B).
     *
     * @return array<int, array{key:string,label:string,description:string,roles_spec:array<int,array<string,mixed>>}>
     */
    private function templates(): array
    {
        return [
            [
                'key' => DirectoryTemplate::KEY_DIRECTION_TO_ALL,
                'label' => 'Direction → tous (publication descendante)',
                'description' => 'La direction (ou une équipe) DÉPOSE en lecture/écriture ; '
                    .'les groupes destinataires LISENT en lecture seule. « Tous » se '
                    .'matérialise par une sélection EXPLICITE de groupes destinataires '
                    .'(un parc ne donnerait que la visibilité, sans aucun accès réel).',
                'roles_spec' => [
                    [
                        'key' => 'source',
                        'label' => 'Source (direction / équipe qui publie)',
                        'maille' => UserGroup::class,
                        'group_type' => null,
                        'access' => 'rw',
                        'cardinality' => 'one',
                    ],
                    [
                        'key' => 'destinataires',
                        'label' => 'Destinataires (groupes qui lisent)',
                        'maille' => UserGroup::class,
                        'group_type' => null,
                        'access' => 'ro',
                        'cardinality' => 'many',
                    ],
                ],
            ],
            [
                'key' => DirectoryTemplate::KEY_PROFS_TO_ELEVES,
                'label' => 'Profs → élèves (distribution de devoirs)',
                'description' => 'Les enseignants de l\'équipe DÉPOSENT en lecture/écriture ; '
                    .'les élèves de la classe LISENT en lecture seule.',
                'roles_spec' => [
                    [
                        'key' => 'profs',
                        'label' => 'Équipe enseignante (dépose)',
                        'maille' => UserGroup::class,
                        'group_type' => 'equipe',
                        'access' => 'rw',
                        'cardinality' => 'one',
                    ],
                    [
                        'key' => 'eleves',
                        'label' => 'Classe (lecture seule)',
                        'maille' => UserGroup::class,
                        'group_type' => 'classe',
                        'access' => 'ro',
                        'cardinality' => 'one',
                    ],
                ],
            ],
            [
                'key' => DirectoryTemplate::KEY_USER_TO_USER,
                'label' => 'Utilisateur ↔ utilisateur (échange bilatéral)',
                'description' => 'Deux utilisateurs partagent un espace commun en '
                    .'lecture/écriture (collaboration directe).',
                'roles_spec' => [
                    [
                        'key' => 'user_a',
                        'label' => 'Premier utilisateur',
                        'maille' => User::class,
                        'group_type' => null,
                        'access' => 'rw',
                        'cardinality' => 'one',
                    ],
                    [
                        'key' => 'user_b',
                        'label' => 'Second utilisateur',
                        'maille' => User::class,
                        'group_type' => null,
                        'access' => 'rw',
                        'cardinality' => 'one',
                    ],
                ],
            ],
            [
                'key' => DirectoryTemplate::KEY_GROUP_SPACE,
                'label' => 'Groupe (espace commun)',
                'description' => 'Espace de travail commun d\'un groupe d\'utilisateurs '
                    .'(lecture/écriture pour tous les membres).',
                'roles_spec' => [
                    [
                        'key' => 'group',
                        'label' => 'Groupe d\'utilisateurs',
                        'maille' => UserGroup::class,
                        'group_type' => null,
                        'access' => 'rw',
                        'cardinality' => 'one',
                    ],
                ],
            ],
        ];
    }
}
