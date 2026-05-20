<?php

namespace App\Http\Controllers\Api\v1;

use App\Gpo\Services\WorkstationConfigContextResolver;
use App\Http\Controllers\Controller;
use App\Models\Shortcut;
use App\Services\ShortcutCompilerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôleur d'export des raccourcis pour les postes Windows/Linux.
 *
 * Remplace le legacy gpo/shortcuts_out.php.
 * Appelé par les scripts GPO au logon/startup/logoff/shutdown.
 *
 * Endpoints :
 * - GET /api/v1/shortcuts/export/script : script complet (.cmd/.sh) pour un poste
 * - GET /api/v1/shortcuts/export/file   : fichier .lnk ou .desktop individuel
 * - GET /api/v1/shortcuts/export/icon   : icône d'un raccourci (.ico/.png)
 */
class ShortcutExportController extends Controller
{
    private ShortcutCompilerService $compiler;

    public function __construct(ShortcutCompilerService $compiler)
    {
        $this->compiler = $compiler;
    }

    /**
     * Dispatche les requêtes legacy gpo/shortcuts_out.php vers les bonnes méthodes.
     *
     * Le legacy utilise un seul endpoint avec un paramètre "action" :
     * - action=logon|logoff|startup|shutdown → script()
     * - action=file + shortcut (nom) → file() (résolution nom → ID)
     * - action=icon + shortcut (nom) → icon()
     *
     * Paramètres legacy (POST ou GET) :
     * - action, os, user, machine, shortcut (nom), userprofile, id
     */
    /**
     * Story 16.13 — endpoint natif `GET /api/v1/workstation-config/shortcuts`.
     *
     * Pattern iso 16.12 strict : `workstation_uuid` extrait EXCLUSIVEMENT
     * du JWT via `$request->attributes->get('auth_v1.workstation_uuid')`.
     *
     * Le `machine` est résolu serveur via `WorkstationConfigContextResolver`
     * (lookup Workstation Eloquent par uuid). Les autres params (`action`,
     * `os`, `user`, `shortcut`, `userprofile`) restent en query (parité
     * legacy).
     *
     * Iso-fonctionnel avec `legacyDispatch()` : même switch action, mêmes
     * Content-Type, mêmes status. Déviation D5 : 404 explicite si
     * `workstation_uuid` JWT inconnu en DB.
     */
    public function apiV1(Request $request, WorkstationConfigContextResolver $resolver): Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $workstationUuid = (string) $request->attributes->get('auth_v1.workstation_uuid', '');
        $os = (string) $request->input('os', 'linux');
        $userLogin = (string) $request->input('user', '');
        $userProfile = (string) $request->input('userprofile', '');

        // F4 (review post-merge) — résolution unifiée via resolver (D4).
        // Le DTO `WorkstationConfigContext` porte `machineName` (= Workstation::name
        // Eloquent) ; pas besoin de second lookup direct.
        $ctx = $resolver->resolve($workstationUuid, $os, $userLogin, $userProfile);
        if ($ctx === null) {
            Log::channel('auth-v1')->warning('[ShortcutExportController] workstation not found', [
                'action_type' => 'agent.v1.config.workstation_not_found',
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'endpoint' => '/api/v1/workstation-config/shortcuts',
            ]);
            // Format JSON unifié post-review (Henri Q2).
            return response()->json(['error' => 'workstation_not_found'], 404);
        }

        $action = (string) $request->input('action', '');
        $shortcutName = (string) $request->input('shortcut', '');

        // Injecte le `machine` résolu serveur dans la requête, écrasant tout
        // input user-controlled éventuel — pour que les méthodes
        // partagées `script()` / `file()` / `icon()` lisent le bon nom
        // sans altération de leur signature legacy.
        $request->merge(['machine' => $ctx->machineName]);

        if ($action === '' || $os === '') {
            return response('', 400);
        }

