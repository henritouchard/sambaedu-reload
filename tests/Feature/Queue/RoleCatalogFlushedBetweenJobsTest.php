<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Support\RoleCatalog;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Review 62.1 #1 — le catalogue de rôles est relu au début de CHAQUE job.
 *
 * **Le défaut que ce test épingle.** `RoleCatalog` mémoïse dans une propriété
 * statique, et `flush()` n'est déclenchée que par les hooks d'écriture du modèle
 * — donc uniquement dans le processus qui a écrit. Sous PHP-FPM, chaque requête
 * repart d'un moteur neuf et la question ne se pose pas. Un worker
 * `queue:work --queue=sync --max-time=3600`
 * (`scripts/config/laravel-queue-sync.service`) est au contraire un process CLI
 * qui enchaîne les jobs sans réinitialiser les statiques : un rôle créé à
 * l'écran restait invisible du worker pendant une heure.
 *
 * **Pourquoi ça comptait vraiment.** `UserGroupService` (projection AD) lit
 * `UserGroupUserPivot::roles()` et traite toute valeur absente du vocabulaire
 * comme « hors vocabulaire » : l'arête `tuteur` d'un enseignant aurait été
 * projetée avec le rôle DÉRIVÉ, dans le mauvais groupe d'annuaire.
 *
 * Le test écrit VOLONTAIREMENT en SQL nu (`DB::table()->insert()`), pour
 * court-circuiter les hooks Eloquent : c'est la seule façon de reproduire, dans
 * un process unique, ce qu'un worker voit d'une écriture faite AILLEURS.
 *
 * Patron du déclenchement : `QueueTaskRunCreatedAtPreservationTest` (story 29.9)
 * — `Queue::before()` écoute `JobProcessing` sur l'event dispatcher, il suffit
 * donc de publier l'événement, sans dispatcher de vrai job.
 */
class RoleCatalogFlushedBetweenJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fireJobProcessing(): void
    {
        /** @var Job&\Mockery\MockInterface $job */
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['uuid' => 'role-catalog-flush', 'displayName' => 'TestJob']);
        $job->shouldReceive('getQueue')->andReturn('sync');
        $job->shouldReceive('getRawBody')->andReturn('{}');

        event(new JobProcessing('sync', $job));
    }

    /** Insertion SQL nue : aucun hook Eloquent, donc aucun `flush()` implicite. */
    private function insertRoleWithoutModelEvents(string $key, string $label): void
    {
        DB::table('group_roles')->insert([
            'key' => $key,
            'label' => $label,
            'sort_order' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_role_created_elsewhere_stays_invisible_until_the_next_job_starts(): void
    {
        // Le worker a déjà traité un job : la mémo est chaude, sans « tuteur ».
        RoleCatalog::flush();
        $this->assertNotContains('tuteur', RoleCatalog::keys());

        // Un admin crée le rôle depuis l'écran — dans un AUTRE processus.
        $this->insertRoleWithoutModelEvents('tuteur', 'Tuteur');

        // Sans relecture, le worker ne le voit toujours pas : c'est le défaut.
        $this->assertNotContains(
            'tuteur',
            RoleCatalog::keys(),
            'Prémisse du test : la mémo statique survit bien à une écriture externe.',
        );

        // Le job suivant démarre → le catalogue est relu.
        $this->fireJobProcessing();

        $this->assertContains(
            'tuteur',
            RoleCatalog::keys(),
            'Un job doit repartir du catalogue : sinon la projection AD traite « tuteur » '
            . 'comme hors vocabulaire et applique le rôle dérivé.',
        );
    }

    #[Test]
    public function the_edge_role_guard_accepts_a_role_created_elsewhere_once_the_job_starts(): void
    {
        // La conséquence directe, prise du côté de la garde d'arête.
        RoleCatalog::flush();
        RoleCatalog::keys();

        $this->insertRoleWithoutModelEvents('referent_projet', 'Référent de projet');

        $this->fireJobProcessing();

        $this->assertContains('referent_projet', \App\Models\Pivot\UserGroupUserPivot::roles());
    }
}
