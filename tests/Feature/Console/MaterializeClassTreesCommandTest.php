<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\NetworkShare;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\Filesystem\ClassTreeShareService;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.5 — la VOIE DE PEUPLEMENT de l'arbre neuf.
 *
 * Ce que la commande doit tenir : peupler le parc EXISTANT (la création de groupe
 * ne couvre que l'avenir), s'exécuter en DIRECT (une commande est hors requête, son
 * code de retour doit garder son sens), et ne jamais toucher l'arbre historique.
 */
class MaterializeClassTreesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Process::fake([
            'getent group *' => Process::result(),
            '*' => Process::result(),
        ]);
        UserGroupObserver::disableSync();
        // Les classes du décor existent AVANT la recette : c'est exactement la
        // situation d'une instance en place, et la raison d'être de la commande.
        ClassTreeShareService::disable();

        config([
            'filesystem.shares_root' => '/var/sambaedu/Partages',
            'filesystem.class_trees_root' => '/var/sambaedu/ClassesSE5',
        ]);
    }

    protected function tearDown(): void
    {
        ClassTreeShareService::enable();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function seedRecipes(): void
    {
        (new DirectoryTemplateSeeder())->run();
        ClassTreeShareService::enable();
    }

    #[Test]
    public function without_any_attached_tree_recipe_the_command_says_so_and_fails(): void
    {
        UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);

        $this->artisan('shares:materialize-class-trees')
            ->expectsOutputToContain('Aucune recette d\'arbre')
            ->assertExitCode(2);

        $this->assertSame(0, NetworkShare::count());
    }

    #[Test]
    public function the_dry_run_lists_without_creating_or_writing_anything(): void
    {
        UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        UserGroup::create(['name' => 'Classe_3emeB', 'type' => 'classe']);
        $this->seedRecipes();

        $this->artisan('shares:materialize-class-trees --dry-run')
            ->expectsOutputToContain('arbre à créer')
            ->assertExitCode(0);

        $this->assertSame(0, NetworkShare::count(), 'une simulation ne crée RIEN');
        Process::assertNothingRan();
    }

    #[Test]
    public function it_materializes_the_existing_class_groups(): void
    {
        UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        UserGroup::create(['name' => 'Classe_3emeB', 'type' => 'classe']);
        UserGroup::create(['name' => 'Robotique', 'type' => 'projet']);
        $this->seedRecipes();

        $this->artisan('shares:materialize-class-trees')->assertExitCode(0);

        $this->assertSame(2, NetworkShare::whereNotNull('directory_template_id')->count());
        $this->assertSame(
            ['Classe_3emeA', 'Classe_3emeB'],
            NetworkShare::orderBy('directory_name')->pluck('directory_name')->all(),
        );
    }

    #[Test]
    public function the_class_option_narrows_the_run_and_accepts_both_name_forms(): void
    {
        UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        UserGroup::create(['name' => 'Classe_3emeB', 'type' => 'classe']);
        $this->seedRecipes();

        // Nom stocké préfixé, saisi NU : les instances en place portent les deux
        // formes, et l'exploitant n'a pas à deviner laquelle est la sienne.
        $this->artisan('shares:materialize-class-trees --class=3emeA')->assertExitCode(0);

        $this->assertSame(['Classe_3emeA'], NetworkShare::pluck('directory_name')->all());
    }

    /** Rejouée, elle ne double rien : la ligne existe déjà, l'état est relu. */
    #[Test]
    public function running_it_twice_creates_nothing_new(): void
    {
        UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $this->seedRecipes();

        $this->artisan('shares:materialize-class-trees')->assertExitCode(0);
        $this->artisan('shares:materialize-class-trees')->assertExitCode(0);

        $this->assertSame(1, NetworkShare::count());
    }

    /**
     * **Une classe dont les groupes d'annuaire ne se résolvent pas est SAUTÉE — et
     * rien n'est créé, ni ligne ni dossier.**
     *
     * Cette règle a REMPLACÉ la précédente, qui créait quand même la ligne « pour
     * qu'elle porte la trace du refus ». L'intention était bonne et la mesure sur
     * instance l'a démentie : 129 des 150 classes d'un parc réel n'ont aucun groupe
     * d'annuaire résolvable, vestiges d'imports successifs. Le premier passage a
     * donc créé **301 lignes de partage et 301 dossiers**, dont environ 280
     * durablement en erreur dans la liste des partages — une trace que personne ne
     * peut lire, qui masque les vrais défauts, et qui salit l'écran pour toujours.
     *
     * Le pré-contrôle passe désormais AVANT toute création, comme celui de l'arbre
     * historique, qui saute exactement les mêmes classes depuis toujours. Sauté
     * n'est pas échoué : le code de retour ne s'en émeut pas.
     */
    #[Test]
    public function a_class_whose_directory_groups_do_not_resolve_is_skipped_without_creating_anything(): void
    {
        Process::fake([
            'getent group *' => Process::result(output: '', errorOutput: '', exitCode: 2),
            '*' => Process::result(),
        ]);

        UserGroup::create(['name' => 'Classe_Dechet', 'type' => 'classe']);
        $this->seedRecipes();

        $this->artisan('shares:materialize-class-trees')
            ->expectsOutputToContain('1 sautée(s)')
            ->assertExitCode(0);

        // AUCUNE ligne : c'est tout l'objet du changement.
        $this->assertSame(0, NetworkShare::count());

        // Et aucun dossier : le pré-contrôle passe avant la création.
        Process::assertNotRan(fn (PendingProcess $p): bool => str_contains((string) $p->command, 'mkdir'));

        // Ce qui n'a JAMAIS été écrit, c'est l'octroi lui-même : aucune commande
        // émise ne nomme un groupe que le serveur ne résout pas. C'est le point
        // exact de l'incident mesuré — poser une entrée sur un nom inconnu échoue
        // avec un « argument invalide », ou pire, reste sans effet.
        // La SONDE, elle, a bien le droit de prononcer ces noms : c'est son travail
        // de demander s'ils existent. Ce qui est interdit, c'est de les POSER.
        Process::assertNotRan(function (PendingProcess $process): bool {
            $command = (string) $process->command;

            return str_contains($command, 'setfacl')
                && (str_contains($command, 'equipe_dechet') || str_contains($command, 'classe_dechet'));
        });
    }

    /**
     * **L'arbre HISTORIQUE n'est jamais touché.** Aucune commande émise ne vise sa
     * racine — c'est la promesse de la story, éprouvée sur les processus réellement
     * lancés plutôt que sur une intention.
     */
    #[Test]
    public function no_emitted_command_ever_touches_the_legacy_class_tree(): void
    {
        UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $this->seedRecipes();

        $this->artisan('shares:materialize-class-trees')->assertExitCode(0);

        Process::assertNotRan(function (PendingProcess $process): bool {
            $command = (string) $process->command;

            // Comparaison avec le SÉPARATEUR : la racine neuve partage un préfixe
            // avec l'arbre historique, et une comparaison de préfixe nu ferait
            // échouer ce test sur un chemin parfaitement légitime.
            return str_contains($command, '/var/sambaedu/Classes/')
                || str_contains($command, "/var/sambaedu/Classes'")
                || str_ends_with($command, '/var/sambaedu/Classes');
        });
    }
}
