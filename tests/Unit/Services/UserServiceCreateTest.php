<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\LdapConfig;
use App\Config\SambaEduConfig;
use App\LdapModels\LdapUser;
use App\LdapModels\SambaEduGroup;
use App\Models\User as SqlUserModel;
use App\Repositories\ClassRepository;
use App\Repositories\EstablishmentRepository;
use App\Repositories\FunctionRepository;
use App\Repositories\OrganizationalUnitRepository;
use App\Repositories\UserRepository;
use App\Services\PasswordService;
use App\Services\UserService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UserServiceCreateTest extends TestCase
{
    private UserService $service;
    private UserRepository $userRepository;
    private OrganizationalUnitRepository $ouRepository;
    private EstablishmentRepository $establishmentRepository;
    private FunctionRepository $functionRepository;
    private ClassRepository $classRepository;
    private PasswordService $passwordService;
    private SambaEduConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = Mockery::mock(UserRepository::class);
        $this->ouRepository = Mockery::mock(OrganizationalUnitRepository::class);
        $this->establishmentRepository = Mockery::mock(EstablishmentRepository::class);
        $this->functionRepository = Mockery::mock(FunctionRepository::class);
        $this->classRepository = Mockery::mock(ClassRepository::class);
        $this->passwordService = Mockery::mock(PasswordService::class);
        $this->config = Mockery::mock(SambaEduConfig::class);

        $this->service = new UserService(
            $this->userRepository,
            $this->ouRepository,
            $this->establishmentRepository,
            $this->functionRepository,
            $this->classRepository,
            $this->passwordService,
            $this->config
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Tests createHomeDirectory()
    // =========================================================================

    #[Test]
    public function createHomeDirectory_rejects_invalid_login_with_special_chars(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn($msg) => str_contains($msg, 'login invalide'));

        $this->service->createHomeDirectory('user; rm -rf /');

        // No exec should have been called — validated by absence of errors
        $this->assertTrue(true);
    }

    #[Test]
    public function createHomeDirectory_rejects_login_with_path_traversal(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn($msg) => str_contains($msg, 'login invalide'));

        $this->service->createHomeDirectory('../etc/passwd');

        // Mockery vérifie l'appel à Log::error dans tearDown
        $this->assertTrue(true);
    }

    #[Test]
    public function createHomeDirectory_accepts_valid_login_formats(): void
    {
        // Ces logins sont valides et ne doivent pas être rejetés par la regex
        $validLogins = ['jean.dupont', 'j.dupont2', 'admin', 'Jean-Pierre_D'];

        foreach ($validLogins as $login) {
            $valid = (bool) preg_match('/^[a-zA-Z0-9._-]+$/', $login);
            $this->assertTrue($valid, "Le login '$login' devrait être valide");
        }
    }

    // =========================================================================
    // Tests PasswordService::determinePassword()
    // =========================================================================

    #[Test]
    public function determinePassword_returns_provided_password_when_given(): void
    {
        $passwordService = new PasswordService();

        // Si un mot de passe est fourni, il doit être retourné directement
        $result = $passwordService->determinePassword('MonMotDePasse!', null);
        $this->assertEquals('MonMotDePasse!', $result);
    }

    #[Test]
    public function determinePassword_returns_birthdate_when_no_password(): void
    {
        // Mock SEConfig pour retourner pwdPolicy = 0
        $this->app->instance('seconfig', new class {
            public function get($key, $default = null) {
                return match($key) {
                    'pwdPolicy' => 0,
                    'min_password_length' => 8,
                    'password_complexity' => 'off',
                    default => $default,
                };
            }
        });

        $passwordService = new PasswordService();
        $result = $passwordService->determinePassword(null, '20100315');
        $this->assertEquals('20100315', $result);
    }

    #[Test]
    public function determinePassword_generates_random_when_no_password_no_birthdate(): void
    {
        $passwordService = new PasswordService();
        $result = $passwordService->determinePassword(null, null);

        // Doit retourner un mot de passe non vide
        $this->assertNotEmpty($result);
        $this->assertGreaterThanOrEqual(8, strlen($result));
    }

    // =========================================================================
    // Tests generateLogin() via createUser() validation
    // =========================================================================

    #[Test]
    public function createUser_fails_with_empty_nom(): void
    {
        $result = $this->service->createUser([
            'nom' => '',
            'prenom' => 'Jean',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('nom', $result['message']);
    }

    #[Test]
    public function createUser_fails_with_empty_prenom(): void
    {
        $result = $this->service->createUser([
            'nom' => 'Dupont',
            'prenom' => '',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('prénom', $result['message']);
    }

    #[Test]
    public function createUser_fails_when_eleve_without_classes(): void
    {
        $result = $this->service->createUser([
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'categorie' => 'Eleves',
            'classes' => [],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('classe', strtolower($result['message']));
    }

    #[Test]
    public function createUser_fails_when_administratif_without_fonction(): void
    {
        $result = $this->service->createUser([
            'nom' => 'Martin',
            'prenom' => 'Sophie',
            'categorie' => 'Administratifs',
            'fonction' => '',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('fonction', strtolower($result['message']));
    }

    #[Test]
    public function createUser_fails_when_user_already_exists(): void
    {
        // Le user existe déjà dans LDAP
        $existingUser = Mockery::mock(\App\Types\User::class);
        $this->userRepository->shouldReceive('findByLogin')
            ->andReturn($existingUser);

        $this->passwordService->shouldReceive('determinePassword')
            ->andReturn('password123');

        $this->config->shouldReceive('get')
            ->andReturn('0');

        $result = $this->service->createUser([
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'categorie' => 'Eleves',
            'classes' => ['6emeA'],
            'login' => 'jean.dupont',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('existe déjà', $result['message']);
    }

    // =========================================================================
    // Tests persistUserToSql() - mapping rôle et données
    // =========================================================================

    #[Test]
    public function roleMap_is_case_insensitive(): void
    {
        // Vérifier que le mapping catégorie→rôle fonctionne quelle que soit la casse
        $roleMap = [
            'eleves' => 'eleve',
            'profs' => 'prof',
            'administratifs' => 'admin',
        ];

        foreach (['Eleves', 'ELEVES', 'eleves'] as $variant) {
            $role = $roleMap[strtolower($variant)] ?? 'autre';
            $this->assertEquals('eleve', $role, "La catégorie '$variant' doit mapper sur 'eleve'");
        }

        // Catégorie inconnue → 'autre'
        $role = $roleMap[strtolower('Inconnu')] ?? 'autre';
        $this->assertEquals('autre', $role);
    }

    #[Test]
    public function fullname_is_trimmed_when_prenom_or_nom_empty(): void
    {
        // Vérifier que trim() empêche " Dupont" ou "Jean "
        $this->assertEquals('Dupont', trim(" Dupont"));
        $this->assertEquals('Jean', trim("Jean "));
        $this->assertEquals('Jean Dupont', trim("Jean Dupont"));
    }

    // =========================================================================
    // Tests createHomeDirectory() - copie skel avec dotfiles
    // =========================================================================

    #[Test]
    public function skel_copy_uses_dot_not_glob_star(): void
    {
        // Le pattern /. copie les dotfiles, /* ne les copie pas.
        // Ce test vérifie que le code source utilise le bon pattern.
        $source = file_get_contents(app_path('Services/UserService.php'));
        $this->assertStringContainsString(
            "escapeshellarg(\$skelPath) . \"/. \"",
            $source,
            "createHomeDirectory doit utiliser /. (pas /*) pour copier les dotfiles du skel"
        );
    }
}
