<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Ipxe\Services\IpxeAuthService;
use App\Models\User;
use App\Models\Workstation;
use App\Services\AuthenticationService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 4.10 — Tests Feature de l'auth iPXE.
 *
 * Couvre AC1, AC2 (sweep des endpoints sensibles), AC3 (permission Spatie
 * `computer.install`), AC6 (matrice {sans creds, creds invalides, creds
 * valides sans permission, creds valides avec permission} × endpoints),
 * AC7 (logs sécurité avec champs précis).
 *
 * **Setup** : stub `AuthenticationService::validateAdCredentials()` via
 * `app->instance()` pour simuler le bind LDAP (le vrai LDAP est indisponible
 * en local). User Eloquent créé en DB avec/sans permission Spatie selon
 * le scénario.
 *
 * **Non-leak** : un test dédié vérifie qu'aucun event log n'expose le
 * password en clair, même tronqué.
 */
class IpxeAdminAuthTest extends TestCase
{
    use CreatesPermissionSchema;

    /**
     * Liste des endpoints sensibles à protéger (AC2).
     *
     * @return array<string, array{0:string, 1:string, 2:string}>
     *   Format: [endpoint_name => [http_path, log_context, http_method]]
     */
    public static function sensitiveEndpointsProvider(): array
    {
        return [
            'admin' => ['/ipxe/admin', 'admin', 'POST'],
            'maintenance' => ['/ipxe/maintenance', 'maintenance', 'POST'],
            'action_rescuecd' => ['/ipxe/action/rescuecd', 'action', 'POST'],
            'action_factory_reset' => ['/ipxe/action/factory_reset', 'action', 'POST'],
            'installation_linux' => ['/ipxe/installation-linux', 'install_linux', 'POST'],
            'installation_windows' => ['/ipxe/installation-windows', 'install_windows', 'POST'],
            'clonezilla_menu' => ['/ipxe/clonezilla-menu', 'clonezilla', 'POST'],
            'enrollment_name' => ['/ipxe/enrollment/name', 'enrollment.name', 'POST'],
            'enrollment_room' => ['/ipxe/enrollment/room', 'enrollment.room', 'POST'],
            'enrollment_parc_add' => ['/ipxe/enrollment/parc-add', 'enrollment.parc-add', 'POST'],
            'enrollment_parc_remove' => ['/ipxe/enrollment/parc-remove', 'enrollment.parc-remove', 'POST'],
            'enrollment_byod' => ['/ipxe/enrollment/byod', 'enrollment.byod', 'POST'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Schéma permissions Spatie + users (trait projet) + tables iPXE.
        $this->createPermissionSchema();
        IpxeSchemaBootstrapper::bootstrap();

        // Seed les permissions+rôles canoniques (idempotent).
        (new PermissionSeeder())->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
            // S'assure que tous les feature flags iPXE sont ON (sinon
            // certains endpoints renvoient un menu vide sans déclencher
            // auth).
            'ipxe.admin.enabled' => true,
            'ipxe.enrollment.enabled' => true,
            'ipxe.linux.enabled' => true,
            'ipxe.windows.enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    /**
     * Stub `AuthenticationService::validateAdCredentials` (bypass LDAP réel).
     */
    private function stubAuthBind(bool $bindOk): void
    {
        $mock = $this->createMock(AuthenticationService::class);
        $mock->method('validateAdCredentials')->willReturn($bindOk);
        $this->app->instance(AuthenticationService::class, $mock);
        $service = new IpxeAuthService($mock);
        $this->app->instance(IpxeAuthService::class, $service);
        $this->app->instance(\App\Ipxe\Contracts\IpxeAuthorizes::class, $service);
    }

    private function seedWorkstation(): Workstation
    {
        return Workstation::create([
            'name' => 'PC-AUTH-' . substr(bin2hex(random_bytes(3)), 0, 6),
            'uuid' => '12345678-1234-1234-1234-' . bin2hex(random_bytes(6)),
            'mac' => 'aa:bb:cc:' . substr(bin2hex(random_bytes(3)), 0, 2) . ':'
                . substr(bin2hex(random_bytes(3)), 0, 2) . ':'
                . substr(bin2hex(random_bytes(3)), 0, 2),
            'status' => 'active',
        ]);
    }

    /**
     * Payload commun POST iPXE : mac + uuid présents (= hors handshake), et
     * éventuellement username/password.
     *
     * @return array<string, string>
     */
    private function payload(
        Workstation $ws,
        ?string $username = null,
        ?string $password = null,
    ): array {
        $p = [
            'mac' => $ws->mac,
            'uuid' => $ws->uuid,
        ];
        if ($username !== null) {
            $p['username'] = $username;
        }
        if ($password !== null) {
            $p['password'] = base64_encode($password);
        }

        return $p;
    }

    // ====================================================================
    // AC1 — handleAdmin : matrice 4 cas
    // ====================================================================

    #[Test]
    #[DataProvider('sensitiveEndpointsProvider')]
    public function it_blocks_without_credentials(string $path, string $context, string $method): void
    {
        $this->stubAuthBind(true); // bind ne sera pas appelé
        $ws = $this->seedWorkstation();

        $response = $this->call($method, $path, $this->payload($ws));

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('Acces refuse', $body, "Endpoint $path doit refuser sans creds");
        self::assertStringContainsString('identifiants requis', $body);
        self::assertStringContainsString('/ipxe/boot##params', $body);
    }

    #[Test]
    #[DataProvider('sensitiveEndpointsProvider')]
    public function it_blocks_with_invalid_credentials(string $path, string $context, string $method): void
    {
        $this->stubAuthBind(false); // bind échoue
        $ws = $this->seedWorkstation();

        $response = $this->call(
            $method,
            $path,
            $this->payload($ws, 'baduser', 'badpassword')
        );

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('identifiants invalides', $body, "Endpoint $path doit rejeter creds invalides");
    }

    #[Test]
    #[DataProvider('sensitiveEndpointsProvider')]
    public function it_blocks_authenticated_user_without_permission(string $path, string $context, string $method): void
    {
        $this->stubAuthBind(true); // bind OK
        // User existe mais sans permission `computer.install`
        User::create(['login' => 'noperm-user', 'is_active' => true]);

        $ws = $this->seedWorkstation();
        $response = $this->call(
            $method,
            $path,
            $this->payload($ws, 'noperm-user', 'goodpassword')
        );

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('droit insuffisant', $body, "Endpoint $path doit refuser sans permission");
        self::assertStringContainsString('computer.install', $body);
    }

    /**
     * Story 4.10 (correctif review #10) — markers stables par endpoint.
     *
     * Assertion positive : on vérifie qu'un substring du template *attendu*
     * (menu admin, maintenance, action rescuecd, etc.) est présent dans la
     * réponse. Détecte les régressions où `guard()` est retiré ET un autre
     * template (ex. handshake, écran d'erreur silencieux) est servi sans
     * déclencher `Acces refuse`.
     *
     * @return array<string,string>  [http_path => substring_attendu]
     */
    private function expectedAllowedSubstring(): array
    {
        return [
            '/ipxe/admin' => 'menu Preboot',
            '/ipxe/maintenance' => 'menu Maintenance',
            '/ipxe/action/rescuecd' => 'sysresccd',
            '/ipxe/action/factory_reset' => 'clonezilla',
            '/ipxe/installation-linux' => 'installation clients-linux',
            '/ipxe/installation-windows' => 'installation clients Windows',
            '/ipxe/clonezilla-menu' => 'menu Clonezilla',
            '/ipxe/enrollment/name' => 'Entrez le nom',
            '/ipxe/enrollment/room' => 'Enregistrement de la salle',
            '/ipxe/enrollment/parc-add' => "Ajout d'un parc",
            '/ipxe/enrollment/parc-remove' => "Retrait d'un parc",
            // BYOD : la fixture seedWorkstation() crée un poste connu en
            // DB → flow byod-denied (iso-legacy : un poste enrôlé ne peut
            // pas se réenregistrer en BYOD). Le marker stable côté
            // `byod.blade.php` est l'echo `ERREUR ! acces refuse` (lowercase
            // — distinct de l'auth_failed `Acces refuse` qui est capitalisé).
            '/ipxe/enrollment/byod' => 'ERREUR ! acces refuse',
        ];
    }

    #[Test]
    #[DataProvider('sensitiveEndpointsProvider')]
    public function it_allows_authenticated_user_with_permission(string $path, string $context, string $method): void
    {
        $this->stubAuthBind(true);

        $user = User::create(['login' => 'admin-user', 'is_active' => true]);
        $user->assignRole(SambaRole::ComputerAdmin->value);

        $ws = $this->seedWorkstation();
        $response = $this->call(
            $method,
            $path,
            $this->payload($ws, 'admin-user', 'goodpassword')
        );

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringNotContainsString('Acces refuse', $body, "Endpoint $path doit autoriser un user avec permission");

        // Correctif review #10 — assertion positive par endpoint.
        $expected = $this->expectedAllowedSubstring();
        if (isset($expected[$path])) {
            self::assertStringContainsString(
                $expected[$path],
                $body,
                "Endpoint $path doit rendre son template attendu (marker `{$expected[$path]}`)"
            );
        }
    }

    // ====================================================================
    // AC7 — non-leak password dans les logs
    // ====================================================================

    #[Test]
    public function it_does_not_leak_password_in_logs_on_auth_failure(): void
    {
        // Capture les logs via un handler Monolog inline branché sur le
        // channel `ipxe`. Plus robuste que `Log::channel(...)->listen(...)`
        // (n'existe pas en Laravel 11+).
        $records = [];
        $handler = new \Monolog\Handler\TestHandler();
        $monolog = Log::channel('ipxe')->getLogger();
        if ($monolog instanceof \Monolog\Logger) {
            $monolog->pushHandler($handler);
        }

        $secretPassword = 'SuperSecret123!Hidden';
        $this->stubAuthBind(false);
        $ws = $this->seedWorkstation();

        $this->call('POST', '/ipxe/admin', $this->payload($ws, 'attacker', $secretPassword));

        // 1) Scan handler Monolog en mémoire.
        foreach ($handler->getRecords() as $r) {
            $serialized = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            self::assertIsString($serialized);
            self::assertStringNotContainsString(
                $secretPassword,
                $serialized,
                "Le password en clair leak dans un log Monolog : " . substr($serialized, 0, 200),
            );
            self::assertStringNotContainsString(
                base64_encode($secretPassword),
                $serialized,
                "Le password base64 leak dans un log Monolog",
            );
        }

        // 2) Scan défensif des fichiers log.
        $logStorage = storage_path('logs');
        $today = date('Y-m-d');
        $candidates = glob($logStorage . '/ipxe*' . $today . '*.log') ?: [];
        $candidates = array_merge($candidates, glob($logStorage . '/ipxe/ipxe-' . $today . '.log') ?: []);
        foreach ($candidates as $file) {
            $contents = @file_get_contents($file);
            if ($contents === false) {
                continue;
            }
            self::assertStringNotContainsString($secretPassword, $contents);
            self::assertStringNotContainsString(base64_encode($secretPassword), $contents);
        }
    }

    #[Test]
    public function it_does_not_leak_password_in_response_body_on_auth_failure(): void
    {
        $this->stubAuthBind(false);
        $secret = 'Sup3rS3cretZ!';
        $ws = $this->seedWorkstation();

        $response = $this->call('POST', '/ipxe/admin', $this->payload($ws, 'attacker', $secret));

        $body = (string) $response->getContent();
        self::assertStringNotContainsString($secret, $body);
        self::assertStringNotContainsString(base64_encode($secret), $body);
        self::assertStringNotContainsString('attacker', $body); // pas de leak username non plus
    }

    // ====================================================================
    // AC1 — flow admin OK : doit servir le menu admin
    // ====================================================================

    #[Test]
    public function it_renders_admin_menu_on_successful_authentication(): void
    {
        $this->stubAuthBind(true);
        $user = User::create(['login' => 'happy-admin', 'is_active' => true]);
        $user->assignRole(SambaRole::ComputerAdmin->value);

        $ws = Workstation::create([
            'name' => 'PC-AUTH-MENU',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:11',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/admin', $this->payload($ws, 'happy-admin', 'good'));

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('PC-AUTH-MENU', $body);
        self::assertStringContainsString('item --key m maintenance', $body);
    }

    // ====================================================================
    // Garde-fou : handshake reste accessible sans auth (pas de mac/uuid)
    // ====================================================================

    #[Test]
    public function handshake_remains_accessible_without_credentials(): void
    {
        $this->stubAuthBind(false);

        $response = $this->get('/ipxe/admin');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        // Handshake = pas l'écran auth_failed.
        self::assertStringNotContainsString('Acces refuse', $body);
        self::assertStringContainsString('admin##params', $body);
    }

    // ====================================================================
    // Story 4.10 — correctif review #5 — spy sur validateAdCredentials
    // ====================================================================
    //
    // Objectif : détecter le retrait silencieux de `$this->guard()` dans un
    // handler. Un mock `expects($this->once())` casse si jamais un endpoint
    // est appelé sans passer par `IpxeAuthService::authorize()`.

    #[Test]
    #[DataProvider('sensitiveEndpointsProvider')]
    public function it_invokes_validate_ad_credentials_for_each_sensitive_endpoint(
        string $path,
        string $context,
        string $method,
    ): void {
        $mock = $this->createMock(AuthenticationService::class);
        // `expects($this->once())` = casse si l'endpoint contourne guard().
        $mock->expects($this->once())
            ->method('validateAdCredentials')
            ->willReturn(true);
        $this->app->instance(AuthenticationService::class, $mock);
        $service = new IpxeAuthService($mock);
        $this->app->instance(IpxeAuthService::class, $service);
        $this->app->instance(\App\Ipxe\Contracts\IpxeAuthorizes::class, $service);

        // Seed user avec permission pour passer le second check (sinon on
        // s'arrête avant la fin du flow mais validateAdCredentials est
        // bien appelé une fois — comportement attendu).
        $user = User::create(['login' => 'spy-admin', 'is_active' => true]);
        $user->assignRole(SambaRole::ComputerAdmin->value);

        $ws = $this->seedWorkstation();
        $response = $this->call($method, $path, $this->payload($ws, 'spy-admin', 'goodpassword'));

        $response->assertStatus(200);
        // PHPUnit vérifie automatiquement `expects($this->once())` en tearDown.
    }

    // ====================================================================
    // Story 4.10 — correctif review #2 — propagation creds multi-step
    // ====================================================================

    /**
     * Endpoints enrollment multi-step : chacun chain re-poste vers lui-même
     * ou vers /ipxe/admin → un 2ème POST avec les mêmes creds doit passer.
     *
     * @return array<string,array{0:string}>
     */
    public static function multiStepEnrollmentProvider(): array
    {
        return [
            'enrollment_name' => ['/ipxe/enrollment/name'],
            'enrollment_room' => ['/ipxe/enrollment/room'],
            'enrollment_parc_add' => ['/ipxe/enrollment/parc-add'],
            'enrollment_parc_remove' => ['/ipxe/enrollment/parc-remove'],
            'enrollment_byod' => ['/ipxe/enrollment/byod'],
        ];
    }

    #[Test]
    #[DataProvider('multiStepEnrollmentProvider')]
    public function it_propagates_credentials_through_multi_step_enrollment_flow(string $path): void
    {
        $this->stubAuthBind(true);
        $user = User::create(['login' => 'multi-step-admin', 'is_active' => true]);
        $user->assignRole(SambaRole::ComputerAdmin->value);

        $ws = $this->seedWorkstation();
        $payload = $this->payload($ws, 'multi-step-admin', 'goodpassword');

        // 1er hit (handshake / saisie initiale).
        $first = $this->post($path, $payload);
        $first->assertStatus(200);
        $firstBody = (string) $first->getContent();
        self::assertStringNotContainsString(
            'Acces refuse',
            $firstBody,
            "1er POST $path : auth refusée alors que creds valides",
        );

        // 2ème hit (équivalent au chain `##params` re-déclenché par iPXE
        // après saisie utilisateur). Les params iPXE intègrent désormais
        // username/password (correctif #2) — on simule ça en re-postant le
        // même payload + un éventuel `new_name` / `room` / `parc`.
        $secondPayload = $payload;
        if ($path === '/ipxe/enrollment/name' || $path === '/ipxe/enrollment/byod') {
            $secondPayload['new_name'] = 'pc-test-multi';
        }
        $second = $this->post($path, $secondPayload);
        $second->assertStatus(200);
        $secondBody = (string) $second->getContent();
        self::assertStringNotContainsString(
            'Acces refuse - identifiants requis',
            $secondBody,
            "2ème POST $path : MissingCredentials alors que les params iPXE doivent propager username/password",
        );
    }

    // ====================================================================
    // Story 4.10 — correctif review #3 — decodePassword durcissement
    // ====================================================================

    #[Test]
    public function it_decodes_standard_base64_password_correctly(): void
    {
        // Capture le password reçu côté validateAdCredentials.
        $received = null;
        $mock = $this->createMock(AuthenticationService::class);
        $mock->method('validateAdCredentials')
            ->willReturnCallback(function (string $u, string $p) use (&$received): bool {
                $received = $p;

                return true;
            });
        $this->app->instance(AuthenticationService::class, $mock);
        $service = new IpxeAuthService($mock);
        $this->app->instance(IpxeAuthService::class, $service);
        $this->app->instance(\App\Ipxe\Contracts\IpxeAuthorizes::class, $service);

        $user = User::create(['login' => 'dec-admin', 'is_active' => true]);
        $user->assignRole(SambaRole::ComputerAdmin->value);

        $ws = $this->seedWorkstation();
        $clear = 'P@ssw0rd!Spécial';
        $this->post('/ipxe/admin', [
            'mac' => $ws->mac,
            'uuid' => $ws->uuid,
            'username' => 'dec-admin',
            'password' => base64_encode($clear),
        ]);

        self::assertSame($clear, $received, 'Password b64 standard doit être décodé');
    }

    #[Test]
    public function it_falls_back_to_raw_when_password_is_full_b64_alphabet_but_not_encoded(): void
    {
        // `mypassword` = uniquement [a-z] (alphabet b64), longueur 10 → pas
        // multiple de 4 → la regex+modulo bloque le décodage et on garde raw.
        $received = null;
        $mock = $this->createMock(AuthenticationService::class);
        $mock->method('validateAdCredentials')
            ->willReturnCallback(function (string $u, string $p) use (&$received): bool {
                $received = $p;

                return true;
            });
        $this->app->instance(AuthenticationService::class, $mock);
        $service = new IpxeAuthService($mock);
        $this->app->instance(IpxeAuthService::class, $service);
        $this->app->instance(\App\Ipxe\Contracts\IpxeAuthorizes::class, $service);

        $user = User::create(['login' => 'raw-admin', 'is_active' => true]);
        $user->assignRole(SambaRole::ComputerAdmin->value);

        $ws = $this->seedWorkstation();
        $this->post('/ipxe/admin', [
            'mac' => $ws->mac,
            'uuid' => $ws->uuid,
            'username' => 'raw-admin',
            'password' => 'mypassword', // raw, full b64 alphabet, len=10
        ]);

        self::assertSame('mypassword', $received, 'Password raw full-b64-alphabet doit fallback raw');
    }

    #[Test]
    public function it_falls_back_to_raw_when_password_contains_non_b64_characters(): void
    {
        $received = null;
        $mock = $this->createMock(AuthenticationService::class);
        $mock->method('validateAdCredentials')
            ->willReturnCallback(function (string $u, string $p) use (&$received): bool {
                $received = $p;

                return true;
            });
        $this->app->instance(AuthenticationService::class, $mock);
        $service = new IpxeAuthService($mock);
        $this->app->instance(IpxeAuthService::class, $service);
        $this->app->instance(\App\Ipxe\Contracts\IpxeAuthorizes::class, $service);

        $user = User::create(['login' => 'raw2-admin', 'is_active' => true]);
        $user->assignRole(SambaRole::ComputerAdmin->value);

        $ws = $this->seedWorkstation();
        $this->post('/ipxe/admin', [
            'mac' => $ws->mac,
            'uuid' => $ws->uuid,
            'username' => 'raw2-admin',
            'password' => 'pass word@123', // espace + @ = hors alphabet b64
        ]);

        self::assertSame('pass word@123', $received, 'Password contenant chars hors b64 doit fallback raw');
    }

    // ====================================================================
    // Story 4.10 — correctif review #14 — case sensitivity AD vs PG
    // ====================================================================

    // ====================================================================
    // Story 4.10 — correctif review #15 — rate-limit 30/min/IP
    // ====================================================================

    #[Test]
    public function it_rate_limits_admin_endpoint_after_30_failures(): void
    {
        // bind échoue → 200 (page auth_failed) tant que sous la limite, puis
        // 429 à partir du 31ème hit.
        $this->stubAuthBind(false);
        $ws = $this->seedWorkstation();
        $payload = $this->payload($ws, 'attacker', 'badpassword');

        // Vider le RateLimiter pour repartir d'un bucket propre (au cas où
        // un autre test aurait déjà tapé l'endpoint).
        \Illuminate\Support\Facades\RateLimiter::clear(
            sha1('127.0.0.1|' . request()->server('SERVER_NAME', 'localhost'))
        );

        // 30 premiers POST → 200 (auth_failed).
        for ($i = 1; $i <= 30; $i++) {
            $resp = $this->call('POST', '/ipxe/admin', $payload);
            self::assertSame(200, $resp->getStatusCode(), "Hit #$i devrait être 200 (sous limite)");
        }

        // 31ème POST → 429 (throttle:30,1 atteint).
        $blocked = $this->call('POST', '/ipxe/admin', $payload);
        self::assertSame(429, $blocked->getStatusCode(), '31ème hit doit déclencher le rate-limit');
    }

    #[Test]
    public function it_resolves_user_with_uppercase_login_case_insensitively(): void
    {
        $this->stubAuthBind(true);

        // User en DB en minuscules (cf. SE5 sync AD lowercase).
        $user = User::create(['login' => 'jdoe', 'is_active' => true]);
        $user->assignRole(SambaRole::ComputerAdmin->value);

        $ws = $this->seedWorkstation();
        // Firmware iPXE POST avec username saisi en MAJUSCULES.
        $response = $this->post('/ipxe/admin', $this->payload($ws, 'JDOE', 'goodpassword'));

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringNotContainsString(
            'Acces refuse',
            $body,
            'Login en MAJUSCULES doit résoudre le user PG en minuscules (case-insensitive)',
        );
        self::assertStringContainsString('menu Preboot', $body);
    }
}
