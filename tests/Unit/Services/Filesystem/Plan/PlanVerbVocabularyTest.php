<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Plan;

use App\Exceptions\Filesystem\PlanResolutionException;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanSubject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 62.4 — LE VOCABULAIRE DES QUATRE VERBES, et le contrat sémantique qui le
 * rend utilisable.
 *
 * `TestCase` PUR, aucune base : le plan se teste sans rien autour, et c'est ce qui
 * rend ses tests rapides et sa sortie rejouable (régime établi en 60.1).
 */
class PlanVerbVocabularyTest extends TestCase
{
    private function grant(array $verbs): PlanGrant
    {
        return new PlanGrant('equipe', PlanSubject::group(1), $verbs);
    }

    // =========================================================================
    // AC1 — le vocabulaire
    // =========================================================================

    #[Test]
    public function the_vocabulary_is_exactly_four_verbs_in_declaration_order(): void
    {
        self::assertSame(['lire', 'editer', 'creer', 'supprimer'], PlanGrant::VERBS);

        // L'ordre canonique n'est PAS l'ordre alphabétique — et c'est le point :
        // il est celui de la déclaration, choisi pour être lisible, et figé parce
        // que le déterminisme octet pour octet de la story 60.1 en dépend.
        $alphabetical = PlanGrant::VERBS;
        sort($alphabetical);
        self::assertNotSame($alphabetical, PlanGrant::VERBS);

        // Les trois verbes de MUTATION sont les quatre moins la lecture.
        self::assertSame(['editer', 'creer', 'supprimer'], PlanGrant::MUTATION_VERBS);
    }

    #[Test]
    public function the_old_binary_vocabulary_no_longer_exists_in_the_plan_namespace(): void
    {
        // Grep de SORTIE : le namespace du plan ne porte plus une seule occurrence
        // des trois constantes abandonnées. Une exécution qui les trouverait
        // signalerait un vestige que la relecture aurait laissé passer.
        $dir = dirname(__DIR__, 5) . '/app/Services/Filesystem/Plan';
        $offenders = [];

        foreach ((array) glob($dir . '/*.php') as $file) {
            $content = (string) file_get_contents((string) $file);
            foreach (['ACCESS_RO', 'ACCESS_RW', 'ACCESSES'] as $abandoned) {
                if (str_contains($content, $abandoned)) {
                    $offenders[] = basename((string) $file) . ' → ' . $abandoned;
                }
            }
        }

        self::assertSame([], $offenders, 'vocabulaire binaire résiduel dans le namespace du plan');
    }

    #[Test]
    public function a_grant_refuses_an_empty_list_an_unknown_verb_and_the_old_scalar(): void
    {
        foreach ([[], ['ro'], ['rw'], ['rwx'], ['none'], ['deny'], [''], [null], [1]] as $forbidden) {
            $rejected = false;
            try {
                $this->grant($forbidden);
            } catch (PlanResolutionException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'octroi accepté à tort : ' . json_encode($forbidden));
        }
    }

    #[Test]
    public function the_refusal_message_names_the_whole_vocabulary(): void
    {
        try {
            $this->grant(['ecrire']);
            self::fail('un verbe inconnu doit être refusé');
        } catch (PlanResolutionException $e) {
            self::assertStringContainsString('lire|editer|creer|supprimer', $e->getMessage());
        }
    }

    #[Test]
    public function verbs_are_deduplicated_and_reordered_canonically(): void
    {
        self::assertSame(
            ['lire', 'editer', 'creer', 'supprimer'],
            $this->grant(['supprimer', 'creer', 'editer', 'lire', 'lire'])->verbs,
        );
    }

    #[Test]
    public function the_sort_key_is_stable_whatever_the_input_order(): void
    {
        self::assertSame(
            $this->grant(['lire', 'creer'])->sortKey(),
            $this->grant(['creer', 'lire'])->sortKey(),
        );
        self::assertNotSame(
            $this->grant(['lire', 'creer'])->sortKey(),
            $this->grant(['lire', 'supprimer'])->sortKey(),
        );
    }

    /**
     * La doctrine « Positif, toujours » survit INTÉGRALEMENT au changement de
     * vocabulaire : rien dans un octroi ne permet d'exprimer une interdiction, et
     * la suspension reste un drapeau, jamais un niveau.
     */
    #[Test]
    public function the_positive_only_doctrine_survives_and_suspension_stays_a_flag(): void
    {
        $grant = new PlanGrant('equipe', PlanSubject::group(1), PlanGrant::VERBS, suspendable: true);

        self::assertTrue($grant->isActive());
        $suspended = $grant->suspend();

        self::assertFalse($suspended->isActive());
        // Les verbes RESTENT écrits : suspendre n'efface rien.
        self::assertSame(PlanGrant::VERBS, $suspended->verbs);
        self::assertSame(
            ['role', 'subject', 'verbs', 'suspendable', 'suspended'],
            array_keys($suspended->toArray()),
            'aucun champ d\'interdiction, aucune priorité : la forme sérialisée est fermée',
        );
    }

