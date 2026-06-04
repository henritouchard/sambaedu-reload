<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\User;
use App\Services\AuthenticationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Tests Feature — synchro password_changed_at dans AuthenticationService.
 *
 * Teste la méthode privée `persistPasswordChangedAt(string $login, int $pwdLastSet)`
 * en isolation via réflexion, pour éviter la complexité du mock ldap_bind.
 *
 * Story 14.4 — AC13 / Tâche 3.4
 */
class AuthenticationServicePasswordChangedAtTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    private AuthenticationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionSchema();

        // Construire le service avec des stubs minimaux
        $userRepoMock = $this->createMock(\App\Repositories\UserRepository::class);
        $sambaConfigMock = $this->createMock(\App\Config\SambaEduConfig::class);
        // Story 21.2 — AuthenticationService prend désormais un AdCredentialValidator
        // injecté (bind LDAP extrait derrière une interface). Stub neutre ici :
        // ces tests exercent `persistPasswordChangedAt` en isolation, sans bind.
        $credentialValidatorMock = $this->createMock(\App\Contracts\Ad\AdCredentialValidator::class);

        $this->service = new AuthenticationService($userRepoMock, $sambaConfigMock, $credentialValidatorMock);
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function callPersistPasswordChangedAt(string $login, int $pwdLastSet): void
    {
        $method = new ReflectionMethod($this->service, 'persistPasswordChangedAt');
        $method->setAccessible(true);
        $method->invoke($this->service, $login, $pwdLastSet);
    }

    private function callResolvePwdLastSetRaw(mixed $rawValue): int
    {
        $method = new ReflectionMethod($this->service, 'resolvePwdLastSetRaw');
        $method->setAccessible(true);

        return $method->invoke($this->service, $rawValue);
    }

    // =========================================================================
    // AC13 — cas 1 : pwdLastSet == 0 → password_changed_at reste NULL
    // =========================================================================

    #[Test]
    public function it_sets_null_when_pwdLastSet_is_zero(): void
    {
        $user = User::query()->create([
            'login' => 'testuser-zero',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => Carbon::now(), // Valeur pré-existante
        ]);

        $this->callPersistPasswordChangedAt('testuser-zero', 0);

        $user->refresh();
        $this->assertNull($user->password_changed_at, 'pwdLastSet=0 doit mettre password_changed_at à NULL');
    }

    // =========================================================================
    // AC13 — cas 2 : pwdLastSet > 0 (valeur FILETIME valide) → Carbon UTC
    // =========================================================================

    #[Test]
    public function it_converts_filetime_to_carbon_when_pwdLastSet_is_positive(): void
    {
        // pwdLastSet = 133000000000000000 → Unix = (133e16 - 116444736e8) / 1e7 = 1655526400
        // Carbon UTC : 2022-06-18 08:00:00 UTC
        $user = User::query()->create([
            'login' => 'testuser-filetime',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => null,
        ]);

        $this->callPersistPasswordChangedAt('testuser-filetime', 133000000000000000);

        $user->refresh();
        $this->assertNotNull($user->password_changed_at);
        $this->assertSame('2022-06-18', $user->password_changed_at->format('Y-m-d'));
    }

    // =========================================================================
    // AC13 — cas 3 : pwdLastSet == -1 → now() best-effort
    // =========================================================================

    #[Test]
    public function it_sets_approx_now_when_pwdLastSet_is_minus_one(): void
    {
        $before = Carbon::now()->subSecond();

        $user = User::query()->create([
            'login' => 'testuser-minus-one',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => null,
        ]);

        $this->callPersistPasswordChangedAt('testuser-minus-one', -1);

        $after = Carbon::now()->addSecond();

        $user->refresh();
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(
            $user->password_changed_at->gte($before),
            'password_changed_at doit être >= now()-1s'
        );
        $this->assertTrue(
            $user->password_changed_at->lte($after),
            'password_changed_at doit être <= now()+1s'
        );
    }

    // =========================================================================
    // AC13 — cas 4 : Carbon (LdapRecord auto-cast) → e2e Carbon → ~now()
    // Post-review #1 / #5 : couvre le pipeline complet
    // Carbon → resolvePwdLastSetRaw → -1 → pwdLastSetToCarbon(-1) → now() → SQL.
    // Tolérance 5s (le test peut être lent en CI).
    // =========================================================================

    #[Test]
    public function it_sets_approx_now_when_pwdLastSet_is_a_carbon_instance(): void
    {
        $user = User::query()->create([
            'login' => 'testuser-carbon',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => null,
        ]);

        // Simuler le retour LdapRecord auto-casté : un Carbon (date passée arbitraire).
        $carbonRaw = Carbon::parse('2022-06-18');
        $resolved = $this->callResolvePwdLastSetRaw($carbonRaw);

        $this->assertSame(-1, $resolved, 'Carbon doit être mappé vers -1 (D7 cas 3, post-review #1)');

        $before = Carbon::now()->subSeconds(5);
        $this->callPersistPasswordChangedAt('testuser-carbon', $resolved);
        $after = Carbon::now()->addSeconds(5);

        $user->refresh();
        $this->assertNotNull(
            $user->password_changed_at,
            'Carbon ne doit PAS produire NULL silencieux (bug review #1 corrigé)'
        );
        $this->assertTrue(
            $user->password_changed_at->gte($before),
            'password_changed_at doit être >= now()-5s'
        );
        $this->assertTrue(
            $user->password_changed_at->lte($after),
            'password_changed_at doit être <= now()+5s'
        );
    }

    // =========================================================================
    // AC13 — cas 5 : login inexistant → update retourne 0, pas d'exception
    // Post-review #7 : assertion plus stricte sur le wording du log « aucune row »
    // pour discriminer du log « value synced » émis dans les cas nominaux.
    // =========================================================================

    #[Test]
    public function it_silently_handles_nonexistent_login_without_exception(): void
    {
        // Le user n'existe pas en SQL — on s'assure qu'aucune exception n'est levée
        Log::spy();

        $this->callPersistPasswordChangedAt('login-inexistant-xyz', 133000000000000000);

        // Post-review #7 : on cible le log debug spécifique « aucune row » émis
        // quand l'update retourne 0. Le wording vient de persistPasswordChangedAt
        // dans AuthenticationService (cf. 'AuthService: aucune row SQL affectée…').
        Log::shouldHaveReceived('debug')
            ->withArgs(function ($message, $context = []) {
                return str_contains($message, 'aucune row')
                    && isset($context['login'])
                    && $context['login'] === 'login-inexistant-xyz';
            })
            ->once();

        // Aucun user créé (on vérifie que l'update ne crée pas de user fantôme)
        $this->assertNull(User::query()->where('login', 'login-inexistant-xyz')->first());
    }

    // =========================================================================
    // AC13 — sécurité : une exception dans l'update ne fait PAS planter le login
    // Post-review #4 : on FORCE une vraie exception en droppant la colonne
    // password_changed_at, puis on vérifie que :
    //   1. persistPasswordChangedAt() n'a PAS levé d'exception
    //   2. Log::warning a bien été appelé avec un contexte cohérent (login + erreur)
    // =========================================================================

    #[Test]
    public function it_does_not_throw_when_update_raises_exception(): void
    {
        // Créer un user valide AVANT de dropper la colonne (sinon l'insert plante).
        User::query()->create([
            'login' => 'testuser-exception',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => null,
        ]);

        // Forcer une vraie exception sur l'UPDATE : on drop la colonne
        // password_changed_at — la query Eloquent va lever une exception SQL
        // (column not found / SQL error selon driver).
        Schema::table('users', function ($table) {
            $table->dropColumn('password_changed_at');
        });

        Log::spy();

        try {
            // L'appel ne doit PAS propager d'exception (try/catch \Throwable interne).
            $this->callPersistPasswordChangedAt('testuser-exception', 133000000000000000);
        } catch (\Throwable $e) {
            $this->fail(
                'persistPasswordChangedAt() doit avaler l\'exception (login non bloqué). Levée : ' . $e->getMessage()
            );
        } finally {
            // Restaurer la colonne pour que le tearDown (dropPermissionSchema) ne plante pas.
            Schema::table('users', function ($table) {
                $table->timestamp('password_changed_at')->nullable();
            });
        }

        // Vérifier que le catch a bien été emprunté (Log::warning appelé avec contexte).
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                $hasLogin = isset($context['login']) && $context['login'] === 'testuser-exception';
                $hasErrorInfo = isset($context['error']) || isset($context['message']) || isset($context['exception']);
                return str_contains($message, 'password_changed_at') && $hasLogin && $hasErrorInfo;
            })
            ->once();
    }
}
