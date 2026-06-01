<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\IpxeService;
use App\Models\Workstation;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;
use Tests\Support\IpxeAuthTestHelper;

/**
 * Story 3.1 — AC7.3 / T4.3.
 *
 * Tests dédiés au logging : vérifie que les events `ipxe.boot.*` émis par
 * `IpxeService` **ne contiennent jamais de MAC/UUID en clair** — seulement
 * des préfixes (6 chars pour MAC, 8 chars pour UUID/product).
 */
class IpxeServiceLoggingTest extends TestCase
{
    use IpxeAuthTestHelper;

    /** @var array<int, array{action:string, context:array<string,mixed>}> */
    private array $capturedLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bypassIpxeAuth();
        IpxeSchemaBootstrapper::bootstrap();
        $this->capturedLogs = [];

        // Intercepte Log::channel('ipxe')->info(...) en remplaçant l'instance
        // du channel par un mock qui capture les appels.
        $channelLogger = Mockery::mock(LoggerInterface::class);
        $channelLogger->shouldReceive('info')
            ->andReturnUsing(function (string $message, array $context = []): void {
                $this->capturedLogs[] = ['action' => $message, 'context' => $context];
            });
        $channelLogger->shouldReceive('warning')
            ->andReturnUsing(function (string $message, array $context = []): void {
                $this->capturedLogs[] = ['action' => $message, 'context' => $context];
            });
        $channelLogger->shouldReceive('error')->andReturnNull();
        $channelLogger->shouldReceive('debug')->andReturnNull();

        /** @var LogManager&MockInterface $logManager */
        $logManager = Mockery::mock(LogManager::class)->makePartial();
        $logManager->shouldReceive('channel')
            ->with('ipxe')
            ->andReturn($channelLogger);
        // Pour les autres channels, on retombe sur la valeur par défaut
        // (les tests SQLite ne logguent rien d'utile ailleurs).
        $logManager->shouldReceive('channel')->andReturn($channelLogger);

        Log::swap($logManager);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeRequest(array $params = []): Request
    {
        $request = Request::create('/ipxe/boot', 'POST', $params);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');

        return $request;
    }

    #[Test]
    public function it_logs_handshake_event_with_ip_only(): void
    {
        $service = $this->app->make(IpxeService::class);
        $service->handleBoot($this->makeRequest());

        $handshakeLog = $this->findLog('ipxe.boot.handshake');
        self::assertNotNull($handshakeLog, 'Aucun log ipxe.boot.handshake émis');
        self::assertSame('192.168.1.42', $handshakeLog['context']['ip']);
        // Pas de mac/uuid (handshake = pas encore de params posés).
        self::assertArrayNotHasKey('mac', $handshakeLog['context']);
        self::assertArrayNotHasKey('uuid', $handshakeLog['context']);
    }

    #[Test]
    public function it_does_not_log_full_mac_or_uuid_on_known_workstation(): void
    {
        Workstation::create([
            'name' => 'PC-SALLE-101',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $service = $this->app->make(IpxeService::class);
        $service->handleBoot($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'product' => 'OptiPlex 3050',
        ]));

        $log = $this->findLog('ipxe.boot.known_workstation');
        self::assertNotNull($log, 'Aucun log ipxe.boot.known_workstation émis');

        // ── Vérifications de troncature ─────────────────────────────────
        // mac_prefix doit faire au plus 6 chars (xx:xx:).
        self::assertArrayHasKey('mac_prefix', $log['context']);
        self::assertLessThanOrEqual(6, strlen((string) $log['context']['mac_prefix']));

        // uuid_prefix doit faire au plus 8 chars.
        self::assertArrayHasKey('uuid_prefix', $log['context']);
        self::assertLessThanOrEqual(8, strlen((string) $log['context']['uuid_prefix']));

        // product_prefix doit faire au plus 8 chars.
        self::assertArrayHasKey('product_prefix', $log['context']);
        self::assertLessThanOrEqual(8, strlen((string) $log['context']['product_prefix']));

        // ── Vérifications anti-fuite ─────────────────────────────────────
        // La MAC complète NE DOIT JAMAIS apparaître en clair dans le context
        // (toutes valeurs concaténées).
        $allValues = json_encode($log['context'], JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(
            'aa:bb:cc:dd:ee:ff',
            (string) $allValues,
            'MAC complète détectée dans le context du log — fuite PII matériel',
        );
        // L'UUID complet NE DOIT JAMAIS apparaître en clair.
        self::assertStringNotContainsString(
            '12345678-1234-1234-1234-123456789abc',
            (string) $allValues,
            'UUID complet détecté dans le context du log — fuite PII matériel',
        );
    }

    #[Test]
    public function it_does_not_log_full_mac_or_uuid_on_unknown_workstation(): void
    {
        $service = $this->app->make(IpxeService::class);
        $service->handleBoot($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '99999999-9999-9999-9999-999999999999',
            'product' => 'SomeRareModelNameThatIsLong',
        ]));

        $log = $this->findLog('ipxe.boot.unknown_workstation');
        self::assertNotNull($log);

        $allValues = json_encode($log['context'], JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('aa:bb:cc:dd:ee:ff', (string) $allValues);
        self::assertStringNotContainsString(
            '99999999-9999-9999-9999-999999999999',
            (string) $allValues,
        );
        self::assertStringNotContainsString(
            'SomeRareModelNameThatIsLong',
            (string) $allValues,
            'Product complet détecté — devrait être tronqué à 8 chars',
        );
    }

