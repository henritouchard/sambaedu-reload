<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypeRoles;

use App\Models\DirectoryTemplate;
use App\Models\GroupTypeRole;
use App\Support\RoleCatalog;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\InstallsCollegeRoleProfile;

/**
 * Une audience résolue par rôle d'arête se LIT depuis le catalogue, jamais depuis
 * son libellé stocké.
 *
 * Le défaut que ces cas verrouillent était visible en production : la recette de
 * classe seedée porte le libellé « Équipe enseignante », tandis que l'onglet
 * « Types de groupes » dit « Enseignant » pour `classe × manager`. Même
 * population, deux mots, deux écrans — et le second, seul, est renommable par
 * l'administrateur.
 *
 * Les stratégies qui ne visent pas un rôle (`self`, `designated`) désignent un
 * groupe entier ou une saisie : le catalogue n'a rien à en dire, leur libellé
 * stocké reste la seule source.
 */
class AudienceLabelDerivesFromCatalogTest extends TestCase
{
    use InstallsCollegeRoleProfile;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new DirectoryTemplateSeeder())->run();
    }

    #[Test]
    public function the_seeded_class_recipe_reads_its_teaching_audience_from_the_catalog(): void
    {
        $this->installCollegeRoleProfile();

        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();
        $audience = $this->audienceOf($template, 'equipe');

        $this->assertSame('Équipe enseignante', $audience['label'], 'la baseline stockée est inchangée');
        $this->assertSame('Enseignant', RoleCatalog::audienceLabel($audience, 'classe'));
    }

    #[Test]
    public function the_two_seeded_edge_role_audiences_now_say_the_same_word(): void
    {
        $this->installCollegeRoleProfile();

        $classe = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();
        $devoirs = DirectoryTemplate::where('key', DirectoryTemplate::KEY_PROFS_TO_ELEVES)->firstOrFail();

        $this->assertSame(
            RoleCatalog::audienceLabel($this->audienceOf($classe, 'equipe'), 'classe'),
            RoleCatalog::audienceLabel($this->audienceOf($devoirs, 'profs'), 'classe'),
            'deux audiences qui visent classe × manager ne peuvent pas se nommer différemment',
        );
    }

    #[Test]
    public function renaming_the_declared_role_moves_the_audience_label(): void
    {
        $this->installCollegeRoleProfile();

        GroupTypeRole::where('group_type_key', 'classe')
            ->where('group_role_key', 'manager')
            ->firstOrFail()
            ->update(['label' => 'Professeur']);
        RoleCatalog::flush();

        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();

        $this->assertSame(
            'Professeur',
            RoleCatalog::audienceLabel($this->audienceOf($template, 'equipe'), 'classe'),
        );
    }

    #[Test]
    public function an_audience_that_targets_no_role_keeps_its_stored_label(): void
    {
        $this->installCollegeRoleProfile();

        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();

        $this->assertSame(
            'Élèves de la classe',
            RoleCatalog::audienceLabel($this->audienceOf($template, 'classe'), 'classe'),
        );

        $designated = DirectoryTemplate::where('key', DirectoryTemplate::KEY_DIRECTION_TO_ALL)->firstOrFail();

        $this->assertSame(
            'Source (direction / équipe qui publie)',
            RoleCatalog::audienceLabel($this->audienceOf($designated, 'source')),
        );
    }

    /**
     * Sans déclaration, le catalogue rend la clé nue — l'audience ne peut pas
     * inventer un mot que personne n'a posé.
     */
    #[Test]
    public function without_the_school_profile_the_audience_falls_back_to_the_bare_role(): void
    {
        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();

        $this->assertSame(
            RoleCatalog::label('classe', 'manager'),
            RoleCatalog::audienceLabel($this->audienceOf($template, 'equipe'), 'classe'),
        );
    }

    #[Test]
    public function the_group_type_of_the_audience_is_used_when_none_is_given(): void
    {
        $this->installCollegeRoleProfile();

        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();

        $this->assertSame('Enseignant', RoleCatalog::audienceLabel($this->audienceOf($template, 'equipe')));
    }

    /** Plusieurs rôles visés : tous nommés, aucun avalé par le premier. */
    #[Test]
    public function every_targeted_role_is_named(): void
    {
        $this->installCollegeRoleProfile();

        $audience = [
            'key' => 'encadrement',
            'label' => 'Encadrement',
            'group_type' => 'classe',
            'resolution' => ['strategy' => 'edge_role', 'edge_roles' => ['manager', 'owner']],
        ];

        $this->assertSame('Enseignant, Professeur principal', RoleCatalog::audienceLabel($audience));
    }

    /**
     * @return array<string, mixed>
     */
    private function audienceOf(DirectoryTemplate $template, string $key): array
    {
        foreach ($template->roles() as $role) {
            if (is_array($role) && ($role['key'] ?? null) === $key) {
                return $role;
            }
        }

        $this->fail(sprintf('audience « %s » absente de la recette « %s »', $key, (string) $template->key));
    }
}
