<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\FileBackendName;
use App\Enums\FileBackendObservation;
use App\Enums\FileBackendOutcome;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.3 — le VOCABULAIRE du contrat de backend, épinglé.
 *
 * Trois enums fermées, et trois propriétés qu'on ne veut pas voir dériver : la
 * colonne n'accueille que ce que le code sait résoudre, les sept résultats disent
 * sept choses différentes, et les deux déclins ne se confondent jamais.
 */
class FileBackendVocabularyTest extends TestCase
{
    // =========================================================================
    // Le nom : deux cases, et pas une de plus
    // =========================================================================

    /**
     * **STORY 61.3 — LA GARDE A CHANGÉ D'OBJET, ET CE N'EST PAS UN AFFAIBLISSEMENT.**
     *
     * Elle s'appelait « aucun backend distant n'a de valeur de colonne » et elle
     * protégeait le squelette jetable de la story 60.3 : rien, hors de l'arbre, ne
     * pouvait se faire choisir faute d'un nom. Cette phrase décrivait un ÉTAT DATÉ —
     * l'epic 61 avait pour objet de l'annuler, et elle est annulée : un backend
     * distant réel existe, il est enregistré, il est sélectionnable.
     *
     * Ce que l'ancienne garde protégeait RÉELLEMENT, c'est autre chose, et c'est
     * permanent : **aucune case ne doit exister sans implémentation.** Une position
     * déclarée que le système ne sait pas tenir est le défaut que tout cet epic
     * combat — un signal accepté qui n'atteint pas son destinataire. La garde porte
     * désormais là-dessus, et elle est plus forte : elle vaudra encore quand un
     * quatrième nom arrivera.
     *
     * L'absence de toute case « déléguée » reste épinglée, elle : le mode
     * « instance non administrée » a été SUPPRIMÉ du produit le 2026-08-08 (mesuré :
     * un compte ordinaire ne peut créer ni dossier d'équipe, ni groupe — donc pas de
     * cloisonnement), et il n'y a rien à rouvrir pour lui, ni maintenant ni plus
     * tard.
     *
     * ---------------------------------------------------------------------------
     * **LA LISTE PASSE À QUATRE : `opencloud` ENTRE AU VOCABULAIRE.** La liste
     * exacte est retouchée, et c'est la SEULE chose qui bouge dans ce test.
     * L'invariant permanent — *chaque case résout dans le registre* — est repris
     * mot pour mot en dessous, et c'est lui qui portait déjà tout le poids : la
     * garde annoncée en 61.3 disait qu'elle « vaudrait encore quand un quatrième
     * nom arriverait ». Il est arrivé, et elle vaut.
     *
     * Pourquoi cette case-là est légitime quand la case déléguée ne l'était pas :
     * la case déléguée déclarait une position que le PRODUIT ne savait pas tenir
     * (pas de cloisonnement possible). Celle-ci arrive avec sa mesure — contre une
     * instance réelle, le 2026-08-13 : espace de projet créé, octroi posé par
     * sous-dossier à un principal groupe, et un compte sans octroi qui obtient
     * `404` plutôt qu'un accès. Le cloisonnement, lui, est bien là.
     * ---------------------------------------------------------------------------
     */
    #[Test]
    public function the_column_vocabulary_is_exact_and_every_case_resolves(): void
    {
        $this->assertSame(['posix', 'preview', 'nextcloud', 'opencloud'], FileBackendName::values());

        foreach (FileBackendName::values() as $value) {
            $this->assertStringNotContainsStringIgnoringCase('delegue', $value);
        }

        // L'INVARIANT PERMANENT : chaque case résout au registre.
        $registry = app(\App\Services\Filesystem\Backend\FileBackendRegistry::class);

        foreach (FileBackendName::cases() as $case) {
            $this->assertTrue(
                $registry->has($case),
                sprintf('la case « %s » est déclarée sans implémentation : le système ne peut pas la tenir', $case->value),
            );
            $this->assertSame($case, $registry->get($case)->name());
        }
    }

    #[Test]
    public function every_backend_name_has_a_human_label_and_no_raw_value_reaches_the_screen(): void
    {
        foreach (FileBackendName::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame($case->value, $case->label());
            $this->assertNotSame('', $case->description());
        }
    }

