<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ipxe\Enums\IpxeEnrollmentFlow;
use App\Ipxe\Support\EnrollNameResult;
use App\Ldap\AdMachineManager;
use App\LdapModels\MachineModel;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationObserver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 3.3 — D5 / AC2.1-AC2.8.
 *
 * Orchestrateur d'enrollment machine — coordonne PostgreSQL
 * (modèle `Workstation` + pivot `workstation_group_workstation`) et AD
 * (via {@see AdMachineManager} — création / `netbootGUID` / rename / groupes).
 *
 * **Périmètre** : 5 méthodes publiques (1 par flow iPXE) :
 *
 *  - {@see enrollName()}         — flow `/ipxe/enrollment/name`.
 *  - {@see logByodEnrollment()}  — flow `/ipxe/enrollment/byod` (audit-only).
 *  - {@see assignRoom()}         — flow `/ipxe/enrollment/room`.
 *  - {@see attachGroup()}        — flow `/ipxe/enrollment/parc-add`.
 *  - {@see detachGroup()}        — flow `/ipxe/enrollment/parc-remove`.
 *
 * **Pattern try/catch obligatoire** sur chaque méthode publique : un firmware
 * iPXE doit toujours recevoir un menu (pas une 500). Les exceptions sont
 * loggées sur le channel `ipxe` puis converties en value object/bool retour
 * d'erreur.
 *
 * **Anti-pattern** :
 *
 *  - Pas d'appel `LdapRecord` direct — passage exclusif via `AdMachineManager`
 *    (parité 16.7 + architecture `App\Ipxe`).
 *  - Pas de modification de schéma `workstations` — `updateOrCreate`/`save`
 *    sur attributs existants Epic 4.
 *  - Pas de transaction DB+AD atomique (best-effort iso pattern 4.1/4.2 — si
 *    AD échoue après DB, log warning + status `AD_ERROR`).
 */
final class WorkstationEnrollmentService
{
    public function __construct(
        private readonly AdMachineManager $adMachineManager,
        private readonly IpxeHostnameSanitizer $sanitizer,
    ) {
    }

