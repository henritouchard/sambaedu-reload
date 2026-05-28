<?php

declare(strict_types=1);

namespace App\Jobs\AdSync;

use App\Ldap\AdMachineManager;
use App\LdapModels\MachineModel;
use App\Models\Workstation;
use App\Observers\WorkstationObserver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Story 4.9 — Job unifié pour la synchronisation des Workstation vers l'AD.
 *
 * Pattern miroir strict de {@see WorkstationGroupAdSyncJob} :
 *  - Constantes ACTION_*
 *  - Factory methods statiques
 *  - tries=3, backoff=10
 *  - handle() dispatch sur l'action
 *
 * Actions supportées :
 *  - create : assure l'existence du compte machine AD (via AdMachineManager
 *             samba-tool — D2) puis stocke `ad_guid` PG via `withoutSync`.
 *  - rename : modrdn LDAP préservant objectGUID + netbootGUID (D1, validé VM
 *             2026-05-28). Repose sAMAccountName / dNSHostName / SPN.
 *  - delete : suppression du compte AD via LdapRecord (idempotent).
 *  - status : pose `userAccountControl` selon mapping D5
 *             (active|protected → 4096 ; inactive → 4098 ; autre → throw).
 *  - update : (D#3 review 4.9) action fusionnée rename+status — dispatchée
 *             par l'observer UNIQUEMENT quand `name` et `status` changent
 *             dans le même `save()`. Une seule transaction LDAP, évite la
 *             race « status job exécuté avant rename ».
 *
 * Idempotence systématique : chaque handler relit l'état AD et no-op + log
 * si l'état cible est déjà atteint.
 */
class WorkstationAdSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public const ACTION_CREATE = 'create';
    public const ACTION_RENAME = 'rename';
    public const ACTION_DELETE = 'delete';
    public const ACTION_STATUS = 'status';

    /**
     * Décision design #3 (Henri 2026-05-28) : action fusionnée rename+status,
     * dispatchée par l'observer UNIQUEMENT quand `name` ET `status` changent
     * dans le même `save()`. Garantit une seule transaction LDAP et évite la
     * race condition « status job exécuté avant rename → findBy(newName) null ».
     */
    public const ACTION_UPDATE = 'update';

    /** UAC : WORKSTATION_TRUST_ACCOUNT (0x1000). */
    private const UAC_WORKSTATION_ACTIVE = 4096;

    /** UAC : WORKSTATION_TRUST_ACCOUNT + ACCOUNTDISABLE (0x1000 + 0x0002). */
    private const UAC_WORKSTATION_INACTIVE = 4098;

    public function __construct(
        public int|string $workstationId,
        public string $action,
        public array $params = []
    ) {
    }

    // ========================================================================
    // FACTORY METHODS
    // ========================================================================

    public static function create(int $workstationId): self
    {
        return new self($workstationId, self::ACTION_CREATE);
    }

    public static function rename(int $workstationId, string $oldName, string $newName): self
    {
        return new self($workstationId, self::ACTION_RENAME, [
            'old_name' => $oldName,
            'new_name' => $newName,
        ]);
    }

    public static function delete(string $name, ?string $adGuid = null): self
    {
        return new self($name, self::ACTION_DELETE, [
            'name' => $name,
            'ad_guid' => $adGuid,
        ]);
    }

    public static function status(int $workstationId, string|int $newStatus): self
    {
        return new self($workstationId, self::ACTION_STATUS, [
            'status' => (string) $newStatus,
        ]);
    }

    /**
     * Factory action `update` fusionnée (rename + status en une seule
     * transaction LDAP). Cf. {@see ACTION_UPDATE} et décision design #3.
     */
    public static function update(int $workstationId, string $oldName, string $newName, string $newStatus): self
    {
        return new self($workstationId, self::ACTION_UPDATE, [
            'old_name' => $oldName,
            'new_name' => $newName,
            'status' => $newStatus,
        ]);
    }

    // ========================================================================
    // HANDLER
    // ========================================================================

    public function handle(AdMachineManager $adMachineManager): void
    {
        Log::info('[WorkstationAdSyncJob] Début', [
            'action' => $this->action,
            'id' => $this->workstationId,
            'params' => $this->params,
        ]);

        $result = match ($this->action) {
            self::ACTION_CREATE => $this->handleCreate($adMachineManager),
            self::ACTION_RENAME => $this->handleRename(),
            self::ACTION_DELETE => $this->handleDelete(),
            self::ACTION_STATUS => $this->handleStatus(),
            self::ACTION_UPDATE => $this->handleUpdate(),
            default => ['success' => false, 'error' => "Action inconnue: {$this->action}"],
        };

        if (!$result['success']) {
            Log::error('[WorkstationAdSyncJob] Échec', [
                'action' => $this->action,
                'id' => $this->workstationId,
                'error' => $result['error'] ?? 'Erreur inconnue',
            ]);
            throw new \RuntimeException($result['error'] ?? 'Erreur inconnue');
        }

        Log::info('[WorkstationAdSyncJob] Succès', [
            'action' => $this->action,
            'id' => $this->workstationId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[WorkstationAdSyncJob] Job échoué définitivement', [
            'action' => $this->action,
            'id' => $this->workstationId,
            'params' => $this->params,
            'error' => $exception->getMessage(),
        ]);
    }

    // ========================================================================
    // ACTION HANDLERS
    // ========================================================================

    private function handleCreate(AdMachineManager $adMachineManager): array
    {
        $ws = $this->findWorkstation();
        if ($ws === null) {
            // Le ws a pu être supprimé entre dispatch et handle — pas une erreur.
            Log::warning('[WorkstationAdSyncJob] handleCreate: workstation introuvable', [
                'id' => $this->workstationId,
            ]);
            return ['success' => true];
        }

        $name = (string) $ws->name;
        if ($name === '') {
            return ['success' => false, 'error' => 'Workstation sans nom'];
        }

        // Idempotence : si le compte AD existe déjà, on log et on continue
        // pour récupérer ad_guid + registerHardware.
        $existing = MachineModel::findBy('cn', $name);
        if ($existing !== null) {
            Log::info('[WorkstationAdSyncJob] handleCreate: compte AD existant (idempotent)', [
                'name' => $name,
                'workstation_id' => $ws->id,
            ]);
        } else {
            // D2 : create reste via AdMachineManager (samba-tool — gère password
            // random + UAC initiaux + idempotence "already exists").
            $created = $adMachineManager->check($name);
            if (!$created) {
                return ['success' => false, 'error' => "AdMachineManager::check() a échoué pour {$name}"];
            }
            $existing = MachineModel::findBy('cn', $name);
        }

        // Récupérer objectGUID et stocker ad_guid en PG sans déclencher l'observer.
        if ($existing !== null) {
            $guid = $existing->getConvertedGuid();
            if (!empty($guid) && $guid !== $ws->ad_guid) {
                WorkstationObserver::withoutSync(function () use ($ws, $guid, $existing): void {
                    $ws->ad_guid = $guid;
                    $dn = $existing->getDn();
                    if (!empty($dn)) {
                        $ws->ad_dn = $dn;
                    }
                    $ws->save();
                });
            }
        }

        // registerHardware si UUID dispo (parité legacy enrollment).
        $uuid = (string) ($ws->uuid ?? '');
        if ($uuid !== '') {
            try {
                $adMachineManager->registerHardware($name, $uuid);
            } catch (\Throwable $e) {
                Log::warning('[WorkstationAdSyncJob] handleCreate: registerHardware échec (best-effort)', [
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['success' => true];
    }

    /**
     * Renomme le compte AD via modrdn LDAP (préserve objectGUID + netbootGUID).
     *
     * Auto-fix #7 (review 4.9) : si `resolveDomain()` retourne `''`
     * (config `sambaedu.domain` vide — typiquement dev/CI fraîche), on émet
     * un warning explicite et on NE pose PAS `dnsHostName` ni
     * `servicePrincipalName` (qui auraient été tronqués/cassés silencieusement
     * et auraient pu briser Kerberos). `samAccountName` reste posé car il
     * n'a pas besoin du domaine.
     */
    private function handleRename(): array
    {
        $oldName = (string) ($this->params['old_name'] ?? '');
        $newName = (string) ($this->params['new_name'] ?? '');

        if ($oldName === '' || $newName === '') {
            return ['success' => false, 'error' => 'Paramètres old_name et new_name requis'];
        }

        if (strcasecmp($oldName, $newName) === 0) {
            Log::info('[WorkstationAdSyncJob] handleRename: oldName === newName (no-op)', [
                'name' => $oldName,
            ]);
            return ['success' => true];
        }

        $machine = MachineModel::findBy('cn', $oldName);

        // Idempotence : si l'ancien CN n'existe plus mais le nouveau existe →
        // rename déjà appliqué, on no-op.
        if ($machine === null) {
            $newExisting = MachineModel::findBy('cn', $newName);
            if ($newExisting !== null) {
                Log::warning('[WorkstationAdSyncJob] handleRename: oldCn absent et newCn présent (déjà renommé)', [
                    'old' => $oldName,
                    'new' => $newName,
                ]);
                return ['success' => true];
            }
            Log::warning('[WorkstationAdSyncJob] handleRename: compte AD introuvable', [
                'old' => $oldName,
                'new' => $newName,
            ]);
            return ['success' => true];
        }

        $domain = $this->resolveDomain();

        // D1 — modrdn LDAP (préserve objectGUID + netbootGUID, validé VM 2026-05-28).
        $machine->rename('CN=' . $newName);

        // Repose les attributs dérivés du CN (samba-tool computer rename les
        // posait manuellement ; modrdn ne touche QUE le RDN/cn).
        $machine->samaccountname = strtoupper($newName) . '$';
        if ($domain !== '') {
            $machine->dnshostname = $newName . '.' . $domain;
            $machine->serviceprincipalname = [
                'HOST/' . $newName,
                'HOST/' . $newName . '.' . $domain,
            ];
        } else {
            // Auto-fix #7 : pas de fallback "cassé" — skip + warning.
            Log::warning('[WorkstationAdSyncJob] handleRename: SAMBAEDU_DOMAIN vide, dnsHostName/SPN non posés', [
                'old' => $oldName,
                'new' => $newName,
            ]);
        }
        $machine->save();

        Log::info('[WorkstationAdSyncJob] handleRename: succès (modrdn)', [
            'old' => $oldName,
            'new' => $newName,
        ]);

        return ['success' => true];
    }

    /**
     * Auto-fix #9 (review 4.9) : priorité `ad_guid` (identifiant stable
     * survivant à un rename hors-Sambaedu) sur `cn` (mutable). L'observer
     * passe `ad_guid` dans `deleting()`. Fallback `findBy('cn', $name)` si
     * guid absent (workstations PG legacy non encore back-fillées).
     */
    private function handleDelete(): array
    {
        $name = (string) ($this->params['name'] ?? (is_string($this->workstationId) ? $this->workstationId : ''));
        $adGuid = (string) ($this->params['ad_guid'] ?? '');

        if ($name === '' && $adGuid === '') {
            return ['success' => false, 'error' => 'Paramètre name ou ad_guid requis pour la suppression'];
        }

        $machine = null;

        if ($adGuid !== '') {
            try {
                $machine = MachineModel::findByGuid($adGuid);
            } catch (\Throwable $e) {
                Log::warning('[WorkstationAdSyncJob] handleDelete: findByGuid a échoué, fallback sur cn', [
                    'ad_guid' => $adGuid,
                    'error' => $e->getMessage(),
                ]);
                $machine = null;
            }

            if ($machine !== null) {
                $foundCn = (string) $machine->getFirstAttribute('cn');
                if ($name !== '' && strcasecmp($foundCn, $name) !== 0) {
                    // Audit : compte AD renommé hors-Sambaedu détecté.
                    Log::warning('[WorkstationAdSyncJob] handleDelete: cn AD diffère du name PG (rename hors-Sambaedu détecté)', [
                        'pg_name' => $name,
                        'ad_cn' => $foundCn,
                        'ad_guid' => $adGuid,
                    ]);
                }
            }
        }

        if ($machine === null && $name !== '') {
            $machine = MachineModel::findBy('cn', $name);
        }

        if ($machine === null) {
            Log::info('[WorkstationAdSyncJob] handleDelete: compte AD déjà absent (idempotent)', [
                'name' => $name,
                'ad_guid' => $adGuid,
            ]);
            return ['success' => true];
        }

        $machine->delete();

        Log::info('[WorkstationAdSyncJob] handleDelete: succès', [
            'name' => $name,
            'ad_guid' => $adGuid,
        ]);

        return ['success' => true];
    }

    private function handleStatus(): array
    {
        $ws = $this->findWorkstation();
        if ($ws === null) {
            Log::warning('[WorkstationAdSyncJob] handleStatus: workstation introuvable', [
                'id' => $this->workstationId,
            ]);
            return ['success' => true];
        }

        // Auto-fix #4 (review 4.9) : priorité à la valeur fraîche relue en
        // DB sur la valeur figée au dispatch. Si un status a changé pendant
        // un backoff, on applique la valeur courante (convergence).
        $status = (string) ($ws->status ?: ($this->params['status'] ?? ''));
        $name = (string) $ws->name;
        if ($name === '') {
            return ['success' => false, 'error' => 'Workstation sans nom'];
        }

        // D5 — mapping figé status PG → UAC AD.
        $uac = match ($status) {
            'active', 'protected' => self::UAC_WORKSTATION_ACTIVE,
            'inactive' => self::UAC_WORKSTATION_INACTIVE,
            default => throw new \InvalidArgumentException(
                "Status AD non supporté : '{$status}' (workstation_id={$ws->id})"
            ),
        };

        $machine = MachineModel::findBy('cn', $name);
        if ($machine === null) {
            Log::warning('[WorkstationAdSyncJob] handleStatus: compte AD introuvable', [
                'name' => $name,
                'workstation_id' => $ws->id,
            ]);
            return ['success' => true];
        }

        // Idempotence : si la valeur UAC est déjà la bonne, no-op.
        $currentUacRaw = $machine->getFirstAttribute('useraccountcontrol');
        if ($currentUacRaw !== null && (int) $currentUacRaw === $uac) {
            Log::info('[WorkstationAdSyncJob] handleStatus: UAC déjà conforme (idempotent)', [
                'name' => $name,
                'uac' => $uac,
            ]);
            return ['success' => true];
        }

        $machine->useraccountcontrol = $uac;
        $machine->save();

        Log::info('[WorkstationAdSyncJob] handleStatus: succès', [
            'name' => $name,
            'status' => $status,
            'uac' => $uac,
        ]);

        return ['success' => true];
    }

    /**
     * Décision design #3 (Henri 2026-05-28) : opération fusionnée
     * rename + status en une seule transaction LDAP.
     *
     * Idempotence : si `findBy('cn', $oldName)` retourne null on log info et
     * on return success (le rename a déjà été appliqué hors-Sambaedu, ou
     * la machine n'a jamais été synchronisée AD).
     *
     * Pas de write-back PG (`ad_guid` ne change pas — modrdn préserve le
     * objectGUID natif AD).
     */
    private function handleUpdate(): array
    {
        $oldName = (string) ($this->params['old_name'] ?? '');
        $newName = (string) ($this->params['new_name'] ?? '');
        $status = (string) ($this->params['status'] ?? '');

        if ($oldName === '' || $newName === '' || $status === '') {
            return ['success' => false, 'error' => 'Paramètres old_name, new_name et status requis'];
        }

        // D5 — mapping figé (parité handleStatus).
        $uac = match ($status) {
            'active', 'protected' => self::UAC_WORKSTATION_ACTIVE,
            'inactive' => self::UAC_WORKSTATION_INACTIVE,
            default => throw new \InvalidArgumentException(
                "Status AD non supporté : '{$status}' (workstation_id={$this->workstationId})"
            ),
        };

        $machine = MachineModel::findBy('cn', $oldName);

        // Idempotence : si l'ancien CN n'existe plus mais le nouveau existe →
        // rename déjà appliqué, on tente quand même la pose du UAC.
        if ($machine === null) {
            $newExisting = MachineModel::findBy('cn', $newName);
            if ($newExisting !== null) {
                Log::warning('[WorkstationAdSyncJob] handleUpdate: oldCn absent et newCn présent (déjà renommé), pose UAC seul', [
                    'old' => $oldName,
                    'new' => $newName,
                ]);
                $newExisting->useraccountcontrol = $uac;
                $newExisting->save();
                return ['success' => true];
            }
            Log::info('[WorkstationAdSyncJob] handleUpdate: compte AD introuvable (idempotent)', [
                'old' => $oldName,
                'new' => $newName,
            ]);
            return ['success' => true];
        }

        if (strcasecmp($oldName, $newName) !== 0) {
            $domain = $this->resolveDomain();

            // D1 — modrdn LDAP.
            $machine->rename('CN=' . $newName);

            $machine->samaccountname = strtoupper($newName) . '$';
            if ($domain !== '') {
                $machine->dnshostname = $newName . '.' . $domain;
                $machine->serviceprincipalname = [
                    'HOST/' . $newName,
                    'HOST/' . $newName . '.' . $domain,
                ];
            } else {
                Log::warning('[WorkstationAdSyncJob] handleUpdate: SAMBAEDU_DOMAIN vide, dnsHostName/SPN non posés', [
                    'old' => $oldName,
                    'new' => $newName,
                ]);
            }
        }

        $machine->useraccountcontrol = $uac;
        $machine->save();

        Log::info('[WorkstationAdSyncJob] handleUpdate: succès (rename+status fusionné)', [
            'old' => $oldName,
            'new' => $newName,
            'status' => $status,
            'uac' => $uac,
        ]);

        return ['success' => true];
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function findWorkstation(): ?Workstation
    {
        if (!is_int($this->workstationId) && !ctype_digit((string) $this->workstationId)) {
            return null;
        }
        return Workstation::find((int) $this->workstationId);
    }

    /**
     * Résolution du domaine AD (FQDN), utilisé pour construire dnsHostName
     * et servicePrincipalName. Source : `sambaedu.domain` (cf. config/sambaedu.php:370).
     */
    private function resolveDomain(): string
    {
        $domain = (string) config('sambaedu.domain', '');
        return strtolower(trim($domain));
    }
}