    #[Test]
    public function an_unknown_column_value_is_never_part_of_the_vocabulary(): void
    {
        // Story 61.3 — `nextcloud` a rejoint le vocabulaire ; la case DÉLÉGUÉE, elle,
        // n'y entrera jamais (elle a été supprimée du produit, pas reportée).
        //
        // `opencloud` figurait ici comme exemple d'inconnu — c'était l'état daté
        // d'un backend annoncé et non écrit. Il est écrit, mesuré et enregistré :
        // il PASSE donc du côté des valeurs connues, et le témoin d'inconnu devient
        // une valeur qui n'a jamais été annoncée nulle part.
        $this->assertFalse(FileBackendName::isKnown('nextcloud_delegue'));
        $this->assertFalse(FileBackendName::isKnown('dropbox'));
        $this->assertFalse(FileBackendName::isKnown(null));
        $this->assertTrue(FileBackendName::isKnown('posix'));
        $this->assertTrue(FileBackendName::isKnown('nextcloud'));
        $this->assertTrue(FileBackendName::isKnown('opencloud'));
    }

    // =========================================================================
    // Les sept résultats
    // =========================================================================

    #[Test]
    public function there_are_exactly_seven_outcomes(): void
    {
        $this->assertSame([
            'conforme',
            'applique',
            'en_attente',
            'echec',
            'non_exprimable',
            'non_implemente',
            'non_execute',
        ], FileBackendOutcome::values());
    }

    #[Test]
    public function the_seven_outcomes_have_seven_distinct_labels(): void
    {
        $labels = array_map(static fn (FileBackendOutcome $o): string => $o->label(), FileBackendOutcome::cases());

        $this->assertCount(7, array_unique($labels), 'deux résultats qui se lisent pareil se confondront à l\'écran');
    }

    /**
     * LA CORRECTION, épinglée : les deux déclins ne sont pas interchangeables.
     * Le permanent est une limite du MODÈLE du backend ; le temporaire est une
     * dette de NOTRE code. Les écraser l'un sur l'autre écrirait une
     * contre-vérité — le serveur de fichiers historique SAIT plafonner.
     */
    #[Test]
    public function the_two_declines_are_never_interchangeable(): void
    {
        $permanent = FileBackendOutcome::NonExprimable;
        $temporaire = FileBackendOutcome::NonImplemente;
        $parConception = FileBackendOutcome::NonExecute;

        foreach ([$permanent, $temporaire, $parConception] as $decline) {
            $this->assertTrue($decline->isDecline(), $decline->value);
        }

        $this->assertTrue($permanent->isModelLimit());
        $this->assertFalse($permanent->isImplementationDebt());
        $this->assertFalse($permanent->isByDesign());

        $this->assertTrue($temporaire->isImplementationDebt());
        $this->assertFalse($temporaire->isModelLimit());
        $this->assertFalse($temporaire->isByDesign());

        $this->assertTrue($parConception->isByDesign());
        $this->assertFalse($parConception->isModelLimit());
        $this->assertFalse($parConception->isImplementationDebt());

        $this->assertNotSame($permanent->label(), $temporaire->label());
    }

    #[Test]
    public function exactly_three_outcomes_require_a_detail(): void
    {
        $requiring = array_values(array_filter(
            FileBackendOutcome::cases(),
            static fn (FileBackendOutcome $o): bool => $o->requiresDetail(),
        ));

        $this->assertSame(
            [FileBackendOutcome::Echec, FileBackendOutcome::NonExprimable, FileBackendOutcome::NonImplemente],
            $requiring,
        );
    }

    #[Test]
    public function only_the_two_settled_states_count_as_converged(): void
    {
        $converged = array_values(array_filter(
            FileBackendOutcome::cases(),
            static fn (FileBackendOutcome $o): bool => $o->isConverged(),
        ));

        $this->assertSame([FileBackendOutcome::Conforme, FileBackendOutcome::Applique], $converged);
    }

    /**
     * Aucun code de transport dans le vocabulaire : les trois sémantiques natives
     * mesurées se normalisent en un ÉTAT, elles ne se nomment pas.
     */
    #[Test]
    public function no_outcome_value_looks_like_a_transport_code(): void
    {
        foreach (FileBackendOutcome::values() as $value) {
            $this->assertSame(0, preg_match('/\d/', $value), 'valeur numérique dans le vocabulaire : ' . $value);
            foreach (['http', 'status', 'code'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $value);
            }
        }
    }

    // =========================================================================
    // Les quatre observations
    // =========================================================================

    #[Test]
    public function the_observation_vocabulary_is_closed_and_distinguishes_absent_from_unobservable(): void
    {
        $this->assertSame(['observe', 'absent', 'non_observable', 'echec'], FileBackendObservation::values());

        $this->assertTrue(FileBackendObservation::Observe->carriesGrants());
        $this->assertFalse(FileBackendObservation::Absent->carriesGrants());
        $this->assertFalse(FileBackendObservation::NonObservable->carriesGrants());
        $this->assertFalse(FileBackendObservation::Echec->carriesGrants());

        $this->assertTrue(FileBackendObservation::Echec->requiresDetail());
        $this->assertNotSame(
            FileBackendObservation::Absent->label(),
            FileBackendObservation::NonObservable->label(),
        );
    }
}
