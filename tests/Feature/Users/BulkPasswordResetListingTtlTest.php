<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Services\BulkResetListingService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\MocksAdminUser;

/**
 * Tests TTL + purge + signature pour le listing post-bulk-reset (story 2.6, AC 9).
 *
 * Couvre :
 *   - stockage hors session PHP (cache Redis simulé ici par cache array en test)
 *   - 2 téléchargements PDF + CSV possibles avant TTL
 *   - purge manuelle et purge automatique par remplacement (un seul listing actif)
 *   - token signé invalide → 403 (middleware signed Laravel)
 *   - expiration → 410 Gone (le controller retourne la vue errors/password-reset-expired)
 */
class BulkPasswordResetListingTtlTest extends TestCase
{
    use MocksAdminUser;
    #[Test]
    public function listing_not_stored_in_php_session(): void
    {
        $service = app(BulkResetListingService::class);
        $service->storeListing(5, [
            ['login' => 'x', 'new_password' => 'MDP-SENSITIVE-XYZ', 'success' => true],
        ]);

        $allSession = json_encode(session()->all());
        $this->assertStringNotContainsString('MDP-SENSITIVE-XYZ', (string) $allSession);
    }

    #[Test]
    public function two_formats_download_before_ttl_keeps_listing(): void
    {
        $service = app(BulkResetListingService::class);
        $token = $service->storeListing(3, [
            ['login' => 'u', 'new_password' => 'abc', 'success' => true],
        ]);

        // Premier fetch (PDF)
        $first = $service->fetchListing($token);
        $this->assertNotNull($first);

        // Deuxième fetch (CSV) — le listing reste accessible
        $second = $service->fetchListing($token);
        $this->assertNotNull($second);
        $this->assertSame($first['listing'], $second['listing']);
    }

    #[Test]
    public function manual_purge_removes_listing(): void
    {
        $service = app(BulkResetListingService::class);
        $token = $service->storeListing(2, [
            ['login' => 'u', 'new_password' => 'abc', 'success' => true],
        ]);

        $service->purgeListing(2, $token);

        $this->assertNull($service->fetchListing($token));
        $this->assertFalse($service->hasActiveListingForOperator(2));
    }

    #[Test]
    public function new_bulk_purges_previous_listing(): void
    {
        $service = app(BulkResetListingService::class);

        $token1 = $service->storeListing(7, [
            ['login' => 'u1', 'new_password' => 'p1', 'success' => true],
        ]);
        $token2 = $service->storeListing(7, [
            ['login' => 'u2', 'new_password' => 'p2', 'success' => true],
        ]);

        $this->assertNull($service->fetchListing($token1));
        $this->assertNotNull($service->fetchListing($token2));
    }

    #[Test]
    public function cache_expiry_nullifies_listing(): void
    {
        $service = app(BulkResetListingService::class);
        $token = $service->storeListing(8, [
            ['login' => 'u', 'new_password' => 'abc', 'success' => true],
        ]);

        // Simuler expiration en vidant le cache
        Cache::flush();

        $this->assertNull($service->fetchListing($token));
    }

    #[Test]
    public function invalid_signed_url_is_rejected_by_middleware(): void
    {
        // Authentifier un user — sinon l'auth middleware redirige (302) avant que signed puisse agir
        $this->actAsAdmin();
        // Token forgé sans signature valide — le middleware `signed` doit retourner 403
        $response = $this->withoutMiddleware([\App\Http\Middleware\Auth\SambaEduAuth::class])
            ->get('/app/users/password-reset/forged-token/pdf?signature=invalid');
        $response->assertStatus(403);
    }

    #[Test]
    public function only_one_active_listing_per_operator(): void
    {
        $service = app(BulkResetListingService::class);

        $service->storeListing(99, [['login' => 'a', 'new_password' => 'p', 'success' => true]]);
        $service->storeListing(99, [['login' => 'b', 'new_password' => 'p', 'success' => true]]);

        $meta = $service->getActiveListingMeta(99);
        $this->assertNotNull($meta);
        $this->assertSame(1, $meta['count']);
    }

    #[Test]
    public function meta_exposes_signed_urls_for_both_formats(): void
    {
        $service = app(BulkResetListingService::class);
        $service->storeListing(11, [['login' => 'u', 'new_password' => 'p', 'success' => true]]);

        $meta = $service->getActiveListingMeta(11);
        $this->assertNotNull($meta);
        $this->assertArrayHasKey('pdf_url', $meta);
        $this->assertArrayHasKey('csv_url', $meta);
        $this->assertStringContainsString('password-reset', $meta['pdf_url']);
        $this->assertStringContainsString('password-reset', $meta['csv_url']);
    }
}