    /**
     * Story 3.3 — AC2.1-AC2.4.
     *
     * Coordonne l'enrollment "nommage" d'un poste — gère les 4 cas iso-legacy
     * `enregistrement.php:22-148` :
     *
     *  1. UUID inconnu → CREATED (Workstation::create + AD check + AD register).
     *  2. UUID connu + même nom → SAME_NAME (no-op idempotent).
     *  3. UUID connu + nouveau nom unique → RENAMED (Workstation::save + AD rename).
     *  4. UUID connu/neuf + nom déjà pris par AUTRE poste → NAME_TAKEN.
     *
     * Le `$rawName` est sanitizé via {@see IpxeHostnameSanitizer::sanitize()}
     * et passé via {@see IpxeHostnameSanitizer::applyHostnameSuffix()} avant
     * validation regex stricte (anti-injection). Un nom invalide retourne
     * `DB_ERROR` avec `reasonLabel='nom invalide'` (le controller mappera
     * sur l'echo Blade `ERREUR nom invalide`).
     *
     * @param  string      $rawName    Nom brut reçu du firmware iPXE.
     * @param  string      $mac        MAC normalisée.
     * @param  string      $uuid       UUID normalisé.
     * @param  string      $platform   Plateforme (`legacy` ou `uefi`).
     * @param  string      $ip         IP du poste (pour logging).
     * @param  Workstation|null $existing Poste déjà résolu (passe-thru
     *                                  optionnel — sinon résolu via UUID).
     */
    public function enrollName(
        string $rawName,
        string $mac,
        string $uuid,
        string $platform = 'legacy',
        string $ip = '',
        ?Workstation $existing = null,
    ): EnrollNameResult {
        try {
            // 1) Sanitize + suffix iso-legacy.
            $sanitized = $this->sanitizer->sanitize($rawName, $platform);

            // Iso-legacy `enregistrement.php:39-43` : les serveurs SE4FS/SE4AD
            // ne reçoivent PAS de suffix (= noms canoniques).
            if ($this->sanitizer->isSpecialServerName($sanitized)) {
                $sanitized = strtolower(substr($sanitized, 0, 15));
            } else {
                $sanitized = $this->sanitizer->applyHostnameSuffix($sanitized);
            }

            // 2) Validation regex stricte anti-injection (post-sanitize).
            if (! $this->sanitizer->isValidHostname($sanitized)) {
                // F7 (review 3.3) : event renommé `rejected_invalid` (vs `name_taken`)
                // pour distinguer côté SIEM concurrentiel légitime vs injection.
                $this->log('ipxe.enrollment.name.rejected_invalid', [
                    'action_type' => 'ipxe.enrollment.name.rejected_invalid',
                    'reason' => 'invalid_hostname',
                    'ip' => $ip,
                    'mac_prefix' => substr($mac, 0, 6),
                    'uuid_prefix' => substr($uuid, 0, 8),
                    'attempted_name_prefix' => substr($sanitized, 0, 8),
                ], 'warning');

                return EnrollNameResult::dbError('', 'nom invalide');
            }

            // 3) Résolution Workstation par UUID (priorité — parité legacy).
            $current = $existing ?? Workstation::query()
                ->where('uuid', strtolower($uuid))
                ->first();
            $currentName = $current !== null ? strtolower((string) $current->name) : null;

            // 4) Cas 2 — idempotent SAME_NAME (UUID + même nom déjà enregistré).
            if ($current !== null && $currentName === $sanitized) {
                $this->log('ipxe.enrollment.name.success', [
                    'action_type' => 'ipxe.enrollment.name.success',
                    'status' => 'same_name',
                    'ad_result' => 'skipped',
                    'ip' => $ip,
                    'mac_prefix' => substr($mac, 0, 6),
                    'uuid_prefix' => substr($uuid, 0, 8),
                    'workstation_id' => $current->id,
                    'sanitized_name_prefix' => substr($sanitized, 0, 8),
                ]);

                $this->persistMachineBootLog($current, $ip, IpxeEnrollmentFlow::Name, true);

                return EnrollNameResult::sameName($current, $sanitized);
            }

            // 5) Cas 4 — NAME_TAKEN (nom déjà pris par AUTRE poste UUID/id).
            $nameOwner = Workstation::query()
                ->where('name', $sanitized)
                ->when(
                    $current !== null,
                    fn ($q) => $q->where('id', '!=', $current->id),
                )
                ->first();
            if ($nameOwner !== null) {
                $this->log('ipxe.enrollment.name.name_taken', [
                    'action_type' => 'ipxe.enrollment.name.name_taken',
                    'ip' => $ip,
                    'mac_prefix' => substr($mac, 0, 6),
                    'uuid_prefix' => substr($uuid, 0, 8),
                    'attempted_name_prefix' => substr($sanitized, 0, 8),
                ], 'warning');

                return EnrollNameResult::nameTaken($sanitized);
            }

            // 6) Cas 1 — création neuve (UUID inconnu).
            //
            // Décision design #1b (Henri 2026-06-01, remplace #1a) : ordre
            // « AD d'abord, PG ensuite ». On crée+enregistre le compte machine
            // AD AVANT toute écriture Postgres ; si l'AD échoue on REJETTE
            // (return adError) sans rien persister en PG — évite la divergence
            // « poste fantôme en base sans compte AD » (cf. incident poste 46).
            // `check()` étant idempotent, un retry ré-utilise un compte déjà
            // présent. On continue de bypasser l'observer (pas de double-passe
            // via WorkstationAdSyncJob::create — l'AD est déjà fait ici).
            if ($current === null) {
                // AD d'ABORD : check (idempotent create) + registerHardware (netbootGUID).
                $adCheck = $this->adMachineManager->check($sanitized);
                $adRegister = $adCheck
                    && $this->adMachineManager->registerHardware($sanitized, $uuid);

                if (! $adRegister) {
                    // Échec AD → rien en PG → message lisible côté firmware.
                    $this->log('ipxe.enrollment.name.ad_error', [
                        'action_type' => 'ipxe.enrollment.name.ad_error',
                        'ad_step' => $adCheck ? 'register_hardware' : 'check',
                        'ip' => $ip,
                        'mac_prefix' => substr($mac, 0, 6),
                        'uuid_prefix' => substr($uuid, 0, 8),
                        'sanitized_name_prefix' => substr($sanitized, 0, 8),
                    ], 'error');

                    return EnrollNameResult::adError($sanitized);
                }

                // AD OK → on persiste PG (sans déclencher l'observer).
                $workstation = WorkstationObserver::withoutSync(fn () => Workstation::create([
                    'name' => $sanitized,
                    'uuid' => strtolower($uuid),
                    'mac' => $mac,
                    'status' => 'active',
                ]));

                // Backfill `ad_guid` + `ad_dn` post-création AD (best-effort).
                try {
                    $machine = MachineModel::findBy('cn', $sanitized);
                    if ($machine !== null) {
                        $guid = $machine->getConvertedGuid();
                        $dn = $machine->getDn();
                        if (!empty($guid)) {
                            WorkstationObserver::withoutSync(function () use ($workstation, $guid, $dn): void {
                                $workstation->ad_guid = $guid;
                                if (!empty($dn)) {
                                    $workstation->ad_dn = $dn;
                                }
                                $workstation->save();
                            });
                        }
                    }
                } catch (Throwable $e) {
                    // best-effort — ne pas bloquer l'enrollment iPXE si la
                    // lecture LDAP post-create échoue (le compte AD existe déjà).
                    $this->log('ipxe.enrollment.name.ad_guid_backfill_failure', [
                        'action_type' => 'ipxe.enrollment.name.ad_guid_backfill_failure',
                        'workstation_id' => $workstation->id,
                        'exception_class' => $e::class,
                        'message' => substr($e->getMessage(), 0, 200),
                    ], 'warning');
                }

                $this->log('ipxe.enrollment.name.success', [
                    'action_type' => 'ipxe.enrollment.name.success',
                    'status' => 'created',
                    'ad_result' => 'success',
                    'ip' => $ip,
                    'mac_prefix' => substr($mac, 0, 6),
                    'uuid_prefix' => substr($uuid, 0, 8),
                    'workstation_id' => $workstation->id,
                    'sanitized_name_prefix' => substr($sanitized, 0, 8),
                ]);

                $this->persistMachineBootLog($workstation, $ip, IpxeEnrollmentFlow::Name, true);

                return EnrollNameResult::created($workstation, $sanitized, true);
            }

            // 7) Cas 3 — renommage (UUID connu, nouveau nom libre).
            //
            // Story 4.9 : le rename AD est désormais piloté par l'observer
            // {@see \App\Observers\WorkstationObserver} qui dispatch async
            // {@see \App\Jobs\AdSync\WorkstationAdSyncJob::rename()} (modrdn
            // LDAP, préserve objectGUID + netbootGUID).
            //
            // D7 : registerHardware post-rename supprimé — modrdn LDAP
            // préserve netbootGUID (validé VM 2026-05-28).
            //
            // Auto-fix #6 (review 4.9) : suppression de `$oldName` mort et
            // de son `unset()`. Le rename AD est délégué à l'observer/job —
            // le résultat réel n'est connu qu'après exécution async, donc on
            // log `ad_result='dispatched'` (vs `'success'` mensonger).
            $current->name = $sanitized;
            if ($mac !== '' && $current->mac !== $mac) {
                $current->mac = $mac;
            }
            $current->save();

            $this->log('ipxe.enrollment.name.success', [
                'action_type' => 'ipxe.enrollment.name.success',
                'status' => 'renamed',
                // async — voir queue logs (channel default) pour résultat réel.
                'ad_result' => 'dispatched',
                'ip' => $ip,
                'mac_prefix' => substr($mac, 0, 6),
                'uuid_prefix' => substr($uuid, 0, 8),
                'workstation_id' => $current->id,
                'sanitized_name_prefix' => substr($sanitized, 0, 8),
            ]);

            $this->persistMachineBootLog($current, $ip, IpxeEnrollmentFlow::Name, true);

            // EnrollNameResult::renamed() exige un bool — on passe `true`
            // pour signaler « dispatch émis » (le retour iPXE ne reflète
            // plus le succès AD réel, désormais async).
            return EnrollNameResult::renamed($current, $sanitized, true);
        } catch (Throwable $e) {
            $this->log('ipxe.enrollment.name.failure', [
                'action_type' => 'ipxe.enrollment.name.failure',
                'ip' => $ip,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
            ], 'error');

            return EnrollNameResult::dbError($rawName, 'erreur infrastructure');
        }
    }

