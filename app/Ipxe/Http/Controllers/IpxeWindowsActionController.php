<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Enums\WindowsInstallStep;
use App\Ipxe\Exceptions\BatPlaceholderInjectionException;
use App\Ipxe\Http\Requests\IpxeWindowsActionRequest;
use App\Ipxe\Services\WindowsActionCmdBuilder;
use App\Ipxe\Services\WindowsPostInstallTracker;
use App\Ipxe\Services\WorkstationLocator;
use App\Ldap\AdMachineManager;
use App\Models\Workstation;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 3.5 — AC5.6 / D2.
 * Story 3.8 — D4 / AC6.1-6.8 — extension dispatcher state machine 8 cases.
 *
 * Controller du endpoint `GET|POST /ipxe/windows/action` (port natif COMPLET
 * `sambaedu/ipxe/Win10/action.php` — 8 cases enum désormais : `winpe`, `oobe`
 * (3.5) + `sysprep`, `nosysprep`, `join`, `renomme`, `post`, `wpkg` (3.8)).
 *
 * **Parité legacy** (`install.bat.php` + `unattend.xml:112`) :
 *   curl -F 'etape=winpe'   -F 'name=PC-101' -F 'ret=0' http://se4fs/ipxe/windows/action
 *   curl -F 'etape=oobe'    -F 'name=%computername%' -o action.cmd http://se4fs/ipxe/windows/action
 *   curl -F 'etape=sysprep' -F 'name=PC-101' http://se4fs/ipxe/windows/action  (3.8)
 *   curl -F 'etape=join'    -F 'name=PC-101' -F 'ret=0' http://se4fs/ipxe/windows/action  (3.8)
 *   ...
 *
 * **Flow 3.8** :
 *  1. Reçoit (name, uuid, etape, ret, role, ou) via multipart form-data.
 *  2. Résout la Workstation par UUID via {@see WorkstationLocator}. Si null
 *     → 200 + body vide + log warning (D4).
 *  3. Parse `etape` via {@see WindowsInstallStep::fromString()}. Si null →
 *     200 + log warning `ipxe.windows.action.unsupported_step`.
 *  4. Check toggle config `ipxe.windows.post_install.enabled` + flag par étape
 *     (D13). Si désactivé → 200 + log warning + body vide.
 *  5. Dispatcher :
 *     - `winpe` + `ret='0'` → `tracker->recordWinpeStart()` (inchangé 3.5).
 *     - `oobe`  + `ret='0'` → `tracker->recordOobeComplete()` (inchangé 3.5).
 *     - `sysprep|nosysprep|join|renomme|post|wpkg` × (ret=-1|0|1|2) → dispatch
 *       vers `handle<Step>()` qui retourne `array{body: string}`.
 *  6. Response 200 + body cmd batch (text/plain) + headers D10 (Cache-Control,
 *     X-Robots-Tag).
 *
 * **Sécurité** :
 *  - Catch {@see BatPlaceholderInjectionException} → 200 + log warning
 *    `placeholder_injection_attempt` + body vide (AC6.7).
 *  - Toutes les exceptions builder/tracker autres → 200 + log warning + body
 *    vide (best-effort — un poste Windows ne doit jamais recevoir 5xx).
 *
 * @phpstan-type HandlerResult array{body: string, log_event: string}
 */
class IpxeWindowsActionController extends Controller
{
    public function __construct(
        private readonly WorkstationLocator $locator,
        private readonly WindowsPostInstallTracker $tracker,
        private readonly WindowsActionCmdBuilder $cmdBuilder,
        private readonly AdMachineManager $adManager,
    ) {
    }

