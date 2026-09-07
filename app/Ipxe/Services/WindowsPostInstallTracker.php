<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ldap\AdMachineManager;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hook étapes Windows post-install : reçoit les callbacks `curl
 * /ipxe/windows/action` émis (a) depuis WinPE en début d'install
 * (`etape=winpe&ret=0`), (b) depuis le 1er logon OOBE (`etape=oobe&ret=0`)
 * via `FirstLogonCommands` injecté dans `unattend.xml`, et (c)
 * depuis les 6 flows post-OOBE (sysprep, nosysprep, join, renomme, post, wpkg).
 *
 * **Port natif COMPLET** de `legacy/modules/ipxe/Win10/action.php` (733 LOC) :
 *
 *  - Branche A (premier appel `ret < 0`) : `record{Sysprep,Join,Renomme,Post,
 *    Wpkg}Initiated` + `recordNosysprep`.
 *  - Branche D (validation state machine) : `record{Sysprep,Join,Renomme,
 *    Post,Wpkg}{...}` selon le tuple (etape, ret).
 *
 * **Idempotence + concurrence** : chaque méthode wrap dans
 * `DB::transaction()` + `Workstation::lockForUpdate()` — protège contre les
 * doubles updates concurrents (2 POSTes simultanés `etape=sysprep` sur même
 * poste).
 *
 * **`Workstation::status` non touché**, comme dans `LinuxPostInstallTracker` :
 * `workstations.status` est un `varchar(20)` à domaine fermé
 * (`active|inactive|protected` — cf. scopes du modèle). Les phrases d'étape
 * iso-legacy (`'installation Windows terminee'` = 29 c., etc.) provoquaient
 * un SQLSTATE[22001] « value too long » → HTTP 500 sur les callbacks
 * `/ipxe/windows/action`. L'avancement est tracé via `progress` +
 * `programmed_action` (etape/ret) + `MachineBootLog` + logs channel `ipxe`.
 * Comme on n'écrit plus `status`, la sémantique « préserver `protected` » est
 * respectée nativement.
 *
 * **Audit MachineBootLog** : insert d'une ligne par étape avec label
 * `ipxe_win_{step}` (labels ≤ varchar(20)).
 *
 * **Logging** : channel `ipxe` events
 * `ipxe.windows.action.<step>.<state>` (pas de secrets clairs).
 */
final class WindowsPostInstallTracker
{
    /**
     * Channel Monolog dédié.
     */
    private function channel(): string
    {
        return (string) config('ipxe.log.channel', 'ipxe');
    }

    /**
     * Enregistre le début d'install WinPE pour un poste donné.
     */
    public function recordWinpeStart(
        Workstation $workstation,
        string $name = '',
        string $ip = '',
    ): void {
        // On ne touche pas `status` (cf. docblock de classe) : le début
        // d'install est tracé via MachineBootLog + le log ci-dessous.
        $this->persistMachineBootLog($workstation, 'ipxe_win_install', true, $ip);
        Log::channel($this->channel())->info('ipxe.windows.action.winpe_start', [
            'action_type' => 'ipxe.windows.action.winpe_start',
            'ip' => $ip,
            'workstation_id' => $workstation->id ?? null,
            'workstation_name_prefix' => substr((string) ($workstation->name ?? ''), 0, 6),
            'reported_name_prefix' => $name !== '' ? substr($name, 0, 6) : '',
        ]);
    }

