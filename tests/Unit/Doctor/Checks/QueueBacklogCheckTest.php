<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Checks;

use App\Doctor\Checks\Queue\QueueBacklogCheck;
use App\Doctor\Level;
use App\Jobs\ReconcileNetworkShareJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.5 — le contrôle qui voit l'OUVRIER MORT.
 *
 * Sa valeur tient à une seule propriété : il alerte sur l'ANCIENNETÉ, jamais sur
 * le VOLUME. Un contrôle qui crierait dès qu'une file est chargée apprendrait à
 * l'exploitant à l'ignorer — et un contrôle ignoré est pire qu'un contrôle absent.
 */
class QueueBacklogCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function ($table): void {
                $table->id();
                $table->string('queue');
                $table->text('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }

    private function enqueue(int $agedSeconds, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => now()->subSeconds($agedSeconds)->getTimestamp(),
            'created_at' => now()->subSeconds($agedSeconds)->getTimestamp(),
        ]);
    }

    #[Test]
    public function an_empty_queue_is_healthy(): void
    {
        $result = (new QueueBacklogCheck())->run();

        $this->assertSame(Level::Ok, $result->level);
    }

    /**
     * **Le faux positif à ne PAS produire.** Mille travaux posés à l'instant : la
     * file est chargée, l'ouvrier va les prendre. Rien d'anormal.
     */
    #[Test]
    public function a_busy_but_living_queue_never_triggers(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->enqueue(agedSeconds: 5);
        }

        $result = (new QueueBacklogCheck())->run();

        $this->assertSame(Level::Ok, $result->level, 'le volume n\'est PAS le critère');
    }

    /** Un travail RÉSERVÉ est en cours de traitement : c'est un ouvrier qui travaille. */
    #[Test]
    public function a_long_running_reserved_job_never_triggers(): void
    {
        $this->enqueue(agedSeconds: 7200, reservedAt: now()->subSeconds(60)->getTimestamp());

        $this->assertSame(Level::Ok, (new QueueBacklogCheck())->run()->level);
    }

    /** UN SEUL travail disponible et vieux suffit : c'est l'ancienneté qui parle. */
    #[Test]
    public function a_single_stale_available_job_is_enough_to_warn(): void
    {
        $this->enqueue(agedSeconds: 1800);

        $result = (new QueueBacklogCheck())->run();

        $this->assertSame(Level::Warn, $result->level);
        $this->assertStringContainsString('sans être pris', $result->detail);
        $this->assertNotNull($result->fix);
        $this->assertStringContainsString('ouvrier', (string) $result->fix, 'le message doit NOMMER le remède');
    }

    /** Juste sous le seuil : rien. La frontière est nette, pas floue. */
    #[Test]
    public function a_job_just_under_the_threshold_stays_silent(): void
    {
        $this->enqueue(agedSeconds: 600);

        $this->assertSame(Level::Ok, (new QueueBacklogCheck())->run()->level);
    }

    /**
     * Sans file (exécution à la volée), la question ne se pose pas — et on le dit.
     *
     * C'est le pilote de LA CONNEXION DU TRAVAIL qui décide, pas le réglage par
     * défaut de l'application : la réconciliation épingle sa connexion.
     */
    #[Test]
    public function the_synchronous_driver_has_nothing_to_watch(): void
    {
        config(['queue.connections.' . ReconcileNetworkShareJob::CONNECTION . '.driver' => 'sync']);

        $result = (new QueueBacklogCheck())->run();

        $this->assertSame(Level::Ok, $result->level);
        $this->assertStringContainsString('aucune file', $result->detail);
    }

    /**
     * LE CAS QUI RENDAIT CE CONTRÔLE MENSONGER.
     *
     * La réconciliation épingle sa connexion pour survivre à un redémarrage,
     * indépendamment du réglage par défaut. Tant que le contrôle interrogeait ce
     * réglage, il suffisait que l'application pointe ailleurs pour qu'il rende
     * « rien à signaler » — pendant que les travaux s'empilaient dans la table
     * qu'il ne regardait plus. Un contrôle qui rate exactement ce pour quoi il
     * existe est pire que pas de contrôle : il rassure.
     */
    #[Test]
    public function a_different_default_connection_does_not_blind_the_check(): void
    {
        $this->enqueue(agedSeconds: 4000);
        config(['queue.default' => 'redis']);

        $result = (new QueueBacklogCheck())->run();

        $this->assertSame(Level::Warn, $result->level);
    }

    /** Le contrôle est découvert automatiquement, sous son étiquette. */
    #[Test]
    public function it_declares_its_tag_and_name_in_french(): void
    {
        $check = new QueueBacklogCheck();

        $this->assertSame('queue', $check->tag());
        $this->assertStringContainsString('File', $check->name());
    }
}