    public function handle(IpxeWindowsActionRequest $request): Response
    {
        $name = (string) $request->input('name', '');
        $uuid = (string) $request->input('uuid', '');
        $mac = (string) $request->input('mac', '');
        $rawEtape = (string) $request->input('etape', '');
        $rawRet = $request->input('ret');
        $role = (string) $request->input('role', '');
        $ou = (string) $request->input('ou', '');
        $ip = (string) ($request->ip() ?? '');

        // 1. Résolution Workstation (priorité UUID, fallback MAC iso 3.4 D4).
        $workstation = $this->locator->locate($mac, $uuid, '');
        if ($workstation === null) {
            $this->tracker->recordUnknown($uuid, $name, $ip);

            return $this->respondPlainEmpty();
        }

        // 2. Parse étape via enum whitelist (D5 — defense in depth).
        $step = WindowsInstallStep::fromString($rawEtape);
        if ($step === null) {
            Log::channel($this->channel())->warning('ipxe.windows.action.unsupported_step', [
                'action_type' => 'ipxe.windows.action.unsupported_step',
                'ip' => $ip,
                'workstation_id' => $workstation->id ?? null,
                'raw_etape' => $this->sanitizeRaw($rawEtape),
                'ret' => $this->sanitizeRaw((string) ($rawRet ?? '')),
            ]);

            return $this->respondPlainEmpty();
        }

        // 3. Story 3.8 D13 — Check toggle config global + flag par étape.
        if (! $this->isStepEnabled($step)) {
            Log::channel($this->channel())->warning('ipxe.windows.action.step_disabled', [
                'action_type' => 'ipxe.windows.action.step_disabled',
                'ip' => $ip,
                'workstation_id' => $workstation->id ?? null,
                'step' => $step->value,
            ]);

            return $this->respondPlainEmpty();
        }

        // 4. Parse ret (defense in depth — FormRequest valide déjà via Rule::in).
        $ret = $this->parseRet($rawRet);

        // 5. Dispatcher state machine — 8 cases enum.
        try {
            $body = match ($step) {
                WindowsInstallStep::Winpe => $this->handleWinpe($workstation, $name, $ret, $ip),
                WindowsInstallStep::Oobe => $this->handleOobe($workstation, $name, $ret, $ip),
                WindowsInstallStep::Sysprep => $this->handleSysprep($workstation, $ret, $ip),
                WindowsInstallStep::Nosysprep => $this->handleNosysprep($workstation, $ret, $ip),
                WindowsInstallStep::Join => $this->handleJoin($workstation, $ret, $role, $ou, $ip),
                WindowsInstallStep::Renomme => $this->handleRenomme($workstation, $ret, $role, $ip),
                WindowsInstallStep::Post => $this->handlePost($workstation, $ret, $ip),
                WindowsInstallStep::Wpkg => $this->handleWpkg($workstation, $ret, $ip),
            };
        } catch (BatPlaceholderInjectionException $e) {
            // AC6.7 — body vide + log warning + 200 (ne pas crash).
            Log::channel($this->channel())->warning('ipxe.windows.action.placeholder_injection_attempt', [
                'action_type' => 'ipxe.windows.action.placeholder_injection_attempt',
                'ip' => $ip,
                'workstation_id' => $workstation->id ?? null,
                'step' => $step->value,
                'ret' => $ret,
                'message' => substr($e->getMessage(), 0, 200),
            ]);

            return $this->respondPlainEmpty();
        } catch (Throwable $e) {
            // Best-effort : tout autre exception → log warning + body vide.
            Log::channel($this->channel())->warning('ipxe.windows.action.handler_exception', [
                'action_type' => 'ipxe.windows.action.handler_exception',
                'ip' => $ip,
                'workstation_id' => $workstation->id ?? null,
                'step' => $step->value,
                'ret' => $ret,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
            ]);

            return $this->respondPlainEmpty();
        }

        return $this->respondPlain($body);
    }

    /* ====================================================================
     * Handlers state machine — 8 cases.
     * ==================================================================== */

    /**
     * winpe (3.5 — inchangé).
     */
    private function handleWinpe(Workstation $ws, string $name, int $ret, string $ip): string
    {
        if ($ret === 0) {
            $this->tracker->recordWinpeStart($ws, $name, $ip);
        } else {
            $this->logNonZeroRet($ws, 'winpe', $ret, $ip);
        }

        return '';
    }

    /**
     * oobe (3.5 — inchangé sur ret=0 + ajout Story 3.8 D-A4 dispatch default
     * sur ret>0 = post-install OK).
     */
    private function handleOobe(Workstation $ws, string $name, int $ret, string $ip): string
    {
        if ($ret === 0) {
            $this->tracker->recordOobeComplete($ws, $name, $ip);
        } else {
            $this->logNonZeroRet($ws, 'oobe', $ret, $ip);
        }

        return '';
    }

    /**
     * sysprep (3.8 — D4 / D7).
     *
     * Branche A (ret<0) : `recordSysprepInitiated` + body cmd_sysprep si
     * `type ∈ {clonage, clonage2}` (sinon body vide — progress=0%).
     * Branche D (ret=0/1/2) : `recordSysprep{GpoStart,Generalized,NoneClone}`
     * + body vide.
     */
    private function handleSysprep(Workstation $ws, int $ret, string $ip): string
    {
        if ($ret < 0) {
            $this->tracker->recordSysprepInitiated($ws, $ip);
            $ws->refresh();
            $type = (string) ($this->paOf($ws)['type'] ?? 'default');
            if ($type === 'clonage' || $type === 'clonage2') {
                return $this->cmdBuilder->buildSysprep($ws);
            }

            return '';
        }

        match ($ret) {
            0 => $this->tracker->recordSysprepGpoStart($ws, $ip),
            1 => $this->tracker->recordSysprepGeneralized($ws, $ip),
            2 => $this->tracker->recordSysprepNoneClone($ws, $ip),
            default => $this->logNonZeroRet($ws, 'sysprep', $ret, $ip),
        };

        return '';
    }