    /**
     * Story 3.3 — AC2.8.
     *
     * Flow BYOD simplifié — audit-only en 3.3 :
     *
     *  - **PAS** de création Workstation (BYOD = appareil élève, pas du parc).
     *  - **PAS** d'appel AD.
     *  - Insert `MachineBootLog` avec `action='ipxe_enroll_byod'`,
     *    `workstation_id=null`, `machine_name='byod:'.$sanitizedName`.
     *  - Log info `ipxe.enrollment.byod.logged`.
     *
     * Le flow complet BYOD (chain vers `/ipxe/installation-linux`) est déféré
     * à la story 3.4 — 3.3 livre un stub qui chain vers `/ipxe/admin` pour
     * boucler le menu.
     */
    public function logByodEnrollment(string $rawName, string $mac, string $uuid, string $ip): void
    {
        try {
            // Opus-1 (review 3.3) : validation isValidHostname obligatoire pour bloquer newline injection iPXE.
            $sanitized = $this->sanitizer->sanitize($rawName);
            if (! $this->sanitizer->isValidHostname($sanitized)) {
                $this->log('ipxe.enrollment.byod.rejected_invalid', [
                    'action_type' => 'ipxe.enrollment.byod.rejected_invalid',
                    'ip' => $ip,
                    'mac_prefix' => substr($mac, 0, 6),
                    'uuid_prefix' => substr($uuid, 0, 8),
                    'attempted_name_prefix' => substr($sanitized, 0, 8),
                ], 'warning');

                return; // pas de MachineBootLog, pas d'echo template
            }

            $this->log('ipxe.enrollment.byod.logged', [
                'action_type' => 'ipxe.enrollment.byod.logged',
                'ip' => $ip,
                'mac_prefix' => substr($mac, 0, 6),
                'uuid_prefix' => substr($uuid, 0, 8),
                'attempted_name_prefix' => substr($sanitized, 0, 8),
            ]);

            try {
                $now = Carbon::now();
                MachineBootLog::query()->create([
                    'workstation_id' => null,
                    'machine_name' => 'byod:' . $sanitized,
                    'action' => IpxeEnrollmentFlow::Byod->machineBootLogAction(),
                    'initiated_by' => 'ipxe',
                    'success' => true,
                    'started_at' => $now,
                    'stopped_at' => $now,
                ]);
            } catch (Throwable $e) {
                $this->log('ipxe.machine_boot_log_failure', [
                    'action_type' => 'ipxe.machine_boot_log_failure',
                    'endpoint_action' => IpxeEnrollmentFlow::Byod->machineBootLogAction(),
                    'exception_class' => $e::class,
                    'message' => substr($e->getMessage(), 0, 200),
                    'ip' => $ip,
                ], 'warning');
            }
        } catch (Throwable $e) {
            $this->log('ipxe.enrollment.byod.failure', [
                'action_type' => 'ipxe.enrollment.byod.failure',
                'ip' => $ip,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
            ], 'error');
        }
    }

