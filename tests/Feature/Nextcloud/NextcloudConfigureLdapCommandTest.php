<?php

declare(strict_types=1);

namespace Tests\Feature\Nextcloud;

use App\Config\LdapConfig;
use App\Config\SambaEduConfig;
use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudLdapSyncSettings;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Le rattachement de l'instance Nextcloud à l'annuaire : ce qu'il écrit, ce qu'il
 * refuse d'écrire, et ce qu'il vérifie après avoir écrit.
 *
 * **Tout passe par `Http::fake()`** — c'est la raison d'être du choix « 100 % HTTP,
 * pas de commande dans le conteneur » : le geste est observable sans réseau, sans
 * instance, à chaque exécution de la suite.
 */
class NextcloudConfigureLdapCommandTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://cloud.etab.fr';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindLdap();
    }

    private function configureInstance(bool $enabled = true): void
    {
        FilePolicyService::setGlobal(true, true, $enabled, self::URL, 'admin', 'se4fs', true);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'sekret');
    }

    private function bindLdap(string $baseDn = 'dc=localdev,dc=fr', string $password = 'S3cr3tDeLecture'): void
    {
        $ldap = new LdapConfig(
            url: 'ldaps://localdev.fr',
            port: 636,
            baseDn: $baseDn,
            adminName: 'Administrator',
            adminPassword: $password,
            domain: 'localdev.fr',
            sambaDomain: 'localdev',
            peopleRdn: 'ou=Utilisateurs',
            groupsRdn: 'ou=Groups',
            computersRdn: 'ou=computers',
            parcsRdn: 'ou=Parcs',
            classesRdn: 'ou=classes',
            equipesRdn: 'ou=equipes',
            matieresRdn: 'ou=matieres',
            coursRdn: 'ou=cours',
            projetsRdn: 'ou=projets',
            otherGroupsRdn: 'ou=autres',
            delegationsRdn: 'ou=delegations',
            equipementsRdn: 'ou=Materiels',
            rightsRdn: 'ou=Rights',
            trashRdn: 'ou=Trash',
            etablissementsRdn: 'ou=Etablissements',
            adminRdn: 'ou=Admin',
        );

        // Mock PARTIEL : seule la configuration d'annuaire est imposée. Le
        // conteneur résout ce même service pour d'autres usages pendant la
        // commande (l'aide au DN, notamment) — un mock strict ferait échouer le
        // test sur un appel qui n'a rien à voir avec ce qu'il vérifie.
        $config = Mockery::mock(SambaEduConfig::class)->makePartial();
        $config->shouldReceive('ldap')->andReturn($ldap);
        $this->app->instance(SambaEduConfig::class, $config);
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $code, array $data = []): array
    {
        return ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => $code, 'message' => 'OK'], 'data' => $data]];
    }

    /** La configuration telle que l'instance la rendrait si elle était conforme. */
    private static function conformingRemote(bool $trustCertificate = false): array
    {
        $ldap = app(SambaEduConfig::class)->ldap();

        return NextcloudLdapSyncSettings::for($ldap, $trustCertificate)->keys
            + ['ldapAgentPassword' => '***'];
    }

    /**
     * L'instance simulée. `$configs` donne l'état des configurations existantes,
     * indexé par identifiant ; tout identifiant absent rend le `404` mesuré.
     *
     * @param  array<string, array<string, mixed>>  $configs
     * @param  array<string, string>  $backendPerLogin  L'origine du compte, par login —
     *   c'est ce qui permet de simuler l'homonyme local d'un compte d'annuaire.
     */
    private function fakeInstance(
        array $configs = [],
        bool $probeFound = true,
        string $backend = 'LDAP',
        array $backendPerLogin = [],
    ): void {
        Http::fake(function (Request $request) use ($configs, $probeFound, $backend, $backendPerLogin) {
            $url = $request->url();
            $method = $request->method();

            if (str_contains($url, '/cloud/apps/user_ldap')) {
                return Http::response(self::ocs(100));
            }

            if (preg_match('#/user_ldap/api/v1/config/(s\d+)#', $url, $matches) === 1) {
                $id = $matches[1];

                if ($method === 'PUT') {
                    return Http::response(self::ocs(200));
                }

                return isset($configs[$id])
                    ? Http::response(self::ocs(200, $configs[$id]))
                    : Http::response([
                        'ocs' => ['meta' => [
                            'status' => 'failure', 'statuscode' => 404, 'message' => 'Config ID not found',
                        ], 'data' => []],
                    ]);
            }

            if (str_contains($url, '/user_ldap/api/v1/config')) {
                return Http::response(self::ocs(200, ['configID' => 's0'.(count($configs) + 1)]));
            }

            if (preg_match('#/cloud/users/([^?]+)#', $url, $who) === 1) {
                $login = rawurldecode($who[1]);

                return $probeFound
                    ? Http::response(self::ocs(100, [
                        'id' => $login,
                        'backend' => $backendPerLogin[$login] ?? $backend,
                    ]))
                    : Http::response([
                        'ocs' => ['meta' => [
                            'status' => 'failure', 'statuscode' => 404, 'message' => 'User does not exist',
                        ], 'data' => []],
                    ]);
            }

            return Http::response(self::ocs(100));
        });
    }

    private function anAdUser(string $login = 'rael.azzzz'): void
    {
        User::factory()->create(['login' => $login, 'source' => 'ad', 'is_active' => true]);
    }

    // =========================================================================
    // Les refus — rien n'est écrit
    // =========================================================================

    /** Capacité éteinte : aucun appel n'est émis, et le refus nomme la cause. */
    #[Test]
    public function it_refuses_when_the_instance_capability_is_off(): void
    {
        $this->configureInstance(enabled: false);
        Http::fake();

        $this->artisan('nextcloud:configure-ldap')->assertExitCode(2);

        Http::assertNothingSent();
    }

    /**
     * NOTRE configuration d'abord : poser une synchro sans mot de passe de lecture
     * activerait une liaison morte que l'instance annoncerait « active ».
     */
    #[Test]
    public function it_refuses_when_our_own_directory_settings_are_incomplete(): void
    {
        $this->configureInstance();
        $this->bindLdap(password: '');
        Http::fake();

        $this->artisan('nextcloud:configure-ldap')
            ->expectsOutputToContain('ldap_admin_passwd')
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    /** La simulation n'émet RIEN — pas même l'activation de l'app. */
    #[Test]
    public function a_simulation_emits_no_call_at_all(): void
    {
        $this->configureInstance();
        Http::fake();

        $this->artisan('nextcloud:configure-ldap --dry-run')->assertExitCode(0);

        Http::assertNothingSent();
    }

    // =========================================================================
    // L'écriture
    // =========================================================================

    /** Instance vierge : on crée une configuration, puis on y écrit la carte. */
    #[Test]
    public function it_creates_a_configuration_when_the_instance_has_none(): void
    {
        $this->configureInstance();
        $this->anAdUser();
        $this->fakeInstance();

        $this->artisan('nextcloud:configure-ldap')->assertExitCode(0);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/cloud/apps/user_ldap'));

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT'
            && str_contains($r->url(), '/user_ldap/api/v1/config/s01')
            && ($r->data()['configData']['ldapExpertUsernameAttr'] ?? null) === 'sAMAccountName');
    }

    /** Le secret de lecture part dans l'écriture, et il n'est PAS affiché. */
    #[Test]
    public function the_reading_secret_is_written_but_never_displayed(): void
    {
        $this->configureInstance();
        $this->anAdUser();
        $this->fakeInstance();

        $this->artisan('nextcloud:configure-ldap')
            ->doesntExpectOutputToContain('S3cr3tDeLecture')
            ->assertExitCode(0);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT'
            && ($r->data()['configData']['ldapAgentPassword'] ?? null) === 'S3cr3tDeLecture');
    }

    /** Rejouée à l'identique : rien n'est écrit, et la commande le dit. */
    #[Test]
    public function replaying_on_a_conforming_instance_writes_nothing(): void
    {
        $this->configureInstance();
        $this->anAdUser();
        $this->fakeInstance(['s01' => self::conformingRemote()]);

        $this->artisan('nextcloud:configure-ldap')
            ->expectsOutputToContain('Déjà conforme')
            ->assertExitCode(0);

        Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'PUT');
    }

    /** Une configuration divergente n'est pas écrasée sans qu'on le demande. */
    #[Test]
    public function a_diverging_configuration_is_not_overwritten_without_force(): void
    {
        $this->configureInstance();
        $this->anAdUser();
        $remote = self::conformingRemote();
        $remote['ldapBaseUsers'] = 'dc=autre,dc=fr';
        $this->fakeInstance(['s01' => $remote]);

        $this->artisan('nextcloud:configure-ldap')
            ->expectsOutputToContain('--force')
            ->assertExitCode(2);

        Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'PUT');
    }

    /** Avec `--force`, on réécrit la configuration EXISTANTE — on n'en crée pas une seconde. */
    #[Test]
    public function force_rewrites_the_existing_configuration_instead_of_adding_one(): void
    {
        $this->configureInstance();
        $this->anAdUser();
        $remote = self::conformingRemote();
        $remote['ldapBaseUsers'] = 'dc=autre,dc=fr';
        $this->fakeInstance(['s01' => $remote]);

        $this->artisan('nextcloud:configure-ldap --force')->assertExitCode(0);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT'
            && str_contains($r->url(), '/config/s01'));

        Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST'
            && preg_match('#/user_ldap/api/v1/config\?#', $r->url()) === 1);
    }

    /** Le certificat invérifiable n'est accepté que si on le demande. */
    #[Test]
    public function the_certificate_is_only_trusted_on_demand(): void
    {
        $this->configureInstance();
        $this->anAdUser();
        $this->fakeInstance();

        $this->artisan('nextcloud:configure-ldap --trust-self-signed')->assertExitCode(0);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT'
            && ($r->data()['configData']['turnOffCertCheck'] ?? null) === '1');
    }

    // =========================================================================
    // La vérification
    // =========================================================================

    /**
     * L'ÉCRITURE QUI RÉUSSIT NE PROUVE RIEN : l'instance ne valide pas à
     * l'écriture, et une liaison refusée est silencieuse. Un compte introuvable
     * fait donc sortir en `1`, configuration posée ou pas.
     */
    #[Test]
    public function a_posed_configuration_that_sees_nobody_is_a_failure(): void
    {
        $this->configureInstance();
        $this->anAdUser();
        $this->fakeInstance(probeFound: false);

        $this->artisan('nextcloud:configure-ldap')
            ->expectsOutputToContain('NE SERT À RIEN')
            ->expectsOutputToContain('--trust-self-signed')
            ->assertExitCode(1);
    }

    /**
     * Le compte est là, mais il est LOCAL : la synchro n'y est pour rien, et
     * conclure qu'elle marche serait le pire des deux mondes.
     */
    #[Test]
    public function a_probe_answered_by_a_local_account_does_not_prove_the_link(): void
    {
        $this->configureInstance();
        $this->anAdUser();
        $this->fakeInstance(backend: 'Database');

        $this->artisan('nextcloud:configure-ldap')
            ->expectsOutputToContain('LIAISON NON PROUVÉE')
            ->assertExitCode(0);
    }

    /**
     * ET ON N'ABANDONNE PAS AU PREMIER : le compte d'annuaire qui vient en premier
     * porte souvent le nom d'un compte local de l'instance (mesuré : `admin`). On
     * continue jusqu'à en trouver un que l'annuaire sert vraiment.
     */
    #[Test]
    public function a_local_homonym_does_not_stop_the_verification(): void
    {
        $this->configureInstance();
        $this->anAdUser('admin');
        $this->anAdUser('rael.azzzz');
        $this->fakeInstance(backendPerLogin: ['admin' => 'Database', 'rael.azzzz' => 'LDAP']);

        $this->artisan('nextcloud:configure-ldap')
            ->expectsOutputToContain('connaît « rael.azzzz » par l\'annuaire')
            ->doesntExpectOutputToContain('LIAISON NON PROUVÉE')
            ->assertExitCode(0);
    }

    /** Aucun compte trouvé : la configuration est posée pour rien, et on sort en 1. */
    #[Test]
    public function the_failure_names_how_many_accounts_were_looked_for(): void
    {
        $this->configureInstance();
        $this->anAdUser('un.premier');
        $this->anAdUser('un.second');
        $this->fakeInstance(probeFound: false);

        $this->artisan('nextcloud:configure-ldap')
            ->expectsOutputToContain('aucun des 2 comptes cherchés')
            ->assertExitCode(1);
    }

    /** Sans personne à chercher, on ne prétend pas avoir vérifié. */
    #[Test]
    public function without_anyone_to_look_for_the_check_is_declared_not_done(): void
    {
        $this->configureInstance();
        $this->fakeInstance();

        $this->artisan('nextcloud:configure-ldap')
            ->expectsOutputToContain('Vérification non faite')
            ->assertExitCode(0);

        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '/cloud/users/'));
    }

    /** Le login désigné prime sur celui qu'on irait chercher en base. */
    #[Test]
    public function the_designated_probe_login_wins(): void
    {
        $this->configureInstance();
        $this->anAdUser('quelquun.dautre');
        $this->fakeInstance();

        $this->artisan('nextcloud:configure-ldap --probe=rael.azzzz')->assertExitCode(0);

        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), '/cloud/users/rael.azzzz'));
    }
}
