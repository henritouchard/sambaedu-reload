<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Config;

use App\Auth\V1\Support\JwtErrorCodes;
use App\Dto\Overlay\OverlayAlert;
use App\Dto\Wallpaper\WallpaperContext;
use App\Models\OverlaySignal;
use App\Services\Overlay\OverlayService;
use App\Services\Overlay\OverlaySignalBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * POC overlay — `GET /api/v1/workstation-config/overlay`.
 *
 * Iso-pattern auth `WallpaperApiV1Test` (16.13) :
 *  - 401 sans Authorization / JWT expiré / mauvais tier
 *  - 404 workstation inconnu
 *  - 200 identité + machine + canal `posted` (persistance, expiration,
 *    ciblage broadcast, garde-fou submarine).
 *
 * Le `OverlaySignalBuilder` est neutralisé (sans services) pour des
 * assertions déterministes sur les alertes `posted` ; les signaux dérivés
 * sont couverts par `OverlaySignalBuilderTest` (unit).
 */
class OverlayApiV1Test extends TestCase
{
    use IssuesWorkstationJwt;
    use SeedsWorkstationConfig;

    private const URL = '/api/v1/workstation-config/overlay';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
        $this->seedWorkstationContextSchemas();
        $this->ensureOverlaySignalsTable();
        Cache::store('array')->flush();

        if (empty(config('app.key'))) {
            config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        }