    /**
     * Enregistre la fin d'install Windows (1er logon OOBE) pour un poste.
     */
    public function recordOobeComplete(
        Workstation $workstation,
        string $name = '',
        string $ip = '',
    ): void {
        // On ne touche pas `status` (cf. docblock de classe).
        // L'issue d'install est tracée via os + last_report_at +
        // MachineBootLog, iso LinuxPostInstallTracker.
        $workstation->os = 'windows';
        $workstation->last_report_at = Carbon::now();
        $workstation->save();
        $this->persistMachineBootLog($workstation, 'ipxe_win_report', true, $ip);

        // Consommation one-shot de la réinstallation armée. L'OOBE
        // atteint = l'OS Windows est fraîchement installé et a bootté (point
        // terminal « install terminée », parallèle du `linux_install_done`). La
        // requête active passe `done` → plus servie aux boots suivants. Best-effort,
        // À CÔTÉ de `programmed_action` (non touché).
        $this->markReinstallDone($workstation);

        Log::channel($this->channel())->info('ipxe.windows.action.oobe_complete', [
            'action_type' => 'ipxe.windows.action.oobe_complete',
            'ip' => $ip,
            'workstation_id' => $workstation->id ?? null,
            'workstation_name_prefix' => substr((string) ($workstation->name ?? ''), 0, 6),
            'reported_name_prefix' => $name !== '' ? substr($name, 0, 6) : '',
        ]);
    }

    /**
     * Enregistre la génération du install.bat WinPE.
     */
    public function recordInstallBatGenerated(Workstation $workstation, string $ip = ''): void
    {
        $this->persistMachineBootLog($workstation, 'ipxe_win_install', true, $ip);

        // Le payload WinPE est délivré : l'installeur a la main. On
        // bascule la requête armée en `installing` pour qu'elle ne soit plus
        // servie aux boots suivants (les reboots de `setup.exe` doivent tomber
        // sur le disque). Best-effort, à côté de `programmed_action`.
        $this->markReinstallInstalling($workstation);
    }

    /**
     * Émet le log warning `ipxe.windows.action.unknown_workstation`.
     */
    public function recordUnknown(string $uuid, string $name, string $ip): void
    {
        Log::channel($this->channel())->warning('ipxe.windows.action.unknown_workstation', [
            'action_type' => 'ipxe.windows.action.unknown_workstation',
            'ip' => $ip,
            'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            'reported_name_prefix' => $name !== '' ? substr($name, 0, 6) : '',
        ]);
    }

    /* Mapping iso-legacy lignes 408-727 (dispatcher branches A + D). */

