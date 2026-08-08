<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypeRoles;

use App\Models\GroupRole;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Support\RoleCatalog;
use Database\Seeders\GroupRoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.3 — LA COMMANDE qui installe le profil scolaire.
 *
 * Elle a repris les sept déclarations que la migration posait, et le contenu de
 * cette reprise est épinglé ICI, exhaustivement, `null` compris. Les deux
 * déclarations à `label = null` — `projet`×`member` et `equipe`×`member` — sont
 * l'écart assumé avec la constante défunte : si quelqu'un « corrige » la commande en
 * recopiant les cinq surcharges, ce fichier tombe, et il tombe avant que `member` ne
 * devienne inattribuable dans tout projet.
 *
 * Le second objet du fichier est le CONTRAT D'ÉCRITURE, qui n'existait nulle part
 * avant : additive par défaut, écrasante seulement sous `--resync`. Le seeder
 * supprimé faisait l'inverse — il resynchronisait sans rien demander — pendant que
 * la migration promettait de ne jamais réécrire un libellé local (contradiction
 * relevée en review 62.3 #2). Il n'y a plus qu'un geste, et il ne réécrit rien sans
 * qu'on le lui demande.
 */
class CollegeSeedRoleXTypeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'college:seed:role-x-type';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GroupRoleSeeder::class);

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    /** @return list<array{0:string,1:string,2:?string}> */
    private function declarations(): array
    {
        return DB::table('group_type_roles')
            ->orderBy('group_type_key')
            ->orderBy('group_role_key')
            ->get(['group_type_key', 'group_role_key', 'label'])
            ->map(fn (object $row): array => [
                (string) $row->group_type_key,
                (string) $row->group_role_key,
                $row->label === null ? null : (string) $row->label,
            ])
            ->all();
    }

    // =========================================================================
    // Ce que la commande POSE
    // =========================================================================

    /** LES SEPT LIGNES, exhaustivement, avec leurs libellés — et leurs `null`. */
    #[Test]
    public function it_lays_the_seven_school_declarations_on_an_empty_table(): void
    {
        $this->assertSame(0, DB::table('group_type_roles')->count(), 'la table doit naître vide');

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame([
            ['classe', 'manager', 'Enseignant'],
            ['classe', 'member', 'Élève'],
            ['classe', 'owner', 'Professeur principal'],
            // DÉCLARÉS SANS SURCHARGE — l'écart assumé avec la constante défunte.
            ['equipe', 'manager', 'Référent'],
            ['equipe', 'member', null],
            ['projet', 'manager', 'Porteur'],
            ['projet', 'member', null],
        ], $this->declarations());
    }

    /**
     * `owner` n'est déclaré QUE sur `classe` : c'est la donnée qui dit ce que la
     * garde D3 dit en littéral dans les écrans.
     */
    #[Test]
    public function owner_is_declared_on_the_class_type_and_nowhere_else(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame(
            ['classe'],
            DB::table('group_type_roles')->where('group_role_key', 'owner')->pluck('group_type_key')->all(),
        );
    }

    /** Le vocabulaire scolaire est lisible IMMÉDIATEMENT : la mémo est vidée. */
    #[Test]
    public function the_school_vocabulary_reads_right_after_the_command(): void
    {
        $this->assertSame('Membre', RoleCatalog::label('classe', 'member'));

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame('Élève', RoleCatalog::label('classe', 'member'));
        $this->assertSame('Porteur', RoleCatalog::label('projet', 'manager'));
        $this->assertSame(['member', 'manager'], RoleCatalog::assignableKeys('equipe'));
    }

    /**
     * Elle REND COMPTE, et surtout elle AVERTIT de ce qu'elle ferme — la
     * conséquence que personne n'a envie de découvrir seul.
     *
     * Lecture par `Artisan::output()` et non par `expectsOutputToContain()` : ce
     * dernier apparie une attente par ÉCRITURE, et le bloc d'avertissement en est
     * une seule — deux attentes portant sur le même bloc ne peuvent pas être
     * satisfaites à la fois.
     */
    #[Test]
    public function it_reports_what_it_did_and_warns_that_declaring_closes_a_type(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call(self::COMMAND));

        $output = Artisan::output();

        // Le compte rendu : les sept lignes, nommées, et leur bilan chiffré.
        foreach (['Élève', 'Enseignant', 'Professeur principal', 'Porteur', 'Référent'] as $label) {
            $this->assertStringContainsString($label, $output);
        }
        $this->assertStringContainsString('créée', $output);
        $this->assertStringContainsString('7 créée(s), 0 laissée(s) en place, 0 réalignée(s)', $output);

        // L'AVERTISSEMENT : déclarer FERME, et l'écran où lever la restriction est
        // nommé.
        $this->assertStringContainsString('FERME', $output);
        $this->assertStringContainsString('n\'y sera plus proposé', $output);
        $this->assertStringContainsString('Types de groupes', $output);
    }

    // =========================================================================
    // Idempotence, et le contrat ADDITIF
    // =========================================================================

    #[Test]
    public function replaying_it_creates_no_duplicate(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();
        $before = $this->declarations();

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame(7, DB::table('group_type_roles')->count());
        $this->assertSame($before, $this->declarations());
    }

    /**
     * LE CŒUR DU CONTRAT : un libellé changé à l'écran survit au rejeu.
     *
     * L'administrateur qui renomme « Enseignant » en « Professeur » depuis l'onglet
     * « Types de groupes » a pris une décision. Une commande d'installation ne la
     * défait pas au prochain déploiement.
     */
    #[Test]
    public function replaying_it_never_overwrites_a_local_label(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();

        DB::table('group_type_roles')
            ->where('group_type_key', 'classe')
            ->where('group_role_key', 'manager')
            ->update(['label' => 'Professeur']);
        RoleCatalog::flush();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('laissée en place')
            ->assertSuccessful();

        $this->assertSame('Professeur', RoleCatalog::label('classe', 'manager'));
        $this->assertSame(7, DB::table('group_type_roles')->count());
    }

    /** Une déclaration AJOUTÉE à la main n'est pas emportée non plus. */
    #[Test]
    public function replaying_it_leaves_administrator_declarations_alone(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();

        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);
        DB::table('group_type_roles')->insert([
            'group_type_key' => 'projet',
            'group_role_key' => 'tuteur',
            'label' => 'Parrain',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RoleCatalog::flush();

        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->artisan(self::COMMAND, ['--resync' => true])->assertSuccessful();

        $this->assertSame(8, DB::table('group_type_roles')->count());
        $this->assertSame('Parrain', RoleCatalog::label('projet', 'tuteur'));
    }

    // =========================================================================
    // `--resync` : le SEUL chemin qui écrase
    // =========================================================================

    #[Test]
    public function resync_realigns_an_edited_label_on_the_reference(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();

        DB::table('group_type_roles')
            ->where('group_type_key', 'classe')
            ->where('group_role_key', 'manager')
            ->update(['label' => 'Professeur']);
        RoleCatalog::flush();

        $this->artisan(self::COMMAND, ['--resync' => true])
            ->expectsOutputToContain('réalignée')
            ->assertSuccessful();

        $this->assertSame('Enseignant', RoleCatalog::label('classe', 'manager'));
        $this->assertSame(7, DB::table('group_type_roles')->count());
    }

    /**
     * `--resync` remet aussi un `null` là où la référence n'a pas de surcharge :
     * « déclaré sans surcharge » est une valeur de référence à part entière, pas
     * une absence de consigne.
     */
    #[Test]
    public function resync_restores_the_absence_of_override_too(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();

        DB::table('group_type_roles')
            ->where('group_type_key', 'projet')
            ->where('group_role_key', 'member')
            ->update(['label' => 'Participant']);
        RoleCatalog::flush();

        $this->artisan(self::COMMAND, ['--resync' => true])->assertSuccessful();

        $this->assertNull(
            DB::table('group_type_roles')
                ->where('group_type_key', 'projet')
                ->where('group_role_key', 'member')
                ->value('label'),
        );
        $this->assertSame('Membre', RoleCatalog::label('projet', 'member'));
    }

    /** Un `--resync` sur une table vide se comporte comme une installation. */
    #[Test]
    public function resync_on_an_empty_table_simply_installs(): void
    {
        $this->artisan(self::COMMAND, ['--resync' => true])->assertSuccessful();

        $this->assertSame(7, DB::table('group_type_roles')->count());
    }

    // =========================================================================
    // Le refus propre
    // =========================================================================

    /**
     * Migration non jouée : refus MÉTIER, code de sortie non nul, et la commande à
     * lancer d'abord est NOMMÉE.
     */
    #[Test]
    public function it_refuses_cleanly_when_the_table_does_not_exist(): void
    {
        Schema::dropIfExists('group_type_roles');

        $this->assertSame(Command::FAILURE, Artisan::call(self::COMMAND));

        $output = Artisan::output();
        $this->assertStringContainsString('group_type_roles', $output);
        $this->assertStringContainsString('migrate', $output);

        $this->assertFalse(Schema::hasTable('group_type_roles'), 'la commande ne crée pas la table');
    }

    /** Elle n'écrit RIEN hors de sa propre table. */
    #[Test]
    public function it_writes_nothing_outside_the_declaration_table(): void
    {
        $snapshots = [
            'group_roles' => DB::table('group_roles')->orderBy('id')->get()->toJson(),
            'group_types' => DB::table('group_types')->orderBy('id')->get()->toJson(),
            'user_groups' => DB::table('user_groups')->orderBy('id')->get()->toJson(),
            'user_group_user' => DB::table('user_group_user')->orderBy('user_id')->get()->toJson(),
        ];

        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->artisan(self::COMMAND, ['--resync' => true])->assertSuccessful();

        foreach ($snapshots as $table => $before) {
            $after = DB::table($table)
                ->orderBy($table === 'user_group_user' ? 'user_id' : 'id')
                ->get()
                ->toJson();

            $this->assertSame($before, $after, 'la commande a écrit dans « ' . $table . ' »');
        }
    }
}
