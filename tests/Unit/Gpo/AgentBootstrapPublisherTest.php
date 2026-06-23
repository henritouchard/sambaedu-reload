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
    public function it_resolves_flat_computers_ou_when_no_establishment_layer(): void
    {
        config([
            'sambaedu.admin_passwd' => 's3cr3t',
            'sambaedu.ldap_base_dn' => 'DC=localdev,DC=fr',
        ]);

        // Topologie plate : seule OU=computers,<base> existe.
        $publisher = $this->testablePublisher(
            establishmentCode: '0991229y',
            existingOus: ['OU=computers,DC=localdev,DC=fr'],
        );

        self::assertSame(
            'OU=computers,DC=localdev,DC=fr',
            $this->resolveOu($publisher),
        );
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
    // Helpers de test (réflexion sur les méthodes protected).
    // -----------------------------------------------------------------------

    private function testablePublisher(string $establishmentCode, array $existingOus): AgentBootstrapPublisher
    {
        $gpo = Mockery::mock(GpoService::class);

        return new class($gpo, new GpoTemplateRegistry(), $establishmentCode, $existingOus) extends AgentBootstrapPublisher {
            public function __construct(
                GpoService $gpo,
                GpoTemplateRegistry $registry,
                private readonly string $code,
                private readonly array $ous,
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
        };
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
