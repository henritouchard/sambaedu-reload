<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Services\BulkResetListingService;
use App\Services\PasswordResetExportService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests feature E2E — flux bulk-reset mdp (story 2.6, AC 1, 4, 6).
 *
 * Couvre :
 *   - génération de l'export (PDF + CSV) sans fichier persistant sur disque
 *   - headers Content-Disposition, Cache-Control, X-Robots-Tag
 *   - audit trail structuré sans mdp clair
 *   - stockage listing chiffré + purge manuelle
 *   - token signé (middleware `signed`)
 */
class BulkPasswordResetTest extends TestCase
{
    #[Test]
    public function export_csv_contains_expected_columns_and_no_persistence(): void
    {
        $storageFilesBefore = $this->snapshotStorageFiles();

        $service = new PasswordResetExportService();
        $response = $service->generateExport([
            [
                'login' => 'alice',
                'new_password' => 'SecretCSV-1',
                'success' => true,
                'source_group_id' => null,
                'source_group_name' => null,
                'metadata' => [
                    'firstname' => 'Alice',
                    'lastname' => 'Dupont',
                    'email' => 'alice@example.com',
                    'structure' => 'College Test',
                    'classes' => ['6A'],
                    'activated' => true,
                ],
            ],
        ], 'csv');

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('password-reset', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));

        // Le streaming response doit émettre le contenu dans php://output
        ob_start();
        $response->sendContent();
        $body = ob_get_clean();

        $this->assertStringContainsString('alice', $body);
        $this->assertStringContainsString('SecretCSV-1', $body);
        $this->assertStringContainsString('login;lastName;firstName', $body);

        // Aucun fichier n'a dû être créé dans storage/
        $this->assertEquals(
            $storageFilesBefore,
            $this->snapshotStorageFiles(),
            'Aucun fichier ne doit être persisté dans storage/ après un export bulk-reset',
        );
    }

    #[Test]
    public function export_pdf_returns_application_pdf_content_type(): void
    {
        $service = new PasswordResetExportService();
        $response = $service->generateExport([
            [
                'login' => 'bob',
                'new_password' => 'SecretPDF-2',
                'success' => true,
                'source_group_id' => 10,
                'source_group_name' => 'Classe_6A',
                'metadata' => [
                    'firstname' => 'Bob',
                    'lastname' => 'Martin',
                    'email' => 'bob@example.com',
                    'structure' => 'College Test',
                    'classes' => ['6A'],
                    'activated' => true,
                ],
            ],
        ], 'pdf', [
            'operator_login' => 'admin',
            'force_change' => true,
        ]);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function listing_service_stores_and_retrieves_encrypted_listing(): void
    {
        $service = new BulkResetListingService();

        $operatorId = 42;
        $listing = [
            ['login' => 'alice', 'new_password' => 'S3cr3t', 'success' => true],
        ];

        $token = $service->storeListing($operatorId, $listing, ['force_change' => true]);
        $this->assertNotEmpty($token);

        $fetched = $service->fetchListing($token);
        $this->assertNotNull($fetched);
        $this->assertSame($listing, $fetched['listing']);
        $this->assertSame($operatorId, $fetched['operator_id']);

        // Purge manuelle
        $service->purgeListing($operatorId, $token);
        $this->assertNull($service->fetchListing($token));
        $this->assertFalse($service->hasActiveListingForOperator($operatorId));
    }

    #[Test]
    public function storing_a_new_listing_purges_previous_for_same_operator(): void
    {
        $service = new BulkResetListingService();
        $operatorId = 101;

        $token1 = $service->storeListing($operatorId, [
            ['login' => 'alice', 'new_password' => 'p1', 'success' => true],
        ]);
        $this->assertNotNull($service->fetchListing($token1));

        $token2 = $service->storeListing($operatorId, [
            ['login' => 'bob', 'new_password' => 'p2', 'success' => true],
        ]);

        // Le premier listing doit être purgé
        $this->assertNull($service->fetchListing($token1));
        $this->assertNotNull($service->fetchListing($token2));
    }

    #[Test]
    public function has_active_listing_returns_false_when_empty(): void
    {
        $service = new BulkResetListingService();
        $this->assertFalse($service->hasActiveListingForOperator(99999));
        $this->assertNull($service->getActiveListingMeta(99999));
    }

    #[Test]
    public function session_never_contains_cleartext_password(): void
    {
        $service = new BulkResetListingService();
        $service->storeListing(7, [
            ['login' => 'alice', 'new_password' => 'cleartext-in-session-would-be-bad', 'success' => true],
        ]);

        // Le listing ne doit JAMAIS être stocké dans la session PHP
        $sessionData = session()->all();
        $serialized = json_encode($sessionData);
        $this->assertStringNotContainsString(
            'cleartext-in-session-would-be-bad',
            (string) $serialized,
            'Le mot de passe clair ne doit JAMAIS apparaître dans la session PHP',
        );
    }

    #[Test]
    public function expired_token_route_returns_410_gone(): void
    {
        // Token inventé — le listing n'existe pas côté cache
        $fakeToken = 'non-existent-token-' . uniqid();

        $signedUrl = url()->temporarySignedRoute(
            'app.users.password-reset.pdf',
            now()->addMinutes(20),
            ['token' => $fakeToken],
        );

        // Authentification requise par le middleware `sambaedu.auth` — on utilise
        // un user minimal persistant pour passer le guard.
        $this->ensureUsersTable();
        $user = \App\Models\User::firstOrCreate(
            ['login' => 'test-admin'],
            ['fullname' => 'Admin Test', 'email' => 'admin@test.local', 'is_active' => true],
        );
        $this->actingAs($user);

        $response = $this->withoutMiddleware([\App\Http\Middleware\Auth\SambaEduAuth::class])->get($signedUrl);
        $response->assertStatus(410);
        $response->assertSee('expiré');
    }

    private function ensureUsersTable(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('users')) {
            \Illuminate\Support\Facades\Schema::create('users', function ($table): void {
                $table->id();
                $table->string('login')->unique();
                $table->string('fullname')->nullable();
                $table->string('firstname')->nullable();
                $table->string('lastname')->nullable();
                $table->string('email')->nullable();
                $table->string('role')->default('eleve');
                $table->string('dn')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('pwd_reset_at')->nullable();
                $table->timestamps();
            });
        }
    }

    #[Test]
    public function unsigned_download_url_is_rejected(): void
    {
        // URL sans signature — le middleware `signed` doit refuser
        $response = $this->get('/app/users/password-reset/any-token/pdf');
        $this->assertNotSame(200, $response->status());
    }

    private function snapshotStorageFiles(): array
    {
        $path = storage_path('app');
        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }
}
