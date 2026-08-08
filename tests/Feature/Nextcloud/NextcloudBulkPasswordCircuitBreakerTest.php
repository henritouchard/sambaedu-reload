<?php

declare(strict_types=1);

namespace Tests\Feature\Nextcloud;

use App\Config\SambaEduConfig;
use App\LdapModels\LdapUser;
use App\Models\User;
use App\Repositories\ClassRepository;
use App\Repositories\EstablishmentRepository;
use App\Repositories\FunctionRepository;
use App\Repositories\OrganizationalUnitRepository;
use App\Repositories\UserRepository;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\PasswordService;
use App\Services\ServiceCredentials;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.1, revue #5 — LE DISJONCTEUR DE LOT DE LA PROPAGATION DE MOT DE PASSE.
 *
 * `bulkResetPasswords` s'exécute **dans le cycle d'une requête HTTP** (la modale
 * de réinitialisation l'appelle synchrone). Chaque propagation vers une instance
 * injoignable coûte le délai complet du client Nextcloud — 15 secondes. Trente
 * élèves feraient sept minutes et demie d'attente, très au-delà du
 * `max_execution_time` et de tout mandataire : le fail-soft protège chaque
 * itération, il ne protège pas le budget de temps GLOBAL.
 *
 * D'où le disjoncteur : la première défaillance d'infrastructure ferme la
 * propagation pour tout le reste du lot, avec UN seul avertissement. Et,
 * invariant qui prime sur tout le reste : **la réinitialisation AD n'est jamais
 * affectée**.
 */
class NextcloudBulkPasswordCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const LOGINS = ['eleve1', 'eleve2', 'eleve3', 'eleve4', 'eleve5'];

    private UserService $service;

    private UserRepository $userRepository;

    private PasswordService $passwordService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = Mockery::mock(UserRepository::class);
        $this->passwordService = Mockery::mock(PasswordService::class);

        $config = Mockery::mock(SambaEduConfig::class);
        $config->shouldReceive('getCurrentEstablishmentCode')->andReturn(null)->byDefault();

        $this->service = new UserService(
            $this->userRepository,
            Mockery::mock(OrganizationalUnitRepository::class),
            Mockery::mock(EstablishmentRepository::class),
            Mockery::mock(FunctionRepository::class),
            Mockery::mock(ClassRepository::class),
            $this->passwordService,
            $config,
        );

        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'sekret');

        Gate::shouldReceive('allows')->with('user.password.init')->andReturn(true);

        $this->passwordService->shouldReceive('generateRandomPassword')->andReturn('MotDePasse-42');

        foreach (self::LOGINS as $login) {
            $user = User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
            // L'identité Nextcloud est résolue : la propagation est donc ARMÉE
            // pour chacun de ces comptes (double condition de l'AC7 tenue).
            $user->nextcloud_user_id = $login . '-nc';
            $user->saveQuietly();

            $this->userRepository->shouldReceive('findLdapModelByLogin')
                ->with($login)
                ->andReturn($this->ldapUserMock($login));
            $this->userRepository->shouldReceive('invalidateCache')->with($login)->andReturn();
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function ldapUserMock(string $login): LdapUser
    {
        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('getDn')->andReturn('CN=' . $login . ',OU=Users,DC=example,DC=com');
        $ldapUser->shouldReceive('getLogin')->andReturn($login);
        $ldapUser->shouldReceive('getFirstAttribute')->andReturn(null);
        $ldapUser->shouldReceive('setAttribute')->andReturnSelf();
        $ldapUser->shouldReceive('save')->andReturn(true);
        $ldapUser->unicodepwd = null;

        return $ldapUser;
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $code, array $data = [], string $message = 'OK'): array
    {
        return ['ocs' => [
            'meta' => ['status' => $code < 300 ? 'ok' : 'failure', 'statuscode' => $code, 'message' => $message],
            'data' => $data,
        ]];
    }

    /**
     * L'instance est injoignable : **UN SEUL** aller-retour est tenté pour tout le
     * lot, **UN SEUL** avertissement est journalisé, et les cinq
     * réinitialisations AD aboutissent.
     */
    #[Test]
    public function an_unreachable_instance_costs_one_attempt_for_the_whole_batch(): void
    {
        // Une tentative qui lève n'est pas ENREGISTRÉE par le double du client
        // HTTP : on compte donc les entrées dans le double lui-même, ce qui est
        // exactement ce que la correction promet de borner.
        $attempts = 0;
        Http::fake(['*' => static function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('cURL error 28: timeout');
        }]);

        Log::spy();

        $result = $this->service->bulkResetPasswords([
            'userIds' => self::LOGINS,
            'groupIds' => [],
        ]);

        // 1. L'invariant qui prime : l'AD n'est PAS affecté.
        self::assertTrue($result['success']);
        self::assertCount(count(self::LOGINS), $result['results']);
        foreach ($result['results'] as $row) {
            self::assertTrue($row['success']);
            self::assertSame('MotDePasse-42', $row['new_password']);
        }

        // 2. Un seul appel réseau tenté, pas cinq.
        self::assertSame(1, $attempts, 'le disjoncteur doit s\'ouvrir dès la première défaillance réseau');

        // 3. Un seul avertissement, et il DIT combien de comptes n'ont pas été
        //    propagés — jamais un avertissement par utilisateur.
        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message, array $context = []): bool => $message === 'nextcloud.user.password.batch_skipped'
                && ($context['skipped'] ?? null) === count(self::LOGINS))
            ->once();

        Log::shouldNotHaveReceived('warning', ['nextcloud.user.password.failed', Mockery::any()]);
    }

    /**
     * Un refus propre à UN compte (compte LDAP non modifiable) **n'ouvre pas** le
     * disjoncteur : ce n'est pas une panne d'infrastructure, et les suivants
     * doivent être tentés.
     */
    #[Test]
    public function a_per_account_refusal_does_not_open_the_breaker(): void
    {
        Http::fake(['*' => Http::response(self::ocs(997, [], 'Cannot set password for LDAP user'), 200)]);

        $result = $this->service->bulkResetPasswords([
            'userIds' => self::LOGINS,
            'groupIds' => [],
        ]);

        self::assertTrue($result['success']);
        Http::assertSentCount(count(self::LOGINS));
    }
}
