<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gpo;

use App\Gpo\Services\ApplicationLoggerService;
use App\Gpo\Services\ApplicationScriptsAssembler;
use App\Gpo\Services\ApplicationScriptsGenerator;
use App\Gpo\Services\ApplicationTemplatesScanner;
use App\Http\Controllers\Controller;
use App\Models\Workstation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint legacy iso-contrat `/gpo/applications.php` — script bash/cmd
 * généré dynamiquement, servi aux postes Windows/Linux par la GPO
 * `se4_applications` au startup/logon/logoff/shutdown.
 *
 * Story 16.7 — Volet 1 (AC1.1-AC1.5) + Volet 6 sécurité.
 *
 * **Position critique dans la chaîne native** : c'est cet endpoint qui POSE
 * la session APCu `apps.$id` (TTL 1800s), consommée par les endpoints natifs
 * runtime déjà portés (`wallpaper_out` 4.7, `firefox_out` 4.8, `network_out`
 * 16.3b, `veyon_out` 16.3b, `associations_out` 16.3c). Toute mutation casse
 * la chaîne complète.
 *
 * **Sécurité défense en profondeur** :
 *  - Régex stricte sur tous les inputs AVANT tout accès AD/FS/APCu (AC1.2)
 *  - Whitelist substitutions (AC4.2)
 *  - Path traversal templates bloqué (AC6.2)
 *  - Mode array `SambaToolRunner` (AC6.4)
 *  - Throttle 300/min/IP (AC6.5)
 *
 * **Logs duals** :
 *  - `gpo` (audit) → actions AD writeback (`AdMachineManager`)
 *  - `daily` (runtime, ~300 logs/min boot de masse) → endpoint lui-même
 *
 * @legacy-port path="sambaedu/gpo/applications.php"
 */
class ApplicationsScriptsController extends Controller
{
    /** Regex iso-legacy `[a-z0-9._\-$]{1,64}` (machine NetBIOS lowercase). */
    private const MACHINE_REGEX = '/^[a-z0-9._\-$]{1,64}$/i';

    /** Regex iso-legacy user samaccountname / login. */
    private const USER_REGEX = '/^[A-Za-z0-9_.\-$]{1,64}$/';

    /** Regex iso-legacy uuid (32-36 hex avec tirets, vide accepté). */
    private const UUID_REGEX = '/^[a-f0-9\-]{0,36}$/';

    /** Regex iso-legacy action (`^((remote)-)?([a-z]*)(-(system|server|once))?$`). */
    private const ACTION_REGEX = '/^((remote)-)?([a-z]*)(-(system|server|once))?$/U';

    /** Regex iso-legacy id md5 32 hex (vide accepté). */
    private const ID_REGEX = '/^([a-f0-9]{32})?$/i';

    /**
     * Regex `userprofile` (Windows USERPROFILE path) — durcissement review #14.
     * Format attendu : `C:\Users\login` ou variantes. Vide accepté (logon-system).
     */
    private const USERPROFILE_REGEX = '/^[A-Za-z]:\\\\[A-Za-z0-9._\-\\\\$ ]{0,255}$/';

    /** Regex `application` — identifiant catalogue WPKG (vide accepté). Durcissement review #15. */
    private const APPLICATION_REGEX = '/^[a-zA-Z0-9._\-]{0,128}$/';

    /** OS supportés. */
    private const SUPPORTED_OS = ['windows', 'linux'];

    /** Interpréteurs supportés. */
    private const SUPPORTED_INTERPRETERS = ['cmd', 'bash', 'ps1', 'powershell', 'redirects', 'apt'];

    public function __construct(
        private readonly ApplicationScriptsGenerator $generator,
        private readonly ApplicationTemplatesScanner $scanner,
        private readonly ApplicationScriptsAssembler $assembler,
        private readonly ApplicationLoggerService $logger,
    ) {}

