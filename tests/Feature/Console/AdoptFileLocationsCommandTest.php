<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\SystemSetting;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocationService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 63.1 — AC6/AC7 : la reprise, en commande — pas en migration.
 *
 * Les cinq combinaisons de l'AC8 sont couvertes par
 * `\Tests\Unit\Services\Filesystem\FileLocationsParityTest` (cité en FQCN :
 * une suite de test n'a pas à importer une autre suite pour un renvoi) ; celle-ci
 * se concentre sur ce qui est spécifique à LA COMMANDE : les deux refus nommés,
 * `--dry-run`, `--force`, l'idempotence, la réparation d'une ligne illisible, et
 * l'absence totale d'appel réseau.
 */
class AdoptFileLocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Le payload que produit la reprise quand `home` et `shares` sont actifs et Nextcloud configuré. */
    private const DECISION_NEXTCLOUD_POSIX = [
        'espace_perso.autorite' => 'posix',
        'espace_partage.autorite' => 'posix',
        'cloud.actif' => 'nextcloud',
    ];

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

    private function configureLesDeuxClouds(bool $home, bool $shares): void
    {
        FilePolicyService::setGlobal(
            $home,
            $shares,
            true,
            'https://nuage.exemple.fr',
            'admin',
            'se4fs',
            true,
            true,
            'https://oc.exemple.fr',
            'admin',
            true,
        );
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret-nc');
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'secret-oc');
    }

    /**
     * Forge en base une ligne PRÉSENTE mais ILLISIBLE : l'autorité désigne un
     * cloud alors qu'aucun cloud n'est actif — la garde rejouée à la lecture la
     * refuse. C'est exactement l'état qu'une édition en SQL ou un import cassé
     * produit, et celui où la commande de réparation doit rester utilisable.
     *
     * @return array<string, string> le payload forgé, pour vérifier qu'il ne bouge pas
     */
    private function forgeLigneIllisible(): array
    {
        $payload = [
            'espace_perso.autorite' => 'nextcloud',
            'espace_partage.autorite' => 'posix',
            'cloud.actif' => 'aucun',
        ];

        SystemSetting::set(FileLocationService::SETTING_KEY, $payload);

        return $payload;
    }

    /** @return array<string, string>|null */
    private function ligneEnregistree(): ?array
    {
        $stored = SystemSetting::get(FileLocationService::SETTING_KEY);

        return is_array($stored) ? $stored : null;
    }

    // =========================================================================
    // AUCUN appel réseau
    // =========================================================================

    #[Test]
    public function elle_n_emet_aucun_appel_reseau(): void
    {
        Http::fake();
        $this->configureNextcloud(true, true);

        $this->artisan('files:adopt-locations')->assertExitCode(0);

        Http::assertNothingSent();
    }

    #[Test]
    public function le_chemin_opencloud_n_emet_pas_davantage_d_appel_reseau(): void
    {
        Http::fake();
        $this->configureOpenCloud(true, false);

        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('Motifs :')
            ->assertExitCode(0);

        Http::assertNothingSent();
        self::assertSame('opencloud', FileLocationService::current()->cloudActif->value);
    }

    // =========================================================================
    // AC7 — refus n°1 : les deux clouds configurés
    // =========================================================================

    #[Test]
    public function les_deux_clouds_configures_est_un_refus_nomme(): void
    {
        Http::fake();
        $this->configureLesDeuxClouds(true, true);

        // AC7 : le message nomme LES DEUX PRODUITS **et leurs URL**, pas
        // seulement le conflit — sans les URL, l'exploitant ne sait pas quelles
        // deux instances s'affrontent.
        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('les deux clouds')
            ->expectsOutputToContain('Nextcloud : https://nuage.exemple.fr')
            ->expectsOutputToContain('OpenCloud : https://oc.exemple.fr')
            ->expectsOutputToContain('ne choisit pas à la place de l\'administrateur')
            ->assertExitCode(1);

        Http::assertNothingSent();
        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
    }

    /**
     * AC7 — « la ligne reste inchangée si elle existait ». Les cinq autres tests
     * de refus vérifient son ABSENCE ; celui-ci vérifie sa NON-MODIFICATION,
     * qui est l'autre moitié du contrat.
     */
    #[Test]
    public function un_refus_laisse_intacte_une_ligne_deja_enregistree(): void
    {
        Http::fake();
        $this->configureNextcloud(true, true);
        $this->artisan('files:adopt-locations')->assertExitCode(0);
        self::assertSame(self::DECISION_NEXTCLOUD_POSIX, $this->ligneEnregistree());

        // Le second cloud est configuré à son tour : la reprise ne peut plus trancher.
        $this->configureLesDeuxClouds(true, true);

        $this->artisan('files:adopt-locations')->assertExitCode(1);

        self::assertSame(self::DECISION_NEXTCLOUD_POSIX, $this->ligneEnregistree(), 'un refus ne réécrit ni n\'efface la ligne existante');
        Http::assertNothingSent();
    }

    // =========================================================================
    // AC7 — refus n°2 : emplacement web-uniquement sans aucun cloud configuré
    // =========================================================================

    #[Test]
    public function home_coupe_sans_aucun_cloud_configure_est_un_refus_nomme(): void
    {
        Http::fake();
        FilePolicyService::setGlobal(false, true, false);

        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('aucun cloud')
            ->expectsOutputToContain('L\'espace perso')
            ->assertExitCode(1);

        Http::assertNothingSent();
        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
    }

    #[Test]
    public function shares_coupe_sans_aucun_cloud_configure_est_un_refus_nomme(): void
    {
        Http::fake();
        FilePolicyService::setGlobal(true, false, false);

        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('aucun cloud')
            ->expectsOutputToContain('n\'invente jamais un emplacement')
            ->expectsOutputToContain('L\'espace partagé')
            ->assertExitCode(1);

        Http::assertNothingSent();
        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
    }

    /**
     * AC7 — le message DISTINGUE « capacité éteinte » de « capacité active mais
     * connexion incomplète », et, dans ce second cas, NOMME ce qui manque : ici
     * la capacité Nextcloud est active, mais ni URL ni identifiant ni secret.
     */
    #[Test]
    public function une_capacite_active_mais_une_connexion_incomplete_n_est_pas_un_cloud_configure(): void
    {
        Http::fake();
        FilePolicyService::setGlobal(false, true, true);

        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('capacité active mais connexion incomplète')
            ->expectsOutputToContain('l\'URL du serveur Nextcloud')
            ->assertExitCode(1);

        Http::assertNothingSent();
        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
    }

    /**
     * Symétrique, et c'est l'AUTRE branche du même message : une connexion
     * complète mais la capacité éteinte n'est pas davantage « configurée »
     * (piège nommé de `OpenCloudDeploymentService::prefillConnection()`). Le
     * libellé attendu doit donc être celui de la capacité, JAMAIS celui de la
     * connexion incomplète — sinon l'assertion passerait pour les deux branches
     * et ne prouverait plus la distinction que l'AC7 lui confie.
     */
    #[Test]
    public function une_connexion_complete_mais_une_capacite_eteinte_n_est_pas_un_cloud_configure(): void
    {
        Http::fake();
        FilePolicyService::setGlobal(false, true, false, 'https://nuage.exemple.fr', 'admin', 'se4fs', true);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret-nc');

        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('capacité « Accès Nextcloud » désactivée')
            ->doesntExpectOutputToContain('capacité active mais connexion incomplète')
            ->assertExitCode(1);

        Http::assertNothingSent();
        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
    }

    // =========================================================================
    // --dry-run — la décision ET LES MOTIFS, sans écrire
    // =========================================================================

    /**
     * AC6 : `--dry-run` affiche la décision ET LES MOTIFS. Le code de sortie
     * est ≠ 0 tant qu'il reste à écrire — même doctrine que `ad:immutable-key`,
     * pour qu'un enchaînement `--dry-run && <geste réel>` ne prenne pas une
     * simulation pour un feu vert.
     */
    #[Test]
    public function dry_run_affiche_la_decision_et_les_motifs_sans_rien_ecrire(): void
    {
        $this->configureNextcloud(true, true);

        $this->artisan('files:adopt-locations', ['--dry-run' => true])
            ->expectsOutputToContain('Simulation')
            ->expectsOutputToContain('Motifs :')
            ->expectsOutputToContain('l\'accès au home est actif')
            ->expectsOutputToContain('Nextcloud : connexion complète')
            ->assertExitCode(1);

        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
    }

    #[Test]
    public function dry_run_ne_rend_zero_que_lorsqu_il_n_y_a_plus_rien_a_ecrire(): void
    {
        $this->configureNextcloud(true, true);
        $this->artisan('files:adopt-locations')->assertExitCode(0);

        $this->artisan('files:adopt-locations', ['--dry-run' => true])
            ->expectsOutputToContain('conforme')
            ->expectsOutputToContain('Motifs :')
            ->assertExitCode(0);
    }

    // =========================================================================
    // Idempotence
    // =========================================================================

    #[Test]
    public function rejouee_sur_un_etat_deja_repris_elle_rend_deja_conforme_et_n_ecrit_rien(): void
    {
        $this->configureNextcloud(true, true);

        $this->artisan('files:adopt-locations')->assertExitCode(0);

        // On RECULE l'horodatage d'un jour avant de rejouer : sans cela,
        // l'assertion ne pourrait pas échouer (deux exécutions dans la même
        // seconde rendent le même horodatage, et Eloquent n'écrit pas une ligne
        // non modifiée). Reculé, tout `save()` — même « à l'identique » — se
        // verrait.
        $backdated = now()->subDay()->startOfSecond();
        SystemSetting::query()
            ->where('key', FileLocationService::SETTING_KEY)
            ->update(['updated_at' => $backdated]);

        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('conforme')
            ->assertExitCode(0);

        $row = SystemSetting::query()->where('key', FileLocationService::SETTING_KEY)->firstOrFail();
        self::assertSame(
            $backdated->format('Y-m-d H:i:s'),
            $row->updated_at->format('Y-m-d H:i:s'),
            'aucune écriture ne doit toucher updated_at',
        );
    }

    // =========================================================================
    // Décision existante différente : refus sans --force, écrasement avec
    // =========================================================================

    #[Test]
    public function une_decision_existante_differente_n_est_pas_ecrasee_sans_force(): void
    {
        $this->configureNextcloud(true, true);
        $this->artisan('files:adopt-locations')->assertExitCode(0);

        // La politique change : home est maintenant coupé, donc la décision
        // calculée diffère de celle déjà enregistrée.
        $this->configureNextcloud(false, true);

        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('force')
            ->assertExitCode(1);

        self::assertSame(self::DECISION_NEXTCLOUD_POSIX, $this->ligneEnregistree(), 'la décision enregistrée ne doit pas bouger');
    }

    #[Test]
    public function force_ecrase_une_decision_existante_differente(): void
    {
        $this->configureNextcloud(true, true);
        $this->artisan('files:adopt-locations')->assertExitCode(0);

        $this->configureNextcloud(false, true);

        $this->artisan('files:adopt-locations', ['--force' => true])->assertExitCode(0);

        self::assertSame('nextcloud', FileLocationService::current()->espacePerso->value);
    }

    /**
     * `--dry-run` reste une pure prévisualisation même si une décision
     * différente est déjà enregistrée : rien n'est écrit, donc pas besoin de
     * `--force` pour l'obtenir — mais le code de sortie dit qu'il reste du
     * travail.
     */
    #[Test]
    public function dry_run_previsualise_sans_exiger_force_meme_avec_une_decision_differente(): void
    {
        $this->configureNextcloud(true, true);
        $this->artisan('files:adopt-locations')->assertExitCode(0);

        $this->configureNextcloud(false, true);

        $this->artisan('files:adopt-locations', ['--dry-run' => true])
            ->expectsOutputToContain('Simulation')
            ->expectsOutputToContain('Une décision différente est déjà enregistrée')
            ->assertExitCode(1);

        // La ligne enregistrée n'a pas bougé.
        self::assertSame(self::DECISION_NEXTCLOUD_POSIX, $this->ligneEnregistree());
    }

    // =========================================================================
    // Une ligne ILLISIBLE : la commande la répare, elle ne plante pas dessus
    // =========================================================================

    /**
     * Une ligne que la garde de lecture refuse ne doit pas faire planter l'outil
     * dont c'est justement le rôle de la réparer : sans `--force`, refus NOMMÉ
     * et ligne intacte — y compris en `--dry-run`, qui lit l'existant lui aussi.
     */
    #[Test]
    public function une_ligne_illisible_est_un_refus_nomme_sans_force_et_reste_intacte(): void
    {
        $this->configureNextcloud(true, true);
        $forge = $this->forgeLigneIllisible();

        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('ILLISIBLE')
            ->expectsOutputToContain('--force')
            ->assertExitCode(1);

        self::assertSame($forge, $this->ligneEnregistree());

        $this->artisan('files:adopt-locations', ['--dry-run' => true])
            ->expectsOutputToContain('ILLISIBLE')
            ->assertExitCode(1);

        self::assertSame($forge, $this->ligneEnregistree());
    }

    #[Test]
    public function force_repare_une_ligne_illisible(): void
    {
        $this->configureNextcloud(true, true);
        $this->forgeLigneIllisible();

        $this->artisan('files:adopt-locations', ['--force' => true])
            ->expectsOutputToContain('illisible')
            ->assertExitCode(0);

        self::assertSame(self::DECISION_NEXTCLOUD_POSIX, $this->ligneEnregistree());
    }
}
