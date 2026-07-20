<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Services\GpoService;
use App\Gpo\Support\GpoTemplateRegistry;
use App\Services\Gpo\AgentBootstrapDeployResult;
use App\Services\Gpo\AgentBootstrapPublisher;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Story 27.16 — comportement de GARDE (fail-soft) du déployeur du bootstrap
 * agent {@see AgentBootstrapPublisher}.
 *
 * On valide UNIQUEMENT les chemins qui n'exigent ni DC réel ni Kerberos ni
 * SYSVOL (impossibles à exécuter sur l'hôte CI) :
 *  - creds Administrator absents → `skipped`, aucun appel destructeur ;
 *  - DC injoignable → `skipped`, aucun appel destructeur ;
 *  - re-exécution (idempotence) → reste `skipped` (pas d'exception, pas d'état).
 *
 * L'e2e réel (publication Administrator + lien + reboot poste) est une action
 * manuelle Henri (cf. story §Actions VM).
 */
final class AgentBootstrapPublisherTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_skips_without_failure_when_admin_password_is_absent(): void
    {
        config(['sambaedu.admin_passwd' => '']);

        // GpoService NE DOIT JAMAIS être touché si la garde creds court-circuite.
        $gpo = Mockery::mock(GpoService::class);
        $gpo->shouldNotReceive('list');
        $gpo->shouldNotReceive('setInheritance');
        $gpo->shouldNotReceive('setLink');

        $publisher = new AgentBootstrapPublisher($gpo, new GpoTemplateRegistry());

        $result = $publisher->deploy();

        self::assertTrue($result->isSkipped());
        self::assertSame(AgentBootstrapDeployResult::KIND_SKIPPED, $result->kind);
        self::assertStringContainsString('admin_passwd', $result->message);
    }

    #[Test]
    public function it_skips_without_failure_when_domain_controller_is_unreachable(): void
    {
        config(['sambaedu.admin_passwd' => 's3cr3t']);

        $gpo = Mockery::mock(GpoService::class);
        // DC injoignable : list() lève. La garde doit AVALER et skipper.
        $gpo->shouldReceive('list')->once()->andThrow(new RuntimeException('samba-tool unreachable'));
        // AUCUNE écriture destructrice ne doit suivre.
        $gpo->shouldNotReceive('setInheritance');
        $gpo->shouldNotReceive('setLink');

        $publisher = new AgentBootstrapPublisher($gpo, new GpoTemplateRegistry());

        $result = $publisher->deploy();

        self::assertTrue($result->isSkipped());
        self::assertStringContainsString('DC', $result->message);
        self::assertFalse($result->isFailed());
    }

    #[Test]
    public function it_is_idempotent_on_repeated_skip(): void
    {
        config(['sambaedu.admin_passwd' => '']);

        $gpo = Mockery::mock(GpoService::class);
        $gpo->shouldNotReceive('setInheritance');
        $gpo->shouldNotReceive('setLink');

        $publisher = new AgentBootstrapPublisher($gpo, new GpoTemplateRegistry());

        $first = $publisher->deploy();
        $second = $publisher->deploy();

        self::assertTrue($first->isSkipped());
        self::assertTrue($second->isSkipped());
    }

    #[Test]
    public function dry_run_does_not_perform_any_destructive_call_when_dc_unreachable(): void
    {
        config(['sambaedu.admin_passwd' => 's3cr3t']);

        $gpo = Mockery::mock(GpoService::class);
        // Même en dry-run, la garde DC s'applique d'abord (list() est lue).
        $gpo->shouldReceive('list')->andThrow(new RuntimeException('unreachable'));
        $gpo->shouldNotReceive('setInheritance');
        $gpo->shouldNotReceive('setLink');

        $publisher = new AgentBootstrapPublisher($gpo, new GpoTemplateRegistry());

        $result = $publisher->deploy(force: false, dryRun: true);

        // DC injoignable prime sur le dry-run → skip (jamais d'exception).
        self::assertTrue($result->isSkipped());
    }

    // -----------------------------------------------------------------------
    // Pb8 — résolution OU cible (2 topologies + cas « aucune OU »).
    // On mocke la détection LDAP (`ouExists`) et le code établissement pour
    // exercer la logique de sélection SANS DC réel.
    // -----------------------------------------------------------------------

    #[Test]
    public function it_resolves_establishment_layer_ou_when_present(): void
    {
        config([
            'sambaedu.admin_passwd' => 's3cr3t',
            'sambaedu.ldap_base_dn' => 'DC=lab1,DC=fr',
        ]);

        // Topologie couche-étab : OU=0991229y,OU=computers,<base> existe.
        $publisher = $this->testablePublisher(
            establishmentCode: '0991229y',
            existingOus: ['OU=0991229y,OU=computers,DC=lab1,DC=fr'],
        );

        self::assertSame(
            'OU=0991229y,OU=computers,DC=lab1,DC=fr',
            $this->resolveOu($publisher),
        );
    }

    #[Test]
    public function it_resolves_flat_computers_ou_only_outside_federation(): void
    {
        config([
            'sambaedu.admin_passwd' => 's3cr3t',
            'sambaedu.ldap_base_dn' => 'DC=localdev,DC=fr',
        ]);

        // Topologie plate SANS code établissement (/vm) : le conteneur plat EST
        // celui de l'instance, le résoudre est légitime.
        $publisher = $this->testablePublisher(
            establishmentCode: '',
            existingOus: ['OU=computers,DC=localdev,DC=fr'],
        );

        self::assertSame(
            'OU=computers,DC=localdev,DC=fr',
            $this->resolveOu($publisher),
        );
    }

    #[Test]
    public function it_never_falls_back_to_the_shared_computers_container_when_federated(): void
    {
        config([
            'sambaedu.admin_passwd' => 's3cr3t',
            'sambaedu.ldap_base_dn' => 'DC=lab1,DC=fr',
        ]);

        // Établissement identifié, mais seule l'OU PARTAGÉE existe. Y retomber
        // bloquerait l'héritage ET lierait SE_agent_bootstrap pour les ~75
        // collèges : on préfère ne pas lier du tout.
        $publisher = $this->testablePublisher(
            establishmentCode: '0991229y',
            existingOus: ['OU=computers,DC=lab1,DC=fr'],
        );

        self::assertNull(
            $this->resolveOu($publisher),
            'Un établissement identifié ne doit JAMAIS retomber sur l\'OU computers du domaine.',
        );
    }

    #[Test]
    public function a_federated_instance_publishes_without_link_rather_than_touching_the_shared_ou(): void
    {
        // Même garde, vue depuis le déploiement : le résultat doit être un
        // published_without_link explicite, et AUCUNE écriture sur l'OU partagée.
        $baseDn = 'DC=lab1,DC=fr';
        $sharedComputersDn = 'OU=computers,DC=lab1,DC=fr';
        $guid = '{11111111-2222-3333-4444-555555555555}';
        config(['sambaedu.ldap_base_dn' => $baseDn]);

        $gpo = Mockery::mock(GpoService::class);
        $gpo->shouldReceive('removeLink')->once()->with($baseDn, $guid)->andReturn(true);
        $gpo->shouldNotReceive('setInheritance');
        $gpo->shouldNotReceive('setLink');

        $publisher = $this->testablePublisher('0991229y', [$sharedComputersDn], $gpo);

        $result = $this->invokeIsolate($publisher, $guid, $this->resolveOu($publisher));

        self::assertSame(AgentBootstrapDeployResult::KIND_PUBLISHED_WITHOUT_LINK, $result->kind);
    }

    #[Test]
    public function it_returns_null_when_no_candidate_ou_exists(): void
    {
        config([
            'sambaedu.admin_passwd' => 's3cr3t',
            'sambaedu.ldap_base_dn' => 'DC=localdev,DC=fr',
        ]);

        // Aucune OU n'existe → null (fail-soft, publication sans lien).
        $publisher = $this->testablePublisher(
            establishmentCode: '0991229y',
            existingOus: [],
        );

        self::assertNull($this->resolveOu($publisher));
    }

    // -----------------------------------------------------------------------
    // Pb1/Pb8 — anti-lien-racine. Le flux d'isolation DOIT retirer le lien
    // racine (removeLink sur le base_dn) ET poser le lien sur l'OU étab,
    // JAMAIS de setLink sur la racine.
    // -----------------------------------------------------------------------

    #[Test]
    public function isolation_removes_root_link_and_links_establishment_ou_never_root(): void
    {
        $baseDn = 'DC=lab1,DC=fr';
        $ouDn = 'OU=0991229y,OU=computers,DC=lab1,DC=fr';
        $guid = '{11111111-2222-3333-4444-555555555555}';
        config(['sambaedu.ldap_base_dn' => $baseDn]);

        $gpo = Mockery::mock(GpoService::class);
        // (1) lien racine neutralisé inconditionnellement.
        $gpo->shouldReceive('removeLink')->once()->with($baseDn, $guid)->andReturn(true);
        // (2) héritage bloqué sur l'OU étab.
        $gpo->shouldReceive('setInheritance')->once()->with($ouDn, false)->andReturn(true);
        // (3) lien posé SUR L'OU ÉTAB.
        $gpo->shouldReceive('setLink')->once()->with($ouDn, $guid)->andReturn(true);
        // JAMAIS de lien sur la racine.
        $gpo->shouldNotReceive('setLink')->with($baseDn, Mockery::any());

        $publisher = new AgentBootstrapPublisher($gpo, new GpoTemplateRegistry());

        $result = $this->invokeIsolate($publisher, $guid, $ouDn);

        self::assertSame(AgentBootstrapDeployResult::KIND_DEPLOYED, $result->kind);
        self::assertSame($ouDn, $result->targetOuDn);
    }

    #[Test]
    public function isolation_still_removes_root_link_when_no_target_ou(): void
    {
        $baseDn = 'DC=lab1,DC=fr';
        $guid = '{11111111-2222-3333-4444-555555555555}';
        config(['sambaedu.ldap_base_dn' => $baseDn]);

        $gpo = Mockery::mock(GpoService::class);
        // Même sans OU cible, le lien racine est neutralisé (jamais de fédération-wide).
        $gpo->shouldReceive('removeLink')->once()->with($baseDn, $guid)->andReturn(true);
        $gpo->shouldNotReceive('setInheritance');
        $gpo->shouldNotReceive('setLink');

        $publisher = new AgentBootstrapPublisher($gpo, new GpoTemplateRegistry());

        $result = $this->invokeIsolate($publisher, $guid, null);

        self::assertSame(AgentBootstrapDeployResult::KIND_PUBLISHED_WITHOUT_LINK, $result->kind);
    }

    #[Test]
    public function isolation_root_link_removal_failure_is_non_blocking(): void
    {
        $baseDn = 'DC=lab1,DC=fr';
        $ouDn = 'OU=computers,DC=lab1,DC=fr';
        $guid = '{11111111-2222-3333-4444-555555555555}';
        config(['sambaedu.ldap_base_dn' => $baseDn]);

        $gpo = Mockery::mock(GpoService::class);
        // removeLink racine lève (« pas de lien ») → toléré, n'interrompt pas.
        $gpo->shouldReceive('removeLink')->once()->with($baseDn, $guid)
            ->andThrow(new RuntimeException('no such link'));
        $gpo->shouldReceive('setInheritance')->once()->with($ouDn, false)->andReturn(true);
        $gpo->shouldReceive('setLink')->once()->with($ouDn, $guid)->andReturn(true);

        $publisher = new AgentBootstrapPublisher($gpo, new GpoTemplateRegistry());

        $result = $this->invokeIsolate($publisher, $guid, $ouDn);

        // L'échec de removeLink racine ne fait PAS échouer le déploiement.
        self::assertSame(AgentBootstrapDeployResult::KIND_DEPLOYED, $result->kind);
    }

    // -----------------------------------------------------------------------
    // Blocage d'héritage sur l'OU des COMPTES (moitié UTILISATEUR des GPO).
    //
    // Les deux moitiés d'une GPO héritent par des chemins distincts : bloquer
    // l'OU des postes ne neutralisait QUE la moitié machine, laissant passer
    // lecteurs réseau, redirections, imprimantes et scripts de logon — en
    // concurrence directe avec les capacités natives de l'agent.
    // -----------------------------------------------------------------------

    #[Test]
    public function it_resolves_establishment_layer_users_ou_when_present(): void
    {
        config(['sambaedu.ldap_base_dn' => 'DC=lab1,DC=fr']);

        $publisher = $this->testablePublisher('0991229y', [
            'OU=0991229y,ou=Utilisateurs,DC=lab1,DC=fr',
            'ou=Utilisateurs,DC=lab1,DC=fr',
        ]);

        // La couche établissement PRIME : on neutralise chez nous, jamais au-dessus.
        self::assertSame('OU=0991229y,ou=Utilisateurs,DC=lab1,DC=fr', $this->resolveUsersOu($publisher));
    }

    #[Test]
    public function it_resolves_flat_users_ou_when_no_establishment_layer(): void
    {
        config(['sambaedu.ldap_base_dn' => 'dc=localdev,dc=fr']);

        // Topologie plate (/vm) : etabCode '0' → pas de couche établissement.
        $publisher = $this->testablePublisher('', ['ou=Utilisateurs,dc=localdev,dc=fr']);

        self::assertSame('ou=Utilisateurs,dc=localdev,dc=fr', $this->resolveUsersOu($publisher));
    }

    #[Test]
    public function it_returns_null_when_no_users_ou_candidate_exists(): void
    {
        config(['sambaedu.ldap_base_dn' => 'DC=lab1,DC=fr']);

        $publisher = $this->testablePublisher('0991229y', []);

        self::assertNull($this->resolveUsersOu($publisher));
    }

    #[Test]
    public function it_never_falls_back_to_the_shared_users_container_when_federated(): void
    {
        // LE test de sûreté. AD mutualisé ~75 collèges : si l'OU comptes de NOTRE
        // établissement est absente, le conteneur partagé `ou=Utilisateurs,<base>`
        // existe quand même — y bloquer l'héritage couperait les stratégies
        // utilisateur de TOUS les collèges. On préfère ne rien bloquer.
        config(['sambaedu.ldap_base_dn' => 'DC=lab1,DC=fr']);

        $publisher = $this->testablePublisher('0991229y', [
            // Seul le conteneur PARTAGÉ existe — pas la couche établissement.
            'ou=Utilisateurs,DC=lab1,DC=fr',
        ]);

        self::assertNull(
            $this->resolveUsersOu($publisher),
            'Un établissement identifié ne doit JAMAIS retomber sur le conteneur des comptes du domaine.',
        );
    }

    #[Test]
    public function isolation_blocks_nothing_when_only_the_shared_users_container_exists(): void
    {
        // Même garde, vue depuis la séquence d'isolation complète : aucun
        // setInheritance ne doit partir vers le conteneur partagé.
        $baseDn = 'DC=lab1,DC=fr';
        $ouDn = 'OU=0991229y,OU=computers,DC=lab1,DC=fr';
        $sharedUsersDn = 'ou=Utilisateurs,DC=lab1,DC=fr';
        $guid = '{11111111-2222-3333-4444-555555555555}';
        config(['sambaedu.ldap_base_dn' => $baseDn]);

        $gpo = Mockery::mock(GpoService::class);
        $gpo->shouldReceive('removeLink')->once()->with($baseDn, $guid)->andReturn(true);
        $gpo->shouldReceive('setInheritance')->once()->with($ouDn, false)->andReturn(true);
        $gpo->shouldReceive('setLink')->once()->with($ouDn, $guid)->andReturn(true);
        $gpo->shouldNotReceive('setInheritance')->with($sharedUsersDn, Mockery::any());

        $publisher = $this->testablePublisher('0991229y', [$ouDn, $sharedUsersDn], $gpo);

        $result = $this->invokeIsolate($publisher, $guid, $ouDn);

        self::assertSame(AgentBootstrapDeployResult::KIND_DEPLOYED, $result->kind);
    }

    #[Test]
    public function it_returns_null_when_people_rdn_is_unavailable(): void
    {
        config(['sambaedu.ldap_base_dn' => 'DC=lab1,DC=fr']);

        $publisher = $this->testablePublisher('0991229y', ['ou=Utilisateurs,DC=lab1,DC=fr'], peopleRdn: '');

        self::assertNull($this->resolveUsersOu($publisher));
    }

    #[Test]
    public function isolation_blocks_inheritance_on_both_computers_and_users_ous(): void
    {
        $baseDn = 'DC=lab1,DC=fr';
        $ouDn = 'OU=0991229y,OU=computers,DC=lab1,DC=fr';
        $usersOuDn = 'OU=0991229y,ou=Utilisateurs,DC=lab1,DC=fr';
        $guid = '{11111111-2222-3333-4444-555555555555}';
        config(['sambaedu.ldap_base_dn' => $baseDn]);

        $gpo = Mockery::mock(GpoService::class);
        $gpo->shouldReceive('removeLink')->once()->with($baseDn, $guid)->andReturn(true);
        $gpo->shouldReceive('setInheritance')->once()->with($ouDn, false)->andReturn(true);
        // Le blocage symétrique côté comptes — c'est lui qui éteint les
        // stratégies utilisateur des GPO de domaine.
        $gpo->shouldReceive('setInheritance')->once()->with($usersOuDn, false)->andReturn(true);
        $gpo->shouldReceive('setLink')->once()->with($ouDn, $guid)->andReturn(true);
        // JAMAIS de lien sur l'OU des comptes : on bloque, on ne lie pas.
        $gpo->shouldNotReceive('setLink')->with($usersOuDn, Mockery::any());

        $publisher = $this->testablePublisher('0991229y', [$ouDn, $usersOuDn], $gpo);

        $result = $this->invokeIsolate($publisher, $guid, $ouDn);

        self::assertSame(AgentBootstrapDeployResult::KIND_DEPLOYED, $result->kind);
    }

    #[Test]
    public function isolation_survives_a_users_ou_block_failure(): void
    {
        $baseDn = 'DC=lab1,DC=fr';
        $ouDn = 'OU=computers,DC=lab1,DC=fr';
        $usersOuDn = 'ou=Utilisateurs,DC=lab1,DC=fr';
        $guid = '{11111111-2222-3333-4444-555555555555}';
        config(['sambaedu.ldap_base_dn' => $baseDn]);

        $gpo = Mockery::mock(GpoService::class);
        $gpo->shouldReceive('removeLink')->once()->with($baseDn, $guid)->andReturn(true);
        $gpo->shouldReceive('setInheritance')->once()->with($ouDn, false)->andReturn(true);
        // Droits refusés sur l'OU des comptes → journalisé, PAS propagé :
        // l'amorçage de l'agent reste l'objectif critique.
        $gpo->shouldReceive('setInheritance')->once()->with($usersOuDn, false)
            ->andThrow(new RuntimeException('insufficient access rights'));
        $gpo->shouldReceive('setLink')->once()->with($ouDn, $guid)->andReturn(true);

        $publisher = $this->testablePublisher('', [$ouDn, $usersOuDn], $gpo);

        $result = $this->invokeIsolate($publisher, $guid, $ouDn);

        self::assertSame(AgentBootstrapDeployResult::KIND_DEPLOYED, $result->kind);
    }

    #[Test]
    public function isolation_skips_users_block_when_no_users_ou_exists(): void
    {
        $baseDn = 'DC=lab1,DC=fr';
        $ouDn = 'OU=computers,DC=lab1,DC=fr';
        $guid = '{11111111-2222-3333-4444-555555555555}';
        config(['sambaedu.ldap_base_dn' => $baseDn]);

        $gpo = Mockery::mock(GpoService::class);
        $gpo->shouldReceive('removeLink')->once()->with($baseDn, $guid)->andReturn(true);
        $gpo->shouldReceive('setInheritance')->once()->with($ouDn, false)->andReturn(true);
        $gpo->shouldReceive('setLink')->once()->with($ouDn, $guid)->andReturn(true);
        // Aucune OU comptes → aucun appel supplémentaire (pas de DN deviné).
        $gpo->shouldNotReceive('setInheritance')->with(Mockery::not($ouDn), Mockery::any());

        $publisher = $this->testablePublisher('', [$ouDn], $gpo);

        $result = $this->invokeIsolate($publisher, $guid, $ouDn);

        self::assertSame(AgentBootstrapDeployResult::KIND_DEPLOYED, $result->kind);
    }

    // -----------------------------------------------------------------------
    // Helpers de test (réflexion sur les méthodes protected).
    // -----------------------------------------------------------------------

    private function testablePublisher(string $establishmentCode, array $existingOus, ?GpoService $gpo = null, string $peopleRdn = 'ou=Utilisateurs'): AgentBootstrapPublisher
    {
        $gpo ??= Mockery::mock(GpoService::class);

        return new class($gpo, new GpoTemplateRegistry(), $establishmentCode, $existingOus, $peopleRdn) extends AgentBootstrapPublisher {
            public function __construct(
                GpoService $gpo,
                GpoTemplateRegistry $registry,
                private readonly string $code,
                private readonly array $ous,
                private readonly string $rdn,
            ) {
                parent::__construct($gpo, $registry);
            }

            protected function establishmentCode(): string
            {
                return $this->code;
            }

            protected function ouExists(string $dn): bool
            {
                return in_array($dn, $this->ous, true);
            }

            protected function peopleRdn(): string
            {
                return $this->rdn;
            }
        };
    }

    private function resolveUsersOu(AgentBootstrapPublisher $publisher): ?string
    {
        $log = \App\Gpo\Support\GpoLogger::action('gpo.create');
        $m = new \ReflectionMethod($publisher, 'resolveTargetUsersOuDn');
        $m->setAccessible(true);

        return $m->invoke($publisher, $log);
    }

    private function resolveOu(AgentBootstrapPublisher $publisher): ?string
    {
        $log = \App\Gpo\Support\GpoLogger::action('gpo.create');
        $m = new \ReflectionMethod($publisher, 'resolveTargetOuDn');
        $m->setAccessible(true);

        return $m->invoke($publisher, $log);
    }

    private function invokeIsolate(AgentBootstrapPublisher $publisher, string $guid, ?string $ouDn): AgentBootstrapDeployResult
    {
        $log = \App\Gpo\Support\GpoLogger::action('gpo.create');
        $m = new \ReflectionMethod($publisher, 'isolateToEstablishmentOu');
        $m->setAccessible(true);

        return $m->invoke($publisher, $guid, $ouDn, $log->operationId(), $log);
    }
}