    /* ------------------------------------------------------------------
     * Story 3.2 — AC7.1 / T4.7 — events admin / maintenance / action
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_logs_admin_menu_rendered_with_correct_prefixes(): void
    {
        Workstation::create([
            'name' => 'PC-LOG-ADMIN',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);

        $service = $this->app->make(IpxeService::class);
        $request = Request::create('/ipxe/admin', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:01',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
        ]);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');
        $service->handleAdmin($request);

        $log = $this->findLog('ipxe.admin.menu_rendered');
        self::assertNotNull($log, 'Aucun log ipxe.admin.menu_rendered émis');
        self::assertSame('known', $log['context']['menu_variant']);
        self::assertLessThanOrEqual(6, strlen((string) $log['context']['mac_prefix']));
        self::assertLessThanOrEqual(8, strlen((string) $log['context']['uuid_prefix']));
        self::assertLessThanOrEqual(6, strlen((string) $log['context']['workstation_name_prefix']));
    }

    #[Test]
    public function it_logs_maintenance_menu_rendered(): void
    {
        $service = $this->app->make(IpxeService::class);
        $request = Request::create('/ipxe/maintenance', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:02',
            'uuid' => '22222222-2222-2222-2222-222222222222',
        ]);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');
        $service->handleMaintenance($request);

        $log = $this->findLog('ipxe.maintenance.menu_rendered');
        self::assertNotNull($log);
        self::assertSame('unknown', $log['context']['menu_variant']);
    }

    #[Test]
    public function it_logs_action_dispatched_with_action_value(): void
    {
        $service = $this->app->make(IpxeService::class);
        $request = Request::create('/ipxe/action/rescuecd', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:03',
            'uuid' => '33333333-3333-3333-3333-333333333333',
        ]);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');
        $service->handleAction($request, 'rescuecd');

        $log = $this->findLog('ipxe.action.dispatched');
        self::assertNotNull($log);
        self::assertSame('rescuecd', $log['context']['action']);
    }

    #[Test]
    public function it_logs_unknown_action_warning_with_sanitized_action(): void
    {
        $service = $this->app->make(IpxeService::class);
        $request = Request::create('/ipxe/action/foo', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:04',
            'uuid' => '44444444-4444-4444-4444-444444444444',
        ]);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');

        try {
            $service->handleAction($request, "weird\xc3\xa9-action-name-with-tons-of-trailing-text-that-is-very-very-long");
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            // expected
        }

        $log = $this->findLog('ipxe.action.unknown_action');
        self::assertNotNull($log);
        // Tronqué à 32 chars.
        self::assertLessThanOrEqual(32, strlen((string) $log['context']['action_requested']));
        // Pas d'UTF-8 (`é` remplacé par `?`).
        self::assertStringNotContainsString("\xc3\xa9", (string) $log['context']['action_requested']);
    }

    /* ------------------------------------------------------------------
     * Story 3.2 — Correctif review #3 / Q1 Henri (warning factory_reset)
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_logs_warning_when_factory_reset_dispatched(): void
    {
        // Fix review #3 / Q1 Henri — l'action `factory_reset` écrase sda1
        // sans confirmation. Un event warning dédié facilite l'alerte SIEM.
        $service = $this->app->make(IpxeService::class);
        $request = Request::create('/ipxe/action/factory_reset', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:fa',
            'uuid' => 'fafafafa-fafa-fafa-fafa-fafafafafafa',
        ]);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');
        $service->handleAction($request, 'factory_reset');

        $log = $this->findLog('ipxe.action.factory_reset_dispatched');
        self::assertNotNull(
            $log,
            'Aucun log warning ipxe.action.factory_reset_dispatched émis lors du factory_reset',
        );
        // Préfixes PII tronqués (6 chars MAC, 8 chars UUID iso AC7.3).
        self::assertLessThanOrEqual(6, strlen((string) $log['context']['mac_prefix']));
        self::assertLessThanOrEqual(8, strlen((string) $log['context']['uuid_prefix']));
        self::assertSame('192.168.1.42', $log['context']['ip']);
    }

    #[Test]
    public function it_does_not_emit_factory_reset_warning_for_non_destructive_actions(): void
    {
        // Non-régression : rescuecd/winpe ne déclenchent pas le warning.
        $service = $this->app->make(IpxeService::class);
        $request = Request::create('/ipxe/action/rescuecd', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:fb',
            'uuid' => 'fbfbfbfb-fbfb-fbfb-fbfb-fbfbfbfbfbfb',
        ]);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');
        $service->handleAction($request, 'rescuecd');

        self::assertNull(
            $this->findLog('ipxe.action.factory_reset_dispatched'),
            'Le warning factory_reset_dispatched ne doit être émis QUE sur factory_reset',
        );
    }

    #[Test]
    public function it_does_not_leak_full_mac_uuid_product_in_admin_event(): void
    {
        Workstation::create([
            'name' => 'PC-LOG-NOLEAK',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:05',
            'status' => 'active',
        ]);

        $service = $this->app->make(IpxeService::class);
        $request = Request::create('/ipxe/admin', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:05',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
            'product' => 'OptiPlex 3050',
        ]);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');
        $service->handleAdmin($request);

        $log = $this->findLog('ipxe.admin.menu_rendered');
        self::assertNotNull($log);
        $serialized = (string) json_encode($log['context'], JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('aa:bb:cc:dd:ee:05', $serialized);
        self::assertStringNotContainsString('12345678-1234-1234-1234-bbbbbbbbbbbb', $serialized);
    }

    /**
     * @return array{action:string, context:array<string,mixed>}|null
     */
    private function findLog(string $actionType): ?array
    {
        foreach ($this->capturedLogs as $entry) {
            if ($entry['action'] === $actionType) {
                return $entry;
            }
        }

        return null;
    }
}
