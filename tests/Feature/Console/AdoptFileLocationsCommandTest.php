<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocationService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use App\Services\Shortcuts\PortalShortcutIcon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
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
    // CORRECTION DE REVUE 63.3 — LA COMMANDE EST UN ÉCRIVAIN **COMPLET** :
    // ELLE ÉCRIT LA DÉCISION **ET SON MIROIR**
    //
    // Elle n'écrivait que `files.locations`. La divergence produite était
    // silencieuse, transitoire (le premier enregistrement d'écran la réparait) —
    // et pourtant suffisante pour qu'un répertoire géré soit créé sur une
    // instance que la décision ne reconnaît plus comme cloud actif.
    // =========================================================================

    #[Test]
    public function la_decision_ecrite_est_immediatement_reflechie_dans_les_quatre_booleens(): void
    {
        Http::fake();
        $this->configureNextcloud(false, true);

        $this->artisan('files:adopt-locations')->assertExitCode(0);

        self::assertSame('nextcloud', FileLocationService::current()->espacePerso->value);
        self::assertSame(
            ['home' => false, 'shares' => true, 'nextcloud' => true, 'opencloud' => false],
            FilePolicyService::capabilities(),
        );
    }

    /**
     * **LE CAS DE DIVERGENCE, ET IL EST DANGEREUX** : capacité Nextcloud
     * ALLUMÉE mais connexion incomplète ⇒ la reprise écrit « aucun cloud actif ».
     * Sans le miroir, `files.policy` continuait d'annoncer la capacité active, et
     * la posabilité d'une autorité d'écriture — qui ne regarde QUE la capacité
     * côté Nextcloud — autorisait alors un répertoire géré servi par une instance
     * injoignable, que rien ne saurait migrer.
     */
    #[Test]
    public function une_capacite_orpheline_ne_survit_pas_a_la_reprise(): void
    {
        Http::fake();

        // Capacité allumée, connexion vide : le produit n'est PAS configuré.
        FilePolicyService::setGlobal(true, true, true, '');

        $this->artisan('files:adopt-locations')->assertExitCode(0);

        self::assertSame('aucun', FileLocationService::current()->cloudActif->value);
        self::assertFalse(
            FilePolicyService::capabilities()['nextcloud'],
            'la capacité ne doit pas survivre à une décision qui ne porte aucun cloud actif',
        );

        // …et les réglages de connexion, eux, sont INTACTS : le miroir ne dérive
        // que les quatre booléens.
        self::assertSame('', FilePolicyService::globalConfig()['nextcloud_server_url']);
    }

    /** `--force` écrit lui aussi le miroir : les deux clés restent d'accord. */
    #[Test]
    public function force_ecrit_la_decision_et_son_miroir_ensemble(): void
    {
        Http::fake();
        $this->configureNextcloud(true, true);
        $this->artisan('files:adopt-locations')->assertExitCode(0);

        $this->configureNextcloud(false, false);
        $this->artisan('files:adopt-locations', ['--force' => true])->assertExitCode(0);

        self::assertSame(
            ['home' => false, 'shares' => false, 'nextcloud' => true, 'opencloud' => false],
            FilePolicyService::capabilities(),
        );
    }

    /** `--dry-run` n'écrit RIEN — pas davantage le miroir. */
    #[Test]
    public function dry_run_n_ecrit_pas_le_miroir(): void
    {
        Http::fake();
        FilePolicyService::setGlobal(true, true, true, '');
        $avant = FilePolicyService::globalConfig();

        $this->artisan('files:adopt-locations', ['--dry-run' => true])->assertExitCode(1);

        self::assertSame($avant, FilePolicyService::globalConfig());
        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
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

    // =========================================================================
    // Story 63.2 — l'icône du raccourci-portail
    //
    // `shortcuts.portal_icon` est une clé NEUVE, absente de toute instance
    // déployée, et le raccourci « Mes fichiers en ligne » ne dépend plus d'un
    // geste d'écran mais du cloud actif — écrit par CETTE commande. Sans
    // publication ici, le parcours de mise en service documenté (jouer la
    // reprise, puis déployer) poserait sur tous les bureaux un `.lnk` affichant
    // l'icône de `rundll32.exe`.
    // =========================================================================

    /**
     * Redirige le dossier servi : un test n'écrit pas dans le storage de
     * l'application. L'icône SOURCE, elle, reste celle livrée avec le code.
     */
    private function redirigerLeDossierServi(): string
    {
        $served = sys_get_temp_dir().'/se5-adopt-portal-icon-'.uniqid();
        config(['shortcut_icons.served_path' => $served]);

        return $served;
    }

    /** @return array{asset:string, checksum:string}|null */
    private function iconePubliee(): ?array
    {
        $stored = SystemSetting::get(PortalShortcutIcon::SETTING_KEY);

        return is_array($stored) ? $stored : null;
    }

    #[Test]
    public function elle_publie_l_icone_du_portail_quand_la_decision_comporte_un_cloud_actif(): void
    {
        $served = $this->redirigerLeDossierServi();
        $this->configureNextcloud(true, true);

        $this->artisan('files:adopt-locations')->assertExitCode(0);

        $icone = $this->iconePubliee();
        self::assertNotNull($icone, 'la reprise publie l\'icône, sans quoi le raccourci arrive avec celle de rundll32.exe');
        self::assertFileExists($served.'/'.$icone['asset']);
    }

    #[Test]
    public function aucun_cloud_actif_ne_publie_aucune_icone(): void
    {
        $this->redirigerLeDossierServi();
        // Ni Nextcloud ni OpenCloud : la décision est posix/posix/aucun, et
        // aucun raccourci de portail ne sera posé — il n'y a donc rien à
        // publier.
        FilePolicyService::setGlobal(true, true, false);

        $this->artisan('files:adopt-locations')->assertExitCode(0);

        self::assertSame('aucun', FileLocationService::current()->cloudActif->value);
        self::assertNull($this->iconePubliee());
    }

    /**
     * Le chemin « déjà conforme » publie AUSSI — c'est même le cas qui compte le
     * plus : une instance dont les emplacements ont été repris par une version
     * antérieure de la commande n'a jamais eu de ligne `shortcuts.portal_icon`,
     * et rejouer la reprise doit la lui donner.
     */
    #[Test]
    public function le_chemin_deja_conforme_republie_l_icone(): void
    {
        $served = $this->redirigerLeDossierServi();
        $this->configureNextcloud(true, true);
        $this->artisan('files:adopt-locations')->assertExitCode(0);

        // On efface la trace de publication : l'état d'une instance reprise
        // avant que la commande ne publie quoi que ce soit.
        SystemSetting::query()->where('key', PortalShortcutIcon::SETTING_KEY)->delete();
        self::assertNull($this->iconePubliee());

        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('conforme')
            ->assertExitCode(0);

        $icone = $this->iconePubliee();
        self::assertNotNull($icone);
        self::assertFileExists($served.'/'.$icone['asset']);
    }

    /**
     * **`--dry-run` ne publie RIEN**, dans les DEUX sens : ni sur le chemin
     * « il reste à écrire », ni sur le chemin « déjà conforme » — le seul qui
     * rende 0 en simulation, et donc le seul où l'oubli d'une garde passerait
     * inaperçu. Une simulation n'écrit pas, pas même de l'état dérivé.
     */
    #[Test]
    public function dry_run_ne_publie_aucune_icone(): void
    {
        $served = $this->redirigerLeDossierServi();
        $this->configureNextcloud(true, true);

        // Sens 1 — rien n'est encore enregistré : la simulation ne publie pas.
        $this->artisan('files:adopt-locations', ['--dry-run' => true])->assertExitCode(1);
        self::assertNull($this->iconePubliee());

        // Sens 2 — la décision est enregistrée puis la trace d'icône effacée :
        // la simulation passe par « déjà conforme », rend 0… et ne publie
        // toujours rien.
        $this->artisan('files:adopt-locations')->assertExitCode(0);
        SystemSetting::query()->where('key', PortalShortcutIcon::SETTING_KEY)->delete();

        $this->artisan('files:adopt-locations', ['--dry-run' => true])
            ->expectsOutputToContain('conforme')
            ->assertExitCode(0);
        self::assertNull($this->iconePubliee(), 'une simulation n\'écrit pas, pas même de l\'état dérivé');

        // Et l'exécution réelle, elle, la repose : la garde du dry-run n'a pas
        // désactivé la publication au passage.
        $this->artisan('files:adopt-locations')->assertExitCode(0);
        $icone = $this->iconePubliee();
        self::assertNotNull($icone);
        self::assertFileExists($served.'/'.$icone['asset']);
    }

    // =========================================================================
    // CORRECTION DE REVUE 63.3 (résidu B1) — `--cloud=` : LE CHOIX DEVIENT UNE
    // ENTRÉE, PLUS UNE DÉRIVATION
    //
    // Deux héritages n'avaient AUCUNE sortie hors SQL, et le second est celui
    // qui motive la correction : `home = false` avec les DEUX capacités cloud
    // éteintes. L'administrateur peut y déclarer une connexion complète (l'écran
    // monte les deux blocs de connexion), mais l'écran affiche le bandeau de
    // reprise et n'offre AUCUN contrôle de décision ; or la capacité, seul
    // critère que la dérivation regardait, n'a plus qu'un écrivain : le miroir,
    // écrit par une décision que le bandeau interdit de prendre.
    // =========================================================================

    /** Pose une connexion Nextcloud COMPLÈTE sans toucher aux capacités. */
    private function poserLaConnexionNextcloud(): void
    {
        FilePolicyService::patchGlobal([
            'nextcloud_server_url' => 'https://nuage.exemple.fr',
            'nextcloud_admin_user' => 'admin',
        ]);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret-nc');
    }

    /**
     * **LE CAS SANS ISSUE, DE BOUT EN BOUT.** L'héritage coupe l'accès au home
     * et n'allume aucune capacité cloud. L'administrateur déclare sa connexion
     * Nextcloud depuis l'écran — le seul geste que le bandeau lui laisse — puis
     * DÉSIGNE le produit. La reprise passe, écrit la décision, ALLUME la
     * capacité par le miroir, et l'écran redevient décidable.
     *
     * Sans l'allumage par le miroir, la connexion déclarée resterait inerte
     * (`NextcloudConnectionConfig::current()` lève tant que la capacité est
     * éteinte) : on n'aurait rien débloqué.
     */
    #[Test]
    public function le_choix_explicite_est_la_sortie_de_l_instance_sans_issue(): void
    {
        Http::fake();

        // ① L'héritage : accès au home coupé, AUCUNE capacité cloud.
        FilePolicyService::setGlobal(false, true, false, '');

        // ② L'écran est bloqué sur le bandeau de reprise — aucun contrôle de
        //    décision — et la reprise dérivée refuse.
        $ecran = $this->ecranDesEmplacements();
        self::assertNotNull($ecran->get('adoptionNotice'));
        self::assertStringNotContainsString('save-locations', $ecran->html());

        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('aucun cloud')
            ->expectsOutputToContain('désignez-le')
            ->assertExitCode(1);

        // ③ Le seul geste que l'écran laisse : déclarer la connexion, depuis le
        //    bloc que le bandeau continue de monter.
        Livewire::test('pages::admin.settings.files._partials.nextcloud-connection')
            ->set('nextcloudServerUrl', 'https://nuage.exemple.fr')
            ->set('nextcloudAdminUser', 'admin')
            ->set('nextcloudAdminPassword', 'app-password');

        self::assertFalse(
            FilePolicyService::capabilities()['nextcloud'],
            'le bloc de connexion n\'écrit AUCUNE capacité — c\'est tout le problème',
        );

        // ④ …et la reprise DÉSIGNÉE passe.
        $this->artisan('files:adopt-locations', ['--cloud' => 'nextcloud'])
            ->expectsOutputToContain('Décision enregistrée.')
            ->expectsOutputToContain('désigné par --cloud')
            ->assertExitCode(0);

        self::assertSame(
            [
                'espace_perso.autorite' => 'nextcloud',
                'espace_partage.autorite' => 'posix',
                'cloud.actif' => 'nextcloud',
            ],
            $this->ligneEnregistree(),
        );

        // ⑤ La capacité est ALLUMÉE par le miroir — sans quoi la connexion
        //    déclarée resterait inerte.
        self::assertTrue(FilePolicyService::capabilities()['nextcloud']);
        self::assertSame(
            'https://nuage.exemple.fr',
            NextcloudConnectionConfig::current()->baseUrl,
            'la connexion doit être résoluble : c\'est la définition de « débloqué »',
        );

        // ⑥ L'écran redevient DÉCIDABLE : bandeau parti, contrôles présents.
        $ecran = $this->ecranDesEmplacements();
        $ecran->assertSet('adoptionNotice', null)->assertSet('decided', true);
        self::assertStringContainsString('save-locations', $ecran->html());
        self::assertSame('nextcloud', $ecran->get('espacePerso'));

        Http::assertNothingSent();
    }

    /**
     * Désigner un produit ne le rend pas joignable : la connexion reste
     * VÉRIFIÉE, et son incomplétude est nommée. Rien n'est écrit — ni décision,
     * ni miroir.
     */
    #[Test]
    public function un_cloud_designe_dont_la_connexion_est_incomplete_est_un_refus_nomme(): void
    {
        Http::fake();
        FilePolicyService::setGlobal(false, true, false, 'https://nuage.exemple.fr');
        $avant = FilePolicyService::globalConfig();

        $this->artisan('files:adopt-locations', ['--cloud' => 'nextcloud'])
            ->expectsOutputToContain('INCOMPLÈTE')
            ->expectsOutputToContain('l\'identifiant du compte admin Nextcloud')
            ->expectsOutputToContain('Désigner un cloud ne le rend pas joignable')
            ->assertExitCode(1);

        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
        self::assertSame($avant, FilePolicyService::globalConfig());
        Http::assertNothingSent();
    }

    /**
     * L'AUTRE héritage sans issue : les deux clouds configurés. Le refus dérivé
     * attend exactement ce geste de l'administrateur — « la reprise ne choisit
     * pas à votre place » — et `--cloud=` est la façon de le poser.
     */
    #[Test]
    public function les_deux_clouds_configures_se_tranchent_par_le_choix_explicite(): void
    {
        Http::fake();
        $this->configureLesDeuxClouds(true, false);

        // Le refus dérivé RENVOIE vers l'option.
        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('--cloud=nextcloud ou --cloud=opencloud')
            ->assertExitCode(1);

        $this->artisan('files:adopt-locations', ['--cloud' => 'opencloud'])->assertExitCode(0);

        self::assertSame(
            [
                'espace_perso.autorite' => 'posix',
                'espace_partage.autorite' => 'opencloud',
                'cloud.actif' => 'opencloud',
            ],
            $this->ligneEnregistree(),
        );

        // Le miroir ferme « les deux à la fois » dans `files.policy` aussi.
        self::assertSame(
            ['home' => true, 'shares' => false, 'nextcloud' => false, 'opencloud' => true],
            FilePolicyService::capabilities(),
        );
        Http::assertNothingSent();
    }

    /** Le vocabulaire est FERMÉ, et le refus le rappelle en entier. */
    #[Test]
    public function une_valeur_de_cloud_inconnue_est_un_refus_nomme(): void
    {
        Http::fake();
        $this->configureNextcloud(true, true);

        $this->artisan('files:adopt-locations', ['--cloud' => 'owncloud'])
            ->expectsOutputToContain('« owncloud » ne désigne aucun cloud connu')
            ->expectsOutputToContain('aucun, nextcloud, opencloud')
            ->assertExitCode(1);

        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
    }

    /**
     * `--cloud=aucun` ne contourne pas la garde de cohérence : un emplacement ne
     * peut pas désigner un cloud absent, et l'héritage `home = false` en exige
     * un.
     */
    #[Test]
    public function cloud_aucun_contredit_par_l_heritage_est_un_refus_nomme(): void
    {
        Http::fake();
        $this->configureNextcloud(false, true);

        $this->artisan('files:adopt-locations', ['--cloud' => 'aucun'])
            ->expectsOutputToContain('ne peut pas désigner un cloud absent')
            ->expectsOutputToContain('L\'espace perso')
            ->assertExitCode(1);

        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
        self::assertTrue(
            FilePolicyService::capabilities()['nextcloud'],
            'un refus ne touche pas davantage au miroir',
        );
    }

    /** `--dry-run --cloud=…` n'écrit RIEN : ni décision, ni miroir, ni icône. */
    #[Test]
    public function dry_run_avec_un_cloud_designe_n_ecrit_rien(): void
    {
        Http::fake();
        $this->redirigerLeDossierServi();
        FilePolicyService::setGlobal(false, true, false, '');
        $this->poserLaConnexionNextcloud();
        $avant = FilePolicyService::globalConfig();

        $this->artisan('files:adopt-locations', ['--cloud' => 'nextcloud', '--dry-run' => true])
            ->expectsOutputToContain('Simulation')
            ->expectsOutputToContain('désigné par --cloud')
            ->assertExitCode(1);

        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
        self::assertSame($avant, FilePolicyService::globalConfig());
        self::assertNull($this->iconePubliee());
    }

    /**
     * L'écran des emplacements, monté sous un compte autorisé — la seule façon
     * de constater qu'une instance est « sans issue », puis qu'elle ne l'est
     * plus.
     */
    private function ecranDesEmplacements(): Testable
    {
        $this->withoutVite();

        $admin = User::query()->firstOrCreate(
            ['login' => 'files-admin'],
            ['role' => 'prof', 'is_active' => false],
        );
        $admin->forceFill(['source' => 'federated'])->save();
        $this->actingAs($admin);
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'server.admin' ? true : null);

        return Livewire::test('pages::admin.settings.files._partials.emplacements-tab');
    }

    /**
     * FAIL-SOFT : une publication impossible (dossier servi non créable) ne fait
     * pas échouer la reprise. Ce que la commande doit garantir, c'est la
     * décision d'emplacement — un raccourci sans icône reste un chemin d'accès,
     * une reprise avortée n'en est pas un.
     */
    #[Test]
    public function une_publication_impossible_ne_fait_pas_echouer_la_reprise(): void
    {
        // Un FICHIER là où le service attend un dossier : ni `is_dir()` ni
        // `mkdir()` ne peuvent aboutir.
        $obstacle = sys_get_temp_dir().'/se5-adopt-portal-icon-obstacle-'.uniqid();
        file_put_contents($obstacle, 'pas un dossier');
        config(['shortcut_icons.served_path' => $obstacle.'/servi']);

        $this->configureNextcloud(true, true);

        $this->artisan('files:adopt-locations')
            ->expectsOutputToContain('sans icône')
            ->assertExitCode(0);

        self::assertSame(self::DECISION_NEXTCLOUD_POSIX, $this->ligneEnregistree());
        self::assertNull($this->iconePubliee());

        @unlink($obstacle);
    }
}
