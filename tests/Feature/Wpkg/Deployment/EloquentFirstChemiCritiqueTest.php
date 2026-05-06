<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Deployment;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Wpkg\Deployment\Generators\WorkstationIniGenerator;
use App\Wpkg\Deployment\Models\WpkgWorkstationOption;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Facades\Cache;
use LdapRecord\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.3 / AC4.2, AC5.3 — Garde-fou cross-feature : le pipeline
 * déploiement (Story 15.2) ne consulte JAMAIS LdapRecord en chemin
 * critique.
 *
 * Stratégie de test : on bind dans le container Laravel un mock strict
 * pour `LdapRecord\Connection` et pour chaque `App\LdapModels\*`. Le mock
 * est configuré `shouldReceive(...)->never()` : si la chaîne 15.2 (resolver,
 * controllers, generator) appelle un de ces mocks, l'assertion finale
 * `Mockery::close()` (via PHPUnit attributes) échoue.
 *
 * Couvre :
 *  - `WorkstationPackagesResolver::resolve($hostname)` (Eloquent only)
 *  - `GET /wpkg/hosts.xml?poste=PC01`
 *  - `GET /wpkg/profiles.xml?poste=PC01`
 *  - `WorkstationIniGenerator::generate($workstation)`
 *
 * Le test architectural `WpkgDeploymentNamespaceTest` (T6) reste filet
 * statique redondant : aucune classe `App\Wpkg\*` n'importe LdapRecord
 * via `use`. Ce test feature couvre l'usage runtime (instanciation par
 * `app(...)`, `class_exists`, etc. — non détectable au parse statique).
 */
class EloquentFirstChemiCritiqueTest extends TestCase
{
    /** @var array<class-string, \Mockery\MockInterface> */
    private array $forbiddenMocks = [];

    private string $tmpIniDir;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        Cache::flush();

        $this->tmpIniDir = sys_get_temp_dir() . '/wpkg-15-3-ini-' . uniqid();
        @mkdir($this->tmpIniDir, 0755, true);
        config()->set('sambaedu.wpkg.ini_path', $this->tmpIniDir);

        $this->bindForbiddenLdapMocks();
    }

    protected function tearDown(): void
    {
        $this->verifyMocksNeverCalled();

        WpkgSchemaBootstrapper::tearDown();

        // Cleanup tmp directory
        if (is_dir($this->tmpIniDir)) {
            foreach (glob($this->tmpIniDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpIniDir);
        }

        parent::tearDown();
    }

    /**
     * Bind dans le container Laravel un mock Mockery strict
     * `shouldReceive(...)->never()` pour `LdapRecord\Connection` et pour
     * chaque modèle `App\LdapModels\*`. Si la chaîne 15.2 instancie un
     * de ces mocks (via `app(...)` ou `make(...)`), l'assertion
     * `Mockery::close()` lèvera un `Mockery\Exception\InvalidCountException`.
     */
    private function bindForbiddenLdapMocks(): void
    {
        $forbidden = [
            Connection::class,
            \App\LdapModels\MachineModel::class,
            \App\LdapModels\DeviceGroupModel::class,
            \App\LdapModels\DeviceGroupTagModel::class,
            \App\LdapModels\OrganizationalUnitModel::class,
            \App\LdapModels\LdapUser::class,
            \App\LdapModels\SambaEduGroup::class,
            \App\LdapModels\LdapRightGroup::class,
        ];

        foreach ($forbidden as $class) {
            // Mockery::mock crée un test double partial qui throw sur tout
            // appel non whitelist. On combine avec ->shouldReceive()->never()
            // pour assurer une assertion explicite via verify().
            $mock = Mockery::mock($class);
            $mock->shouldNotReceive(); // any unexpected call → fail

            // Bind dans le container Laravel — toute résolution `app($class)`
            // ou injection DI renverra ce mock.
            $this->app->instance($class, $mock);

            $this->forbiddenMocks[$class] = $mock;
        }
    }

    private function verifyMocksNeverCalled(): void
    {
        foreach ($this->forbiddenMocks as $class => $mock) {
            // Si une méthode du mock a été appelée, Mockery l'enregistre
            // dans le container ; on déclenche les expectations pour
            // remonter une éventuelle violation.
            try {
                $mock->mockery_verify();
            } catch (\Throwable $e) {
                self::fail(sprintf(
                    'Garde-fou Eloquent first violé : %s a été utilisé en chemin critique (%s)',
                    $class,
                    $e->getMessage(),
                ));
            }
        }
        Mockery::close();
    }

    private function seedFixture(string $hostname): Workstation
    {
        $w = Workstation::create(['name' => $hostname, 'status' => 'active']);
        $g = WorkstationGroup::create(['name' => 'parc-' . strtolower($hostname)]);
        $w->groups()->attach($g);

        $appPoste = Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);
        $appParc = Application::create(['app_id' => 'libreoffice', 'name' => 'LibreOffice']);

        $w->applications()->attach($appPoste);
        $g->applications()->attach($appParc);

        return $w;
    }

    #[Test]
    public function resolver_returns_packages_without_touching_ad(): void
    {
        $this->seedFixture('PC01');

        $resolver = new WorkstationPackagesResolver();
        $packages = $resolver->resolve('PC01');

        self::assertTrue($packages->contains('firefox'));
        self::assertTrue($packages->contains('libreoffice'));
    }

    #[Test]
    public function hosts_xml_endpoint_serves_xml_without_touching_ad(): void
    {
        $this->seedFixture('PC02');

        $response = $this->withoutMiddleware()->get('/wpkg/hosts.xml?poste=PC02');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        self::assertStringContainsString('<host name="PC02"', (string) $response->getContent());
    }

    #[Test]
    public function profiles_xml_endpoint_serves_xml_without_touching_ad(): void
    {
        $this->seedFixture('PC03');

        $response = $this->withoutMiddleware()->get('/wpkg/profiles.xml?poste=PC03');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $body = (string) $response->getContent();
        self::assertStringContainsString('<package package-id="firefox"/>', $body);
    }

    #[Test]
    public function ini_generator_writes_file_without_touching_ad(): void
    {
        $w = $this->seedFixture('PC04');
        WpkgWorkstationOption::create([
            'workstation_id' => $w->id,
            'option_key' => 'debug',
            'option_value' => 'true',
        ]);

        $generator = new WorkstationIniGenerator();
        $ok = $generator->generate($w);

        self::assertTrue($ok, 'le generator doit réussir sans LDAP');
        $iniPath = $this->tmpIniDir . '/PC04.ini';
        self::assertFileExists($iniPath);

        $content = (string) file_get_contents($iniPath);
        self::assertStringContainsString('debug=true', $content);
    }
}
