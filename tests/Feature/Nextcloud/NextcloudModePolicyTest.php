<?php

declare(strict_types=1);

namespace Tests\Feature\Nextcloud;

use App\Enums\NextcloudInstanceMode;
use App\Models\SystemSetting;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudDelegateConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.2 — AC1 (la clé de réglage et son défaut sûr) et AC4 (les deux
 * credentials cohabitent : un aller-retour de mode ne perd aucune configuration).
 */
class NextcloudModePolicyTest extends TestCase
{
    use RefreshDatabase;

    // =====================================================================
    // AC1 — la clé, le défaut, la préservation, le repli
    // =====================================================================

    #[Test]
    public function the_default_mode_is_the_administered_instance(): void
    {
        self::assertSame('admin', FilePolicyService::defaults()['nextcloud_mode']);
        self::assertSame('admin', FilePolicyService::globalConfig()['nextcloud_mode']);
        self::assertSame(NextcloudInstanceMode::Admin, FilePolicyService::nextcloudMode());
    }

    /**
     * Le payload d'une instance configurée AVANT 61.2 n'a pas la clé : elle doit
     * retomber sur `admin`, c'est-à-dire ne rien changer au comportement de 61.1.
     */
    #[Test]
    public function a_pre_existing_payload_without_the_key_keeps_the_61_1_behaviour(): void
    {
        SystemSetting::set(FilePolicyService::SETTING_KEY, [
            'home' => true,
            'shares' => true,
            'nextcloud' => true,
            'nextcloud_server_url' => 'https://cloud.etab.fr',
            'nextcloud_admin_user' => 'admin',
        ]);

        self::assertSame(NextcloudInstanceMode::Admin, FilePolicyService::nextcloudMode());
        self::assertSame('', FilePolicyService::globalConfig()['nextcloud_delegue_user']);
    }

    #[Test]
    public function the_mode_persists_and_reads_back(): void
    {
        FilePolicyService::setGlobal(
            true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true,
            NextcloudInstanceMode::Delegue, 'se5porteur',
        );

        self::assertSame(NextcloudInstanceMode::Delegue, FilePolicyService::nextcloudMode());
        self::assertSame('se5porteur', FilePolicyService::globalConfig()['nextcloud_delegue_user']);
    }

    /**
     * Patron 61.1 : un appelant qui ne connaît pas ces paramètres ne doit pas les
     * effacer. C'est ce qui rend l'aller-retour de mode sans perte possible.
     */
    #[Test]
    public function omitting_the_mode_preserves_the_persisted_value(): void
    {
        FilePolicyService::setGlobal(
            true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true,
            NextcloudInstanceMode::Delegue, 'se5porteur',
        );

        // Un appelant antérieur, qui ne connaît que les sept premiers paramètres.
        FilePolicyService::setGlobal(false, true, true, 'https://cloud.etab.fr');

        $config = FilePolicyService::globalConfig();
        self::assertSame('delegue', $config['nextcloud_mode']);
        self::assertSame('se5porteur', $config['nextcloud_delegue_user']);
        self::assertSame('admin', $config['nextcloud_admin_user']);
    }

    /** Une valeur hors vocabulaire ne fait ni planter, ni inventer un mode. */
    #[Test]
    public function an_unknown_persisted_mode_falls_back_to_the_default(): void
    {
        SystemSetting::set(FilePolicyService::SETTING_KEY, [
            'home' => true,
            'shares' => true,
            'nextcloud' => true,
            'nextcloud_mode' => 'nextcloud_delegue',
        ]);

        self::assertSame('admin', FilePolicyService::globalConfig()['nextcloud_mode']);
        self::assertSame(NextcloudInstanceMode::Admin, FilePolicyService::nextcloudMode());
    }

    // =====================================================================
    // AC4 — les deux credentials cohabitent
    // =====================================================================

    #[Test]
    public function the_two_credentials_live_under_distinct_names(): void
    {
        self::assertNotSame(
            NextcloudConnectionConfig::CREDENTIAL_NAME,
            NextcloudDelegateConfig::CREDENTIAL_NAME,
        );
    }

