<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Exceptions\FsAclAuthoringException;
use App\Models\FolderAccessRule;
use App\Models\FolderAccessRuleAuditLog;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\FolderAccessRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Story 36.4 (AC2) — Service : guard EXPLICITE (leçon review 36.1 #2b) + audit
 * append-only + cycle de vie sûr (off réel / refus suppression active).
 */
class FolderAccessRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function service(): FolderAccessRuleService
    {
        return app(FolderAccessRuleService::class);
    }

    private function group(?string $adDn = null): UserGroup
    {
        return UserGroup::factory()->create([
            'name' => '3A',
            'ad_dn' => $adDn,
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(UserGroup $group, array $overrides = []): array
    {
        return array_merge([
            'path' => 'D:\\Ressources',
            'user_group_id' => $group->id,
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
            'label' => 'Interdire Ressources aux 3A',
        ], $overrides);
    }

    #[Test]
    public function create_persists_and_writes_a_create_audit_log(): void
    {
        $actor = User::factory()->create();
        $rule = $this->service()->create($this->payload($this->group()), $actor);

        self::assertTrue($rule->exists);
        $log = FolderAccessRuleAuditLog::forRule($rule->id)->forAction('create')->first();
        self::assertNotNull($log);
        self::assertSame($actor->id, $log->actor_user_id);
        self::assertSame($actor->login, $log->actor_login);
        self::assertSame('D:\\Ressources', $log->new_state['path']);
    }

    #[Test]
    public function create_refuses_the_forbidden_combo_via_the_guard_at_service_level(): void
    {
        // deny descendant sur une racine protégée (C:\Windows) → guard refuse.
        $this->expectException(FsAclAuthoringException::class);

        $this->service()->create($this->payload($this->group(), [
            'path' => 'C:\\Windows',
            'ace_type' => 'deny',
            'rights' => 'modify',
            'applies_to' => 'folder_subfolders_files',
        ]), null);
    }

    #[Test]
    public function create_refuses_a_deny_on_a_system_principal_group(): void
    {
        // Un groupe dont le CN dérivé est « Administrators » → deny refusé (guard).
        $group = UserGroup::factory()->create(['name' => 'Administrators', 'ad_dn' => null]);

        $this->expectException(FsAclAuthoringException::class);
        $this->service()->create($this->payload($group), null);
    }

    #[Test]
    public function create_refuses_a_non_absolute_path(): void
    {
        $this->expectException(FsAclAuthoringException::class);
        $this->service()->create($this->payload($this->group(), ['path' => 'Software\\X']), null);
    }

    #[Test]
    public function no_rule_is_persisted_when_the_guard_refuses(): void
    {
        try {
            $this->service()->create($this->payload($this->group(), ['path' => 'C:\\PROGRA~1', 'applies_to' => 'folder_subfolders_files']), null);
            self::fail('attendu : FsAclAuthoringException');
        } catch (FsAclAuthoringException) {
            // rollback transactionnel : aucune ligne.
        }
        self::assertSame(0, FolderAccessRule::count());
    }

    #[Test]
    public function update_revalidates_the_guard_and_audits(): void
    {
        $rule = $this->service()->create($this->payload($this->group()), null);

        // Édition vers un combo interdit → refus.
        try {
            $this->service()->update($rule, [
                'path' => 'C:\\Windows',
                'user_group_id' => $rule->user_group_id,
                'ace_type' => 'deny',
                'rights' => 'modify',
                'applies_to' => 'folder_subfolders_files',
                'label' => $rule->label,
            ], null);
            self::fail('attendu : FsAclAuthoringException');
        } catch (FsAclAuthoringException) {
        }

        // Édition valide → audit update.
        $this->service()->update($rule, $this->payload(UserGroup::find($rule->user_group_id), ['label' => 'Nouveau libellé']), null);
        self::assertSame('Nouveau libellé', $rule->fresh()->label);
        self::assertSame(1, FolderAccessRuleAuditLog::forRule($rule->id)->forAction('update')->count());
    }

    #[Test]
    public function set_active_false_keeps_the_rule_and_audits_an_update(): void
    {
        $rule = $this->service()->create($this->payload($this->group()), null);
        $this->service()->setActive($rule, false, null);

        self::assertFalse($rule->fresh()->is_active, 'désactiver ne supprime PAS la règle (off réel, D3)');
        self::assertSame(1, FolderAccessRuleAuditLog::forRule($rule->id)->forAction('update')->count());
    }

    #[Test]
    public function deleting_an_active_rule_is_refused(): void
    {
        $rule = $this->service()->create($this->payload($this->group()), null);

        $this->expectException(RuntimeException::class);
        $this->service()->delete($rule, null);
    }

    #[Test]
    public function deleting_an_inactive_rule_succeeds_and_audits_a_delete(): void
    {
        $rule = $this->service()->create($this->payload($this->group()), null);
        $this->service()->setActive($rule, false, null);

        $label = $rule->label;
        $this->service()->delete($rule, null);

        self::assertNull(FolderAccessRule::find($rule->id));
        // La FK `nullOnDelete` a mis `rule_id` à null ; `rule_label` dénormalisé
        // préserve la traçabilité de la suppression.
        self::assertSame(1, FolderAccessRuleAuditLog::forAction('delete')->where('rule_label', $label)->count());
    }

    #[Test]
    public function audit_log_is_append_only(): void
    {
        $rule = $this->service()->create($this->payload($this->group()), null);
        $log = FolderAccessRuleAuditLog::forRule($rule->id)->first();

        $this->expectException(\LogicException::class);
        $log->update(['action' => 'tamper']);
    }

    #[Test]
    public function attach_and_detach_parc_are_audited(): void
    {
        $rule = $this->service()->create($this->payload($this->group()), null);
        $wg = WorkstationGroup::factory()->logical()->create();

        // Acteur null → autorisé (contexte serveur/seed) : le contrôle scopé est
        // testé séparément (policy test).
        $this->service()->attachParc($rule, $wg, null);
        self::assertContains($wg->id, $rule->fresh()->assignedWorkstationGroupIds());

        $this->service()->detachParc($rule, $wg, null);
        self::assertNotContains($wg->id, $rule->fresh()->assignedWorkstationGroupIds());

        // 1 create + 1 attach(update) + 1 detach(update) = 2 update.
        self::assertSame(2, FolderAccessRuleAuditLog::forRule($rule->id)->forAction('update')->count());
    }
}
