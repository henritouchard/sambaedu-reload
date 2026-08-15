<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocationService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 63.1 — AC8 : sur une instance en place, la résolution rend
 * EXACTEMENT ce que les capacités globales produisent aujourd'hui.
 *
 * Les cinq lignes de la table de l'AC8, jouées via la commande de reprise
 * (`files:adopt-locations`) puis vérifiées contre `FileLocationService`, et
 * contre l'équivalence `capabilities()['home'] === espacePersoSurSmb()` /
 * `capabilities()['shares'] === espacePartageSurSmb()` que 63.2 invoquera.
 */
class FileLocationsParityTest extends TestCase
{
    use RefreshDatabase;

    private function configureNoCloud(bool $home, bool $shares): void
    {
        FilePolicyService::setGlobal($home, $shares, false);
    }

    private function configureNextcloud(bool $home, bool $shares): void
    {
        FilePolicyService::setGlobal($home, $shares, true, 'https://nuage.exemple.fr', 'admin', 'se4fs', true);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret-nc');
    }

    private function configureOpenCloud(bool $home, bool $shares): void
    {
        FilePolicyService::setGlobal($home, $shares, false, '', null, null, null, true, 'https://oc.exemple.fr', 'admin', true);
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'secret-oc');
    }

    private function assertParity(string $espacePerso, string $espacePartage, string $cloudActif): void
    {
        $this->artisan('files:adopt-locations')->assertExitCode(0);

        $locations = FileLocationService::current();

        self::assertSame($espacePerso, $locations->espacePerso->value);
        self::assertSame($espacePartage, $locations->espacePartage->value);
        self::assertSame($cloudActif, $locations->cloudActif->value);

        $capabilities = FilePolicyService::capabilities();
        self::assertSame($capabilities['home'], $locations->espacePersoSurSmb());
        self::assertSame($capabilities['shares'], $locations->espacePartageSurSmb());
    }

    #[Test]
    public function home_et_shares_actifs_sans_cloud(): void
    {
        $this->configureNoCloud(true, true);
        $this->assertParity('posix', 'posix', 'aucun');
    }

    #[Test]
    public function home_et_shares_actifs_avec_nextcloud_configure(): void
    {
        $this->configureNextcloud(true, true);
        $this->assertParity('posix', 'posix', 'nextcloud');
    }

    #[Test]
    public function home_coupe_shares_actif_avec_nextcloud_configure(): void
    {
        $this->configureNextcloud(false, true);
        $this->assertParity('nextcloud', 'posix', 'nextcloud');
    }

    #[Test]
    public function home_actif_shares_coupe_avec_opencloud_configure(): void
    {
        $this->configureOpenCloud(true, false);
        $this->assertParity('posix', 'opencloud', 'opencloud');
    }

    #[Test]
    public function home_et_shares_coupes_avec_opencloud_configure(): void
    {
        $this->configureOpenCloud(false, false);
        $this->assertParity('opencloud', 'opencloud', 'opencloud');
    }
}
