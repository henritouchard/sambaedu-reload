<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Exceptions\FsAclAuthoringException;
use App\Models\FolderAccessRule;
use App\Models\FolderAccessRuleAssignable;
use App\Models\FolderAccessRuleAuditLog;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Services\Agent\Providers\FsAclAuthoringGuard;
use App\Services\PermissionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Story 36.4 — Service métier des règles d'accès aux dossiers (D8).
 *
 * Cœur de l'authoring de la SECONDE surface `fs_acl` : create / update /
 * setActive / delete / (dé)assignation de parcs. Trois invariants CRITIQUES :
 *
 *  1. **Guard EXPLICITE (D4, leçon review 36.1 #2b).** L'observer
 *     `CapabilityProjectionObserver` ne couvre QUE `capability_projections` — les
 *     règles vivent dans une table DÉDIÉE, AUCUN filet automatique. Le service
 *     appelle donc {@see FsAclAuthoringGuard::violations()} à CHAQUE create ET
 *     update (adaptation règle → forme projection : trustee dérivé D9,
 *     `warning = DENY_WARNING` pour satisfaire « deny ⇒ warning non vide » — la
 *     confirmation RÉELLE est portée par l'UI). Refus ⇒ {@see FsAclAuthoringException}
 *     (messages FR du guard).
 *
 *  2. **Audit APPEND-ONLY (D7).** Chaque mutation (create/update/delete, y compris
 *     activation-désactivation et (dé)assignation = `update`) écrit une ligne
 *     {@see FolderAccessRuleAuditLog} DANS la transaction (atomicité acte ↔ trace).
 *
 *  3. **Retrait propre (D3, piège #3).** Désactiver ≠ éteindre l'émission (la règle
 *     émet `ensure:'absent'`, off réel) ; la SUPPRESSION d'une règle ACTIVE est
 *     REFUSÉE (message FR) — inactive seulement.
 *
 * **Délégation scopée par parc (piège #9).** Chaque (dé)assignation de parc est
 * vérifiée PAR PARC via {@see PermissionService::canOnWorkstationGroup()}
 * (anti-piège « Gate global non scopé »). Le service ne touche JAMAIS le FS ni
 * l'AD (Postgres pur) et n'écrit AUCUN SID (D5 36.1 : résolution LSA côté poste).
 */
class FolderAccessRuleService
{
    /**
     * Implications FR d'une règle `deny`, affichées dans l'encart de confirmation
     * UI ET passées au guard comme `warning` (D4/piège #10 : le guard exige un
     * warning non vide pour tout `deny` ; l'ACQUITTEMENT est contrôlé par l'UI).
     */
    public const DENY_WARNING =
        "Cette règle REFUSE l'accès au dossier pour les membres du groupe ciblé : "
        ."ils ne pourront plus l'ouvrir (ou son contenu, selon la portée choisie). "
        .'Vérifiez le chemin et le groupe avant de confirmer.';

    public function __construct(
        private readonly FsAclAuthoringGuard $guard,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * Crée une règle après validation EXPLICITE du guard (D4). Les parcs sont
     * assignés séparément (page d'édition) — une règle neuve n'a aucun parc.
     *
     * @param  array{path:string,user_group_id:int,ace_type:string,rights:string,applies_to:string,label:string,is_active?:bool}  $data
     *
     * @throws FsAclAuthoringException  si le guard refuse (racines protégées ×
     *         héritage, principals système en deny, noms courts 8.3, enums hors
     *         domaine, chemin non absolu, trustee vide/inconnu)
     */
    public function create(array $data, ?User $actor): FolderAccessRule
    {
        return DB::transaction(function () use ($data, $actor): FolderAccessRule {
            $rule = new FolderAccessRule([
                'path' => $data['path'],
                'user_group_id' => $data['user_group_id'],
                'ace_type' => $data['ace_type'],
                'rights' => $data['rights'],
                'applies_to' => $data['applies_to'],
                'label' => $data['label'],
                'is_active' => $data['is_active'] ?? true,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->assertGuard($rule);
            $rule->save();

            FolderAccessRuleAuditLog::log(
                FolderAccessRuleAuditLog::ACTION_CREATE,
                $actor?->id,
                $actor?->login,
                $rule->id,
                $rule->label,
                null,
                $this->snapshot($rule),
            );

            return $rule;
        });
    }

    /**
     * Met à jour les champs métier d'une règle (re-validation guard).
     *
     * @param  array{path:string,user_group_id:int,ace_type:string,rights:string,applies_to:string,label:string}  $data
     *
     * @throws FsAclAuthoringException
     */
    public function update(FolderAccessRule $rule, array $data, ?User $actor): FolderAccessRule
    {
        return DB::transaction(function () use ($rule, $data, $actor): FolderAccessRule {
            $old = $this->snapshot($rule);

            $rule->fill([
                'path' => $data['path'],
                'user_group_id' => $data['user_group_id'],
                'ace_type' => $data['ace_type'],
                'rights' => $data['rights'],
                'applies_to' => $data['applies_to'],
                'label' => $data['label'],
            ]);

            // Le trustee peut avoir changé (groupe différent) → re-dériver via la
            // relation fraîche pour le guard.
            $rule->unsetRelation('userGroup');
            $this->assertGuard($rule);
            $rule->save();

            FolderAccessRuleAuditLog::log(
                FolderAccessRuleAuditLog::ACTION_UPDATE,
                $actor?->id,
                $actor?->login,
                $rule->id,
                $rule->label,
                $old,
                $this->snapshot($rule),
            );

            return $rule;
        });
    }

    /**
     * Active/désactive une règle. Désactiver n'ÉTEINT PAS l'émission : la règle
     * émet ses items avec `ensure:'absent'` (off réel, D3/piège #3) — le retrait
     * des ACE au parc passe PAR LÀ.
     */
    public function setActive(FolderAccessRule $rule, bool $active, ?User $actor): FolderAccessRule
    {
        return DB::transaction(function () use ($rule, $active, $actor): FolderAccessRule {
            $old = $this->snapshot($rule);
            $rule->is_active = $active;
            $rule->save();

            FolderAccessRuleAuditLog::log(
                FolderAccessRuleAuditLog::ACTION_UPDATE,
                $actor?->id,
                $actor?->login,
                $rule->id,
                $rule->label,
                $old,
                $this->snapshot($rule),
            );

            return $rule;
        });
    }

    /**
     * Supprime une règle. REFUSÉE si la règle est ACTIVE (D3/piège #3 : le retrait
     * des ACE au parc passe par la DÉSACTIVATION — sinon le type disparaît du
     * state et l'ACE gérée SURVIT au poste). Une règle inactive est supprimable
     * (cascade pivot).
     *
     * @throws RuntimeException  si la règle est active (message FR)
     */
    public function delete(FolderAccessRule $rule, ?User $actor): void
    {
        if ($rule->is_active) {
            throw new RuntimeException(
                "Désactivez d'abord la règle — le retrait des ACE au parc passe par la désactivation."
            );
        }

        DB::transaction(function () use ($rule, $actor): void {
            // Trace AVANT la suppression (rule_id valide à l'INSERT) — la FK
            // `nullOnDelete` mettra ensuite `rule_id` à null, `rule_label`
            // dénormalisé préserve la lisibilité.
            FolderAccessRuleAuditLog::log(
                FolderAccessRuleAuditLog::ACTION_DELETE,
                $actor?->id,
                $actor?->login,
                $rule->id,
                $rule->label,
                $this->snapshot($rule),
                null,
            );

            $rule->delete(); // cascade pivot
        });
    }

    /**
     * Assigne un parc à une règle depuis l'UI (contrôle PAR PARC, piège #9). No-op
     * si déjà assigné. Audité (`update`).
     *
     * **Correction review #3.** L'acteur est OBLIGATOIRE : un acteur `null`
     * (session absente, ou guard fédéré renvoyant un `Authenticatable` non-`User`,
     * cf. login fédéré controlHub) est REFUSÉ — jamais autorisé par défaut. Le
     * contexte serveur/seed passe par {@see attachParcAsSystem()}.
     *
     * @throws RuntimeException  si l'acteur est absent OU n'a pas `folderrule.manage` sur CE parc
     */
    public function attachParc(FolderAccessRule $rule, WorkstationGroup $group, ?User $actor): void
    {
        $this->assertActorCanManageParc($actor, $group);
        $this->doAttachParc($rule, $group, $actor);
    }

    /**
     * Retire un parc d'une règle depuis l'UI (contrôle PAR PARC, piège #9).
     * Audité (`update`). Acteur OBLIGATOIRE (correction review #3).
     *
     * @throws RuntimeException  si l'acteur est absent OU n'a pas `folderrule.manage` sur CE parc
     */
    public function detachParc(FolderAccessRule $rule, WorkstationGroup $group, ?User $actor): void
    {
        $this->assertActorCanManageParc($actor, $group);
        $this->doDetachParc($rule, $group, $actor);
    }

    /**
     * Assigne un parc SANS contrôle d'acteur — RÉSERVÉ aux seeds / CLI / contexte
     * serveur (aucune session). Ne JAMAIS exposer à une surface UI (correction
     * review #3 : l'autorisation par défaut sur acteur `null` était un bypass
     * silencieux).
     */
    public function attachParcAsSystem(FolderAccessRule $rule, WorkstationGroup $group): void
    {
        $this->doAttachParc($rule, $group, null);
    }

    /**
     * Retire un parc SANS contrôle d'acteur — RÉSERVÉ aux seeds / CLI / contexte
     * serveur (correction review #3).
     */
    public function detachParcAsSystem(FolderAccessRule $rule, WorkstationGroup $group): void
    {
        $this->doDetachParc($rule, $group, null);
    }

    private function doAttachParc(FolderAccessRule $rule, WorkstationGroup $group, ?User $actor): void
    {
        DB::transaction(function () use ($rule, $group, $actor): void {
            $old = $this->snapshot($rule);

            FolderAccessRuleAssignable::firstOrCreate([
                'folder_access_rule_id' => $rule->id,
                'assignable_type' => WorkstationGroup::class,
                'assignable_id' => $group->id,
            ]);

            FolderAccessRuleAuditLog::log(
                FolderAccessRuleAuditLog::ACTION_UPDATE,
                $actor?->id,
                $actor?->login,
                $rule->id,
                $rule->label,
                $old,
                $this->snapshot($rule->fresh()),
            );
        });
    }

    private function doDetachParc(FolderAccessRule $rule, WorkstationGroup $group, ?User $actor): void
    {
        DB::transaction(function () use ($rule, $group, $actor): void {
            $old = $this->snapshot($rule);

            FolderAccessRuleAssignable::where('folder_access_rule_id', $rule->id)
                ->where('assignable_type', WorkstationGroup::class)
                ->where('assignable_id', $group->id)
                ->delete();

            FolderAccessRuleAuditLog::log(
                FolderAccessRuleAuditLog::ACTION_UPDATE,
                $actor?->id,
                $actor?->login,
                $rule->id,
                $rule->label,
                $old,
                $this->snapshot($rule->fresh()),
            );
        });
    }

    // =========================================================================
    // Interne
    // =========================================================================

    /**
     * Adapte la règle en une projection `windows/fs_acl` et la soumet au guard
     * (D4). Le trustee est DÉRIVÉ du groupe (D9). `ensure:'present'` (forme
     * armée — c'est celle qui doit être sûre). `warning = DENY_WARNING` non vide
     * satisfait la règle « deny ⇒ warning » (l'acquittement est UI).
     *
     * @throws FsAclAuthoringException
     */
    private function assertGuard(FolderAccessRule $rule): void
    {
        $group = UserGroup::find($rule->user_group_id);
        $trustee = FolderAccessRule::deriveTrustee($group?->ad_dn, (string) ($group?->name ?? ''));

        $violations = $this->guard->violations([[
            'capability' => "règle « {$rule->label} »",
            'warning' => self::DENY_WARNING,
            'spec' => ['aces' => [[
                'path' => (string) $rule->path,
                'trustee' => $trustee,
                'ace_type' => (string) $rule->ace_type,
                'rights' => (string) $rule->rights,
                'applies_to' => (string) $rule->applies_to,
                'ensure' => 'present',
            ]]],
        ]]);

        if ($violations !== []) {
            throw new FsAclAuthoringException($violations);
        }
    }

    /**
     * Vérifie qu'un acteur UI peut gérer CE parc (délégation scopée, piège #9).
     *
     * **Correction review #3.** Un acteur `null` est REFUSÉ (jamais autorisé par
     * défaut) : la session est absente, ou un guard fédéré a renvoyé un
     * `Authenticatable` non-`User` (login fédéré controlHub) → la surface UI ne
     * doit PAS agir. Le contexte serveur/seed passe explicitement par
     * `attachParcAsSystem()`/`detachParcAsSystem()` (aucun contrôle d'acteur).
     *
     * @throws RuntimeException
     */
    private function assertActorCanManageParc(?User $actor, WorkstationGroup $group): void
    {
        if ($actor === null) {
            throw new RuntimeException(
                "Action non autorisée : aucun acteur authentifié pour gérer les parcs de cette règle."
            );
        }

        if (! $this->permissions->canOnWorkstationGroup($actor, 'folderrule.manage', $group)) {
            throw new RuntimeException(
                "Vous n'avez pas la permission de gérer les règles d'accès sur ce parc."
            );
        }
    }

    /**
     * Snapshot JSON pour l'audit : champs + ids de parcs assignés (D7).
     *
     * @return array<string,mixed>
     */
    private function snapshot(FolderAccessRule $rule): array
    {
        return [
            'path' => $rule->path,
            'user_group_id' => $rule->user_group_id,
            'ace_type' => $rule->ace_type,
            'rights' => $rule->rights,
            'applies_to' => $rule->applies_to,
            'label' => $rule->label,
            'is_active' => (bool) $rule->is_active,
            'parc_ids' => $rule->exists ? $rule->assignedWorkstationGroupIds() : [],
        ];
    }
}
