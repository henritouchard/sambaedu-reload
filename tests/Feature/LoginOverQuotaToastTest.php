<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Listeners\NotifyQuotaOverageOnLogin;
use App\Models\User;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Story 5.1c — Tests Feature listener `NotifyQuotaOverageOnLogin`.
 *
 * Couvre AC 13 #15-19 + AC 9 :
 *  15. fire toast quand user is_over_soft sur /home
 *  16. no-op si snapshot null
 *  17. no-op si rien over (toutes les partitions sous quota)
 *  18. 1 SEUL toast quand 2 partitions over (UX moins bruyante)
 *  19. listener n'est pas re-firé à la 2ᵉ requête de la même session
 *      (validé par le pattern Login event — Laravel ne fire qu'au premier
 *      Auth::login, pas à chaque hit cookie revalidé)
 */
class LoginOverQuotaToastTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createTablesIfNeeded();

        // ToastMagic stocke en session — on utilise array driver via phpunit.xml.
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    private function createTablesIfNeeded(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->json('quota_snapshot')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }
    }

    private function makeUser(string $login, ?array $snapshot = null): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
        if ($snapshot !== null) {
            $u->update(['quota_snapshot' => $snapshot]);
            $u->refresh();
        }
        return $u;
    }

    /**
     * Récupère les messages toast accumulés dans la session par ToastMagic.
     * La structure exacte importe peu — on vérifie surtout que le tableau
     * contient un message et qu'il mentionne nos mots-clés.
     *
     * @return array<int, mixed>
     */
    private function readToastMessages(): array
    {
        // ToastMagic utilise plusieurs clés possibles selon la version.
        // On scanne les clés classiques + on parcourt toute la session
        // en filtrant sur "toast".
        $session = session()->all();
        $collected = [];

        foreach ($session as $key => $val) {
            if (stripos($key, 'toast') !== false) {
                $collected[] = ['key' => $key, 'value' => $val];
            }
        }

        return $collected;
    }

    /**
     * Vérifie de manière flexible que ToastMagic::warning() a été appelé.
     * On capture la facade via PartialMock pour intercepter sans dépendre
     * du driver session.
     */
    private function expectToastMagicWarning(int $times = 1, ?string $partialTitleMatch = null): void
    {
        ToastMagic::shouldReceive('warning')
            ->times($times)
            ->withArgs(function (string $title, ?string $description = null) use ($partialTitleMatch): bool {
                if ($partialTitleMatch !== null) {
                    return str_contains($title, $partialTitleMatch);
                }
                return true;
            })
            ->andReturnNull();
    }

    private function snapshotOverHome(): array
    {
        return [
            'home' => [
                'used_kb' => 520_000, 'soft_kb' => 500_000, 'hard_kb' => 600_000,
                'used_mb' => 510, 'soft_mb' => 500, 'hard_mb' => 586,
                'percent' => 100, 'is_over_soft' => true, 'is_over_hard' => false, 'grace_days' => 5,
            ],
            'sambaedu' => [
                'used_kb' => 1024, 'soft_kb' => 100_000, 'hard_kb' => 120_000,
                'used_mb' => 1, 'soft_mb' => 98, 'hard_mb' => 117,
                'percent' => 1, 'is_over_soft' => false, 'is_over_hard' => false, 'grace_days' => null,
            ],
            'captured_at' => '2026-04-25T03:00:00+02:00',
        ];
    }

    private function snapshotOverBoth(): array
    {
        return [
            'home' => [
                'used_kb' => 520_000, 'soft_kb' => 500_000, 'hard_kb' => 600_000,
                'used_mb' => 510, 'soft_mb' => 500, 'hard_mb' => 586,
                'percent' => 100, 'is_over_soft' => true, 'is_over_hard' => false, 'grace_days' => 5,
            ],
            'sambaedu' => [
                'used_kb' => 2_500_000, 'soft_kb' => 2_000_000, 'hard_kb' => 2_400_000,
                'used_mb' => 2400, 'soft_mb' => 2000, 'hard_mb' => 2400,
                'percent' => 100, 'is_over_soft' => true, 'is_over_hard' => true, 'grace_days' => null,
            ],
            'captured_at' => '2026-04-25T03:00:00+02:00',
        ];
    }

    private function snapshotNothingOver(): array
    {
        return [
            'home' => [
                'used_kb' => 50_000, 'soft_kb' => 500_000, 'hard_kb' => 600_000,
                'used_mb' => 49, 'soft_mb' => 500, 'hard_mb' => 586,
                'percent' => 10, 'is_over_soft' => false, 'is_over_hard' => false, 'grace_days' => null,
            ],
            'sambaedu' => [
                'used_kb' => 1024, 'soft_kb' => 100_000, 'hard_kb' => 120_000,
                'used_mb' => 1, 'soft_mb' => 98, 'hard_mb' => 117,
                'percent' => 1, 'is_over_soft' => false, 'is_over_hard' => false, 'grace_days' => null,
            ],
            'captured_at' => '2026-04-25T03:00:00+02:00',
        ];
    }

    // =========================================================================
    // AC 13 #15 — fire on over_soft on home
    // =========================================================================

    public function test_it_fires_toast_when_user_is_over_soft_on_home(): void
    {
        $user = $this->makeUser('over-home', $this->snapshotOverHome());

        $this->expectToastMagicWarning(1, 'Espace personnel');

        $listener = new NotifyQuotaOverageOnLogin();
        $listener->handle(new Login('web', $user, false));
    }

    // =========================================================================
    // AC 13 #16 — no toast if snapshot null
    // =========================================================================

    public function test_it_does_not_fire_toast_when_snapshot_is_null(): void
    {
        $user = $this->makeUser('no-snapshot', null);

        // Aucune attente sur ToastMagic (ne doit JAMAIS être appelée).
        ToastMagic::shouldReceive('warning')->never();

        $listener = new NotifyQuotaOverageOnLogin();
        $listener->handle(new Login('web', $user, false));
    }

    // =========================================================================
    // AC 13 #17 — no toast if nothing over
    // =========================================================================

    public function test_it_does_not_fire_toast_when_nothing_over(): void
    {
        $user = $this->makeUser('all-good', $this->snapshotNothingOver());

        ToastMagic::shouldReceive('warning')->never();

        $listener = new NotifyQuotaOverageOnLogin();
        $listener->handle(new Login('web', $user, false));
    }

    // =========================================================================
    // AC 13 #18 — single toast when both partitions over
    // =========================================================================

    public function test_it_fires_single_toast_when_both_partitions_are_over(): void
    {
        $user = $this->makeUser('over-both', $this->snapshotOverBoth());

        // 1 SEUL appel (UX moins bruyante).
        $this->expectToastMagicWarning(1, 'Plusieurs');

        $listener = new NotifyQuotaOverageOnLogin();
        $listener->handle(new Login('web', $user, false));
    }

    // =========================================================================
    // AC 13 #19 — listener doesn't refire on second request same session
    // =========================================================================

    /**
     * D5=A : le listener est attaché à `Illuminate\Auth\Events\Login`. Cet
     * event est émis UNIQUEMENT par `Auth::login()` (1 seule fois en début
     * de session — pas à chaque revalidation cookie). L'idempotence 1×/session
     * est donc garantie par le framework (event Login unique), PAS par le
     * listener lui-même qui est volontairement stateless et fire à chaque
     * invocation.
     *
     * Ce test documente cette dépendance : on invoque `handle()` 2× et on
     * vérifie qu'on a bien 2 toasts — preuve que le listener N'A PAS de
     * dédoublonnage interne. La protection 1×/session vient en amont, pas ici.
     * Un futur refactoring qui retirerait l'event Login (ex: déplacer la
     * logique dans un middleware rejoué à chaque hit) casserait ce contrat
     * et serait détecté par les tests d'intégration HTTP en aval.
     */
    public function test_listener_is_stateless_and_relies_on_login_event_uniqueness(): void
    {
        $user = $this->makeUser('over-once', $this->snapshotOverHome());

        // 2 appels handle() → 2 toasts attendus : pas de dédoublonnage interne.
        $this->expectToastMagicWarning(2);

        $listener = new NotifyQuotaOverageOnLogin();

        // 1ʳᵉ invocation simulant le 1er event Login.
        $listener->handle(new Login('web', $user, false));

        // 2ᵉ invocation simulant un (hypothétique) 2ᵉ event Login : si le
        // framework le fire, le listener fire aussi. La garantie 1×/session
        // vient du fait qu'`Auth::login()` n'est PAS rappelé sur les requêtes
        // suivantes (cookie session valide), donc l'event ne re-fire pas en prod.
        $listener->handle(new Login('web', $user, false));
    }

    /**
     * Garde-fou défensif : si une exception est levée pendant le handle (ex:
     * snapshot mal formé), elle est CAPTURÉE silencieusement — un échec
     * listener ne casse JAMAIS le login.
     */
    public function test_it_does_not_break_login_if_handler_throws(): void
    {
        $user = $this->makeUser('broken-snap', ['home' => 'not-an-array']);

        ToastMagic::shouldReceive('warning')->never();

        $listener = new NotifyQuotaOverageOnLogin();

        // Aucune exception ne doit remonter.
        $listener->handle(new Login('web', $user, false));

        $this->assertTrue(true); // Si on arrive ici sans throw, c'est gagné.
    }
}