        // Builder neutre (sans services) → buildDerived() == [] de façon
        // déterministe ; on teste ici uniquement identité + canal posted.
        $this->app->instance(OverlaySignalBuilder::class, new OverlaySignalBuilder(null, null));
    }

    private function ensureOverlaySignalsTable(): void
    {
        if (config('database.default') !== 'sqlite' || Schema::hasTable('overlay_signals')) {
            return;
        }
        Schema::create('overlay_signals', function (Blueprint $t): void {
            $t->id();
            $t->string('kind', 32)->default('notice');
            $t->string('severity', 16)->default('info');
            $t->string('title');
            $t->text('text');
            $t->string('workstation_uuid', 36)->nullable()->index();
            $t->unsignedBigInteger('workstation_group_id')->nullable()->index();
            $t->string('user_login')->nullable()->index();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
    }

    private function authHeaders(?array $claims = null): array
    {
        $emitted = $this->issueTestJwt($claims ?? ['sub' => $this->seededWorkstationUuid]);

        return ['Authorization' => 'Bearer ' . $emitted['token']];
    }

    private function pollUrl(): string
    {
        return self::URL . '?os=linux&user=' . $this->seededUserLogin;
    }

    #[Test]
    public function missing_authorization_returns_401_missing(): void
    {
        $this->getJson(self::URL)
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_MISSING]);
    }

    #[Test]
    public function expired_jwt_returns_401_expired(): void
    {
        $this->seedWorkstationContext();

        $headers = $this->authHeaders([
            'sub' => $this->seededWorkstationUuid,
            'iat' => Carbon::now()->subDays(2)->getTimestamp(),
            'exp' => Carbon::now()->subDay()->getTimestamp(),
        ]);

        $this->getJson(self::URL, $headers)
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_EXPIRED]);
    }

    #[Test]
    public function wrong_tier_returns_401_wrong_tier(): void
    {
        $headers = $this->authHeaders([
            'sub' => $this->seededWorkstationUuid,
            'tier' => 'controlhub',
        ]);

        $this->getJson(self::URL, $headers)
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_WRONG_TIER]);
    }

    #[Test]
    public function unknown_workstation_returns_404(): void
    {
        $headers = $this->authHeaders(['sub' => '99999999-9999-4999-9999-999999999999']);

        $this->getJson(self::URL . '?os=linux', $headers)
            ->assertStatus(404)
            ->assertJson(['error' => 'workstation_not_found']);
    }

    #[Test]
    public function happy_path_returns_identity_and_machine(): void
    {
        $this->seedWorkstationContext();

        $this->getJson($this->pollUrl(), $this->authHeaders())
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('schema', config('overlay.schema'))
            ->assertJsonPath('identity.login', $this->seededUserLogin)
            ->assertJsonPath('machine.name', $this->seededMachineName)
            ->assertJsonPath('machine.room', $this->seededSalleName)
            ->assertJsonPath('machine.os', 'linux')
            ->assertJsonPath('alerts', []);
    }

    #[Test]
    public function posted_signal_targeting_workstation_and_user_appears(): void
    {
        $this->seedWorkstationContext();

        OverlaySignal::create([
            'kind' => 'notice',
            'severity' => 'info',
            'title' => 'Maintenance prévue',
            'text' => 'Sauvegardez avant 18h.',
            'workstation_uuid' => $this->seededWorkstationUuid,
            'user_login' => $this->seededUserLogin,
            'expires_at' => Carbon::now()->addHour(),
        ]);

        $this->getJson($this->pollUrl(), $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('alerts.0.source', 'posted')
            ->assertJsonPath('alerts.0.kind', 'notice')
            ->assertJsonPath('alerts.0.title', 'Maintenance prévue')
            ->assertJsonCount(1, 'alerts');
    }

    #[Test]
    public function broadcast_signal_without_target_is_visible(): void
    {
        $this->seedWorkstationContext();

        OverlaySignal::create([
            'kind' => 'notice',
            'severity' => 'warning',
            'title' => 'Annonce générale',
            'text' => 'À tous les postes.',
            'workstation_uuid' => null,
            'user_login' => null,
            'expires_at' => null,
        ]);

        $this->getJson($this->pollUrl(), $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('alerts.0.title', 'Annonce générale')
            ->assertJsonCount(1, 'alerts');
    }

    #[Test]
    public function expired_posted_signal_is_absent(): void
    {
        $this->seedWorkstationContext();

        OverlaySignal::create([
            'kind' => 'notice',
            'severity' => 'info',
            'title' => 'Périmé',
            'text' => 'Ne doit pas apparaître.',
            'workstation_uuid' => $this->seededWorkstationUuid,
            'user_login' => null,
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->getJson($this->pollUrl(), $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('alerts', []);
    }

    #[Test]
    public function signal_targeting_another_workstation_is_absent(): void
    {
        $this->seedWorkstationContext();

        OverlaySignal::create([
            'kind' => 'notice',
            'severity' => 'info',
            'title' => 'Autre poste',
            'text' => 'Pas pour nous.',
            'workstation_uuid' => '00000000-0000-4000-8000-000000000000',
            'user_login' => null,
            'expires_at' => null,
        ]);

        $this->getJson($this->pollUrl(), $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('alerts', []);
    }

    #[Test]
    public function submarine_hides_posted_remote_control_signal(): void
    {
        $this->seedWorkstationContext();
        config()->set('sambaedu.veyon_submarine', true);

        OverlaySignal::create([
            'kind' => 'remote_control',
            'severity' => 'critical',
            'title' => 'Prise de contrôle à distance en cours',
            'text' => 'Veyon actif.',
            'workstation_uuid' => $this->seededWorkstationUuid,
            'user_login' => null,
            'expires_at' => Carbon::now()->addHour(),
        ]);

        $this->getJson($this->pollUrl(), $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('alerts', []);
    }

    #[Test]
    public function post_signal_remote_control_blocked_when_submarine_on(): void
    {
        config()->set('sambaedu.veyon_submarine', true);

        $result = app(OverlayService::class)->postSignal(
            'remote_control', 'critical', 'Veyon', 'x', $this->seededWorkstationUuid,
        );

        self::assertNull($result);
        $this->assertDatabaseCount('overlay_signals', 0);
    }

    #[Test]
    public function post_signal_remote_control_stored_when_submarine_off(): void
    {
        config()->set('sambaedu.veyon_submarine', false);

        $result = app(OverlayService::class)->postSignal(
            'remote_control', 'critical', 'Veyon', 'x', $this->seededWorkstationUuid,
        );

        self::assertNotNull($result);
        $this->assertDatabaseCount('overlay_signals', 1);
    }

    #[Test]
    public function signal_targeting_workstation_only_appears_for_any_user(): void
    {
        $this->seedWorkstationContext();

        OverlaySignal::create([
            'kind' => 'notice', 'severity' => 'info',
            'title' => 'Pour ce poste', 'text' => 'Tout user.',
            'workstation_uuid' => $this->seededWorkstationUuid,
            'user_login' => null, 'expires_at' => null,
        ]);

        $this->getJson(self::URL . '?os=linux&user=quelquun', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('alerts.0.title', 'Pour ce poste')
            ->assertJsonCount(1, 'alerts');
    }

    #[Test]
    public function signal_targeting_user_only_follows_the_user_and_excludes_others(): void
    {
        $this->seedWorkstationContext();

        OverlaySignal::create([
            'kind' => 'notice', 'severity' => 'info',
            'title' => 'Pour jdoe', 'text' => 'Quel que soit le poste.',
            'workstation_uuid' => null,
            'user_login' => $this->seededUserLogin, 'expires_at' => null,
        ]);

        $this->getJson($this->pollUrl(), $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('alerts.0.title', 'Pour jdoe');

        $this->getJson(self::URL . '?os=linux&user=autre', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('alerts', []);
    }

    #[Test]
    public function derived_alerts_precede_posted_alerts(): void
    {
        $this->seedWorkstationContext();

        // Builder stub : une alerte dérivée déterministe.
        $this->app->instance(OverlaySignalBuilder::class, new class extends OverlaySignalBuilder {
            public function buildDerived(WallpaperContext $ctx): array
            {
                return [new OverlayAlert('quota', OverlayAlert::SOURCE_DERIVED, 'quota', 'warning', 'Quota', 'q')];
            }
        });

        OverlaySignal::create([
            'kind' => 'notice', 'severity' => 'info',
            'title' => 'Posté', 'text' => 'p',
            'workstation_uuid' => $this->seededWorkstationUuid,
            'user_login' => null, 'expires_at' => null,
        ]);

        $this->getJson($this->pollUrl(), $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('alerts.0.source', 'derived')
            ->assertJsonPath('alerts.1.source', 'posted')
            ->assertJsonCount(2, 'alerts');
    }

    #[Test]
    public function signal_targeting_salle_appears_for_workstation_in_that_salle(): void
    {
        $seed = $this->seedWorkstationContext();

        OverlaySignal::create([
            'kind' => 'notice', 'severity' => 'info',
            'title' => 'Pour la salle', 'text' => 'x',
            'workstation_uuid' => null,
            'workstation_group_id' => $seed['group']->id,
            'user_login' => null, 'expires_at' => null,
        ]);

        $this->getJson($this->pollUrl(), $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('alerts.0.title', 'Pour la salle')
            ->assertJsonCount(1, 'alerts');
    }

    #[Test]
    public function signal_targeting_another_salle_is_absent(): void
    {
        $this->seedWorkstationContext();

        OverlaySignal::create([
            'kind' => 'notice', 'severity' => 'info',
            'title' => 'Autre salle', 'text' => 'x',
            'workstation_uuid' => null,
            'workstation_group_id' => 999999,
            'user_login' => null, 'expires_at' => null,
        ]);

        $this->getJson($this->pollUrl(), $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('alerts', []);
    }
}