    /**
     * Q1 (review 3.3) — iso-legacy `enregistrement_byod.php:72-81`.
     *
     * Log audit : un poste connu en AD a tenté un POST /ipxe/enrollment/byod.
     * Pas de side-effect DB (pas de MachineBootLog) — c'est un rejet pur,
     * traité côté template avec `denied=true` → chain boot.
     */
    public function logByodDenied(string $mac, string $uuid, string $ip): void
    {
        $this->log('ipxe.enrollment.byod.denied_known_host', [
            'action_type' => 'ipxe.enrollment.byod.denied_known_host',
            'ip' => $ip,
            'mac_prefix' => substr($mac, 0, 6),
            'uuid_prefix' => substr($uuid, 0, 8),
        ], 'warning');
    }

    /**
     * Story 3.3 — AC2.5 / AC2.6.
     *
     * Affecte un poste à une salle physique (`WorkstationGroup::is_physical = true`).
     *
     * Side effect : la sync AD (déplacement OU) est déléguée au workflow
     * existant Epic 4 (au moins via observers / jobs). Le service touche
     * uniquement `Workstation::physical_room_id` et délègue la propagation.
     *
     * Retourne `false` si :
     *  - `$roomId` n'existe pas
     *  - le `WorkstationGroup` correspondant n'est pas physique ou est archivé
     *  - exception DB
     */
    public function assignRoom(Workstation $ws, int $roomId, string $ip = ''): bool
    {
        try {
            $room = WorkstationGroup::query()
                ->where('id', $roomId)
                ->where('is_physical', true)
                // F9 (review 3.3) : cohérence avec builder (item non-actif invisible côté iPXE).
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->first();

            if ($room === null) {
                $this->log('ipxe.enrollment.room.failure', [
                    'action_type' => 'ipxe.enrollment.room.failure',
                    'ip' => $ip,
                    'workstation_id' => $ws->id,
                    'room_id' => $roomId,
                    'reason' => 'invalid_room_id',
                ], 'error');

                return false;
            }

            $ok = $ws->assignToPhysicalRoom($roomId);

            if ($ok) {
                $this->log('ipxe.enrollment.room.success', [
                    'action_type' => 'ipxe.enrollment.room.success',
                    'ip' => $ip,
                    'workstation_id' => $ws->id,
                    'room_id' => $roomId,
                    'room_name_prefix' => substr((string) ($room->name ?? ''), 0, 6),
                ]);

                $this->persistMachineBootLog($ws, $ip, IpxeEnrollmentFlow::Room, true);
            } else {
                $this->log('ipxe.enrollment.room.failure', [
                    'action_type' => 'ipxe.enrollment.room.failure',
                    'ip' => $ip,
                    'workstation_id' => $ws->id,
                    'room_id' => $roomId,
                    'reason' => 'save_failed',
                ], 'error');
            }

            return $ok;
        } catch (Throwable $e) {
            $this->log('ipxe.enrollment.room.failure', [
                'action_type' => 'ipxe.enrollment.room.failure',
                'ip' => $ip,
                'workstation_id' => $ws->id,
                'room_id' => $roomId,
                'reason' => 'exception',
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
            ], 'error');

            return false;
        }
    }

