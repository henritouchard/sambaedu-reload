<?php

declare(strict_types=1);

namespace Tests\Feature\OpenCloud;

use App\Services\FilePolicyService;
use App\Services\OpenCloud\Deployment\DeploymentOutcome;
use App\Services\OpenCloud\Deployment\OpenCloudDeploymentService;
use App\Services\OpenCloud\Deployment\OpenCloudHelperRunner;
use App\Services\OpenCloud\Deployment\SudoOpenCloudHelperRunner;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LE DÉPLOIEMENT : IDEMPOTENT, NON DESTRUCTEUR, ET IL N'ACTIVE RIEN.
 *
 * Le seam privilégié est REMPLACÉ par une doublure qui observe la SÉQUENCE exacte
 * des appels — aucun conteneur n'est exécuté ici. C'est le patron du système
 * d'extensions, repris tel quel : ce qui se vérifie, c'est ce que SE5 DEMANDE au
 * root, pas ce que root en fait.
 *
 * Trois propriétés valent plus que les autres, parce qu'un manquement à chacune
 * détruit des données ou en donne l'accès :
 *
 *  1. **aucun verbe destructeur n'existe** — pas dans la commande, pas dans le
 *     pilote, pas dans le script ;
 *  2. **le secret ne sort par aucun canal** — il ne passe que par l'entrée
 *     standard, jamais en argument (argv est lisible par n'importe qui) ;
 *  3. **la capacité n'est jamais activée** — une instance déployée ne devient pas
 *     une autorité d'écriture parce qu'elle existe.
 */
class OpenCloudDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private FakeOpenCloudHelperRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new FakeOpenCloudHelperRunner();
        $this->app->instance(OpenCloudHelperRunner::class, $this->runner);
    }

    private function service(): OpenCloudDeploymentService
    {
        return $this->app->make(OpenCloudDeploymentService::class);
    }

    // =========================================================================
    // Le déploiement
    // =========================================================================

    #[Test]
    public function a_first_deployment_generates_the_secret_stores_it_encrypted_and_never_shows_it(): void
    {
        $this->runner->stub('deploy', [
            'outcome=deployed', 'image=opencloudeu/opencloud:7.2.3', 'container=sambaedu-opencloud-opencloud',
            'port=9200', 'url=https://fichiers.etab.fr', 'initialised=false', 'health=200',
        ]);

        $report = $this->service()->deploy(9200, 'https://fichiers.etab.fr');

        self::assertSame(DeploymentOutcome::Deployed, $report->outcome);
        self::assertSame(0, $report->outcome->exitCode());

        // Le secret est GÉNÉRÉ et rangé CHIFFRÉ.
        $secret = app(ServiceCredentials::class)->password(OpenCloudConnectionConfig::CREDENTIAL_NAME);
        self::assertNotNull($secret);
        self::assertGreaterThanOrEqual(24, strlen((string) $secret));

        // Il n'est passé QUE par l'entrée standard — jamais en argument. (L'état
        // est consulté d'abord : on ne génère un secret que si l'instance n'est
        // pas déjà initialisée, sans quoi on rangerait un mot de passe qui
        // n'ouvre rien.)
        self::assertSame(
            ['status', 'deploy'],
            array_map(static fn (array $c): string => $c['verb'], $this->runner->calls),
        );
        $call = $this->runner->calls[1];
        self::assertSame(['deploy', '9200', 'https://fichiers.etab.fr'], $call['args']);
        self::assertSame($secret . "\n", $call['stdin']);
        foreach ($call['args'] as $arg) {
            self::assertStringNotContainsString((string) $secret, $arg);
        }

        // Et il n'apparaît nulle part dans ce que la commande rend.
        self::assertStringNotContainsString((string) $secret, json_encode($report->toArray(), JSON_UNESCAPED_UNICODE));
    }

    /** Rejoué, il CONVERGE : pas de recréation, et le secret déjà rangé est réutilisé. */
    #[Test]
    public function a_replayed_deployment_converges_and_reuses_the_stored_secret(): void
    {
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'déjà-là');

        $this->runner->stub('deploy', ['outcome=conforming', 'initialised=true', 'health=200', 'port=9200']);

        $report = $this->service()->deploy(9200, 'https://fichiers.etab.fr');

        self::assertSame(DeploymentOutcome::Conforming, $report->outcome);
        self::assertSame(0, $report->outcome->exitCode());
        self::assertStringContainsString('aucune donnée touchée', $report->message);

        // Le secret n'est PAS régénéré : le régénérer laisserait l'instance avec
        // l'ancien et SE5 avec le nouveau, sans que rien ne le dise.
        self::assertSame('déjà-là', app(ServiceCredentials::class)->password(OpenCloudConnectionConfig::CREDENTIAL_NAME));
        self::assertSame("déjà-là\n", $this->runner->calls[0]['stdin']);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * **UN SECRET PERDU SUR UNE INSTANCE DÉJÀ INITIALISÉE EST UN REFUS NOMMÉ, PAS
     * UN NOUVEAU SECRET.**
     *
     * Le seam ne pose le mot de passe qu'à la PREMIÈRE initialisation ; ensuite il
     * l'ignore, parce que ré-initialiser réécrirait les secrets internes de
     * l'instance. En générer un ici rangerait donc un mot de passe qui n'ouvre
     * rien : l'authentification rendrait `401` nu, et il n'existe **aucun chemin
     * de reprise** — pas de verbe de changement de mot de passe, et la
     * ré-initialisation est interdite à raison.
     *
     * Le cas est réel : restauration de base sans la table des secrets, ou oubli
     * volontaire du secret suivi d'un redéploiement.
     * ═══════════════════════════════════════════════════════════════════════
     */
    #[Test]
    public function a_lost_secret_on_an_already_initialised_instance_is_refused_by_name_never_regenerated(): void
    {
        $this->runner->stub('status', ['present=true', 'state=running', 'initialised=true']);

        $report = $this->service()->deploy(9200, 'https://fichiers.etab.fr');

        self::assertTrue($report->isFailure());
        self::assertStringContainsString('DÉJÀ initialisée', $report->message);
        self::assertStringContainsString('Administration › Fichiers', $report->message);

        // RIEN n'a été rangé : un secret qui n'ouvre rien est pire qu'aucun.
        self::assertNull(app(ServiceCredentials::class)->password(OpenCloudConnectionConfig::CREDENTIAL_NAME));

        // Et le déploiement n'a jamais été demandé au root.
        self::assertSame(
            ['status'],
            array_map(static fn (array $c): string => $c['verb'], $this->runner->calls),
        );
    }

    /** Le pendant : sur une instance NEUVE, le secret est bien généré et rangé. */
    #[Test]
    public function a_lost_secret_on_a_fresh_instance_is_generated_as_before(): void
    {
        $this->runner->stub('status', ['present=false', 'initialised=false']);
        $this->runner->stub('deploy', ['outcome=deployed', 'initialised=false', 'health=200', 'port=9200']);

        $report = $this->service()->deploy(9200, 'https://fichiers.etab.fr');

        self::assertFalse($report->isFailure());
        self::assertNotNull(app(ServiceCredentials::class)->password(OpenCloudConnectionConfig::CREDENTIAL_NAME));
    }

    /**
     * **LA REPRISE DE PROPRIÉTÉ DES VOLUMES SE DIT.** Une instance installée avant
     * le durcissement avait ses volumes sur un compte ordinaire de la machine —
     * donc son fichier de configuration, et les secrets internes qu'il porte,
     * lisibles par lui. Le déploiement les reprend ; le taire laisserait
     * l'exploitant croire qu'il n'y a jamais rien eu à savoir.
     */
    #[Test]
    public function reclaiming_the_volumes_from_another_account_is_reported_not_silent(): void
    {
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'déjà-là');

        $this->runner->stub('deploy', [
            'outcome=deployed', 'initialised=true', 'health=200', 'port=9200',
            'run_user=sambaedu-opencloud', 'ownership_reclaimed=true',
        ]);

        $report = $this->service()->deploy(9200, 'https://fichiers.etab.fr');

        self::assertStringContainsString('REPRISE', $report->message);
        self::assertStringContainsString('sambaedu-opencloud', $report->message);
    }

    /**
     * **L'URL DOIT ÊTRE EN HTTPS, ET LE REFUS ARRIVE AVANT LE SEAM.** Mesuré : le
     * service d'identité de l'instance refuse de démarrer sinon, et le conteneur
     * meurt APRÈS avoir à moitié amorcé son annuaire interne — un état dont
     * l'instance ne se relève pas. On refuse donc AVANT, en le disant.
     */
    #[Test]
    public function a_non_https_url_is_refused_before_the_privileged_seam_is_even_called(): void
    {
        $report = $this->service()->deploy(9200, 'http://fichiers.etab.fr');

        self::assertTrue($report->isFailure());
        self::assertSame(2, $report->outcome->exitCode());
        self::assertStringContainsString('https://', $report->message);
        self::assertStringContainsString('annuaire interne', $report->message);
        self::assertSame([], $this->runner->calls, 'aucun appel privilégié ne doit être émis');
    }

    /** Un port occupé est un refus NOMMÉ remonté tel quel — jamais un écrasement. */
    #[Test]
    public function a_port_taken_by_a_third_party_is_a_named_refusal(): void
    {
        $this->runner->fail('deploy', 'sambaedu-opencloud-helper: le port 9200 est déjà utilisé par un autre service');

        $report = $this->service()->deploy(9200, 'https://fichiers.etab.fr');

        self::assertTrue($report->isFailure());
        self::assertStringContainsString('déjà utilisé', $report->message);
    }

    /**
     * **LE MODE SANS ÉCRITURE N'EXÉCUTE RIEN** — et il dit ce qui SERAIT fait,
     * y compris ce qui ne serait PAS fait.
     */
    #[Test]
    public function the_dry_run_executes_nothing_and_lists_what_it_would_refuse_to_do(): void
    {
        $this->runner->stub('status', ['present=false', 'initialised=false']);

        $report = $this->service()->deploy(9200, 'https://fichiers.etab.fr', dryRun: true);

        self::assertFalse($report->isFailure());
        self::assertSame(['status'], array_column($this->runner->calls, 'verb'));

        $steps = implode(' | ', $report->steps);
        self::assertStringContainsString('NE PAS activer la capacité', $steps);
        self::assertStringContainsString('NE supprimer aucun conteneur, aucun volume, aucune donnée', $steps);

        // Aucun secret n'a été créé : le mode sans écriture n'écrit pas, même en base.
        self::assertNull(app(ServiceCredentials::class)->password(OpenCloudConnectionConfig::CREDENTIAL_NAME));
    }

    /**
     * Sur une instance DÉJÀ initialisée, le mode sans écriture doit dire qu'il ne
     * RÉ-initialisera pas : ré-initialiser réécrirait les secrets internes de
     * l'instance et la rendrait inutilisable sur ses propres données.
     */
    #[Test]
    public function the_dry_run_says_it_would_not_reinitialise_an_existing_instance(): void
    {
        $this->runner->stub('status', ['present=true', 'initialised=true']);

        $report = $this->service()->deploy(9200, 'https://fichiers.etab.fr', dryRun: true);

        self::assertStringContainsString('NE PAS ré-initialiser', implode(' ', $report->steps));
    }

    // =========================================================================
    // Ce que le déploiement REFUSE de faire
    // =========================================================================

    /**
     * **LA CAPACITÉ N'EST JAMAIS ACTIVÉE.** Le déploiement pré-remplit deux
     * réglages non secrets, et rien d'autre : activer la capacité, confirmer la
     * connexion et choisir cette autorité à la création d'un répertoire sont trois
     * gestes explicites, dans cet ordre.
     */
    #[Test]
    public function the_deployment_prefills_the_connection_but_never_switches_the_capability_on(): void
    {
        $this->runner->stub('deploy', ['outcome=deployed', 'health=200']);

        $this->service()->deploy(9200, 'https://fichiers.etab.fr');

        $policy = FilePolicyService::globalConfig();
        self::assertFalse($policy['opencloud'], 'la capacité DOIT rester éteinte');
        self::assertSame('https://fichiers.etab.fr', $policy['opencloud_server_url']);
        self::assertSame('admin', $policy['opencloud_admin_user']);
        self::assertTrue($policy['opencloud_verify_tls']);
    }

    /** Et il ne touche à AUCUN réglage de l'autre produit. */
    #[Test]
    public function the_deployment_leaves_every_other_capability_exactly_as_it_was(): void
    {
        FilePolicyService::setGlobal(false, true, true, 'https://nuage.etab.fr', 'ncadmin', 'se4fs', false);
        $before = FilePolicyService::globalConfig();

        $this->runner->stub('deploy', ['outcome=deployed', 'health=200']);
        $this->service()->deploy(9200, 'https://fichiers.etab.fr');

        $after = FilePolicyService::globalConfig();
        foreach (['home', 'shares', 'nextcloud', 'nextcloud_server_url', 'nextcloud_admin_user',
            'nextcloud_smb_host', 'nextcloud_verify_tls'] as $key) {
            self::assertSame($before[$key], $after[$key], $key . ' a bougé');
        }
    }

    /** Un réglage déjà saisi par l'administrateur n'est PAS écrasé. */
    #[Test]
    public function an_already_entered_connection_setting_is_never_overwritten(): void
    {
        FilePolicyService::setGlobal(true, true, false, '', null, null, null, false, 'https://saisi.etab.fr', 'pilote', false);

        $this->runner->stub('deploy', ['outcome=deployed', 'health=200']);
        $this->service()->deploy(9200, 'https://autre.etab.fr');

        $policy = FilePolicyService::globalConfig();
        self::assertSame('https://saisi.etab.fr', $policy['opencloud_server_url']);
        self::assertSame('pilote', $policy['opencloud_admin_user']);
        self::assertFalse($policy['opencloud_verify_tls']);
    }

    /**
     * **AUCUN OBJET DU SYSTÈME D'EXTENSIONS N'EST CRÉÉ, ET LE CANAL D'INSTALLATION
     * NE BOUGE PAS.** C'est la décision de cadre, épinglée : Q3 est tranchée dans
     * une TROISIÈME direction — ni paquet maison, ni canal de conteneur dans le
     * système d'extensions.
     */
    #[Test]
    public function no_extension_object_is_created_and_the_install_channel_is_untouched(): void
    {
        $this->runner->stub('deploy', ['outcome=deployed', 'health=200']);
        $this->service()->deploy(9200, 'https://fichiers.etab.fr');

        self::assertSame(0, \App\Models\Extension::query()->count());
        self::assertSame(
            ['deb'],
            \App\Services\Extensions\ExtensionManifestValidator::SUPPORTED_INSTALL_CHANNELS,
            'aucun canal d\'installation nouveau : Q3 est tranchée dans l\'autre sens',
        );
    }

    /** ARRÊTER n'est pas DÉSINSTALLER : les volumes et les données restent. */
    #[Test]
    public function stopping_the_instance_leaves_its_volumes_and_data_in_place(): void
    {
        $this->runner->stub('stop', ['outcome=stopped', 'state=exited']);

        $report = $this->service()->stop();

        self::assertFalse($report->isFailure());
        self::assertStringContainsString('volumes et les données sont intacts', $report->message);
        self::assertSame(['stop'], array_column($this->runner->calls, 'verb'));
    }

    /**
     * **LE PILOTE NE COMPOSE AUCUN VERBE DESTRUCTEUR — jamais, sur aucun chemin.**
     *
     * C'est une propriété STRUCTURELLE, pas une promesse de commentaire : on
     * exerce les quatre actions et on vérifie que la SÉQUENCE d'appels privilégiés
     * ne contient que les quatre verbes fermés.
     */
    #[Test]
    public function no_code_path_ever_composes_a_destructive_verb(): void
    {
        $this->runner->stub('deploy', ['outcome=deployed', 'health=200']);
        $this->runner->stub('status', ['present=true']);
        $this->runner->stub('stop', ['outcome=stopped']);
        $this->runner->stub('logs', ['ligne']);

        $this->service()->deploy(9200, 'https://fichiers.etab.fr');
        $this->service()->status();
        $this->service()->stop();
        $this->service()->logs(50);

        $verbs = array_values(array_unique(array_column($this->runner->calls, 'verb')));
        sort($verbs);
        self::assertSame(['deploy', 'logs', 'status', 'stop'], $verbs);

        foreach ($this->runner->calls as $call) {
            $line = implode(' ', $call['args']);
            foreach (['rm', 'down', 'prune', 'delete', 'purge', '-v', '--volumes'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $line,
                    'un verbe destructeur a été composé : ' . $line,
                );
            }
        }
    }

    /**
     * **LA COMMANDE PASSE PAR `sudo -n` ET ÉCHAPPE CHAQUE ARGUMENT.** Ce n'est pas
     * la défense principale — le script re-valide tout côté root — c'est la
     * première, et elle est assertable telle quelle.
     */
    #[Test]
    public function the_real_runner_builds_a_non_interactive_escaped_sudo_command(): void
    {
        config()->set('opencloud.helper_path', '/usr/share/sambaedu/sbin/sambaedu-opencloud-helper.sh');

        $command = (new SudoOpenCloudHelperRunner())->buildCommand(['deploy', '9200', 'https://x.fr; rm -rf /']);

        self::assertStringStartsWith('LC_ALL=C sudo -n ', $command);
        self::assertStringContainsString("'/usr/share/sambaedu/sbin/sambaedu-opencloud-helper.sh'", $command);
        // L'argument hostile est ÉCHAPPÉ : il ne peut pas s'échapper de ses quotes.
        self::assertStringContainsString("'https://x.fr; rm -rf /'", $command);
    }

    /** Un seam absent ou non autorisé est un refus NOMMÉ, avec sa piste. */
    #[Test]
    public function a_missing_or_unauthorised_seam_is_a_named_refusal_with_its_lead(): void
    {
        $this->runner->fail('deploy', '');

        $report = $this->service()->deploy(9200, 'https://fichiers.etab.fr');

        self::assertTrue($report->isFailure());
        self::assertStringContainsString('sudoers', $report->message);
    }
}
