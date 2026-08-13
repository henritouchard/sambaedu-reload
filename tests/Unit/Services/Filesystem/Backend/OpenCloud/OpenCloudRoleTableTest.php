<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\OpenCloud;

use App\Services\Filesystem\Backend\OpenCloud\OpenCloudRoleTable;
use App\Services\Filesystem\Plan\PlanGrant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LA TRADUCTION VERBES → RÔLES, ET SES DEUX FAÇONS DE MAL TOURNER.
 *
 * Un catalogue de rôles n'est pas un masque de bits : il propose des PAQUETS que
 * personne n'a taillés pour notre vocabulaire. Deux erreurs symétriques guettent,
 * et elles ont le même remède — le CONSTAT écrit :
 *
 *  - **arrondir vers le haut** : accorder un rôle qui donne plus que ce que les
 *    verbes décrivent. Sous drift STRICT, l'état créé par ce droit non écrit est
 *    indescriptible : dérive perpétuelle, ou réconciliation qui « corrige » en
 *    détruisant du travail ;
 *  - **arrondir vers le bas EN SILENCE** : poser le rôle restrictif sans dire que
 *    des verbes du plan ne sont pas transmis. L'écran serait vert sur un accès qui
 *    n'existe pas.
 *
 * Les valeurs employées ici — identifiants et actions — sont celles RELEVÉES
 * contre l'instance réelle le 2026-08-13.
 */
class OpenCloudRoleTableTest extends TestCase
{
    private const VIEW_ITEM = 'b1e2218d-eef8-4d4c-b82d-0f1a1b48f3b5';

    private const UPLOAD_ITEM = '1c996275-f1c9-4e71-abdf-a42f6495e960';

    private const EDIT_ITEM = 'fb6c3e19-e378-47e5-b277-9732f9de6e21';

    private const VIEW_SPACE = 'a8d5fe5e-96e3-418d-825b-534dbdf22b99';

    private const EDIT_SPACE = '58c63c02-1d89-4572-916a-870abc5a1b7d';

    /**
     * **LES DEUX CAS QUE LES RECETTES LIVRÉES PRODUISENT TOMBENT EXACTEMENT JUSTE.**
     *
     * L'ancien vocabulaire binaire a été traduit une fois pour toutes : lecture
     * seule → `lire`, écriture → les QUATRE verbes. Ce sont donc les deux seules
     * combinaisons qu'une instance en service rencontre aujourd'hui, et aucune des
     * deux ne dégrade.
     */
    #[Test]
    public function the_two_combinations_the_shipped_recipes_produce_map_exactly(): void
    {
        $read = OpenCloudRoleTable::resolve([PlanGrant::VERB_LIRE], OpenCloudRoleTable::FAMILY_ITEM);
        self::assertSame(self::VIEW_ITEM, $read['id']);
        self::assertSame([], $read['missing'], 'la lecture seule doit être transmise sans perte');

        $full = OpenCloudRoleTable::resolve(PlanGrant::VERBS, OpenCloudRoleTable::FAMILY_ITEM);
        self::assertSame(self::EDIT_ITEM, $full['id']);
        self::assertSame([], $full['missing'], 'les quatre verbes doivent être transmis sans perte');
    }

    /** La RACINE d'un espace emploie l'AUTRE famille, et ses identifiants diffèrent. */
    #[Test]
    public function the_space_family_carries_different_identifiers_for_the_same_labels(): void
    {
        $read = OpenCloudRoleTable::resolve([PlanGrant::VERB_LIRE], OpenCloudRoleTable::FAMILY_SPACE);
        $full = OpenCloudRoleTable::resolve(PlanGrant::VERBS, OpenCloudRoleTable::FAMILY_SPACE);

        self::assertSame(self::VIEW_SPACE, $read['id']);
        self::assertSame(self::EDIT_SPACE, $full['id']);

        self::assertNotSame(self::VIEW_ITEM, self::VIEW_SPACE, 'deux rôles homonymes, deux identifiants');
        self::assertNotSame(self::EDIT_ITEM, self::EDIT_SPACE);
    }

    /**
     * **« DÉPOSER SANS POUVOIR EFFACER » N'EST PAS EXPRIMABLE, ET ON LE DIT.**
     *
     * Le seul candidat (`Can upload`) accorde en plus le renommage, que le contrat
     * des verbes définit comme *créer + supprimer* : il donnerait donc une partie
     * de `supprimer` que l'octroi ne porte pas. On retient le plus restrictif
     * compatible, et `creer` est déclaré NON TRANSMIS — jamais l'inverse.
     */
    #[Test]
    public function create_without_delete_degrades_to_the_most_restrictive_and_names_what_is_lost(): void
    {
        $resolved = OpenCloudRoleTable::resolve(
            [PlanGrant::VERB_LIRE, PlanGrant::VERB_CREER],
            OpenCloudRoleTable::FAMILY_ITEM,
        );

        self::assertNotNull($resolved);
        self::assertSame(self::VIEW_ITEM, $resolved['id'], 'jamais l\'arrondi vers le haut');
        self::assertNotSame(self::UPLOAD_ITEM, $resolved['id']);
        self::assertSame([PlanGrant::VERB_CREER], $resolved['missing'], 'le verbe perdu DOIT être nommé');
    }

