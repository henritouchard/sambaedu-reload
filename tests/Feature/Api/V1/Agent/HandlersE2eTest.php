<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Enums\AgentResourceStatus;
use App\Models\AgentReportEvent;
use App\Models\AgentResourceState;
use App\Models\OverlaySignal;
use App\Models\User;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Enrollment\TokenRotationService;
use App\Services\Agent\StateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature e2e handlers — Story 24.4 (AC7).
 *
 * LA boucle complète avec des items RÉELS : `GET /state` sert des règles
 * wallpaper + overlay réelles (tables métier), puis `POST /report` rapporte
 * les 4 statuts du contrat avec les hashes EXACTEMENT comme l'agent les
 * construit (exclusive = hash d'item verbatim ; aggregate = empreinte
 * SHA-256 de la concaténation des hashes opaques, ordre serveur — décision
 * n° 7). Vérifie les comportements 24.1 (états upsertés, événements sur
 * transition, rapport identique = zéro événement) SUR CES items réels.
 *
 * Le serveur ne connaît PAS la convention d'empreinte d'agrégat (il compare
 * des chaînes opaques au rapport précédent) — ces tests prouvent qu'elle
 * traverse l'ingestion sans friction.
 */
final class HandlersE2eTest extends TestCase
{
    use RefreshDatabase;

    private const STATE_ROUTE = '/api/v1/agent/state';

    private const REPORT_ROUTE = '/api/v1/agent/report';