    #[Test]
    public function configuring_the_delegate_does_not_erase_the_admin_and_vice_versa(): void
    {
        $credentials = app(ServiceCredentials::class);

        $credentials->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'SecretAdmin');
        $credentials->put(NextcloudDelegateConfig::CREDENTIAL_NAME, 'SecretPorteur');

        self::assertSame('SecretAdmin', $credentials->password(NextcloudConnectionConfig::CREDENTIAL_NAME));
        self::assertSame('SecretPorteur', $credentials->password(NextcloudDelegateConfig::CREDENTIAL_NAME));

        // …et oublier l'un laisse l'autre intact.
        $credentials->forget(NextcloudDelegateConfig::CREDENTIAL_NAME);

        self::assertSame('SecretAdmin', $credentials->password(NextcloudConnectionConfig::CREDENTIAL_NAME));
        self::assertNull($credentials->password(NextcloudDelegateConfig::CREDENTIAL_NAME));
    }

    /**
     * L'ALLER-RETOUR DE MODE NE PERD RIEN : les deux identifiants et les deux
     * secrets sont toujours là après un passage en délégué puis un retour.
     */
    #[Test]
    public function a_mode_round_trip_loses_no_configuration(): void
    {
        $credentials = app(ServiceCredentials::class);
        $credentials->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'SecretAdmin');
        $credentials->put(NextcloudDelegateConfig::CREDENTIAL_NAME, 'SecretPorteur');

        FilePolicyService::setGlobal(
            true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true,
            NextcloudInstanceMode::Admin, 'se5porteur',
        );
        FilePolicyService::setGlobal(
            true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true,
            NextcloudInstanceMode::Delegue, 'se5porteur',
        );
        FilePolicyService::setGlobal(
            true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true,
            NextcloudInstanceMode::Admin, 'se5porteur',
        );

        $config = FilePolicyService::globalConfig();
        self::assertSame('admin', $config['nextcloud_mode']);
        self::assertSame('admin', $config['nextcloud_admin_user']);
        self::assertSame('se5porteur', $config['nextcloud_delegue_user']);
        self::assertSame('SecretAdmin', $credentials->password(NextcloudConnectionConfig::CREDENTIAL_NAME));
        self::assertSame('SecretPorteur', $credentials->password(NextcloudDelegateConfig::CREDENTIAL_NAME));
    }

    // =====================================================================
    // AC4 — le croisement de credentials est impossible par TYPAGE
    // =====================================================================

    /**
     * **Le test qui verrouille l'invariant** : en mode délégué, aucun appel ne
     * porte l'auth admin ; en mode admin, aucun appel ne porte l'auth porteur.
     * Ici on constate la propriété STRUCTURELLE qui le garantit — chaque
     * configuration ne sait lire qu'un seul nom de credential. Les tests de la
     * sonde et du gating constatent, eux, le comportement sur le fil.
     */
    #[Test]
    public function each_configuration_reads_only_its_own_credential(): void
    {
        $credentials = app(ServiceCredentials::class);
        $credentials->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'SecretAdmin');
        $credentials->put(NextcloudDelegateConfig::CREDENTIAL_NAME, 'SecretPorteur');

        FilePolicyService::setGlobal(
            true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true,
            NextcloudInstanceMode::Admin, 'se5porteur',
        );

        $admin = NextcloudConnectionConfig::current($credentials);
        self::assertSame('admin', $admin->adminUser);
        self::assertSame('SecretAdmin', $admin->adminPassword());

        FilePolicyService::setGlobal(
            true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true,
            NextcloudInstanceMode::Delegue, 'se5porteur',
        );

        $delegate = NextcloudDelegateConfig::current($credentials);
        self::assertSame('se5porteur', $delegate->delegateUser);
        self::assertSame('SecretPorteur', $delegate->delegatePassword());
    }

    /** Le secret n'apparaît dans aucune vue de débogage. */
    #[Test]
    public function the_delegate_secret_is_masked_in_debug_output(): void
    {
        $config = NextcloudDelegateConfig::fromValues('https://cloud.etab.fr', 'se5porteur', 'SecretPorteur');

        self::assertStringNotContainsString('SecretPorteur', print_r($config->__debugInfo(), true));
        self::assertStringNotContainsString('SecretPorteur', var_export($config->__debugInfo(), true));
    }
}
