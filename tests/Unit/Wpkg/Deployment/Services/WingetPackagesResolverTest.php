<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Services;

use App\Models\Application;
use App\Wpkg\Deployment\Services\WingetPackagesResolver;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use App\Wpkg\Deployment\Support\ApplicationXmlReader;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 17.6 / AC2.3 / AC6.3 / D5 — Tests Unit du mapping winget.
 *
 * On isole la logique métier (merge add/remove, priorité /etc/ vs /usr/share/,
 * comparaison de version pinnée, décisions install/upgrade/uninstall) en mockant
 * `WorkstationPackagesResolver` (résolution app_id) et `ApplicationXmlReader`
 * (extraction des noeuds winget). Les catalogues `add.json`/`remove.json` sont
 * écrits dans des fichiers temporaires et injectés via la config.
 *
 * Parité référence : `sambaedu/wpkg/winget_out.php:61-194`.
 */
class WingetPackagesResolverTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    /**
     * Neutralise les 4 chemins de catalogue add/remove vers des fichiers
     * inexistants — sinon les tests qui ne posent pas de catalogue lisent
     * /usr/share/sambaedu/applications/winget/*.json présent sur la VM
     * (PowerShell + AppInstaller) et pollue le résultat.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'sambaedu.wpkg.winget_catalog_add_local',
            'sambaedu.wpkg.winget_catalog_add_default',
            'sambaedu.wpkg.winget_catalog_remove_local',
            'sambaedu.wpkg.winget_catalog_remove_default',
        ] as $key) {
            config()->set($key, sys_get_temp_dir() . '/winget-absent-' . uniqid() . '.json');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = [];

        // Réinitialise la config winget catalog (les tests positionnent des
        // chemins temporaires via le helper config() global).
        config()->set('sambaedu.wpkg.winget_catalog_add_local', '/etc/sambaedu/applications/winget/add.json');
        config()->set('sambaedu.wpkg.winget_catalog_add_default', '/usr/share/sambaedu/applications/winget/add.json');
        config()->set('sambaedu.wpkg.winget_catalog_remove_local', '/etc/sambaedu/applications/winget/remove.json');
        config()->set('sambaedu.wpkg.winget_catalog_remove_default', '/usr/share/sambaedu/applications/winget/remove.json');

        Mockery::close();
        parent::tearDown();
    }

    /**
     * Cas 1 — Machine vierge : toutes les apps demandées (XML + add.json)
     * absentes localement → toutes en `install`. Vérifie aussi le merge add :
     * une entrée add.json non présente dans la liste XML est ajoutée.
     */
    #[Test]
    public function machine_vierge_met_tout_en_install(): void
    {
        $this->writeCatalogs(
            addDefault: [['Id' => 'Mozilla.Firefox', 'Source' => 'winget']],
        );

        $resolver = $this->makeResolver(
            wingetEntriesByAppId: [
                'libreoffice' => [['Id' => 'TheDocumentFoundation.LibreOffice', 'Source' => 'winget']],
            ],
        );

        $out = $resolver->resolve('PC-VIERGE', localApps: []);

        // Pas de poste local → ni upgrade ni uninstall.
        self::assertArrayNotHasKey('upgrade', $out);
        self::assertArrayNotHasKey('uninstall', $out);

        $installIds = array_column($out['install'], 'Id');
        // add.json (Firefox) mergé AVANT la liste XML (LibreOffice).
        self::assertSame(['Mozilla.Firefox', 'TheDocumentFoundation.LibreOffice'], $installIds);
    }

    /**
     * Cas 2 — Apps déjà installées à jour (IsUpdateAvailable=false) → ni install
     * ni upgrade. La sortie est vide (toutes les clés omises).
     */
    #[Test]
    public function apps_a_jour_ne_produisent_ni_install_ni_upgrade(): void
    {
        $resolver = $this->makeResolver(
            wingetEntriesByAppId: [
                'firefox' => [['Id' => 'Mozilla.Firefox', 'Source' => 'winget']],
            ],
        );

        $local = [
            [
                'Id' => 'Mozilla.Firefox',
                'InstalledVersion' => '120.0',
                'IsUpdateAvailable' => false,
                'AvailableVersions' => [],
            ],
        ];

        $out = $resolver->resolve('PC-AJOUR', localApps: $local);

        self::assertSame([], $out, 'Aucune décision attendue pour une app à jour.');
    }

    /**
     * Cas 3 — App installée avec MAJ dispo, SANS version pinnée → upgrade vers
     * la dernière version dispo (`AvailableVersions[0]`, parité :149).
     */
    #[Test]
    public function app_sans_pin_passe_en_upgrade_vers_derniere_version(): void
    {
        $resolver = $this->makeResolver(
            wingetEntriesByAppId: [
                'firefox' => [['Id' => 'Mozilla.Firefox', 'Source' => 'winget']],
            ],
        );

        $local = [
            [
                'Id' => 'Mozilla.Firefox',
                'InstalledVersion' => '120.0',
                'IsUpdateAvailable' => true,
                'AvailableVersions' => ['125.0', '124.0', '123.0'],
            ],
        ];

        $out = $resolver->resolve('PC-MAJ', localApps: $local);

        self::assertArrayNotHasKey('install', $out);
        self::assertCount(1, $out['upgrade']);
        self::assertSame('Mozilla.Firefox', $out['upgrade'][0]['Id']);
        self::assertSame('125.0', $out['upgrade'][0]['AvailableVersion']);
        // Version = version installée (parité :151).
        self::assertSame('120.0', $out['upgrade'][0]['Version']);
    }

    /**
     * Cas 4 — Version pinnée dans le XML : on choisit la plus haute version
     * dispo <= pin (parité :139-147). Pin = 124.0, dispo = [125, 124, 123].
     * Le foreach break dès qu'on dépasse 124 → AvailableVersion = 124.0.
     */
    #[Test]
    public function version_pinnee_borne_la_version_cible(): void
    {
        $resolver = $this->makeResolver(
            wingetEntriesByAppId: [
                'firefox' => [['Id' => 'Mozilla.Firefox', 'Version' => '124.0', 'Source' => 'winget']],
            ],
        );

        $local = [
            [
                'Id' => 'Mozilla.Firefox',
                'InstalledVersion' => '120.0',
                'IsUpdateAvailable' => true,
                'AvailableVersions' => ['125.0', '124.0', '123.0'],
            ],
        ];

        $out = $resolver->resolve('PC-PIN', localApps: $local);

        self::assertCount(1, $out['upgrade']);
        self::assertSame('124.0', $out['upgrade'][0]['AvailableVersion']);
    }

    /**
     * Cas 5 — uninstall : une app locale présente dans remove.json (merge
     * /etc/ + /usr/share/) → uninstall (parité :164-189).
     */
    #[Test]
    public function app_locale_dans_remove_passe_en_uninstall(): void
    {
        $this->writeCatalogs(
            removeDefault: [['Id' => 'Microsoft.BingNews', 'Name' => 'News']],
        );

        $resolver = $this->makeResolver(wingetEntriesByAppId: []);

        $local = [
            ['Id' => 'Microsoft.BingNews', 'InstalledVersion' => '1.0', 'IsUpdateAvailable' => false, 'AvailableVersions' => []],
            ['Id' => 'Mozilla.Firefox', 'InstalledVersion' => '120.0', 'IsUpdateAvailable' => false, 'AvailableVersions' => []],
        ];

        $out = $resolver->resolve('PC-DEBLOAT', localApps: $local);

        self::assertCount(1, $out['uninstall']);
        self::assertSame('Microsoft.BingNews', $out['uninstall'][0]['Id']);
    }

    /**
     * Cas 6 — Priorité /usr/share sur /etc pour un même Id (parité :115-119) :
     * l'entrée /usr/share l'emporte (l'entrée /etc du même Id est retirée).
     */
    #[Test]
    public function priorite_usr_share_sur_etc_pour_meme_id(): void
    {
        $this->writeCatalogs(
            addLocal: [['Id' => 'Shared.App', 'Source' => 'msstore']],     // /etc/
            addDefault: [['Id' => 'Shared.App', 'Source' => 'winget']],    // /usr/share/
        );

        $resolver = $this->makeResolver(wingetEntriesByAppId: []);

        $out = $resolver->resolve('PC-MERGE', localApps: []);

        self::assertCount(1, $out['install'], 'Le même Id ne doit apparaître qu\'une fois.');
        self::assertSame('Shared.App', $out['install'][0]['Id']);
        // /usr/share (Source=winget) l'emporte sur /etc (Source=msstore).
        self::assertSame('winget', $out['install'][0]['Source']);
    }

    /**
     * Cas 7 — App XML sans noeud winget → ignorée (aucune décision).
     */
    #[Test]
    public function app_sans_noeud_winget_est_ignoree(): void
    {
        $resolver = $this->makeResolver(
            wingetEntriesByAppId: [
                'apt-only-app' => [], // aucune entrée winget extraite.
            ],
        );

        $out = $resolver->resolve('PC-NOWINGET', localApps: []);

        self::assertSame([], $out);
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Construit le service avec un resolver + reader mockés.
     *
     * @param  array<string, list<array<string, string>>>  $wingetEntriesByAppId
     */
    private function makeResolver(array $wingetEntriesByAppId): WingetPackagesResolver
    {
        $appIds = array_keys($wingetEntriesByAppId);

        $packagesResolver = Mockery::mock(WorkstationPackagesResolver::class);
        $packagesResolver->shouldReceive('resolve')
            ->andReturn(new Collection($appIds));

        // On fabrique des modèles Application "détachés" (non persistés) pour
        // que le reader retourne leurs entrées winget. loadByAppIds est mocké
        // pour court-circuiter la DB.
        $applications = [];
        foreach ($appIds as $appId) {
            $app = new Application(['app_id' => $appId]);
            $applications[$appId] = $app;
        }

        $reader = Mockery::mock(ApplicationXmlReader::class);
        $reader->shouldReceive('loadByAppIds')
            ->andReturn(new Collection(array_values($applications)));
        $reader->shouldReceive('wingetEntriesFor')
            ->andReturnUsing(static function (Application $app) use ($wingetEntriesByAppId): array {
                return $wingetEntriesByAppId[$app->app_id] ?? [];
            });

        return new WingetPackagesResolver($packagesResolver, $reader);
    }

    /**
     * Écrit les catalogues add/remove dans des fichiers temporaires et
     * positionne les chemins via la config.
     *
     * @param  list<array<string, string>>|null  $addLocal
     * @param  list<array<string, string>>|null  $addDefault
     * @param  list<array<string, string>>|null  $removeLocal
     * @param  list<array<string, string>>|null  $removeDefault
     */
    private function writeCatalogs(
        ?array $addLocal = null,
        ?array $addDefault = null,
        ?array $removeLocal = null,
        ?array $removeDefault = null,
    ): void {
        $map = [
            'sambaedu.wpkg.winget_catalog_add_local' => $addLocal,
            'sambaedu.wpkg.winget_catalog_add_default' => $addDefault,
            'sambaedu.wpkg.winget_catalog_remove_local' => $removeLocal,
            'sambaedu.wpkg.winget_catalog_remove_default' => $removeDefault,
        ];

        foreach ($map as $configKey => $content) {
            if ($content === null) {
                // Pointe vers un fichier inexistant → readCatalog retourne [].
                config()->set($configKey, sys_get_temp_dir() . '/winget-absent-' . uniqid() . '.json');

                continue;
            }
            $path = tempnam(sys_get_temp_dir(), 'winget-cat-');
            file_put_contents($path, json_encode($content));
            $this->tmpFiles[] = $path;
            config()->set($configKey, $path);
        }
    }
}
