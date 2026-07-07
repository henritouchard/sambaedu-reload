<?php

declare(strict_types=1);

namespace Tests\Feature\Parc;

use App\Models\WorkstationGroup;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Le nom technique (`name`) d'un groupe de postes n'est plus saisi : l'utilisateur
 * ne renseigne qu'un nom affiché (`display_name`), et `createGroup` dérive un slug
 * technique propre — valide pour la projection AD (`[a-z0-9_]`, ≤ 20 car.), unique,
 * immuable après création. Ces tests couvrent la dérivation, ses bornes, la
 * déduplication, et la préservation du chemin explicite-`name`
 * (imposition controlHub / import AD).
 */
class WorkstationGroupTechnicalNameTest extends TestCase
{
    use RefreshDatabase;

    private WorkstationGroupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // L'observer dispatche un job de sync AD à la création : on le neutralise.
        Queue::fake();
        $this->service = app(WorkstationGroupService::class);
    }

    #[Test]
    public function slugifies_display_name_stripping_spaces(): void
    {
        self::assertSame('salle_info_101', $this->service->generateTechnicalName('Salle Info 101'));
    }

    #[Test]
    public function strips_accents_and_punctuation(): void
    {
        // Str::slug translittère les accents et supprime la ponctuation.
        self::assertSame('eleve_prof', $this->service->generateTechnicalName('Élève & Prof !'));
    }

    #[Test]
    public function caps_slug_to_twenty_characters(): void
    {
        $slug = $this->service->generateTechnicalName('Salle informatique batiment central rez');
        self::assertLessThanOrEqual(20, strlen($slug));
        self::assertSame('salle_informatique_b', $slug);
    }

    #[Test]
    public function falls_back_when_slug_would_be_empty(): void
    {
        // Ponctuation seule (hors dictionnaire Str::slug, où « @ » → « at ») → slug vide.
        self::assertSame('groupe', $this->service->generateTechnicalName('!!! --- ??? ///'));
    }

    #[Test]
    public function deduplicates_colliding_slugs_within_bound(): void
    {
        WorkstationGroup::factory()->create(['name' => 'salle_info']);

        $slug = $this->service->generateTechnicalName('Salle Info');

        self::assertSame('salle_info_2', $slug);
        self::assertLessThanOrEqual(20, strlen($slug));
    }

    #[Test]
    public function create_group_derives_name_from_display_name(): void
    {
        $group = $this->service->createGroup([
            'display_name' => 'Parc Portables B12',
            'is_physical' => false,
        ]);

        self::assertSame('Parc Portables B12', $group->display_name);
        self::assertSame('parc_portables_b12', $group->name);
    }

    #[Test]
    public function create_group_keeps_explicit_name_untouched(): void
    {
        // Chemin imposition controlHub / import AD : `name` fourni explicitement,
        // aucun slug dérivé, la valeur est conservée telle quelle.
        $group = $this->service->createGroup([
            'name' => 'parc-impose-amont',
            'display_name' => 'Parc Imposé Amont',
            'is_physical' => false,
        ]);

        self::assertSame('parc-impose-amont', $group->name);
    }
}