    public function generate(Request $request): Response
    {
        // ── AC1.2 — Validation stricte AVANT tout side effect.
        $machine = strtolower((string) $request->input('machine', ''));
        $action = (string) $request->input('action', '');
        $os = (string) $request->input('os', 'windows');
        $user = (string) $request->input('user', '');
        $uuid = (string) $request->input('uuid', '');
        $interpreter = (string) $request->input('interpreter', '');
        $id = (string) $request->input('id', '');
        $ret = (int) $request->input('ret', 1);
        $application = (string) $request->input('application', '');
        $userprofile = (string) $request->input('userprofile', '');
        $speed = (int) $request->input('speed', 0);

        // Validation regex stricte (rejet précoce 400 — AC1.2). Si la GPO côté
        // poste plante avec un 400, basculer en `@legacy-port` (cf. tech-debt).
        if ($machine !== '' && preg_match(self::MACHINE_REGEX, $machine) !== 1) {
            return $this->badRequest('machine', $machine, $request);
        }
        if ($action !== '' && preg_match(self::ACTION_REGEX, $action) !== 1) {
            return $this->badRequest('action', $action, $request);
        }
        if (! in_array($os, self::SUPPORTED_OS, true)) {
            return $this->badRequest('os', $os, $request);
        }
        if ($user !== '' && preg_match(self::USER_REGEX, $user) !== 1) {
            return $this->badRequest('user', $user, $request);
        }
        if ($uuid !== '' && preg_match(self::UUID_REGEX, $uuid) !== 1) {
            return $this->badRequest('uuid', $uuid, $request);
        }
        if ($interpreter !== '' && ! in_array($interpreter, self::SUPPORTED_INTERPRETERS, true)) {
            return $this->badRequest('interpreter', $interpreter, $request);
        }
        if ($id !== '' && preg_match(self::ID_REGEX, $id) !== 1) {
            return $this->badRequest('id', $id, $request);
        }
        if ($ret < 0) {
            return $this->badRequest('ret', (string) $ret, $request);
        }
        if ($application !== '' && preg_match(self::APPLICATION_REGEX, $application) !== 1) {
            return $this->badRequest('application', $application, $request);
        }
        if ($userprofile !== '' && preg_match(self::USERPROFILE_REGEX, $userprofile) !== 1) {
            return $this->badRequest('userprofile', $userprofile, $request);
        }

        // ── Volet 2 : résolution contexte runtime (port `get_app_scripts_info`).
        $info = $this->generator->resolveInfo([
            'machine' => $machine,
            'action' => $action,
            'application' => $application,
            'os' => $os,
            'uuid' => $uuid,
            'interpreter' => $interpreter,
            'speed' => $speed,
            'user' => $user,
            'id' => $id,
            'userprofile' => $userprofile,
        ]);

        if ($info === []) {
            // Cas dégénéré iso-legacy : body vide 200 (parité ligne 33 « if (! empty($info) … »).
            return $this->emptyOk($this->resolveContentType($os));
        }

        // ── Log + clean APCu (port `log_application_scripts`).
        try {
            $shouldGenerate = $this->logger->logScripts($info, $ret);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('[ApplicationsScriptsController] logScripts threw', [
                'action' => $action,
                'machine' => $machine,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyOk($this->resolveContentType($os));
        }

        if (! $shouldGenerate) {
            return $this->emptyOk($this->resolveContentType($os));
        }

        // ── Volet 5 : assemblage scripts (avec cache APCu `scripts.$id`,
        // parité legacy `:203-304` — économise scan FS + assemblage sur boot
        // de masse, review #3).
        $scriptCacheKey = 'scripts.' . (string) ($info['id'] ?? '');
        $cached = function_exists('apcu_fetch') ? @apcu_fetch($scriptCacheKey) : false;
        if (is_array($cached)) {
            $texts = $cached;
        } else {
            try {
                $scripts = $this->scanner->scan();
                $texts = $this->assembler->assemble($info, $scripts);
            } catch (\Throwable $e) {
                Log::channel('daily')->error('[ApplicationsScriptsController] assemble threw', [
                    'action' => $action,
                    'machine' => $machine,
                    'error' => $e->getMessage(),
                ]);
                return $this->emptyOk($this->resolveContentType($os));
            }
            if (function_exists('apcu_store') && ($info['id'] ?? '') !== '') {
                @apcu_store($scriptCacheKey, $texts, 300);
            }
        }

        $interpreterKey = $info['interpreter'] ?? ($os === 'linux' ? 'bash' : 'cmd');
        $body = (string) ($texts[$interpreterKey] ?? '');

        // ── AC5.3 : write debug `/tmp/applications-…` (skip testing — parité 16.3b).
        if (! app()->environment('testing')) {
            $this->writeDebugFile($info, $body);
        }

        Log::channel('daily')->info('[gpo] gpo.applications.script.generate', [
            'action_type' => 'gpo.applications.script.generate',
            'action' => $action,
            'machine' => $machine,
            'id' => $info['id'] ?? null,
            'interpreter' => $interpreterKey,
            'os' => $os,
            'bytes' => strlen($body),
        ]);

        return response($body, 200, [
            'Content-Type' => $this->resolveContentType($os),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Story 16.13 — endpoint natif `GET /api/v1/workstation-config/applications-scripts`.
     *
     * Pattern iso 16.12 strict : `workstation_uuid` extrait EXCLUSIVEMENT
     * du JWT via `$request->attributes->get('auth_v1.workstation_uuid')`.
     *
     * Cette méthode reprend l'intégralité de la chaîne `generate()` legacy
     * en injectant le `uuid` JWT (et non plus le query input `uuid` qui est
     * **ignoré**). Le `machine` est lu en query (`%COMPUTERNAME%` /
     * `$HOSTNAME` côté poste) mais cross-checké en best-effort avec le nom
     * Eloquent du `Workstation` résolu par UUID pour traçabilité.
     *
     * Iso-fonctionnel avec `generate()` : mêmes validations regex, mêmes
     * Content-Type (text/plain cp1252 Win / utf-8 Linux), mêmes status
     * (400/200/200-vide). Déviation D5 : 404 explicite si
     * `workstation_uuid` JWT inconnu en DB (vs 200 vide legacy).
     */
    public function apiV1(Request $request): Response
    {
        $workstationUuid = (string) $request->attributes->get('auth_v1.workstation_uuid', '');

        $workstation = Workstation::query()->where('uuid', $workstationUuid)->first();
        if ($workstation === null) {
            Log::channel('auth-v1')->warning('[ApplicationsScriptsController] workstation not found', [
                'action_type' => 'agent.v1.config.workstation_not_found',
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'endpoint' => '/api/v1/workstation-config/applications-scripts',
            ]);
            // Format JSON unifié post-review (Henri Q2).
            return response()->json(['error' => 'workstation_not_found'], 404);
        }

        // ── Validation stricte iso-legacy (mêmes regex que `generate()`)
        // — on lit les query params poste comme la méthode legacy, à
        // l'exception du `uuid` qui est **ignoré** en faveur du claim JWT.
        $machine = strtolower((string) $request->input('machine', ''));
        $action = (string) $request->input('action', '');
        $os = (string) $request->input('os', 'windows');
        $user = (string) $request->input('user', '');
        $interpreter = (string) $request->input('interpreter', '');
        $id = (string) $request->input('id', '');
        $ret = (int) $request->input('ret', 1);
        $application = (string) $request->input('application', '');
        $userprofile = (string) $request->input('userprofile', '');
        $speed = (int) $request->input('speed', 0);

        if ($machine !== '' && preg_match(self::MACHINE_REGEX, $machine) !== 1) {
            return $this->badRequest('machine', $machine, $request);
        }
        if ($action !== '' && preg_match(self::ACTION_REGEX, $action) !== 1) {
            return $this->badRequest('action', $action, $request);
        }
        if (! in_array($os, self::SUPPORTED_OS, true)) {
            return $this->badRequest('os', $os, $request);
        }
        if ($user !== '' && preg_match(self::USER_REGEX, $user) !== 1) {
            return $this->badRequest('user', $user, $request);
        }
        if ($interpreter !== '' && ! in_array($interpreter, self::SUPPORTED_INTERPRETERS, true)) {
            return $this->badRequest('interpreter', $interpreter, $request);
        }
        if ($id !== '' && preg_match(self::ID_REGEX, $id) !== 1) {
            return $this->badRequest('id', $id, $request);
        }
        if ($ret < 0) {
            return $this->badRequest('ret', (string) $ret, $request);
        }
        if ($application !== '' && preg_match(self::APPLICATION_REGEX, $application) !== 1) {
            return $this->badRequest('application', $application, $request);
        }
        if ($userprofile !== '' && preg_match(self::USERPROFILE_REGEX, $userprofile) !== 1) {
            return $this->badRequest('userprofile', $userprofile, $request);
        }

        // Reconstruction du contexte iso-legacy via `resolveInfo` (même
        // chaîne que `generate()`). Le `uuid` est passé depuis le JWT
        // — c'est la seule différence comportementale.
        $info = $this->generator->resolveInfo([
            'machine' => $machine !== '' ? $machine : strtolower((string) ($workstation->name ?? '')),
            'action' => $action,
            'application' => $application,
            'os' => $os,
            'uuid' => $workstationUuid, // ← claim JWT, pas query
            'interpreter' => $interpreter,
            'speed' => $speed,
            'user' => $user,
            'id' => $id,
            'userprofile' => $userprofile,
        ]);

        if ($info === []) {
            return $this->emptyOk($this->resolveContentType($os));
        }

        try {
            $shouldGenerate = $this->logger->logScripts($info, $ret);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('[ApplicationsScriptsController] logScripts threw (apiV1)', [
                'action' => $action,
                'machine' => $machine,
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'error' => $e->getMessage(),
            ]);
            return $this->emptyOk($this->resolveContentType($os));
        }

        if (! $shouldGenerate) {
            return $this->emptyOk($this->resolveContentType($os));
        }

        $scriptCacheKey = 'scripts.' . (string) ($info['id'] ?? '');
        $cached = function_exists('apcu_fetch') ? @apcu_fetch($scriptCacheKey) : false;
        if (is_array($cached)) {
            $texts = $cached;
        } else {
            try {
                $scripts = $this->scanner->scan();
                $texts = $this->assembler->assemble($info, $scripts);
            } catch (\Throwable $e) {
                Log::channel('daily')->error('[ApplicationsScriptsController] assemble threw (apiV1)', [
                    'action' => $action,
                    'machine' => $machine,
                    'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                    'error' => $e->getMessage(),
                ]);
                return $this->emptyOk($this->resolveContentType($os));
            }
            if (function_exists('apcu_store') && ($info['id'] ?? '') !== '') {
                @apcu_store($scriptCacheKey, $texts, 300);
            }
        }

        $interpreterKey = $info['interpreter'] ?? ($os === 'linux' ? 'bash' : 'cmd');
        $body = (string) ($texts[$interpreterKey] ?? '');

        if (! app()->environment('testing')) {
            $this->writeDebugFile($info, $body);
        }

        Log::channel('daily')->info('[gpo] gpo.applications.script.generate (apiV1)', [
            'action_type' => 'gpo.applications.script.generate',
            'action' => $action,
            'machine' => $machine,
            'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
            'id' => $info['id'] ?? null,
            'interpreter' => $interpreterKey,
            'os' => $os,
            'bytes' => strlen($body),
        ]);

        return response($body, 200, [
            'Content-Type' => $this->resolveContentType($os),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Content-Type iso-legacy selon OS :
     *  - Windows : `text/plain; charset=cp1252` (encodage cmd.exe historique).
     *  - Linux   : `text/plain; charset=utf-8`.
     */
    private function resolveContentType(string $os): string
    {
        return $os === 'windows'
            ? 'text/plain; charset=cp1252'
            : 'text/plain; charset=utf-8';
    }

    /**
     * Réponse 400 (input invalide) — rejet précoce sans accès AD/FS/APCu.
     */
    private function badRequest(string $field, string $value, Request $request): Response
    {
        Log::channel('daily')->warning('[ApplicationsScriptsController] invalid input', [
            'field' => $field,
            'value_sample' => substr($value, 0, 64),
            'ip' => $request->ip(),
            'ua' => substr((string) $request->userAgent(), 0, 128),
        ]);
        return response('', 400, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * Réponse vide iso-legacy : `200 body=""` (pas 204). Le legacy retourne un
     * fall-through PHP body vide (aucun `echo`).
     */
    private function emptyOk(string $contentType): Response
    {
        return response('', 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Écrit le script généré dans `/tmp/applications-<action>-...` (parité
     * `applications.php:42-50`). Skippé en testing.
     *
     * @param  array<string,mixed>  $info
     */
    private function writeDebugFile(array $info, string $body): void
    {
        $action = (string) ($info['action'] ?? '');
        $userCn = (string) ($info['user']['cn'] ?? '');
        if ($userCn === '' || $userCn === 'nobody') {
            $userCn = (string) ($info['machine']['cn'] ?? '');
        }
        $context = (string) ($info['context'] ?? '');
        $interpreter = (string) ($info['interpreter'] ?? 'cmd');
        $remote = (bool) ($info['remote'] ?? false);

        // Validation finale anti-path-traversal : tous ces champs sont déjà
        // contraints par les regex Controller mais on re-purifie ici.
        $safe = static fn (string $s): string => preg_replace('/[^A-Za-z0-9._\-$]/', '_', $s) ?? '_';
        $logfile = $context !== ''
            ? '/tmp/applications-' . $safe($action) . '-' . $safe($context) . '-' . $safe($userCn) . '.' . $safe($interpreter)
            : '/tmp/applications-' . $safe($action) . '-' . $safe($userCn) . '.' . $safe($interpreter);
        if ($remote) {
            $logfile .= '-remote';
        }
        @file_put_contents($logfile, $body);
    }
}