    /**
     * nosysprep (3.8 — Q-2 refacto clarté).
     *
     * Branche A (ret<0) : body cmd_nosysprep + recordNosysprep (progress=50%).
     * Branche D (ret=0) : recordNosysprep — pas de body.
     *
     * Note Q-2 : le legacy n'a pas de cas ret=0 distinct pour nosysprep — le
     * SE5 refactore avec etape=nosysprep distinct pour clarté state machine.
     */
    private function handleNosysprep(Workstation $ws, int $ret, string $ip): string
    {
        if ($ret < 0) {
            // Body cmd_nosysprep + initiated.
            $this->tracker->recordNosysprep($ws, $ip);

            return $this->cmdBuilder->buildNosysprep($ws);
        }

        // ret=0 — Q-2 refacto : recordNosysprep réutilisé (status inchangé,
        // progress=50%). Pas de body.
        if ($ret === 0) {
            $this->tracker->recordNosysprep($ws, $ip);

            return '';
        }

        $this->logNonZeroRet($ws, 'nosysprep', $ret, $ip);

        return '';
    }

    /**
     * join (3.8 — D4 / D7).
     *
     * Branche A (ret<0) : body cmd_join + recordJoinInitiated.
     * Branche B (ret=0) : body cmd_join + recordJoinAdminseStarted (parité
     * legacy 495-500 : re-renvoie cmd_join + status="mise au domaine v2").
     * Branche C (ret=1) : body cmd_join + recordJoinDomained (parité 506-511).
     * Branche D (ret=2) : recordJoinComplete + body vide (parité 589-600).
     */
    private function handleJoin(Workstation $ws, int $ret, string $role, string $ou, string $ip): string
    {
        if ($ret < 0) {
            // Review #3 — persiste role/ou pour les re-render aux ret=0/1.
            $this->tracker->recordJoinInitiated($ws, $role, $ou, $ip);
            $ws->refresh();

            return $this->cmdBuilder->buildJoin($ws, $role, $ou);
        }

        if ($ret === 0) {
            $this->tracker->recordJoinAdminseStarted($ws, $ip);
            $ws->refresh();
            // Review #3 — le poste ne re-envoie pas role/ou au 2e curl ;
            // fallback sur programmed_action (parité legacy APCu serveur-side).
            [$resolvedRole, $resolvedOu] = $this->resolveJoinRoleOu($ws, $role, $ou);

            return $this->cmdBuilder->buildJoin($ws, $resolvedRole, $resolvedOu);
        }

        if ($ret === 1) {
            $this->tracker->recordJoinDomained($ws, $ip);
            $ws->refresh();
            [$resolvedRole, $resolvedOu] = $this->resolveJoinRoleOu($ws, $role, $ou);

            return $this->cmdBuilder->buildJoin($ws, $resolvedRole, $resolvedOu);
        }

        if ($ret === 2) {
            $this->tracker->recordJoinComplete($ws, $ip);

            return '';
        }

        $this->logNonZeroRet($ws, 'join', $ret, $ip);

        return '';
    }

    /**
     * renomme (3.8 — D4 / D7 / D14).
     *
     * Branche A (ret<0) : body cmd_renomme + recordRenommeInitiated.
     * Branche D (ret=0) : recordRenommeAdRenamed (AD rename via AdMachineManager).
     * Branche D (ret=1) : recordRenommeFinished + body vide.
     */
    private function handleRenomme(Workstation $ws, int $ret, string $role, string $ip): string
    {
        if ($ret < 0) {
            $this->tracker->recordRenommeInitiated($ws, $ip);

            return $this->cmdBuilder->buildRenomme($ws, $role);
        }

        if ($ret === 0) {
            // D14 — AD rename via AdMachineManager (best-effort).
            // Si role vide → lookup dans programmed_action (parité legacy 674
            // qui lit `actions[uuid][role]`).
            $resolvedRole = $role !== '' ? $role : (string) ($this->paOf($ws)['role'] ?? '');
            $this->tracker->recordRenommeAdRenamed($ws, $this->adManager, $resolvedRole, $ip);

            return '';
        }

        if ($ret === 1) {
            $this->tracker->recordRenommeFinished($ws, $ip);

            return '';
        }

        $this->logNonZeroRet($ws, 'renomme', $ret, $ip);

        return '';
    }

    /**
     * post (3.8 — D4 / D7).
     *
     * Branche A (ret<0) : body cmd_post + recordPostInitiated.
     * Branche B (ret=0) : body cmd_post + recordPostAutologon (parité legacy
     * 491-494 : re-renvoie cmd_post pour le 2e tour autologon).
     * Branche D (ret=1) : recordPostFinished + body vide.
     */
    private function handlePost(Workstation $ws, int $ret, string $ip): string
    {
        if ($ret < 0) {
            $this->tracker->recordPostInitiated($ws, $ip);

            return $this->cmdBuilder->buildPost($ws);
        }

        if ($ret === 0) {
            $this->tracker->recordPostAutologon($ws, $ip);

            return $this->cmdBuilder->buildPost($ws);
        }

        if ($ret === 1) {
            $this->tracker->recordPostFinished($ws, $ip);

            return '';
        }

        $this->logNonZeroRet($ws, 'post', $ret, $ip);

        return '';
    }

