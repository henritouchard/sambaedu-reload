<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Http\Middleware;

use App\Auth\V1\Http\Middleware\InjectBootstrapFragment;
use App\Auth\V1\Models\WorkstationMigrationStatus;
use App\Services\AppCustomization\Contracts\AppContextWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.11 — AC5.1.
 *
 * Tests `InjectBootstrapFragment` — middleware post-response qui préfixe un
 * fragment de bootstrap dans les réponses legacy si poste non migré.
 */
class InjectBootstrapFragmentTest extends TestCase
{
    use IssuesWorkstationJwt;

    private const TEST_UUID = '11111111-1111-4111-8111-111111111111';

    /** @var array<string, array{context: array<string,mixed>, ttl: int}> */
    private array $contextStore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
        InjectBootstrapFragment::clearFragmentCache();
        $this->contextStore = [];
        config([
            'sambaedu.se4fs_name' => 'se4fs-test001',
            'sambaedu.domain' => 'lab.local',
            'auth_v1.server.host_suffix' => 'lab.local',
        ]);
    }

    /**
     * Crée une instance du middleware avec un writer in-memory (capture
     * les `apps.<token>` posés par le middleware — Story 16.11 Q1.b).
     */
    private function makeMiddleware(): InjectBootstrapFragment
    {
        $writer = new class($this->contextStore) implements AppContextWriter {
            /** @param array<string, array{context: array<string,mixed>, ttl: int}> $store */
            public function __construct(private array &$store) {}

            public function write(string $id, array $context, int $ttl = 1800): void
            {
                $this->store[$id] = ['context' => $context, 'ttl' => $ttl];
            }

            public function forget(string $id): void
            {
                unset($this->store[$id]);
            }
        };

        return new InjectBootstrapFragment($writer);
    }

    private function makeRequest(string $os = 'windows', ?string $uuid = self::TEST_UUID): Request
    {
        $params = ['os' => $os];
        if ($uuid !== null) {
            $params['uuid'] = $uuid;
        }

        return Request::create('/gpo/wallpaper_out.php', 'GET', $params);
    }

    private function passNext(int $status = 200, string $body = 'legacy-body', string $ct = 'text/plain; charset=utf-8'): \Closure
    {
        return function () use ($status, $body, $ct) {
            $resp = new Response($body, $status);
            $resp->headers->set('Content-Type', $ct);

            return $resp;
        };
    }

    #[Test]
    public function skips_if_content_type_is_json(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest(),
            $this->passNext(200, '{"foo":"bar"}', 'text/json'),
        );

        $this->assertSame('{"foo":"bar"}', $res->getContent());
    }

    #[Test]
    public function skips_if_status_is_4xx(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest(),
            $this->passNext(400, 'bad request body'),
        );
        $this->assertSame('bad request body', $res->getContent());
    }

    #[Test]
    public function skips_if_status_is_5xx(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest(),
            $this->passNext(500, 'server error'),
        );
        $this->assertSame('server error', $res->getContent());
    }

    #[Test]
    public function skips_if_no_uuid_in_request(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('windows', null),
            $this->passNext(),
        );
        $this->assertSame('legacy-body', $res->getContent());
    }

    #[Test]
    public function skips_if_uuid_format_invalid(): void
    {
        $req = Request::create('/gpo/wallpaper_out.php', 'GET', ['uuid' => 'not-a-uuid', 'os' => 'linux']);
        $res = $this->makeMiddleware()->handle($req, $this->passNext());
        $this->assertSame('legacy-body', $res->getContent());
    }

    #[Test]
    public function skips_if_workstation_already_migrated(): void
    {
        WorkstationMigrationStatus::factory()->forUuid(self::TEST_UUID)->create();

        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('windows'),
            $this->passNext(),
        );
        $this->assertSame('legacy-body', $res->getContent());
    }

    #[Test]
    public function injects_windows_fragment_for_windows_request(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('windows'),
            $this->passNext(),
        );

        $body = (string) $res->getContent();
        $this->assertStringContainsString('@echo off', $body);
        $this->assertStringContainsString('SambaEdu auto-bootstrap', $body);
        $this->assertStringEndsWith('legacy-body', $body);
    }

    #[Test]
    public function injects_linux_fragment_for_linux_request(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('linux'),
            $this->passNext(),
        );

        $body = (string) $res->getContent();
        $this->assertStringContainsString('/var/lib/sambaedu/auth.json', $body);
        $this->assertStringContainsString('curl -kfsS', $body);
        $this->assertStringEndsWith('legacy-body', $body);
    }

    #[Test]
    public function substitutes_server_base_url_in_fragment(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('windows'),
            $this->passNext(),
        );

        $body = (string) $res->getContent();
        $this->assertStringContainsString('https://se4fs-test001.lab.local', $body);
    }

    #[Test]
    public function ua_fallback_detects_linux(): void
    {
        // Pas de query ?os= → fallback User-Agent.
        InjectBootstrapFragment::clearFragmentCache();
        $req = Request::create('/gpo/wallpaper_out.php', 'GET', ['uuid' => self::TEST_UUID]);
        $req->headers->set('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64)');

        $res = $this->makeMiddleware()->handle($req, $this->passNext());

        $body = (string) $res->getContent();
        $this->assertStringContainsString('/var/lib/sambaedu/auth.json', $body);
    }

    #[Test]
    public function ua_fallback_detects_windows(): void
    {
        InjectBootstrapFragment::clearFragmentCache();
        $req = Request::create('/gpo/wallpaper_out.php', 'GET', ['uuid' => self::TEST_UUID]);
        $req->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');

        $res = $this->makeMiddleware()->handle($req, $this->passNext());

        $body = (string) $res->getContent();
        $this->assertStringContainsString('@echo off', $body);
    }

    #[Test]
    public function streamed_response_is_skipped(): void
    {
        $req = $this->makeRequest('windows');
        $streamed = new StreamedResponse(function (): void {
            echo 'streamed';
        });
        $streamed->headers->set('Content-Type', 'text/plain');

        $res = $this->makeMiddleware()->handle($req, fn () => $streamed);

        $this->assertSame($streamed, $res); // pas modifié
    }

    #[Test]
    public function does_not_alter_legacy_body_suffix(): void
    {
        // Garantit que `substr($after, -strlen($legacy)) === $legacy`.
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('windows'),
            $this->passNext(200, "#!/bin/bash\nsome legacy content\nexit 0"),
        );
        $body = (string) $res->getContent();
        $this->assertStringEndsWith("#!/bin/bash\nsome legacy content\nexit 0", $body);
    }

    // ====================================================================
    // Q1.b — BOOTSTRAP_TOKEN minted + posé en APCu + injecté dans fragment
    // ====================================================================

    #[Test]
    public function it_mints_a_bootstrap_token_and_injects_it_in_windows_fragment(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('windows'),
            $this->passNext(),
        );

        $body = (string) $res->getContent();
        // 1 contexte écrit avec uuid matching
        $this->assertCount(1, $this->contextStore);
        $token = array_key_first($this->contextStore);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $token);
        $this->assertSame(self::TEST_UUID, $this->contextStore[$token]['context']['uuid']);
        $this->assertSame(1800, $this->contextStore[$token]['ttl']);
        $this->assertSame('inject.bootstrap-fragment', $this->contextStore[$token]['context']['source']);

        // Token injecté dans le fragment (set BOOTSTRAP_TOKEN=<md5>).
        $this->assertStringContainsString('set "BOOTSTRAP_TOKEN=' . $token . '"', $body);
        // Placeholder NE doit PAS subsister dans la sortie.
        $this->assertStringNotContainsString('###_BOOTSTRAP_TOKEN_###', $body);
    }

    #[Test]
    public function it_mints_a_bootstrap_token_and_injects_it_in_linux_fragment(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('linux'),
            $this->passNext(),
        );

        $body = (string) $res->getContent();
        $this->assertCount(1, $this->contextStore);
        $token = array_key_first($this->contextStore);
        $this->assertSame(self::TEST_UUID, $this->contextStore[$token]['context']['uuid']);

        // Token exporté en env var bash : `export BOOTSTRAP_TOKEN="<md5>"`.
        $this->assertStringContainsString('export BOOTSTRAP_TOKEN="' . $token . '"', $body);
        $this->assertStringNotContainsString('###_BOOTSTRAP_TOKEN_###', $body);
    }

    #[Test]
    public function each_injection_mints_a_fresh_token(): void
    {
        // 1ère injection.
        $this->makeMiddleware()->handle($this->makeRequest('windows'), $this->passNext());
        $token1 = array_key_first($this->contextStore);

        // Reset le store + cache template (on garde le même test config).
        $this->contextStore = [];
        InjectBootstrapFragment::clearFragmentCache();

        // 2ème injection (request différente, même uuid).
        $this->makeMiddleware()->handle($this->makeRequest('windows'), $this->passNext());
        $token2 = array_key_first($this->contextStore);

        $this->assertNotSame($token1, $token2, 'Each injection must mint a fresh md5 token');
    }
}