    /**
     * Story 3.3 — AC2.7 (amendée post-merge 2026-05-20).
     *
     * Attache un poste à un parc logique (`WorkstationGroup::is_physical = false`).
     * Délègue à {@see Workstation::attachGroups()}.
     *
     * **Note archi (décision Epic 4 antérieure à 3.3)** : l'appartenance machine→
     * groupe logique (parc) est désormais gérée **uniquement en SQL** —
     * {@see \App\Jobs\AdSync\WorkstationMembershipAdSyncJob} ne supporte plus
     * d'action `add`/`remove` (seul `move` salle subsiste). Le texte original
     * d'AC2.7 mentionnant la sync AD via observer est donc obsolète : pas de
     * dispatch AD ici, le pivot `workstation_group_workstation` est la source de
     * vérité unique.
     */
    public function attachGroup(Workstation $ws, int $groupId, string $ip = ''): bool
    {
        try {
            $group = WorkstationGroup::query()
                ->where('id', $groupId)
                ->where('is_physical', false)
                // F9 (review 3.3) : cohérence avec builder (item non-actif invisible côté iPXE).
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->first();

            if ($group === null) {
                $this->log('ipxe.enrollment.parc.failure', [
                    'action_type' => 'ipxe.enrollment.parc.failure',
                    'ip' => $ip,
                    'workstation_id' => $ws->id,
                    'group_id' => $groupId,
                    'action' => 'add',
                    'reason' => 'invalid_group_id',
                ], 'error');

                return false;
            }

            $ws->attachGroups([$groupId]);

            $this->log('ipxe.enrollment.parc.added', [
                'action_type' => 'ipxe.enrollment.parc.added',
                'ip' => $ip,
                'workstation_id' => $ws->id,
                'group_id' => $groupId,
                'group_name_prefix' => substr((string) ($group->name ?? ''), 0, 6),
            ]);

            $this->persistMachineBootLog($ws, $ip, IpxeEnrollmentFlow::ParcAdd, true);

            return true;
        } catch (Throwable $e) {
            $this->log('ipxe.enrollment.parc.failure', [
                'action_type' => 'ipxe.enrollment.parc.failure',
                'ip' => $ip,
                'workstation_id' => $ws->id,
                'group_id' => $groupId,
                'action' => 'add',
                'reason' => 'exception',
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
            ], 'error');

            return false;
        }
    }