    /** Même règle pour `lire + editer` : aucun rôle ne la porte exactement. */
    #[Test]
    public function read_and_edit_without_create_also_degrades_and_says_so(): void
    {
        $resolved = OpenCloudRoleTable::resolve(
            [PlanGrant::VERB_LIRE, PlanGrant::VERB_EDITER],
            OpenCloudRoleTable::FAMILY_ITEM,
        );

        self::assertSame(self::VIEW_ITEM, $resolved['id']);
        self::assertSame([PlanGrant::VERB_EDITER], $resolved['missing']);
    }

    /**
     * **UN OCTROI EXPLICITEMENT VIDE N'EXISTE PAS DANS CE MODÈLE.** Mesuré :
     * `roles: []` et `actions: []` sont refusés, et le minimum acceptable rend le
     * dossier VISIBLE chez son destinataire. La table le dit par `null`, et le
     * backend en fait un `non_exprimable` constaté.
     */
    #[Test]
    public function an_empty_grant_has_no_role_at_all(): void
    {
        self::assertNull(OpenCloudRoleTable::resolve([], OpenCloudRoleTable::FAMILY_ITEM));
        self::assertNull(OpenCloudRoleTable::resolve([], OpenCloudRoleTable::FAMILY_SPACE));
    }

    /**
     * **LE RÔLE D'ADMINISTRATION N'EST JAMAIS LE RÉSULTAT D'UNE TRADUCTION.** Il
     * porte la gestion des membres, qu'aucun verbe ne décrit — c'est le pendant
     * exact du droit de re-partage refusé sur l'autre produit.
     */
    #[Test]
    public function the_manage_role_is_never_produced_by_any_verb_combination(): void
    {
        foreach ($this->everyVerbCombination() as $verbs) {
            foreach ([OpenCloudRoleTable::FAMILY_ITEM, OpenCloudRoleTable::FAMILY_SPACE] as $family) {
                $resolved = OpenCloudRoleTable::resolve($verbs, $family);
                if ($resolved === null) {
                    continue;
                }
                self::assertNotSame(
                    OpenCloudRoleTable::MANAGE_ROLE_ID,
                    $resolved['id'],
                    'le rôle d\'administration a été octroyé pour ' . implode('+', $verbs),
                );
            }
        }

        // Et la relecture ne le prend pas pour un rôle du plan : c'est `isKnown()`
        // — le seul chemin que la production emprunte — qui le dit.
        self::assertFalse(OpenCloudRoleTable::isKnown(OpenCloudRoleTable::MANAGE_ROLE_ID));
    }

    /**
     * **JAMAIS UN VERBE DE MUTATION QUE L'OCTROI NE PORTE PAS**, sur TOUTE
     * combinaison. C'est la propriété centrale de la traduction, et elle se vérifie
     * exhaustivement : quinze combinaisons, deux familles, aucune exception.
     */
    #[Test]
    public function no_combination_ever_conveys_a_verb_it_was_not_given(): void
    {
        foreach ($this->everyVerbCombination() as $verbs) {
            foreach ([OpenCloudRoleTable::FAMILY_ITEM, OpenCloudRoleTable::FAMILY_SPACE] as $family) {
                $resolved = OpenCloudRoleTable::resolve($verbs, $family);
                if ($resolved === null) {
                    continue;
                }

                self::assertSame(
                    [],
                    array_diff($resolved['verbs'], $verbs),
                    sprintf(
                        'le rôle « %s » transmet un verbe non demandé pour %s',
                        $resolved['label'],
                        implode('+', $verbs),
                    ),
                );
            }
        }
    }

    /** La reprojection inverse : un identifiant inconnu ne se devine JAMAIS. */
    #[Test]
    public function an_unknown_role_identifier_yields_no_verb_and_is_flagged(): void
    {
        self::assertSame([], OpenCloudRoleTable::verbsOf('00000000-0000-0000-0000-000000000000'));
        self::assertFalse(OpenCloudRoleTable::isKnown('00000000-0000-0000-0000-000000000000'));

        self::assertSame([PlanGrant::VERB_LIRE], OpenCloudRoleTable::verbsOf(self::VIEW_ITEM));
        self::assertSame(PlanGrant::VERBS, OpenCloudRoleTable::verbsOf(self::EDIT_ITEM));
    }

    /** @return list<list<string>> les quinze combinaisons non vides */
    private function everyVerbCombination(): array
    {
        $combinations = [];
        for ($mask = 1; $mask < 16; $mask++) {
            $verbs = [];
            foreach (PlanGrant::VERBS as $index => $verb) {
                if (($mask & (1 << $index)) !== 0) {
                    $verbs[] = $verb;
                }
            }
            $combinations[] = $verbs;
        }

        return $combinations;
    }
}
