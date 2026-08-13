<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpenCloud;

use App\Exceptions\OpenCloud\OpenCloudConfigurationException;
use App\Services\FilePolicyService;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LE FAIL-CLOSED DE LA CONFIGURATION, ET LE SECRET QUI NE SORT PAS.
 *
 * Deux propriétés, et la seconde est une protection de sécurité :
 *
 *  1. **un refus NOMME ce qui manque, AVANT le premier appel.** Une zone à moitié
 *     provisionnée est pire qu'une zone refusée : sous drift STRICT, un état que
 *     personne n'a décrit ne se réconcilie pas ;
 *  2. **le secret n'est lisible que par le client HTTP.** `var_dump` et `dd()`
 *     d'un objet de configuration sont le chemin le plus court vers un secret dans
 *     un journal ; la porte est fermée ici plutôt qu'espérée fermée ailleurs.
 */
class OpenCloudConnectionConfigTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_disabled_capability_refuses_before_anything_else_and_says_so(): void
    {
        FilePolicyService::setGlobal(true, true, false, '', null, null, null, false, 'https://x.fr', 'admin', true);

        try {
            OpenCloudConnectionConfig::current();
            self::fail('la capacité éteinte aurait dû refuser');
        } catch (OpenCloudConfigurationException $e) {
            self::assertSame(['opencloud'], $e->missing);
            self::assertStringContainsString('Accès OpenCloud', $e->getMessage());
        }
    }

    /** Chaque réglage manquant est NOMMÉ — jamais un « configuration invalide » global. */
    #[Test]
    public function every_missing_setting_is_named_individually(): void
    {
        try {
            OpenCloudConnectionConfig::fromValues('', '', '');
            self::fail('une configuration vide aurait dû refuser');
        } catch (OpenCloudConfigurationException $e) {
            self::assertCount(3, $e->missing);
            self::assertStringContainsString('URL', $e->getMessage());
            self::assertStringContainsString('identifiant', $e->getMessage());
            self::assertStringContainsString('mot de passe', $e->getMessage());
        }
    }

    #[Test]
    public function an_url_without_a_scheme_is_refused_by_name(): void
    {
        $this->expectException(OpenCloudConfigurationException::class);
        $this->expectExceptionMessageMatches('/schéma est requis/');

        OpenCloudConnectionConfig::fromValues('nuage.exemple.fr', 'admin', 'secret');
    }

    /** L'URL est NORMALISÉE une fois : pas de slash final, pas d'espaces de bord. */
    #[Test]
    public function the_url_is_normalised_once_and_for_all(): void
    {
        $config = OpenCloudConnectionConfig::fromValues('  https://nuage.exemple.fr/  ', ' admin ', 'secret');

        self::assertSame('https://nuage.exemple.fr', $config->baseUrl);
        self::assertSame('admin', $config->adminUser);
        self::assertSame('https://nuage.exemple.fr/graph/v1.0/me', $config->url('/graph/v1.0/me'));
        self::assertSame('https://nuage.exemple.fr/graph/v1.0/me', $config->url('graph/v1.0/me'));
    }

    /**
     * **LE SECRET EST MASQUÉ À L'INTROSPECTION** — ce qui couvre `var_dump`, `dd()`
     * et les traces d'exception qui sérialisent leurs arguments.
     */
    #[Test]
    public function the_secret_is_masked_in_every_debug_shape(): void
    {
        $config = OpenCloudConnectionConfig::fromValues('https://nuage.exemple.fr', 'admin', 'ultra-secret');

        $dump = print_r($config->__debugInfo(), true);
        self::assertStringNotContainsString('ultra-secret', $dump);
        self::assertStringContainsString('***', $dump);

        ob_start();
        var_dump($config);
        $varDump = (string) ob_get_clean();
        self::assertStringNotContainsString('ultra-secret', $varDump);

        // Et il reste lisible par le SEUL appelant qui en a besoin.
        self::assertSame('ultra-secret', $config->adminPassword());
    }

    /** Le credential est le SEUL secret OpenCloud, et il porte un nom lisible en base. */
    #[Test]
    public function the_secret_lives_encrypted_under_a_readable_name(): void
    {
        FilePolicyService::setGlobal(true, true, false, '', null, null, null, true, 'https://nuage.fr', 'admin', true);
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'du-secret');

        self::assertSame('opencloud_admin', OpenCloudConnectionConfig::CREDENTIAL_NAME);
        self::assertSame('du-secret', OpenCloudConnectionConfig::current()->adminPassword());

        // Et il n'est PAS dans le réglage en clair.
        self::assertStringNotContainsString(
            'du-secret',
            json_encode(FilePolicyService::globalConfig(), JSON_UNESCAPED_UNICODE),
        );
    }
}