    /**
     * Sysprep branche A (`ret<0` premier appel).
     *
     * Parité legacy lignes 415-428 :
     *  - Si `type ∈ {clonage, clonage2}` → progress=0%,
     *    programmed_action.role=modele.
     *  - Sinon → progress=0%.
     *
     * (Le status legacy « préparation 1er boot » n'est plus écrit.)
     */
    public function recordSysprepInitiated(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $pa = $this->paOf($ws);
            $type = (string) ($pa['type'] ?? 'default');
            $updates = ['etape' => 'sysprep'];

            if ($type === 'clonage' || $type === 'clonage2') {
                $updates['type'] = $type;
                $updates['role'] = 'modele';
            }
            $ws->progress = '0%';
            $this->mergeProgrammedAction($ws, $updates);
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_sysprep', true, $ip);
        });
        $this->logState($workstation, 'sysprep', 'initiated', $ip);
    }

    /**
     * Sysprep ret=0 (branche D ligne 527-538).
     */
    public function recordSysprepGpoStart(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'type' => 'clonage2',
                'role' => 'modele',
                'script' => 'windows',
                'ret' => 0,
            ]);
            $ws->progress = '50%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_sysprep', true, $ip);
        });
        $this->logState($workstation, 'sysprep', 'gpo_start', $ip);
    }

    /**
     * Sysprep ret=1 (branche D ligne 539-550).
     */
    public function recordSysprepGeneralized(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'role' => 'modele',
                'script' => 'rescuecd',
                'ret' => -1,
                'etape' => 'init-modele',
            ]);
            $ws->progress = '50%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_sysprep', true, $ip);
        });
        $this->logState($workstation, 'sysprep', 'generalized', $ip);
    }

    /**
     * Sysprep ret=2 (branche D ligne 551-562).
     */
    public function recordSysprepNoneClone(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'type' => 'clonage2',
                'role' => 'modele',
                'script' => 'rescuecd',
                'ret' => -1,
                'etape' => 'init-modele',
            ]);
            $ws->progress = '100%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_sysprep', true, $ip);
        });
        $this->logState($workstation, 'sysprep', 'none_clone', $ip);
    }

    /**
     * Q-2 refacto clarté — `etape=nosysprep&ret=0` SE5 distinct.
     *
     * Branche A (premier appel) : status inchangé, progress=50%, etape=nosysprep.
     * Branche D (ret=0) : update etape, log advanced.
     */
    public function recordNosysprep(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, ['etape' => 'nosysprep']);
            $ws->progress = '50%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_nosysprep', true, $ip);
        });
        $this->logState($workstation, 'nosysprep', 'initiated', $ip);
    }

    /**
     * Join branche A (`ret<0`) — port legacy lignes 436-446.
     *
     * Persiste l'OU cible (`$ou`) et le role de jonction
     * (`$role`) dans `programmed_action`. Le poste ne re-envoie PAS ces
     * paramètres dans les curls internes `ret=0/1` (cf. `join.blade.php`) ;
     * sans persistance serveur-side, le 2e render ferait `Add-Computer
     * -OUPath ''` → poste joint dans `CN=Computers` au lieu de l'OU cible.
     * Le legacy résolvait via APCu (`actions[uuid][role]`) ; SE5 utilise la
     * colonne JSONB `programmed_action` dédiée.
     */
    public function recordJoinInitiated(
        Workstation $workstation,
        string $role = '',
        string $ou = '',
        string $ip = '',
    ): void {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($role, $ou, $ip): void {
            $updates = [
                'role' => 'windows',
                'etape' => 'join',
            ];
            // Persister OU cible + role jonction (lus aux ret=0/1).
            if ($ou !== '') {
                $updates['ou'] = $ou;
            }
            if ($role !== '') {
                $updates['join_role'] = $role;
            }
            $this->mergeProgrammedAction($ws, $updates);
            $ws->progress = '0%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_join', true, $ip);
        });
        $this->logState($workstation, 'join', 'initiated', $ip);
    }

    /**
     * Join ret=0 (branche D ligne 567-577).
     */
    public function recordJoinAdminseStarted(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'type' => 'clonage2',
                'role' => 'windows',
                'script' => 'default',
                'ret' => 0,
            ]);
            $ws->progress = '30%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_join', true, $ip);
        });
        $this->logState($workstation, 'join', 'adminse_started', $ip);
    }

    /**
     * Join ret=1 (branche D ligne 578-588).
     */
    public function recordJoinDomained(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'type' => 'clonage2',
                'role' => 'windows',
                'script' => 'default',
                'ret' => 1,
            ]);
            $ws->progress = '60%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_join', true, $ip);
        });
        $this->logState($workstation, 'join', 'domained', $ip);
    }

    /**
     * Join ret=2 (branche D ligne 589-600).
     */
    public function recordJoinComplete(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'type' => 'clonage2',
                'role' => 'windows',
                'script' => 'default',
                'etape' => 'default',
                'ret' => -1,
            ]);
            $ws->progress = '100%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_join', true, $ip);
        });
        $this->logState($workstation, 'join', 'complete', $ip);
    }

    /**
     * Renomme branche A (`ret<0`) — port legacy lignes 447-456.
     */
    public function recordRenommeInitiated(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, ['etape' => 'renomme']);
            $ws->progress = '20%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_renomme', true, $ip);
        });
        $this->logState($workstation, 'renomme', 'initiated', $ip);
    }

    /**
     * Renomme ret=0 (branche D lignes 671-700) — AD rename.
     *
     * ** refactor (root-cause fix divergence PG↔AD)** :
     *
     *  - Avant : appel manuel `$adManager->renameComputer()` (samba-tool plan B)
     *    SANS écrire `$ws->name = $role` en PG → divergence permanente.
     *  - Maintenant : on écrit `$ws->name = $role` dans la transaction PG, et
     *    l'observer {@see \App\Observers\WorkstationObserver} dispatch async
     *  {@see \App\Jobs\AdSync\WorkstationAdSyncJob::rename()} qui exécute
     *    modrdn LDAP (préserve objectGUID + netbootGUID).
     *
     *  - Trade-off accepté : si le job AD échoue (3 retries × backoff
     *    10s), le PG est déjà committé → fenêtre transitoire de divergence
     *    jusqu'à retry final / alerte. Identique au pattern
     *    `WorkstationGroupAdSyncJob` en prod.
     *
     *  - Plus de `registerHardware` post-rename : modrdn préserve netbootGUID
     *    (vérifié sur VM).
     *
     *  - Le paramètre `$adManager` est conservé pour compat ABI (call-sites
     *    iPXE) mais n'est plus utilisé. Le rename AD passe par l'observer.
     *
     * Logique post-refactor :
     *  - Si `role` non vide → renommage PG via Eloquent + observer async,
     *    progress=60%.
     *  - Si `role` vide → progress=20% + MachineBootLog success=false + log
     *    warning (les phrases de status legacy ne sont plus écrites).
     */
    public function recordRenommeAdRenamed(
        Workstation $workstation,
        AdMachineManager $adManager,
        string $role = '',
        string $ip = '',
    ): void {
        unset($adManager); // Story 4.9 : conservé pour compat ABI, non utilisé.

        if ($role === '') {
            $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
                $ws->progress = '20%';
                $ws->save();
                $this->persistMachineBootLog($ws, 'ipxe_win_renomme', false, $ip);
            });
            Log::channel($this->channel())->warning('ipxe.windows.action.renomme.ad_rename_no_role', [
                'action_type' => 'ipxe.windows.action.renomme.ad_rename_no_role',
                'workstation_id' => $workstation->id ?? null,
                'ip' => $ip,
            ]);

            return;
        }

        $oldName = (string) ($workstation->name ?? '');

        $this->wrapTransaction($workstation, function (Workstation $ws) use ($role, $ip): void {
            // Fix root cause : écrire le nouveau nom côté PG.
            // L'observer Workstation dispatchera async le job AD rename
            // (modrdn LDAP, préserve objectGUID + netbootGUID).
            $ws->name = $role;

            $this->mergeProgrammedAction($ws, [
                'type' => 'renomme',
                'id' => $ws->id ?? null,
                'role' => $role,
                'script' => 'default',
                'etape' => 'default',
                'ret' => 0,
            ]);
            $ws->progress = '60%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_renomme', true, $ip);
        });

        Log::channel($this->channel())->info('ipxe.windows.action.renomme.ad_rename_success', [
            'action_type' => 'ipxe.windows.action.renomme.ad_rename_success',
            'workstation_id' => $workstation->id ?? null,
            'ip' => $ip,
            'old_name_prefix' => substr($oldName, 0, 6),
            'new_name_prefix' => substr($role, 0, 6),
        ]);
    }

    /**
     * Renomme ret=1 (branche D ligne 702-712).
     */
    public function recordRenommeFinished(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'type' => 'default',
                'script' => 'default',
                'etape' => 'default',
                'ret' => -1,
            ]);
            $ws->progress = '100%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_renomme', true, $ip);
        });
        $this->logState($workstation, 'renomme', 'finished', $ip);
    }

    /**
     * Post branche A (`ret<0`) — port legacy lignes 457-465.
     */
    public function recordPostInitiated(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, ['etape' => 'post']);
            $ws->progress = '20%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_post', true, $ip);
        });
        $this->logState($workstation, 'post', 'initiated', $ip);
    }

    /**
     * Post ret=0 (branche D ligne 619-629).
     */
    public function recordPostAutologon(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'role' => 'windows',
                'script' => 'default',
                'ret' => 0,
            ]);
            $ws->progress = '50%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_post', true, $ip);
        });
        $this->logState($workstation, 'post', 'autologon', $ip);
    }

    /**
     * Post ret=1 (branche D ligne 630-640).
     */
    public function recordPostFinished(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'role' => 'windows',
                'script' => 'default',
                'etape' => 'default',
                'ret' => -1,
            ]);
            $ws->progress = '100%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_post', true, $ip);
        });
        $this->logState($workstation, 'post', 'finished', $ip);
    }

    /**
     * Wpkg branche A (`ret<0`) — port legacy lignes 466-474.
     */
    public function recordWpkgInitiated(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, ['etape' => 'wpkg']);
            $ws->progress = '10%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_wpkg', true, $ip);
        });
        $this->logState($workstation, 'wpkg', 'initiated', $ip);
    }

    /**
     * Wpkg ret=0 (branche D ligne 644-655).
     */
    public function recordWpkgAutologon(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'role' => 'windows',
                'script' => 'default',
                'etape' => 'wpkg',
                'ret' => 0,
            ]);
            $ws->progress = '50%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_wpkg', true, $ip);
        });
        $this->logState($workstation, 'wpkg', 'autologon', $ip);
    }

    /**
     * Wpkg ret=1 (branche D ligne 656-666).
     */
    public function recordWpkgFinished(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'role' => 'windows',
                'script' => 'default',
                'etape' => 'default',
                'ret' => -1,
            ]);
            // Note: legacy ligne 664 a une typo « d'exec » avec apostrophe —
            // SE5 simplifie en ASCII pur.
            $ws->progress = '100%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_wpkg', true, $ip);
        });
        $this->logState($workstation, 'wpkg', 'finished', $ip);
    }

    /**
     * Default branche D (ligne 716-727) — fin process.
     *
     * Parité legacy : os='windows', progress=100% (le status legacy 'termine'
     * n'est pas porté).
     *
     * @internal Non dispatchée par le controller :
     * l'étape `default` (= step inconnu) est rejetée 422 par
     * {@see \App\Ipxe\Http\Requests\IpxeWindowsActionRequest} (Rule::in 8 cases)
     * AVANT d'atteindre le controller. Méthode conservée pour symétrie avec le
     * pattern tracker legacy + testabilité directe.
     */
    public function recordDefault(Workstation $workstation, string $ip = ''): void
    {
        $this->wrapTransaction($workstation, function (Workstation $ws) use ($ip): void {
            $this->mergeProgrammedAction($ws, [
                'type' => 'default',
                'script' => 'default',
                'etape' => 'default',
                'ret' => -1,
            ]);
            $ws->os = 'windows';
            $ws->progress = '100%';
            $ws->save();
            $this->persistMachineBootLog($ws, 'ipxe_win_report', true, $ip);
        });
        $this->logState($workstation, 'default', 'finished', $ip);
    }

    /* ==================================================================
     * Helpers privés.
     * ================================================================== */

    /**
     * Wrap une closure dans `DB::transaction()` + `lockForUpdate()` sur la
     * workstation — defense in depth contre les doubles updates
     * concurrents.
     *
     * Cas test (SQLite :memory:) : `lockForUpdate` est silencieusement
     * ignoré par SQLite — le wrapping reste cohérent côté API.
     *
     * Note : si la Workstation est supprimée entre la résolution
     * par {@see \App\Ipxe\Services\WorkstationLocator} et le lock (race
     * < 100ms), la closure abort silencieusement MAIS le `logState()` appelé
     * par la méthode `record*` après s'exécute quand même (log "phantom" sur
     * une instance non persistée). Accepté : risque quasi-inexistant en
     * prod, log inoffensif (audit seulement). À revoir si la rigueur d'audit
     * l'exige (retourner un bool depuis cette méthode + conditionner logState).
     *
     * @param  callable(Workstation): void  $closure
     */
    private function wrapTransaction(Workstation $workstation, callable $closure): void
    {
        DB::transaction(function () use ($workstation, $closure): void {
            // Re-fetch + lock (defense in depth contre les doubles updates).
            $locked = Workstation::query()
                ->whereKey($workstation->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                // Workstation supprimée entre-temps — abort silent.
                return;
            }
            $closure($locked);
            // Sync l'instance reçue en paramètre (le caller peut s'attendre
            // à voir les changements via refresh).
            $workstation->fill($locked->getAttributes())->exists = true;
        });
    }

    /**
     * Marque `installing` la réinstallation armée du poste dès que
     * le payload WinPE est délivré : les reboots de `setup.exe` ne doivent plus
     * se faire re-servir l'action, sinon l'installation repart de zéro sans
     * jamais atteindre l'OOBE. Best-effort — jamais bloquant.
     */
    private function markReinstallInstalling(Workstation $workstation): void
    {
        try {
            app(\App\Services\Parc\WorkstationReinstallService::class)
                ->markInstallingForWorkstation($workstation);
        } catch (Throwable $e) {
            Log::channel($this->channel())->warning('ipxe.reinstall.mark_installing_failed', [
                'action_type' => 'ipxe.reinstall.mark_installing_failed',
                'workstation_id' => $workstation->id ?? null,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
            ]);
        }
    }

    /**
     * Marque `done` la réinstallation OS armée du poste (table
     * dédiée `workstation_reinstall_requests`). Best-effort — jamais bloquant.
     */
    private function markReinstallDone(Workstation $workstation): void
    {
        try {
            app(\App\Services\Parc\WorkstationReinstallService::class)
                ->markDoneForWorkstation($workstation);
        } catch (Throwable $e) {
            Log::channel($this->channel())->warning('ipxe.reinstall.mark_done_failed', [
                'action_type' => 'ipxe.reinstall.mark_done_failed',
                'workstation_id' => $workstation->id ?? null,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
            ]);
        }
    }

    /**
     * Lit `programmed_action` comme array (cast `array` du model).
     *
     * @return array<string, mixed>
     */
    private function paOf(Workstation $workstation): array
    {
        $pa = $workstation->programmed_action ?? [];

        return is_array($pa) ? $pa : [];
    }

    /**
     * Merge programmed_action JSON cohérent (préserve clés non touchées).
     *
     * @param  array<string, mixed>  $updates
     */
    private function mergeProgrammedAction(Workstation $workstation, array $updates): void
    {
        $current = $this->paOf($workstation);
        $merged = array_merge($current, $updates);
        $workstation->programmed_action = $merged;
    }

    /**
     * Log info channel `ipxe` event `ipxe.windows.action.<step>.<state>`.
     */
    private function logState(Workstation $workstation, string $step, string $state, string $ip): void
    {
        Log::channel($this->channel())->info("ipxe.windows.action.{$step}.{$state}", [
            'action_type' => "ipxe.windows.action.{$step}.{$state}",
            'workstation_id' => $workstation->id ?? null,
            'ip' => $ip,
            'workstation_name_prefix' => substr((string) ($workstation->name ?? ''), 0, 6),
        ]);
    }

    /**
     * Insert `MachineBootLog` (best-effort). Iso `LinuxPostInstallTracker`.
     */
    private function persistMachineBootLog(
        Workstation $workstation,
        string $action,
        bool $success,
        string $ip,
    ): void {
        try {
            $now = Carbon::now();
            MachineBootLog::query()->create([
                'workstation_id' => $workstation->id ?? null,
                'machine_name' => strtolower((string) ($workstation->name ?? 'unknown')),
                'action' => $action,
                'initiated_by' => 'ipxe',
                'success' => $success,
                'started_at' => $now,
                'stopped_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::channel($this->channel())->warning('ipxe.windows.action.machine_boot_log_failure', [
                'action_type' => 'ipxe.windows.action.machine_boot_log_failure',
                'endpoint_action' => $action,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
            ]);
        }
    }
}