    /**
     * Story 3.3 — AC2.7 — symétrique de {@see attachGroup()}.
     *
     * Idem note archi : SQL only, pas de dispatch AD.
     */
    public function detachGroup(Workstation $ws, int $groupId, string $ip = ''): bool
    {
        try {
            $group = WorkstationGroup::query()
                ->where('id', $groupId)
                ->where('is_physical', false)
                // F9 (review 3.3) : cohérence avec builder (item non-actif invisible côté iPXE).
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->first();

            if ($group === null) {
                $this->log('ipxe.enrollment.parc.failure', [
                    'action_type' => 'ipxe.enrollment.parc.failure',
                    'ip' => $ip,
                    'workstation_id' => $ws->id,
                    'group_id' => $groupId,
                    'action' => 'remove',
                    'reason' => 'invalid_group_id',
                ], 'error');

                return false;
            }

            // F11 (review 3.3) : vérifier appartenance avant détachement (parité legacy `enleveparc.php`).
            $ws->load('groups');
            if (! $ws->groups->contains('id', $groupId)) {
                $this->log('ipxe.enrollment.parc.failure', [
                    'action_type' => 'ipxe.enrollment.parc.failure',
                    'ip' => $ip,
                    'workstation_id' => $ws->id,
                    'group_id' => $groupId,
                    'action' => 'remove',
                    'reason' => 'not_member',
                ], 'warning');

                return false;
            }

            $ws->detachGroups([$groupId]);

            $this->log('ipxe.enrollment.parc.removed', [
                'action_type' => 'ipxe.enrollment.parc.removed',
                'ip' => $ip,
                'workstation_id' => $ws->id,
                'group_id' => $groupId,
                'group_name_prefix' => substr((string) ($group->name ?? ''), 0, 6),
            ]);

            $this->persistMachineBootLog($ws, $ip, IpxeEnrollmentFlow::ParcRemove, true);

            return true;
        } catch (Throwable $e) {
            $this->log('ipxe.enrollment.parc.failure', [
                'action_type' => 'ipxe.enrollment.parc.failure',
                'ip' => $ip,
                'workstation_id' => $ws->id,
                'group_id' => $groupId,
                'action' => 'remove',
                'reason' => 'exception',
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
            ], 'error');

            return false;
        }
    }

    /**
     * Insert `MachineBootLog` best-effort (parité 3.1 / 3.2 — un échec ne
     * doit jamais bloquer la réponse iPXE).
     */
    private function persistMachineBootLog(
        Workstation $ws,
        string $ip,
        IpxeEnrollmentFlow $flow,
        bool $success,
    ): void {
        try {
            $now = Carbon::now();
            MachineBootLog::query()->create([
                'workstation_id' => $ws->id,
                'machine_name' => strtolower((string) ($ws->name ?? '')),
                'action' => $flow->machineBootLogAction(),
                'initiated_by' => 'ipxe',
                'success' => $success,
                'started_at' => $now,
                'stopped_at' => $now,
            ]);
        } catch (Throwable $e) {
            $this->log('ipxe.machine_boot_log_failure', [
                'action_type' => 'ipxe.machine_boot_log_failure',
                'endpoint_action' => $flow->machineBootLogAction(),
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
            ], 'warning');
        }
    }

    /**
     * Helper logging — émet un log structuré channel `ipxe` (parité 3.1/3.2).
     *
     * @param  array<string,mixed>  $context
     * @param  'info'|'warning'|'error'  $level
     */
    private function log(string $event, array $context, string $level = 'info'): void
    {
        $channel = (string) config('ipxe.log.channel', 'ipxe');
        $logger = Log::channel($channel);

        match ($level) {
            'warning' => $logger->warning($event, $context),
            'error' => $logger->error($event, $context),
            default => $logger->info($event, $context),
        };
    }
}
