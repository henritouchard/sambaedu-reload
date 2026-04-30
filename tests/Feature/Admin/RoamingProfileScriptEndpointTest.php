<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Services\RoamingProfileService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 1bis.18f — Tests Feature de l'endpoint /admin/gpo/del-roam.sh.
 *
 * Couvre AC #6 + AC #10 cas #7-8 :
 *  - Auth IP whitelistée OU paramètre `se4_key` valide
 *  - Content-Type: text/plain
 *  - Format byte-fidèle au legacy (header + Firefox lines + lignes dynamiques)
 *  - 403 si ni IP ni clé ne matchent
 */
class RoamingProfileScriptEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        // Bind un stub déterministe pour produire un script reproductible.
        $stub = new class extends RoamingProfileService {
            public function getExclusions(): array
            {
                return ['AppData/Local/Mozilla'];
            }
        };
        $this->app->instance(RoamingProfileService::class, $stub);
    }

    #[Test]
    public function it_returns_text_plain_with_correct_format_when_se4_key_matches(): void
    {
        Config::set('sambaedu.se4_key', 'secret-key-test');
        Config::set('sambaedu.se4fs_ip', '10.0.0.99'); // ne matche pas 127.0.0.1

        $response = $this->get('/admin/gpo/del-roam.sh?se4_key=secret-key-test');

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));

        $body = $response->getContent();
        $this->assertStringStartsWith("# suppression des dossiers trop gros\n", $body);
        $this->assertStringContainsString(
            'rm -fr "/home/profiles/${username}/AppData/Local/Mozilla" 2>/dev/null' . "\n",
            $body
        );
        $this->assertMatchesRegularExpression(
            '#rm -fr "/home/profiles/\$\{username\}/AppData/Roaming/Mozilla/Firefox/Profiles" 2>/dev/null\n$#',
            $body
        );
    }

    #[Test]
    public function it_blocks_without_se4_key_and_wrong_ip(): void
    {
        Config::set('sambaedu.se4_key', 'expected-key');
        Config::set('sambaedu.se4fs_ip', '10.0.0.99'); // pas l'IP de test

        $this->get('/admin/gpo/del-roam.sh')->assertStatus(403);
    }

    #[Test]
    public function it_blocks_when_se4_key_is_wrong(): void
    {
        Config::set('sambaedu.se4_key', 'expected-key');
        Config::set('sambaedu.se4fs_ip', '10.0.0.99');

        $this->get('/admin/gpo/del-roam.sh?se4_key=wrong-key')->assertStatus(403);
    }

    #[Test]
    public function it_allows_access_when_client_ip_matches_se4fs_ip(): void
    {
        // L'IP par défaut de la requête de test Laravel est 127.0.0.1.
        Config::set('sambaedu.se4_key', '');
        Config::set('sambaedu.se4fs_ip', '127.0.0.1');

        $this->get('/admin/gpo/del-roam.sh')->assertStatus(200);
    }

    #[Test]
    public function it_blocks_when_both_ip_and_key_are_empty(): void
    {
        Config::set('sambaedu.se4_key', '');
        Config::set('sambaedu.se4fs_ip', '');

        $this->get('/admin/gpo/del-roam.sh')->assertStatus(403);
    }
}
