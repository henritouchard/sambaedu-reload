<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Agent;

use App\Models\AgentEnrollmentRequest;
use App\Models\User;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\EnrollmentCampaign;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests Feature Livewire — surface d'approbation des enrôlements porte 2 (AC5).
 *
 * Liste pending, action approuver (un-clic → armé + toast), action rejeter
 * (modale → statut rejected + toast), bandeau campagne (activer/désactiver).
 * Le composant délègue au service ; on vérifie l'effet de bord en base.
 *
 * Review #4/#5 : les actions mutantes sont gardées par `Gate::authorize(
 * 'computer.install')` (double protection iso-pattern projet). Les tests
 * agissent comme un admin muni de la permission, et un test négatif vérifie le
 * 403 pour un utilisateur sans droit (`resolved_by` réellement renseigné).
 */
class EnrollmentRequestsSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc-settings.agent._partials.enrollment-requests';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'computer.install', 'guard_name' => 'web']);

        $this->admin = User::query()->create(['login' => 'enroll-admin', 'role' => 'prof', 'is_active' => true]);
        $this->admin->givePermissionTo('computer.install');
        $this->actingAs($this->admin);
    }

    private function pending(array $attributes = []): AgentEnrollmentRequest
    {
        return AgentEnrollmentRequest::query()->create(array_merge([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'PC-MIGRE',
            'uuid' => 'u-1',
            'status' => AgentEnrollmentRequest::STATUS_PENDING,
            'last_seen_at' => now(),
        ], $attributes));
    }

    #[Test]
    public function lists_pending_requests(): void
    {
        $this->pending(['hostname' => 'PC-MIGRE']);
        // Une demande déjà résolue ne doit pas apparaître.
        $this->pending(['hostname' => 'PC-RESOLU', 'mac' => '11:22:33:44:55:66', 'status' => AgentEnrollmentRequest::STATUS_REJECTED]);

        Livewire::test(self::COMPONENT)
            ->assertSee('PC-MIGRE')
            ->assertDontSee('PC-RESOLU');
    }

    #[Test]
    public function approve_arms_the_request_and_toasts(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-MIGRE']);
        $req = $this->pending(['matched_workstation_id' => $ws->id]);

        Livewire::test(self::COMPONENT)
            ->call('approve', $req->id)
            ->assertDispatched('toastMagic');

        $req->refresh();
        self::assertSame(AgentEnrollmentRequest::STATUS_APPROVED, $req->status);
        self::assertFalse($req->auto_approved);
        // (review #5) `resolved_by` réellement renseigné par l'admin authentifié.
        self::assertSame($this->admin->id, $req->resolved_by);
        // Le token ne transite pas par l'UI : le poste reste non enrôlé ici.
        self::assertFalse($ws->refresh()->isAgentEnrolled());
    }

    #[Test]
    public function approve_is_refused_for_unmatched_request(): void
    {
        // (review #3) Une demande « inconnu » (sans rapprochement) ne peut pas
        // être armée — sinon le poste resterait 403 et la demande, invisible.
        $req = $this->pending(['matched_workstation_id' => null]);

        Livewire::test(self::COMPONENT)
            ->call('approve', $req->id);

        self::assertSame(AgentEnrollmentRequest::STATUS_PENDING, $req->refresh()->status);
    }

    #[Test]
    public function reject_via_modal_marks_rejected_and_toasts(): void
    {
        $req = $this->pending();

        Livewire::test(self::COMPONENT)
            ->call('openReject', $req->id)
            ->assertSet('isRejectOpen', true)
            ->call('confirmReject')
            ->assertSet('isRejectOpen', false)
            ->assertDispatched('toastMagic');

        self::assertSame(AgentEnrollmentRequest::STATUS_REJECTED, $req->refresh()->status);
    }

    #[Test]
    public function campaign_toggle_reflects_in_setting(): void
    {
        $campaign = app(EnrollmentCampaign::class);
        self::assertFalse($campaign->isActive());

        Livewire::test(self::COMPONENT)
            ->set('campaignDays', 3)
            ->call('enableCampaign')
            ->assertDispatched('toastMagic');

        self::assertTrue($campaign->isActive());

        Livewire::test(self::COMPONENT)
            ->call('disableCampaign');

        self::assertFalse($campaign->isActive());
    }

    #[Test]
    public function approve_is_forbidden_without_permission(): void
    {
        // (review #4/#5) Un utilisateur sans `computer.install` est refusé même
        // s'il adresse directement l'action via /livewire/update. On désactive le
        // handler d'exception (la conversion en page d'erreur 403 exigerait le
        // manifest Vite, absent sur l'hôte) pour vérifier directement que le Gate
        // lève AuthorizationException AVANT toute mutation.
        $viewer = User::query()->create(['login' => 'enroll-viewer', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-MIGRE']);
        $req = $this->pending(['matched_workstation_id' => $ws->id]);

        $this->withoutExceptionHandling();
        $this->expectException(AuthorizationException::class);

        Livewire::test(self::COMPONENT)->call('approve', $req->id);
    }
}