    /**
     * wpkg (3.8 — D4 / D7).
     *
     * Branche A (ret<0) : body cmd_wpkg + recordWpkgInitiated.
     * Branche D (ret=0) : recordWpkgAutologon + body vide.
     * Branche D (ret=1) : recordWpkgFinished + body vide.
     */
    private function handleWpkg(Workstation $ws, int $ret, string $ip): string
    {
        if ($ret < 0) {
            $this->tracker->recordWpkgInitiated($ws, $ip);

            return $this->cmdBuilder->buildWpkg($ws);
        }

        if ($ret === 0) {
            $this->tracker->recordWpkgAutologon($ws, $ip);

            return '';
        }

        if ($ret === 1) {
            $this->tracker->recordWpkgFinished($ws, $ip);

            return '';
        }

        $this->logNonZeroRet($ws, 'wpkg', $ret, $ip);

        return '';
    }

    /* ====================================================================
     * Helpers privés.
     * ==================================================================== */

    /**
     * Parse `ret` reçu en multipart. Defense in depth — la FormRequest a
     * déjà validé `Rule::in(['0','1','2','-1'])`. Default = -1 (= absent ou
     * non-zéro pour parité legacy).
     */
    private function parseRet(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return -1;
        }
        if (! is_numeric($raw)) {
            return -1;
        }

        return (int) $raw;
    }

    /**
     * Check toggle config `ipxe.windows.post_install.enabled` global + flag
     * par étape (D13). Retourne `true` si l'étape est activée.
     *
     * Note D-3.5 : `winpe` et `oobe` SONT toggleables aussi (defense rollback
     * total) MAIS leur comportement par défaut reste opérationnel.
     */
    private function isStepEnabled(WindowsInstallStep $step): bool
    {
        // winpe + oobe restent toujours actifs (comportement 3.5 préservé).
        if ($step === WindowsInstallStep::Winpe || $step === WindowsInstallStep::Oobe) {
            return true;
        }

        if (! (bool) config('ipxe.windows.post_install.enabled', true)) {
            return false;
        }
        $flagKey = "ipxe.windows.post_install.{$step->value}_enabled";

        return (bool) config($flagKey, true);
    }

    /**
     * Lit programmed_action (array safe).
     *
     * @return array<string, mixed>
     */
    private function paOf(Workstation $ws): array
    {
        $pa = $ws->programmed_action ?? [];

        return is_array($pa) ? $pa : [];
    }

    /**
     * Review #3 — résout role/ou pour le re-render cmd_join aux curls ret=0/1.
     *
     * Le poste ne re-envoie pas ces paramètres (cf. `join.blade.php` curls
     * internes) ; on retombe sur les valeurs persistées dans
     * `programmed_action` à `recordJoinInitiated`. Garantit que
     * `Add-Computer -OUPath` cible bien l'OU initiale et non `CN=Computers`.
     *
     * @return array{0: string, 1: string} [role, ou]
     */
    private function resolveJoinRoleOu(Workstation $ws, string $role, string $ou): array
    {
        $pa = $this->paOf($ws);
        $resolvedRole = $role !== '' ? $role : (string) ($pa['join_role'] ?? '');
        $resolvedOu = $ou !== '' ? $ou : (string) ($pa['ou'] ?? '');

        return [$resolvedRole, $resolvedOu];
    }

    private function logNonZeroRet(Workstation $ws, string $step, int $ret, string $ip): void
    {
        Log::channel($this->channel())->warning('ipxe.windows.action.non_zero_ret', [
            'action_type' => 'ipxe.windows.action.non_zero_ret',
            'ip' => $ip,
            'workstation_id' => $ws->id ?? null,
            'etape' => $step,
            'ret' => $ret,
        ]);
    }

    private function sanitizeRaw(string $raw): string
    {
        $truncated = substr($raw, 0, 32);
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $truncated);

        return $clean ?? $truncated;
    }

    /**
     * Réponse 200 text/plain avec body non vide (cmd batch CRLF strict 3.8).
     */
    private function respondPlain(string $body): Response
    {
        return (new Response($body, 200))
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * Réponse 200 text/plain avec body vide (parité legacy `action.php:519`).
     */
    private function respondPlainEmpty(): Response
    {
        return $this->respondPlain('');
    }

    private function channel(): string
    {
        return (string) config('ipxe.log.channel', 'ipxe');
    }
}
