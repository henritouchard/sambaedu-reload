<?php

declare(strict_types=1);

namespace Tests\Feature\Services\AppProfile;

use App\Services\AppProfile\LegacyParcApplicationReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 38.7 / AC9 — lecteur mutualisé du signal « ce parc legacy porte-t-il des
 * applications ? ». C'est ce signal (même clé que le linker) qui conditionne la
 * création d'un WorkstationGroup logique (étape 5) et d'un AppProfile (étape 7).
 */
class LegacyParcApplicationReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupLegacyConnection();
    }

    protected function tearDown(): void
    {
        try {
            Schema::connection('legacy_mysql')->dropIfExists('applications_profile');
            Schema::connection('legacy_mysql')->dropIfExists('parc');
            Schema::connection('legacy_mysql')->dropIfExists('applications');
        } catch (\Throwable $e) {
            // ignore
        }
        DB::purge('legacy_mysql');
        parent::tearDown();
    }

    private function setupLegacyConnection(): void
    {
        Config::set('database.connections.legacy_mysql', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('legacy_mysql');

        Schema::connection('legacy_mysql')->create('applications', function (Blueprint $table) {
            $table->integer('id_app');
            $table->string('id_nom_app');
            $table->tinyInteger('active_app')->default(1);
        });
        Schema::connection('legacy_mysql')->create('parc', function (Blueprint $table) {
            $table->integer('id_parc');
            $table->string('nom_parc');
        });
        Schema::connection('legacy_mysql')->create('applications_profile', function (Blueprint $table) {
            $table->id('id_applications_profile');
            $table->integer('id_appli');
            $table->string('type_entite', 10);
            $table->integer('id_entite');
        });
    }

    private function app(int $idApp, string $idNomApp, int $active = 1): void
    {
        DB::connection('legacy_mysql')->table('applications')->insert([
            'id_app' => $idApp,
            'id_nom_app' => $idNomApp,
            'active_app' => $active,
        ]);
    }

    private function parc(int $id, string $nom): void
    {
        DB::connection('legacy_mysql')->table('parc')->insert(['id_parc' => $id, 'nom_parc' => $nom]);
    }

    private function assign(int $idAppli, int $idEntite): void
    {
        DB::connection('legacy_mysql')->table('applications_profile')->insert(['id_appli' => $idAppli, 'type_entite' => 'parc', 'id_entite' => $idEntite]);
    }

    #[Test]
    public function parc_with_applications_is_detected_case_insensitively(): void
    {
        $this->app(1, 'firefox');
        $this->parc(10, 'Salle101');
        $this->assign(1, 10);

        $reader = new LegacyParcApplicationReader();
        [$parcAppNames, $parcByName] = $reader->read();

        $this->assertTrue($reader->parcHasApplications($parcAppNames, $parcByName, 'salle101'));
        $this->assertSame(['firefox'], $reader->applicationsForParc($parcAppNames, $parcByName, 'SALLE101'));
    }

    #[Test]
    public function parc_without_applications_has_no_signal(): void
    {
        $this->parc(20, 'rangement');

        $reader = new LegacyParcApplicationReader();
        [$parcAppNames, $parcByName] = $reader->read();

        $this->assertFalse($reader->parcHasApplications($parcAppNames, $parcByName, 'rangement'));
        $this->assertSame([], $reader->applicationsForParc($parcAppNames, $parcByName, 'rangement'));
    }

    #[Test]
    public function unknown_parc_has_no_signal(): void
    {
        $reader = new LegacyParcApplicationReader();
        [$parcAppNames, $parcByName] = $reader->read();

        $this->assertFalse($reader->parcHasApplications($parcAppNames, $parcByName, 'inconnu'));
    }

    #[Test]
    public function inactive_apps_do_not_count_as_applications(): void
    {
        $this->app(1, 'vieuxsoft', active: 0);
        $this->parc(30, 'parc-inactif');
        $this->assign(1, 30);

        $reader = new LegacyParcApplicationReader();
        [$parcAppNames, $parcByName] = $reader->read();

        $this->assertFalse($reader->parcHasApplications($parcAppNames, $parcByName, 'parc-inactif'));
    }

    #[Test]
    public function read_returns_null_when_legacy_unavailable(): void
    {
        Config::set('database.connections.legacy_mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => '',
            'username' => '',
            'password' => '',
        ]);
        DB::purge('legacy_mysql');

        $this->assertNull((new LegacyParcApplicationReader())->read());
        $this->assertFalse((new LegacyParcApplicationReader())->isConfiguredAndReachable());
    }
}
