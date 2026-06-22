<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Tests Feature de l'endpoint de dépôt chunké
 * `POST /admin/ipxe/iso-windows/upload-chunk`.
 *
 * Couvre : auth, append séquentiel + complete, idempotence des doublons,
 * 409 hors séquence, rejet uploadId/filename invalides, feature désactivée.
 */
class WindowsIsoUploadChunkTest extends TestCase
{
    use CreatesPermissionSchema;
    use DatabaseTransactions;

    private const URL = '/admin/ipxe/iso-windows/upload-chunk';
    private const UUID = '11111111-2222-4333-8444-555566667777';

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionSchema();
        (new PermissionSeeder())->run();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->tmpDir = sys_get_temp_dir() . '/se5-iso-upload-' . getmypid() . '-' . uniqid();
        config([
            'ipxe.iso_management.enabled'                => true,
            'ipxe.iso_management.upload_enabled'         => true,
            'ipxe.iso_management.upload_tmp_path'        => $this->tmpDir,
            'ipxe.iso_management.upload_chunk_bytes'     => 8,
            'ipxe.iso_management.upload_max_total_bytes' => 1024,
            'ipxe.iso_management.upload_stale_ttl'       => 86400,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function actingAsAdmin(): void
    {
        $u = User::query()->create([
            'login'     => 'admin-iso-upload-' . uniqid(),
            'role'      => 'prof',
            'is_active' => true,
        ]);
        $u->givePermissionTo('server.admin');
        $this->actingAs($u);

        // iso WinePageTest / WindowsIsoRouteTest — on bypasse les middlewares
        // session legacy pour atteindre `can:server.admin`.
        $this->withoutMiddleware([
            \App\Http\Middleware\Auth\SambaEduAuth::class,
            \App\Http\Middleware\RequireAdminRights::class,
        ]);
    }

    /**
     * @param array<string, string|int> $params
     */
    private function postChunk(array $params, string $body)
    {
        $url = self::URL . '?' . http_build_query($params);

        return $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $body);
    }

    private function baseParams(int $index, int $total, string $body): array
    {
        return [
            'uploadId'  => self::UUID,
            'index'     => $index,
            'total'     => $total,
            'chunkSize' => 8,
            'filename'  => 'Win11_24H2.iso',
            'version'   => 'Win11',
        ];
    }

    #[Test]
    public function it_refuses_unauthenticated_requests(): void
    {
        $response = $this->postChunk($this->baseParams(0, 1, 'abc'), 'abc');

        self::assertContains($response->status(), [302, 401, 403]);
    }

    #[Test]
    public function it_appends_chunks_in_sequence_and_reports_complete(): void
    {
        $this->actingAsAdmin();

        // 2 chunks de 8 octets + 1 reliquat = "AAAAAAAA" "BBBBBBBB" "CC".
        $r0 = $this->postChunk($this->baseParams(0, 3, 'AAAAAAAA'), 'AAAAAAAA');
        $r0->assertOk()->assertJson(['ok' => true, 'received' => 1, 'complete' => false]);

        $r1 = $this->postChunk($this->baseParams(1, 3, 'BBBBBBBB'), 'BBBBBBBB');
        $r1->assertOk()->assertJson(['ok' => true, 'received' => 2, 'complete' => false]);

        $r2 = $this->postChunk($this->baseParams(2, 3, 'CC'), 'CC');
        $r2->assertOk()->assertJson(['ok' => true, 'received' => 3, 'complete' => true]);

        $partPath = $this->tmpDir . '/' . self::UUID . '.part';
        self::assertFileExists($partPath);
        self::assertSame('AAAAAAAABBBBBBBBCC', file_get_contents($partPath));
    }

    #[Test]
    public function it_is_idempotent_on_duplicate_chunk(): void
    {
        $this->actingAsAdmin();

        $this->postChunk($this->baseParams(0, 2, 'AAAAAAAA'), 'AAAAAAAA')->assertOk();
        // Renvoi du même chunk 0 (réponse perdue côté client) → no-op succès.
        $dup = $this->postChunk($this->baseParams(0, 2, 'AAAAAAAA'), 'AAAAAAAA');
        $dup->assertOk()->assertJson(['ok' => true, 'received' => 1]);

        $partPath = $this->tmpDir . '/' . self::UUID . '.part';
        self::assertSame('AAAAAAAA', file_get_contents($partPath));
    }

    #[Test]
    public function it_returns_409_on_out_of_sequence_chunk(): void
    {
        $this->actingAsAdmin();

        $this->postChunk($this->baseParams(0, 3, 'AAAAAAAA'), 'AAAAAAAA')->assertOk();
        // On saute le chunk 1 → 409 + received=1 (le client reprend là).
        $gap = $this->postChunk($this->baseParams(2, 3, 'CCCCCCCC'), 'CCCCCCCC');
        $gap->assertStatus(409)->assertJson(['ok' => false, 'received' => 1]);
    }

    #[Test]
    public function it_rejects_invalid_upload_id(): void
    {
        $this->actingAsAdmin();

        $params = $this->baseParams(0, 1, 'abc');
        $params['uploadId'] = 'not-a-uuid';
        $this->postChunk($params, 'abc')->assertStatus(422);
    }

    #[Test]
    public function it_rejects_bad_filename_on_first_chunk(): void
    {
        $this->actingAsAdmin();

        $params = $this->baseParams(0, 1, 'abc');
        $params['filename'] = '../evil.iso';
        $this->postChunk($params, 'abc')->assertStatus(422);
    }

    #[Test]
    public function it_enforces_total_size_cap(): void
    {
        $this->actingAsAdmin();
        config(['ipxe.iso_management.upload_max_total_bytes' => 10]);

        // chunk 0 de 8 octets OK, mais chunk 1 ferait 16 > 10 → rejet.
        $this->postChunk($this->baseParams(0, 2, 'AAAAAAAA'), 'AAAAAAAA')->assertOk();
        $this->postChunk($this->baseParams(1, 2, 'BBBBBBBB'), 'BBBBBBBB')->assertStatus(422);
    }

    #[Test]
    public function it_returns_403_when_upload_disabled(): void
    {
        $this->actingAsAdmin();
        config(['ipxe.iso_management.upload_enabled' => false]);

        $this->postChunk($this->baseParams(0, 1, 'abc'), 'abc')->assertStatus(403);
    }
}
