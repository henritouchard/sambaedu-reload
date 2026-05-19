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

/**
 * Story 3.1 — AC7.3 / T4.3.
 *
 * Tests dédiés au logging : vérifie que les events `ipxe.boot.*` émis par
 * `IpxeService` **ne contiennent jamais de MAC/UUID en clair** — seulement
 * des préfixes (6 chars pour MAC, 8 chars pour UUID/product).
 */
class IpxeServiceLoggingTest extends TestCase
{
    /** @var array<int, array{action:string, context:array<string,mixed>}> */
    private array $capturedLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
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