        return match ($action) {
            'logon', 'logoff', 'startup', 'shutdown' => $this->script($request),
            'file' => $this->legacyFile($request, $shortcutName, $os),
            'icon' => $this->icon($request->merge(['name' => urldecode($shortcutName)])),
            default => response('Unknown action', 400),
        };
    }

    public function legacyDispatch(Request $request): Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $action = $request->input('action', '');
        $os = $request->input('os', 'linux');
        $shortcutName = $request->input('shortcut', '');

        if (empty($action) || empty($os)) {
            return response('', 400);
        }

        return match ($action) {
            'logon', 'logoff', 'startup', 'shutdown' => $this->script($request),
            'file' => $this->legacyFile($request, $shortcutName, $os),
            'icon' => $this->icon($request->merge(['name' => urldecode($shortcutName)])),
            default => response('Unknown action', 400),
        };
    }

    /**
     * Gère action=file du legacy : résout le nom du raccourci en ID.
     */
    private function legacyFile(Request $request, string $shortcutName, string $os): Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        // Le legacy Windows envoie le nom en ISO-8859-15
        if ($os === 'windows') {
            $shortcutName = mb_convert_encoding($shortcutName, 'UTF-8', 'ISO-8859-15');
        }

        $shortcut = Shortcut::where('name', $shortcutName)->first();
        if (!$shortcut) {
            return response('Shortcut not found: ' . $shortcutName, 404);
        }

        // Injecter shortcut_id dans la requête et déléguer à file()
        $request->merge(['shortcut_id' => $shortcut->id]);
        return $this->file($request);
    }

    /**
     * Génère le script complet de déploiement des raccourcis pour un poste.
     *
     * Paramètres attendus (GET ou POST) :
     * - os       : windows | linux
     * - action   : logon | logoff | startup | shutdown
     * - user     : nom d'utilisateur (%username%)
     * - machine  : nom de la machine (%computername%)
     * - userprofile : chemin du profil utilisateur (Windows uniquement)
     *
     * Retourne un script texte (.cmd pour Windows, .sh pour Linux).
     */
    public function script(Request $request): Response
    {
        $os = $request->input('os', 'windows');
        $action = $request->input('action', 'logon');
        $user = $request->input('user', '');
        $machine = $request->input('machine', '');
        $userprofile = $request->input('userprofile', '');

        if (empty($action) || empty($os)) {
            return response('', 400);
        }

        $script = $this->compiler->resolveForMachine(
            $machine,
            $user,
            $os,
            $action,
            $userprofile
        );

        // Log pour debug (comme le legacy)
        $logFile = "/tmp/shortcuts-{$action}-{$machine}-{$user}.log";
        @file_put_contents($logFile, $script);

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * Génère un fichier .lnk (Windows) ou .desktop (Linux) pour un raccourci.
     *
     * Paramètres :
     * - shortcut_id : ID du raccourci en base
     * - os          : windows | linux
     * - user        : nom d'utilisateur (pour substitution dynamique)
     * - userprofile : chemin du profil (Windows)
     */
    public function file(Request $request): Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $shortcutId = $request->input('shortcut_id');
        $os = $request->input('os', 'windows');
        $user = $request->input('user', '');
        $userprofile = $request->input('userprofile', '');

        $shortcut = Shortcut::find($shortcutId);
        if (!$shortcut) {
            return response('Shortcut not found', 404);
        }

        $ext = $os === 'windows' ? 'lnk' : 'desktop';
        $contentType = $os === 'windows' ? 'application/x-ms-shortcut' : 'application/x-desktop';

        // Raccourci statique : servir le fichier pré-compilé depuis le disque
        if (!$shortcut->is_dynamic) {
            $compiled = $shortcut->compiledShortcuts()
                ->where('os', $os)
                ->whereNotNull('compiled_path')
                ->first();

            if ($compiled && $compiled->compiledFileExists()) {
                return response()->file($compiled->compiled_path, [
                    'Content-Type' => $contentType,
                    'Content-Disposition' => 'attachment; filename="' . $shortcut->name . '.' . $ext . '"',
                ]);
            }
        }

        // Générer à la volée (dynamique ou pas de cache)
        if ($os === 'windows') {
            $content = $this->compiler->generateWindowsLnk($shortcut, $user, $userprofile);
        } else {
            $content = $this->compiler->generateLinuxDesktop($shortcut, $user);
        }

        if (!$content) {
            return response("Failed to generate .{$ext}", 500);
        }

        // Écrire dans un fichier temporaire et servir
        $tmpFile = tempnam(sys_get_temp_dir(), 'se4_export_');
        file_put_contents($tmpFile, $content);

        return response()->file($tmpFile, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $shortcut->name . '.' . $ext . '"',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Retourne l'icône d'un raccourci.
     *
     * Paramètres :
     * - name : nom du raccourci
     * - os   : windows (retourne .ico) | linux (retourne .png)
     */
    public function icon(Request $request): Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $name = $request->input('name', '');
        $os = $request->input('os', 'linux');

        if (empty($name)) {
            return response('Missing name', 400);
        }

        $iconPath = $this->compiler->getIconPath($name, $os);
        if (!$iconPath || !file_exists($iconPath)) {
            return response('Icon not found', 404);
        }

        $contentType = $os === 'windows' ? 'image/x-icon' : 'image/png';

        return response()->file($iconPath, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