    // =========================================================================
    // AC1 — LE CONTRAT SÉMANTIQUE Q2, ÉPINGLÉ AU DOCBLOCK
    // =========================================================================

    /**
     * **Pourquoi un test de DOCUMENTATION.**
     *
     * Le périmètre d'« éditer » n'est vérifiable par aucune assertion de
     * comportement : le plan ne renomme rien, ne déplace rien, n'exécute rien. Il
     * ne vit que dans la tête de celui qui écrit une recette et de celui qui écrit
     * un backend — et deux backends qui le devineraient différemment produiraient
     * deux systèmes de droits différents à partir de la même recette.
     *
     * Le seul endroit où ce contrat peut vivre est donc le docblock du vocabulaire,
     * et la seule façon de l'empêcher de disparaître à la première réécriture est
     * de l'épingler ici. Le dépôt scanne déjà ses propres sources (gardes
     * d'architecture) : le procédé est établi.
     */
    #[Test]
    public function the_vocabulary_carries_the_q2_semantic_contract_in_full_words(): void
    {
        $raw = (string) file_get_contents(
            dirname(__DIR__, 5) . '/app/Services/Filesystem/Plan/PlanGrant.php'
        );

        // Le docblock est RETOURNÉ À LA LIGNE : chercher des phrases dans la source
        // brute rendrait le test faux au premier reformatage. On aplatit d'abord —
        // le contrat est une PHRASE, pas une mise en page.
        $source = (string) preg_replace('/\s+/u', ' ', str_replace('*', ' ', $raw));

        foreach ([
            'le périmètre exact d\'éditer' => 'modifier le CONTENU d\'un fichier EXISTANT, et RIEN D\'AUTRE',
            'renommer, nommé' => 'renommer',
            'renommer = créer + supprimer' => 'créer + supprimer',
            'déplacer, nommé' => 'déplacer',
            'déplacer = supprimer puis créer' => 'supprimer à la source + créer à destination',
            'le POURQUOI : le bit du fichier' => 'SUR LE FICHIER',
            'le POURQUOI : le bit du dossier' => 'SUR LE DOSSIER',
            'le POURQUOI : la même découpe ailleurs' => 'quatre bits séparés',
        ] as $label => $needle) {
            self::assertStringContainsString(
                $needle,
                $source,
                sprintf(
                    'le contrat sémantique Q2 a perdu « %s » : sans lui, le périmètre d\'« éditer » '
                    . 'redevient une devinette, et deux backends le devineront différemment.',
                    $label,
                ),
            );
        }
    }

    // =========================================================================
    // AC2 — la sérialisation, et le refus NOMMÉ de l'ancien monde
    // =========================================================================

    #[Test]
    public function the_plan_format_version_moved_to_two(): void
    {
        self::assertSame(2, FilePlan::VERSION);
    }

    #[Test]
    public function a_grant_serialized_with_the_abandoned_access_key_is_refused_by_name(): void
    {
        $payload = [
            'role' => 'equipe',
            'subject' => PlanSubject::group(1)->toArray(),
            'access' => 'rw',
            'suspendable' => false,
            'suspended' => false,
        ];

        try {
            PlanGrant::fromArray($payload);
            self::fail('un octroi au vocabulaire abandonné doit être refusé');
        } catch (PlanResolutionException $e) {
            self::assertStringContainsString('ABANDONNÉ', $e->getMessage());
            self::assertStringContainsString('access', $e->getMessage());
            // La VOIE DE SORTIE est dans le message : un refus sans issue est un
            // mur, pas une garde.
            self::assertStringContainsString('re-résoudre le plan depuis la source SQL', $e->getMessage());
        }
    }

    #[Test]
    public function a_grant_serialized_without_any_verb_list_is_refused(): void
    {
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/sans liste de verbes/u');

        PlanGrant::fromArray([
            'role' => 'equipe',
            'subject' => PlanSubject::group(1)->toArray(),
        ]);
    }

    #[Test]
    public function the_round_trip_of_a_grant_loses_nothing(): void
    {
        $grant = new PlanGrant('equipe', PlanSubject::group(3, 'manager'), ['creer', 'lire'], true, true);
        $revived = PlanGrant::fromArray($grant->toArray());

        self::assertEquals($grant->toArray(), $revived->toArray());
        self::assertSame($grant->sortKey(), $revived->sortKey());
    }
}
