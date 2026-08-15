<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocationOptions;
use App\Services\Filesystem\FileLocations;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 63.3 AC4 — CE QU'ON PEUT DÉSIGNER COMME EMPLACEMENT, ET LE MOTIF QUAND
 * ON NE PEUT PAS.
 *
 * Deux propriétés se jouent ici, et la seconde est le vrai apport :
 *  1. une position non posable est ABSENTE, avec son motif — jamais grisée ;
 *  2. **la règle est SYMÉTRIQUE entre les deux produits**, contrairement à
 *     `FileBackendSelection::refusalFor(Nextcloud)` qui ne regarde que la
 *     capacité. Un espace placé sur une connexion incomplète serait un espace
 *     que rien ne peut servir, quel que soit le produit.
 *
 * Aucun appel réseau n'est émis : `Http::assertNothingSent()` l'épingle.
 */
class FileLocationOptionsTest extends TestCase
{
    use RefreshDatabase;

    private FileLocationOptions $options;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->options = app(FileLocationOptions::class);
    }

    private static function locations(ActiveCloud $cloud): FileLocations
    {
        return FileLocations::make(FileBackendName::Posix, FileBackendName::Posix, $cloud);
    }

    private function configureNextcloud(bool $capability = true, bool $complete = true): void
    {
        FilePolicyService::setGlobal(
            true,
            true,
            $capability,
            $complete ? 'https://cloud.etab.fr' : '',
            $complete ? 'admin' : '',
        );

        if ($complete) {
            app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret');
        }
    }

    private function configureOpenCloud(bool $capability = true, bool $complete = true): void
    {
        FilePolicyService::setGlobal(
            true,
            true,
            false,
            '',
            null,
            null,
            null,
            $capability,
            $complete ? 'https://fichiers.etab.fr' : '',
            $complete ? 'admin' : '',
        );

        if ($complete) {
            app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'secret');
        }
    }

    // =====================================================================
    // Le serveur de fichiers, et l'aperçu
    // =====================================================================

    #[Test]
    public function the_file_server_is_always_available(): void
    {
        foreach (ActiveCloud::cases() as $cloud) {
            self::assertNull($this->options->refusalFor(FileBackendName::Posix, self::locations($cloud)));
            self::assertContains(FileBackendName::Posix, $this->options->available(self::locations($cloud)));
        }
    }

    #[Test]
    public function the_preview_backend_is_never_an_available_location(): void
    {
        $this->configureNextcloud();

        $locations = self::locations(ActiveCloud::Nextcloud);

        self::assertNotContains(FileBackendName::Preview, $this->options->available($locations));
        self::assertStringContainsString(
            'n\'écrit aucun droit',
            (string) $this->options->refusalFor(FileBackendName::Preview, $locations),
        );
    }

    // =====================================================================
    // Aucun cloud actif
    // =====================================================================

    #[Test]
    public function without_an_active_cloud_no_cloud_authority_is_available_and_the_reason_is_named(): void
    {
        $locations = self::locations(ActiveCloud::Aucun);

        self::assertSame([FileBackendName::Posix], $this->options->available($locations));

        foreach ([FileBackendName::Nextcloud, FileBackendName::OpenCloud] as $backend) {
            self::assertSame(
                FileLocationOptions::REFUSAL_NO_ACTIVE_CLOUD,
                $this->options->refusalFor($backend, $locations),
            );
        }

        self::assertSame(
            'Aucun cloud n\'est configuré : choisissez-en un ci-dessus avant d\'y placer un espace.',
            FileLocationOptions::REFUSAL_NO_ACTIVE_CLOUD,
        );
    }

    // =====================================================================
    // LA SYMÉTRIE — les deux produits, les mêmes deux causes
    // =====================================================================

    #[Test]
    public function an_active_cloud_with_a_complete_connection_is_available(): void
    {
        $this->configureNextcloud();
        self::assertContains(
            FileBackendName::Nextcloud,
            $this->options->available(self::locations(ActiveCloud::Nextcloud)),
        );

        $this->configureOpenCloud();
        self::assertContains(
            FileBackendName::OpenCloud,
            $this->options->available(self::locations(ActiveCloud::OpenCloud)),
        );
    }

    #[Test]
    public function an_incomplete_connection_makes_the_authority_absent_with_a_named_reason(): void
    {
        $this->configureNextcloud(complete: false);
        $refusal = (string) $this->options->refusalFor(
            FileBackendName::Nextcloud,
            self::locations(ActiveCloud::Nextcloud),
        );

        self::assertStringContainsString('La connexion à l\'instance Nextcloud est incomplète :', $refusal);
        self::assertStringContainsString('Complétez-la ci-dessus avant d\'y placer un espace.', $refusal);
        // Le motif NOMME ce qui manque — il vient de l'objet de configuration.
        self::assertStringContainsString('URL', $refusal);
        self::assertNotContains(
            FileBackendName::Nextcloud,
            $this->options->available(self::locations(ActiveCloud::Nextcloud)),
        );

        $this->configureOpenCloud(complete: false);
        $refusal = (string) $this->options->refusalFor(
            FileBackendName::OpenCloud,
            self::locations(ActiveCloud::OpenCloud),
        );

        self::assertStringContainsString('La connexion à l\'instance OpenCloud est incomplète :', $refusal);
        self::assertStringContainsString('Complétez-la ci-dessus avant d\'y placer un espace.', $refusal);
        self::assertNotContains(
            FileBackendName::OpenCloud,
            $this->options->available(self::locations(ActiveCloud::OpenCloud)),
        );
    }

    /**
     * **LE POINT DE SYMÉTRIE.** Capacité ÉTEINTE avec une connexion complète :
     * les deux produits sont absents, et pour la même cause dite de la même
     * façon. C'est exactement ce que `FileBackendSelection` ne fait pas côté
     * Nextcloud — et c'est la raison d'être de ce service.
     */
    #[Test]
    public function a_disabled_capability_makes_the_authority_absent_for_both_products_alike(): void
    {
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret');
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'secret');

        // Connexions complètes des DEUX produits, capacités ÉTEINTES.
        FilePolicyService::setGlobal(
            true, true, false, 'https://cloud.etab.fr', 'admin', 'se4fs', true,
            false, 'https://fichiers.etab.fr', 'admin', true,
        );

        foreach ([
            [ActiveCloud::Nextcloud, FileBackendName::Nextcloud],
            [ActiveCloud::OpenCloud, FileBackendName::OpenCloud],
        ] as [$cloud, $backend]) {
            $locations = self::locations($cloud);

            self::assertNotContains($backend, $this->options->available($locations));

            $refusal = (string) $this->options->refusalFor($backend, $locations);
            self::assertStringContainsString('est incomplète', $refusal);
            self::assertStringContainsString('Complétez-la ci-dessus avant d\'y placer un espace.', $refusal);
        }
    }

    #[Test]
    public function an_authority_that_is_not_the_active_cloud_is_refused(): void
    {
        $this->configureNextcloud();

        $refusal = (string) $this->options->refusalFor(
            FileBackendName::OpenCloud,
            self::locations(ActiveCloud::Nextcloud),
        );

        self::assertStringContainsString('Le cloud actif de l\'instance est « Nextcloud »', $refusal);
    }

    // =====================================================================
    // La garde REJOUÉE côté service
    // =====================================================================

    #[Test]
    public function the_service_refuses_a_forged_choice_the_screen_never_offered(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Aucun cloud n\'est configuré/');

        $this->options->assertAvailable(FileBackendName::Nextcloud, self::locations(ActiveCloud::Aucun));
    }

    #[Test]
    public function an_available_authority_passes_the_assertion_without_throwing(): void
    {
        $this->configureNextcloud();

        $this->options->assertAvailable(FileBackendName::Nextcloud, self::locations(ActiveCloud::Nextcloud));
        $this->options->assertAvailable(FileBackendName::Posix, self::locations(ActiveCloud::Nextcloud));

        $this->expectNotToPerformAssertions();
    }

    /** La posabilité se lit SANS parler à l'instance : elle doit tenir hors ligne. */
    #[Test]
    public function nothing_is_ever_sent_over_the_network(): void
    {
        $this->configureNextcloud();
        $this->options->available(self::locations(ActiveCloud::Nextcloud));

        $this->configureOpenCloud();
        $this->options->available(self::locations(ActiveCloud::OpenCloud));

        Http::assertNothingSent();
    }
}
