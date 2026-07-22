<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suppression de `app_profiles.display_name` : un profil applicatif ne porte
 * plus qu'un `name` et une `description`.
 *
 * Le point sensible n'est pas le `dropColumn` mais la reprise de données :
 * un libellé distinct du `name` et sans `description` en face est la seule
 * information que la suppression pourrait perdre — elle doit devenir la
 * description.
 */
class DropAppProfileDisplayNameTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('app_profiles');
        Schema::create('app_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('display_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->migration = require base_path(
            'database/migrations/2026_07_22_130000_drop_display_name_from_app_profiles.php'
        );
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('app_profiles');

        parent::tearDown();
    }

    #[Test]
    public function it_drops_the_column(): void
    {
        $this->migration->up();

        self::assertNotContains('display_name', Schema::getColumnListing('app_profiles'));
        self::assertContains('name', Schema::getColumnListing('app_profiles'));
        self::assertContains('description', Schema::getColumnListing('app_profiles'));
    }

    #[Test]
    public function it_promotes_a_distinct_label_to_the_description_when_there_is_none(): void
    {
        DB::table('app_profiles')->insert([
            'name' => 'profil-bureautique',
            'display_name' => 'Bureautique Standard',
            'description' => null,
            'is_active' => true,
        ]);

        $this->migration->up();

        self::assertSame(
            'Bureautique Standard',
            DB::table('app_profiles')->where('name', 'profil-bureautique')->value('description')
        );
    }

    #[Test]
    public function it_never_overwrites_an_existing_description(): void
    {
        DB::table('app_profiles')->insert([
            'name' => 'dev-tools',
            'display_name' => 'Outils de développement',
            'description' => 'IDE et outils pour les cours de programmation',
            'is_active' => true,
        ]);

        $this->migration->up();

        self::assertSame(
            'IDE et outils pour les cours de programmation',
            DB::table('app_profiles')->where('name', 'dev-tools')->value('description')
        );
    }

    #[Test]
    public function it_ignores_a_label_that_merely_repeats_the_name(): void
    {
        // Cas majoritaire : tous les profils créés depuis un parc portaient
        // `display_name === name`. Recopier ce doublon en description serait
        // du bruit.
        DB::table('app_profiles')->insert([
            'name' => 'salle-101',
            'display_name' => 'salle-101',
            'description' => null,
            'is_active' => true,
        ]);

        $this->migration->up();

        self::assertNull(
            DB::table('app_profiles')->where('name', 'salle-101')->value('description')
        );
    }

    #[Test]
    public function it_is_reversible(): void
    {
        $this->migration->up();
        $this->migration->down();

        self::assertContains('display_name', Schema::getColumnListing('app_profiles'));
    }
}