    private TokenRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);
    }

    private function state(string $token, string $query = ''): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson(self::STATE_ROUTE . $query);
    }

    /** @param array<string, mixed> $payload */
    private function report(string $token, array $payload): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson(self::REPORT_ROUTE, $payload);
    }

    /**
     * Le décor complet de la démo palier 1 : poste en salle, user avec
     * fullname, wallpaper broadcast AVEC asset, signal overlay posté.
     *
     * @return array{ws: Workstation, token: string, user: User, room: WorkstationGroup, asset: WallpaperAsset}
     */
    private function demoSetup(): array
    {
        $ws = Workstation::factory()->create();
        $room = WorkstationGroup::factory()->create();
        $ws->groups()->attach($room->id);
        $token = $this->service->issueFor($ws);
        $user = User::factory()->create(['fullname' => 'Marie Dupont']);

        $asset = WallpaperAsset::factory()->create();
        Wallpaper::factory()->default()->create(['asset_id' => $asset->id]);

        OverlaySignal::create([
            'kind' => 'notice', 'severity' => 'info',
            'title' => 'Maintenance', 'text' => 'Ce soir 18h',
        ]);

        return [
            'ws' => $ws->refresh(),
            'token' => $token,
            'user' => $user,
            'room' => $room,
            'asset' => $asset,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>> items session d'un type donné
     */
    private function sessionItemsOfType(array $state, string $type): array
    {
        return array_values(array_filter(
            $state[StateContract::SCOPE_SESSION],
            fn (array $item): bool => $item['type'] === $type,
        ));
    }

    /**
     * Empreinte d'agrégat EXACTEMENT comme l'agent la construit
     * (ConvergenceEngine::Get-AggregateHash) : SHA-256 hex de la
     * concaténation des hashes opaques, dans l'ordre du payload serveur.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function aggregateHash(array $items): string
    {
        return hash('sha256', implode('', array_column($items, 'hash')));
    }

    /**
     * Rapport conforme au contrat §6, identité déclarée alignée (zéro bruit
     * identity_mismatch).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function reportPayload(Workstation $ws, array $items): array
    {
        return [
            'schema' => 'se5.desired-state/v1',
            'generated_at' => now()->utc()->toIso8601String(),
            'agent_version' => '1.0.0',
            'workstation' => ['hostname' => $ws->name, 'uuid' => $ws->uuid],
            'items' => $items,
        ];
    }

    // ── L'état servi porte les règles réelles (aller) ─────────────────────

    #[Test]
    public function state_serves_real_wallpaper_rule_and_overlay_with_identity_first(): void
    {
        $d = $this->demoSetup();

        $state = $this->state($d['token'], '?user=' . $d['user']->login)->assertOk()->json();

        // Wallpaper : payload {asset, checksum} de la biblio (figé 23.4).
        $wallpapers = $this->sessionItemsOfType($state, 'wallpaper');
        self::assertCount(1, $wallpapers);
        self::assertSame('exclusive', $wallpapers[0]['semantics']);
        self::assertSame('default', $wallpapers[0]['mode']);
        self::assertSame(
            ['asset' => $d['asset']->filename, 'checksum' => $d['asset']->checksum],
            $wallpapers[0]['payload'],
        );

        // Overlay : item identity EN TÊTE (sourceId 0 < ids signaux) puis le
        // signal posté — l'union aggregate dans l'ordre serveur.
        $overlays = $this->sessionItemsOfType($state, 'overlay');
        self::assertCount(2, $overlays);
        self::assertSame('aggregate', $overlays[0]['semantics']);
        self::assertSame('strict', $overlays[0]['mode']);
        self::assertSame(
            [
                'kind' => 'identity',
                'login' => $d['user']->login,
                'fullname' => 'Marie Dupont',
                'room' => $d['room']->name,
            ],
            $overlays[0]['payload'],
        );
        self::assertSame('Maintenance', $overlays[1]['payload']['title']);
    }

    #[Test]
    public function machine_only_state_has_no_identity_item(): void
    {
        $d = $this->demoSetup();

        $state = $this->state($d['token'])->assertOk()->json();

        $kinds = array_column(
            array_column($this->sessionItemsOfType($state, 'overlay'), 'payload'),
            'kind',
        );
        self::assertNotContains('identity', $kinds);
    }

    // ── La boucle complète : state → report 4 statuts → états/événements ──

    #[Test]
    public function full_loop_reports_real_items_through_all_four_statuses(): void
    {
        $d = $this->demoSetup();
        $ws = $d['ws'];

        $state = $this->state($d['token'], '?user=' . $d['user']->login)->assertOk()->json();
        $wallpaperHash = $this->sessionItemsOfType($state, 'wallpaper')[0]['hash'];
        $overlayHash = $this->aggregateHash($this->sessionItemsOfType($state, 'overlay'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $overlayHash);

        // Rapport 1 : premier passage — wallpaper appliqué (drift), overlay
        // écrit (drift). Hash wallpaper VERBATIM, overlay = empreinte.
        $this->report($d['token'], $this->reportPayload($ws, [
            ['type' => 'wallpaper', 'status' => 'drift', 'hash' => $wallpaperHash],
            ['type' => 'overlay', 'status' => 'drift', 'hash' => $overlayHash],
        ]))->assertOk()->assertJsonPath('counts.drift', 2);

        self::assertSame(2, AgentResourceState::where('workstation_id', $ws->id)->count());
        $wallpaperState = AgentResourceState::where('workstation_id', $ws->id)
            ->where('type', 'wallpaper')->firstOrFail();
        self::assertSame(AgentResourceStatus::Drift, $wallpaperState->status);
        self::assertSame($wallpaperHash, $wallpaperState->hash);
        $overlayState = AgentResourceState::where('workstation_id', $ws->id)
            ->where('type', 'overlay')->firstOrFail();
        self::assertSame($overlayHash, $overlayState->hash);
        // Premier rapport ≠ compliant → 2 événements.
        self::assertSame(2, AgentReportEvent::where('workstation_id', $ws->id)->count());

        // Rapport 2 : convergence (compliant) + dérive humaine wallpaper
        // tolérée plus tard — ici les transitions drift → compliant.
        $this->report($d['token'], $this->reportPayload($ws, [
            ['type' => 'wallpaper', 'status' => 'compliant', 'hash' => $wallpaperHash],
            ['type' => 'overlay', 'status' => 'compliant', 'hash' => $overlayHash],
        ]))->assertOk()->assertJsonPath('counts.compliant', 2);
        $eventsAfterConvergence = AgentReportEvent::where('workstation_id', $ws->id)->count();
        self::assertSame(4, $eventsAfterConvergence);

        // Rapport 3 : mode default — l'élève change son fond (drifted_allowed)
        // + le handler overlay échoue (error, detail OBLIGATOIRE).
        $this->report($d['token'], $this->reportPayload($ws, [
            ['type' => 'wallpaper', 'status' => 'drifted_allowed', 'hash' => $wallpaperHash],
            [
                'type' => 'overlay', 'status' => 'error', 'hash' => $overlayHash,
                'detail' => 'ecriture overlay.json refusee (profil verrouille)',
            ],
        ]))->assertOk()
            ->assertJsonPath('counts.drifted_allowed', 1)
            ->assertJsonPath('counts.error', 1);

        self::assertSame(
            AgentResourceStatus::DriftedAllowed,
            $wallpaperState->refresh()->status,
        );
        $overlayState->refresh();
        self::assertSame(AgentResourceStatus::Error, $overlayState->status);
        self::assertSame('ecriture overlay.json refusee (profil verrouille)', $overlayState->detail);
        self::assertSame(6, AgentReportEvent::where('workstation_id', $ws->id)->count());

        // Rapport 4 : IDENTIQUE au 3 → zéro événement, fraîcheur rafraîchie.
        $reportedAt = $overlayState->reported_at;
        $this->travel(1)->hour();
        $this->report($d['token'], $this->reportPayload($ws, [
            ['type' => 'wallpaper', 'status' => 'drifted_allowed', 'hash' => $wallpaperHash],
            [
                'type' => 'overlay', 'status' => 'error', 'hash' => $overlayHash,
                'detail' => 'ecriture overlay.json refusee (profil verrouille)',
            ],
        ]))->assertOk();

        self::assertSame(6, AgentReportEvent::where('workstation_id', $ws->id)->count());
        self::assertTrue($overlayState->refresh()->reported_at->gt($reportedAt));
    }

    #[Test]
    public function aggregate_fingerprint_changes_when_a_signal_is_added(): void
    {
        // La convention d'empreinte est DÉTERMINISTE et sensible au contenu :
        // un signal posté en plus → autre empreinte → le serveur verra un
        // changement (status, hash) au rapport suivant.
        $d = $this->demoSetup();

        $before = $this->aggregateHash($this->sessionItemsOfType(
            $this->state($d['token'], '?user=' . $d['user']->login)->json(),
            'overlay',
        ));

        OverlaySignal::create([
            'kind' => 'notice', 'severity' => 'warning',
            'title' => 'Quota', 'text' => 'Presque plein',
        ]);

        $after = $this->aggregateHash($this->sessionItemsOfType(
            $this->state($d['token'], '?user=' . $d['user']->login)->json(),
            'overlay',
        ));

        self::assertNotSame($before, $after);
        // Et rejouer la même compilation redonne la même empreinte.
        $again = $this->aggregateHash($this->sessionItemsOfType(
            $this->state($d['token'], '?user=' . $d['user']->login)->json(),
            'overlay',
        ));
        self::assertSame($after, $again);
    }
}
