<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SystemSetting;
use App\Services\FilePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Politique de gestion des fichiers : réglage GLOBAL d'instance UNIQUEMENT
 * (`SystemSetting`), trois capacités indépendantes home/shares/nextcloud, sans
 * override par parc (décision Henri 2026-07-17). Défaut `home✓ shares✓ nextcloud✗`.
 */
class FilePolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function defaults_to_home_and_shares_when_nothing_persisted(): void
    {
        self::assertSame(
            // La QUATRIÈME capacité naît ÉTEINTE, et c'est la seule chose que son
            // arrivée change ici : les trois autres défauts sont intacts, et un
            // payload persisté avant elle se relit exactement comme avant.
            ['home' => true, 'shares' => true, 'nextcloud' => false, 'opencloud' => false],
            FilePolicyService::capabilities(),
        );
    }

    #[Test]
    public function persists_and_reads_back_each_capability_independently(): void
    {
        // K coupé, H gardé, Nextcloud activé — les 3 axes sont indépendants.
        FilePolicyService::setGlobal(false, true, true, '  https://cloud.etab.fr  ');

        $config = FilePolicyService::globalConfig();
        self::assertFalse($config['home']);
        self::assertTrue($config['shares']);
        self::assertTrue($config['nextcloud']);
        // URL trimée à l'écriture.
        self::assertSame('https://cloud.etab.fr', $config['nextcloud_server_url']);
    }

    #[Test]
    public function tolerates_a_partial_or_legacy_stored_payload(): void
    {
        // Ancien payload mode-unique → clés inconnues ignorées, repli sur défauts.
        SystemSetting::set(FilePolicyService::SETTING_KEY, ['mode' => 'autre_web']);

        self::assertSame(
            // La QUATRIÈME capacité naît ÉTEINTE, et c'est la seule chose que son
            // arrivée change ici : les trois autres défauts sont intacts, et un
            // payload persisté avant elle se relit exactement comme avant.
            ['home' => true, 'shares' => true, 'nextcloud' => false, 'opencloud' => false],
            FilePolicyService::capabilities(),
        );
        self::assertSame('', FilePolicyService::globalConfig()['nextcloud_server_url']);
    }
}
