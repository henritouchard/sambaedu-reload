<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrateur de génération des scripts applications.
 *
 * Story 16.7 — option DO2 (c) hybride (tranchement par défaut) : un seul
 * service `ApplicationScriptsAssembler` agrège les ports natifs des 13
 * fonctions legacy `applications.inc.php`. La granularité 1:1 (13 services)
 * a été écartée par effet de fragmentation excessive ; la granularité 3:N
 * par interpréteur (`CmdScriptGenerator`...) imbriquait trop la logique.
 *
 * **Port natif** de :
 *  - `make_application_scripts()` (`applications.inc.php:201-307`) → {@see assemble()}
 *  - `add_scripts()`              (`:321-342`) → {@see addScripts()}
 *  - `header_scripts()`           (`:352-409`) → {@see headerScripts()}
 *  - `footer_scripts()`           (`:419-436`) → {@see footerScripts()}
 *  - `once_scripts()`             (`:488-532`) → {@see onceScripts()}
 *  - `redirect_scripts()`         (`:553-647`) → {@see redirectScripts()}
 *  - `sudo_scripts()`             (`:649-662`) → {@see sudoScripts()}
 *  - `wpkg_scripts()`             (`:664-675`) → {@see wpkgScripts()}
 *  - `apt_scripts()`              (`:677-690`) → {@see aptScripts()}
 *  - `local_admin_scripts()`      (`:692-773`) → {@see localAdminScripts()}
 *  - `powershell_scripts()`       (`:445-478`) → {@see powershellScripts()}
 *  - `applySubstitutions()`       (port de `write_param()` legacy)
 *
 * **Iso-bytes obligatoire** : préserve CR/LF, charset, séparateurs `REM
 * script[...]\r\n` et `# script[...]\n`.
 *
 * @legacy-port path="sambaedu/includes/applications.inc.php (13 fonctions assemblage)"
 */
class ApplicationScriptsAssembler
{
    /** Liste blanche des clés de substitution (chargée depuis config). */
    private ?array $substitutionsCache = null;

    /**
     * Story 16.7 post-review #4 (2026-05-13) : permission Spatie native qui
     * remplace le composite legacy `SE_COMPUTER_ADMIN` (0xEF00) dans la
     * condition d'élévation locale `local_admin_scripts`.
     *
     * Choix `computer.elevate` (et non `computer.install`) :
     *  - `SambaPermission::ComputerElevate` est la seule permission qui
     *    déclare `requiresGpoSync() === true` (cf. `app/Enums/SambaPermission.php:189`)
     *    — c'est sa raison d'être : élever l'utilisateur en admin local Windows.
     *  - Le composite legacy `SE_COMPUTER_ADMIN` contient `SE_COMPUTER_ELEVATE`
     *    (cf. `legacy/ldap.inc.php:2973`), donc tout user porteur de l'ancien
     *    bit composite avait aussi `0x400` → mappé sur `computer.elevate`.
     *  - `PermissionService::canOnWorkstationGroup($user, 'computer.elevate', $group)`
     *    couvre déjà l'équivalent natif de `have_delegation($machine, SE_COMPUTER_ADMIN, $user)`.
     *
     * Référence : matrice rôles × permissions
     * `_bmad-output/planning-artifacts/profiles-rights-matrix.md`.
     */
    private const LOCAL_ADMIN_PERMISSION = 'computer.elevate';

    /**
     * Story 16.7 post-review #4 (2026-05-13) : injection du `PermissionService`
     * Spatie pour câbler `localAdminScripts` au pendant natif des fonctions
     * legacy `have_right`/`have_delegation`. Optionnel pour rétro-compat des
     * appelants existants qui construisaient l'Assembler sans DI — fallback
     * sur le container Laravel via {@see resolvePermissionService()}.
     */
    public function __construct(
        private readonly ?PermissionService $permissionService = null,
    ) {}

    /**
     * Assemble les scripts pour tous les interpréteurs (cmd/bash/powershell/server).
     *
     * Retourne un dict indexé par interpréteur, valeurs = string concaténée.
     *
     * @param  array<string,mixed>  $info  Contexte ApcuAppContextWriter.
     * @param  list<array<string,mixed>>  $scripts  Sortie de ApplicationTemplatesScanner::scan.
     * @return array<string,string>  ['cmd' => "...", 'bash' => "...", 'powershell' => "...", 'server' => "..."]
     */
    public function assemble(array $info, array $scripts): array
    {
        $out = [
            'cmd' => [],
            'powershell' => [],
            'bash' => [],
            'apt' => [],
            'server' => [],
        ];

        $outHeader = $this->headerScripts($info);
        $packages = '';

        // Scripts spéciaux selon le contexte (parité legacy switch :215-223).
        switch ($info['context'] ?? '') {
            case 'system':
                $this->addScripts($info, 'local_admin', $out, $this->localAdminScripts($info));
                break;
            case '':
                $this->addScripts($info, 'sudo', $out, $this->sudoScripts($info));
                break;
        }

        // Scripts applications filtrés par includes/excludes/apps/OS.
        foreach ($scripts as $script) {
            if (! $this->isScriptApplicable($script, $info)) {
                continue;
            }
            if (($script['action'] ?? '') !== ($info['action'] ?? '')) {
                continue;
            }
            if (($script['os'] ?? '') !== ($info['os'] ?? '')) {
                continue;
            }

            if (($script['context'] ?? '') === 'server') {
                // Scripts côté serveur générés à part (iso-legacy :233).
                if (! empty($script['script']) && is_array($script['script'])) {
                    $out['server'] = array_merge($out['server'], $script['script']);
                }
                continue;
            }

            switch ($script['action']) {
                case 'startup':
                    switch ($script['context'] ?? '') {
                        case 'once':
                            $this->addScripts($info, $script['app'] ?? '', $out, $this->onceScripts($script));
                            break;
                        default:
                            $this->addScripts($info, $script['app'] ?? '', $out, $script);
                    }
                    if (($script['interpreter'] ?? '') === 'apt') {
                        $packages .= (string) (is_array($script['script']) ? implode('', $script['script']) : $script['script']);
                    }
                    break;
                case 'logon':
                    if (($info['context'] ?? '') === ($script['context'] ?? '')) {
                        if (($script['interpreter'] ?? '') === 'redirects') {
                            $this->addScripts($info, 'redirect-' . ($script['app'] ?? ''), $out, $this->redirectScripts($info, $script));
                        } else {
                            $this->addScripts($info, $script['app'] ?? '', $out, $script);
                        }
                    }
                    break;
                case 'logoff':
                    if (($info['context'] ?? '') === ($script['context'] ?? '')) {
                        $this->addScripts($info, $script['app'] ?? '', $out, $script);
                    }
                    break;
                case 'shutdown':
                    $this->addScripts($info, $script['app'] ?? '', $out, $script);
                    break;
                case 'wpkg':
                    $this->addScripts($info, 'wpkg_' . ($script['app'] ?? ''), $out, $this->wpkgScripts($info, $script));
                    break;
            }
        }

        // Apt scripts au startup (parité :282-285).
        if (($info['action'] ?? '') === 'startup') {
            $this->addScripts($info, 'apt', $out, $this->aptScripts($info, $packages), prepend: true);
        }

        $this->powershellScripts($info, $out);

        // Concaténation finale par interpréteur (header + body + footer).
        $footers = $this->footerScripts($info);
        $texts = [];
        foreach (['cmd', 'powershell', 'bash', 'server', 'apt'] as $interp) {
            $head = $outHeader[$interp] ?? [];
            $body = $out[$interp] ?? [];
            $foot = $footers[$interp] ?? [];
            $merged = array_merge($head, $body, $foot);
            $texts[$interp] = $this->applySubstitutions(implode('', $merged));
        }

        return $texts;
    }

    /**
     * Vérifie si un script s'applique au contexte runtime (filtres
     * includes/excludes/_apps/system+userprofile).
     *
     * Iso-legacy :225-229.
     *
     * @param  array<string,mixed>  $script
     * @param  array<string,mixed>  $info
     */
    private function isScriptApplicable(array $script, array $info): bool
    {
        $listLower = array_map('strtolower', (array) ($info['list'] ?? []));
        $listAppsLower = array_map('strtolower', (array) ($info['liste_applications'] ?? []));

        // include OS: linux ne filtre pas par profil, system requiert userprofile pour windows.
        $include = (($info['os'] ?? '') === 'linux')
            || ($info['context'] ?? '') !== 'system'
            || ! empty($info['userprofile']);

        $includes = array_map('strtolower', (array) ($script['includes'] ?? []));
        $excludes = array_map('strtolower', (array) ($script['excludes'] ?? []));
        $includesApps = array_map('strtolower', (array) ($script['includes_apps'] ?? []));
        $excludesApps = array_map('strtolower', (array) ($script['excludes_apps'] ?? []));

        $include = $include
            && (empty($includes) || count(array_intersect($includes, $listLower)) > 0)
            && count(array_intersect($excludes, $listLower)) === 0
            && (empty($includesApps) || count(array_intersect($includesApps, $listAppsLower)) > 0)
            && count(array_intersect($excludesApps, $listAppsLower)) === 0;

        return $include;
    }

    // ────────────────────── ports legacy individuels ──────────────────────

    /**
     * Port `add_scripts()` legacy (`:321-342`).
     *
     * @param  array<string,mixed>  $info
     * @param  array<string,array<int,string>>  $out  Référence mutée.
     * @param  array<string,mixed>  $script  Avec clés `interpreter` + `script` (array).
     */
    private function addScripts(array $info, string $name, array &$out, array $script, bool $prepend = false): void
    {
        $interpreter = (string) ($script['interpreter'] ?? '');
        $lines = $script['script'] ?? null;
        if (! is_array($lines) || $lines === []) {
            return;
        }
        if (! isset($out[$interpreter])) {
            $out[$interpreter] = [];
        }

        $separators = [
            'cmd'        => ["\r\nREM script [" . $name . "]\r\n"],
            'bash'       => ["\n# script[" . $name . "]\n"],
            'powershell' => ["\r\n# script[" . $name . "]\r\n"],
            'apt'        => ["\n"],
        ];
        $sep = $separators[$interpreter] ?? [];

        if ($prepend) {
            $out[$interpreter] = array_merge($sep, $lines, $out[$interpreter]);
        } else {
            $out[$interpreter] = array_merge($out[$interpreter], $sep, $lines);
        }
    }

    /**
     * Port `header_scripts()` legacy (`:352-409`).
     *
     * @param  array<string,mixed>  $info
     * @return array<string, list<string>>
     */
    private function headerScripts(array $info): array
    {
        $header = [
            'cmd' => [],
            'powershell' => [],
            'bash' => [],
            'server' => ["#!/bin/bash\n"],
            'apt' => [],
        ];

        $se4fsName = (string) (config('sambaedu.se4fs_name') ?: '');
        $domain = (string) (config('sambaedu.domain') ?: '');
        $uai = (string) (config('sambaedu.uai') ?: '');

        $machineCn = (string) ($info['machine']['cn'] ?? '');
        $userCn = (string) ($info['user']['cn'] ?? '');
        $action = (string) ($info['action'] ?? '');
        $os = (string) ($info['os'] ?? 'windows');
        $id = (string) ($info['id'] ?? '');
        $salle = (string) ($info['salle'] ?? '');
        $userprofile = (string) ($info['userprofile'] ?? '');

        if ($os === 'windows' && $action === 'startup') {
            // Iso-legacy :362 — domainsid lu via `sudo net getdomainsid`. On
            // ne reproduit pas cet appel `exec` côté natif (sécurité +
            // testabilité) : la valeur reste vide, le poste relit via le
            // mécanisme samba local si nécessaire. @legacy-port — comportement
            // gracieux acceptable car DOMAINSID est rarement consommé par
            // les scripts générés (audit).
            $domainsid = '';
            $header['cmd'] = [
                "REM cmd\r\n"
                . "REM script de configuration des applications windows pour " . $machineCn . "\r\n"
                . "SET DOMAINSID=" . $domainsid . "\r\n"
                . "SET TAG=" . $salle . "," . $uai . "\r\n"
                . "FOR /f \"delims=\" %%s IN ('powershell -NoLogo -NoProfile -Command \"(Get-CimInstance -ClassName Win32_NetworkAdapter | Where-Object { \$_.NetEnabled -eq \$true } | Select-Object -ExpandProperty Speed)\"') DO (\r\n"
                . "    SET SPEED=%%s\r\n"
                . "    GOTO speed\r\n"
                . ")\r\n"
                . ":speed\r\n"
                . "for /f \"delims=\" %%a in ('powershell -NoLogo -NoProfile -Command \"(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID\"') do (set \"UUID=%%a\"\r\n"
                . "goto uuid)\r\n"
                . ":uuid\r\n"
                . "SET id=" . $id . "\r\n"
                . "IF [%SE4FS%]==[] (\r\n"
                . "    SET SE4FS=" . $se4fsName . "\r\n"
                . "    SETX SE4FS " . $se4fsName . " /m\r\n"
                . ")\r\n",
            ];
            $header['powershell'] = [
                "# script powershell de configuration des applications windows pour " . $machineCn . "\r\n",
            ];
        } elseif (($info['interpreter'] ?? '') === 'powershell') {
            $header['powershell'] = [
                "# script powershell " . $action . "\r\n",
            ];
        } else {
            $header['cmd'] = [
                "REM cmd\r\n"
                . "REM " . $action . " pour " . $machineCn . " et " . $userCn . " \r\n"
                . "REM script de configuration des applications windows profile=" . $userprofile . "\r\n"
                . "SET id=" . $id . "\r\n"
                . "IF [%SE4FS%]==[] (\r\n"
                . "    SET SE4FS=" . $se4fsName . "\r\n"
                . ")\r\n",
            ];
            // Iso-bytes legacy (`applications.inc.php:400-406`) : lignes vides `\n\n`
            // entre chaque ligne, héritées de LF source PHP entre concaténations.
            $header['bash'] = [
                "#!/bin/bash\n#" . $action . "\n\n"
                . "# script de configuration des applications Linux\n\n"
                . "id=" . $id . "\n\n"
                . "SE4FS=" . $se4fsName . "\n\n"
                . "URL=http://" . $se4fsName . "." . $domain . "\n",
            ];
        }

        return $header;
    }

    /**
     * Port `footer_scripts()` legacy (`:419-436`).
     *
     * @return array<string, list<string>>
     */
    private function footerScripts(array $info): array
    {
        $se4fsName = (string) (config('sambaedu.se4fs_name') ?: '');
        $domain = (string) (config('sambaedu.domain') ?: '');
        $action = (string) ($info['action'] ?? '');
        $os = (string) ($info['os'] ?? 'windows');
        $id = (string) ($info['id'] ?? '');

        return [
            'cmd' => [
                "\r\ncurl -F \"os=" . $os . "\" -F \"uuid=%UUID%\" -F \"speed=%SPEED%\" -F \"id=" . $id . "\"  -F \"ret=0\"  \"http://" . $se4fsName . "/gpo/applications.php\">NUL\r\n",
            ],
            'bash' => [
                "\n/usr/bin/curl -s -F \"id=" . $id . "\" -F \"action=" . $action . "\" -F \"os=" . $os . "\" -F \"ret=0\" http://" . $se4fsName . "." . $domain . "/gpo/applications.php\n",
            ],
            'powershell' => ["# fin du script powershell\r\n"],
            'server' => ["# fin du script server\n"],
            'apt' => [],
        ];
    }

    /**
     * Port `powershell_scripts()` legacy (`:445-478`). Mute `$out` en place.
     *
     * @param  array<string, list<string>>  $out
     */
    private function powershellScripts(array $info, array &$out): void
    {
        if (empty($out['powershell'])) {
            return;
        }
        $action = (string) ($info['action'] ?? '');
        if (! empty($info['context'])) {
            $action .= '-' . $info['context'];
        }
        $run = match ($action) {
            'startup', 'logon-system', 'logoff-system', 'shutdown', 'wpkg'
                => "\"%ProgramFiles%\\Sambaedu\\powershellTask.ps1\" -Action:" . $action,
            default => "\"%TEMP%\\applications-" . $action . ".ps1\"",
        };
        $id = (string) ($info['id'] ?? '');
        $se4fsName = (string) (config('sambaedu.se4fs_name') ?: '');

        $out['cmd'][] = "REM DL et exec powershell\r\n"
            . "IF EXIST \"%TEMP%\\applications-" . $action . ".ps1\" (\r\n"
            . "    DEL /F /Q \"%TEMP%\\applications-" . $action . ".ps1\"\r\n"
            . ")\r\n"
            . "curl -o \"%TEMP%\\applications-" . $action . ".ps1\" -F \"interpreter=powershell\" -F \"id=" . $id . "\"  \"http://" . $se4fsName . "/gpo/applications.php\">NUL\r\n"
            . "IF EXIST \"%TEMP%\\applications-" . $action . ".ps1\" (\r\n"
            . "    IF EXIST \"%ProgramFiles%\\Powershell\\7\\pwsh.exe\" (\r\n"
            . "        pwsh.exe -NoProfile -ExecutionPolicy Bypass -File " . $run . "\r\n"
            . "    ) ELSE (\r\n"
            . "        powershell.exe -NoProfile -ExecutionPolicy Bypass -File " . $run . "\r\n"
            . "    )\r\n"
            . ")\r\n";
    }

    /**
     * Port `once_scripts()` legacy (`:488-532`).
     *
     * @param  array<string,mixed>  $script
     * @return array{interpreter: string, script: list<string>}
     */
    private function onceScripts(array $script): array
    {
        $interpreter = (string) ($script['interpreter'] ?? '');
        $name = ($script['app'] ?? '') . '-' . ($script['file'] ?? '') . '-' . $interpreter;
        $filePath = ($script['path'] ?? '') . '/' . ($script['file'] ?? '');
        $md5 = is_file($filePath) ? (string) md5_file($filePath) : '';

        $body = is_array($script['script'] ?? null) ? $script['script'] : [];

        $out = ['interpreter' => $interpreter, 'script' => []];
        switch ($interpreter) {
            case 'bash':
                $out['script'] = array_merge(
                    [
                        "[ -f \"/etc/sambaedu/applications/" . $name . ".md5\" ] && local_md5=\$(cat /etc/sambaedu/applications/" . $name . ".md5)\n",
                        "if [ \"\${local_md5}\" != \"$md5\" ] ; then\n",
                    ],
                    $body,
                    [
                        "    echo \"" . $md5 . "\">\"/etc/sambaedu/applications/" . $name . ".md5\"\n",
                        "fi\n",
                    ],
                );
                break;
            case 'cmd':
                $out['script'] = array_merge(
                    [
                        "if exist  \"%windir%\\" . $name . ".md5\" (for /F \"usebackq delims=\" %%A in (\"%windir%\\" . $name . ".md5\") do (set \"local_md5=%%A\"))\r\n",
                        "if [%local_md5%] NEQ [$md5] (\r\n",
                    ],
                    $body,
                    [
                        "    echo " . $md5 . ">%windir%\\" . $name . ".md5\r\n",
                        ")\r\n",
                    ],
                );
                break;
            case 'powershell':
                $out['script'] = array_merge(
                    [
                        "if (Test-Path \"\$env:windir\\" . $name . ".md5\") {\n",
                        "    \$local_md5 = Get-Content -Path \"\$env:windir\\" . $name . ".md5\"\n",
                        "}\n",
                        "if (\$local_md5 -ne \"" . $md5 . "\") {\n",
                    ],
                    $body,
                    [
                        "    \"" . $md5 . "\" | Set-Content -Path \"\$env:windir\\" . $name . ".md5\"\n",
                        "}\n",
                    ],
                );
                break;
        }
        return $out;
    }

    /**
     * Port `redirect_scripts()` legacy (`:553-647`). Logique cmd Windows
     * pour mklink /j et /d entre Local/Roaming/Server.
     *
     * @param  array<string,mixed>  $info
     * @param  array<string,mixed>  $script
     * @return array{interpreter: string, script: list<string>}
     */
    private function redirectScripts(array $info, array $script): array
    {
        $listAppsLower = array_map('strtolower', (array) ($info['liste_applications'] ?? []));
        $appLower = strtolower((string) ($script['app'] ?? ''));
        if (! in_array($appLower, $listAppsLower, true)) {
            return ['interpreter' => 'cmd', 'script' => ['']];
        }

        $out = '';
        $userprofile = (string) ($info['userprofile'] ?? '');
        $userCn = (string) ($info['user']['cn'] ?? '');
        $link = (string) ($script['link'] ?? '');
        $dest = (string) ($script['dest'] ?? '');
        $server = (string) ($script['server'] ?? '');
        $context = (string) ($info['context'] ?? '');

        if ($link !== '') {
            if ($server !== '') {
                if ($context === '') {
                    // jonction Local→Roaming→Server (logon, contexte vide).
                    $ps = explode('\\', $server);
                    if (count($ps) > 1) {
                        $d = '';
                        foreach ($ps as $p) {
                            $d .= '\\' . $p;
                            $out .= "IF NOT EXIST \"\\\\%SE4FS%\\users\\" . $userCn . $d . "\" (MD \"\\\\%SE4FS%\\users\\" . $userCn . $d . "\")\r\n";
                        }
                    }
                    $ps = explode('\\', $link);
                    if (count($ps) > 1) {
                        array_pop($ps);
                        $d = '';
                        foreach ($ps as $p) {
                            $d .= '\\' . $p;
                            $out .= "IF NOT EXIST \"" . $userprofile . $d . "\" (MD \"" . $userprofile . $d . "\")\r\n";
                        }
                    }
                    if ($dest !== '') {
                        $ps = explode('\\', $dest);
                        if (count($ps) > 1) {
                            array_pop($ps);
                            $d = '';
                            foreach ($ps as $p) {
                                $d .= '\\' . $p;
                                $out .= "IF NOT EXIST \"" . $userprofile . $d . "\" (MD \"" . $userprofile . $d . "\")\r\n";
                            }
                        }
                        $out .= "IF EXIST \"" . $userprofile . "\\" . $link . "\" (RD /Q \"" . $userprofile . "\\" . $link . "\")\r\n";
                        $out .= "IF NOT EXIST \"" . $userprofile . "\\" . $link . "\" (MKLINK /J \"" . $userprofile . "\\" . $link . "\"  \"" . $userprofile . "\\" . $dest . "\")\r\n";
                    }
                } else {
                    // logon-system : lien vers le dossier serveur.
                    if ($dest !== '') {
                        $out .= "IF EXIST \"" . $userprofile . "\\" . $dest . "\" (RD /Q \"" . $userprofile . "\\" . $link . "\" || RD /S /Q \"" . $userprofile . "\\" . $dest . "\")\r\n";
                        $out .= "IF NOT EXIST \"" . $userprofile . "\\" . $dest . "\" (MKLINK /D \"" . $userprofile . "\\" . $dest . "\" \"\\\\%SE4FS%\\users\\" . $userCn . "\\" . $server . "\")\r\n";
                    } else {
                        $out .= "IF EXIST \"" . $userprofile . "\\" . $link . "\" (RD /Q \"" . $userprofile . "\\" . $link . "\" || RD /S /Q \"" . $userprofile . "\\" . $link . "\")\r\n";
                        $out .= "IF NOT EXIST \"" . $userprofile . "\\" . $link . "\" (MKLINK /D \"" . $userprofile . "\\" . $link . "\" \"\\\\%SE4FS%\\users\\" . $userCn . "\\" . $server . "\")\r\n";
                    }
                }
            } else {
                // jonction Local→Roaming uniquement.
                if ($context === '') {
                    $ps = explode('\\', $dest);
                    if (count($ps) > 1) {
                        array_pop($ps);
                        $d = '';
                        foreach ($ps as $p) {
                            $d .= '\\' . $p;
                            $out .= "IF NOT EXIST \"" . $userprofile . $d . "\" (MD \"" . $userprofile . $d . "\")\r\n";
                        }
                    }
                    $out .= "IF NOT EXIST \"" . $userprofile . "\\" . $dest . "\" (MD \"" . $userprofile . "\\" . $dest . "\")\r\n";
                    $ps = explode('\\', $link);
                    if (count($ps) > 1) {
                        array_pop($ps);
                        $d = '';
                        foreach ($ps as $p) {
                            $d .= '\\' . $p;
                            $out .= "IF NOT EXIST \"" . $userprofile . $d . "\" (MD \"" . $userprofile . $d . "\")\r\n";
                        }
                    }
                    $out .= "IF NOT EXIST \"" . $userprofile . "\\" . $link . "\" (MKLINK /J \"" . $userprofile . "\\" . $link . "\"  \"" . $userprofile . "\\" . $dest . "\")\r\n";
                }
            }
        } elseif ($dest !== '' && $context === '') {
            $ps = explode('\\', $dest);
            if (count($ps) > 1) {
                $d = '';
                foreach ($ps as $p) {
                    $d .= '\\' . $p;
                    $out .= "IF NOT EXIST \"" . $userprofile . $d . "\" (MD \"" . $userprofile . $d . "\")\r\n";
                }
            }
        }

        return ['interpreter' => 'cmd', 'script' => [$out]];
    }

    /**
     * Port `sudo_scripts()` legacy (`:649-662`).
     *
     * @return array{interpreter: string, script: list<string>}
     */
    private function sudoScripts(array $info): array
    {
        $os = $info['os'] ?? '';
        $action = $info['action'] ?? '';
        $script = ($os === 'linux' && in_array($action, ['logon', 'logoff'], true))
            ? ["sudo /usr/share/sambaedu/scripts/system_script " . $action . "-system\n"]
            : [];
        return ['interpreter' => 'bash', 'script' => $script];
    }

    /**
     * Port `wpkg_scripts()` legacy (`:664-675`).
     *
     * @return array{interpreter: string, script: list<string>}
     */
    private function wpkgScripts(array $info, array $script): array
    {
        $appLower = strtolower((string) ($script['app'] ?? ''));
        $listAppsLower = array_map('strtolower', (array) ($info['liste_applications'] ?? []));
        $application = (string) ($info['application'] ?? '');

        $out = ($script['app'] ?? '') === $application && in_array($appLower, $listAppsLower, true)
            ? (array) ($script['script'] ?? [])
            : [];
        return ['interpreter' => (string) ($info['interpreter'] ?? 'cmd'), 'script' => $out];
    }

    /**
     * Port `apt_scripts()` legacy (`:677-690`).
     *
     * @return array{interpreter: string, script: list<string>}
     */
    private function aptScripts(array $info, string $packages): array
    {
        $script = ($packages !== '' && ($info['os'] ?? '') === 'linux')
            ? ["local_packages=\"" . trim($packages) . "\"\n"]
            : [];
        return ['interpreter' => 'bash', 'script' => $script];
    }

    /**
     * Port `local_admin_scripts()` legacy (`:692-773`).
     *
     * Story 16.7 post-review #4 (2026-05-13) : élévation locale câblée aux
     * services Spatie natifs Epic 7 (`done` 2026-04-29). Les fonctions legacy
     * `have_right(SE_COMPUTER_ADMIN)` et `have_delegation($machine, SE_COMPUTER_ADMIN)`
     * sont désormais traduites en {@see resolveLocalAdminRight()} :
     *  - global : `User::hasPermissionTo('computer.elevate')`
     *  - scopé : `PermissionService::canOnWorkstationGroup($user, 'computer.elevate', $group)`
     *
     * **Note sémantique** : le legacy gardait aussi un test
     * `get_local_admin_right > 0` qui correspond à un mécanisme d'élévation
     * **temporaire** (paramètre `local_admin_<user>` posé par
     * `set_local_admin_right` legacy — cf. `sambaedu/includes/ldap.inc.php:3319-3354`).
     * Ce mécanisme n'a pas (encore) d'équivalent natif et est donc ignoré ici ;
     * en pratique la condition cumulée legacy retombait toujours sur
     * `have_right || have_delegation` pour décider de l'`/add` au logon (le
     * gating `get_local_admin_right != 0` était davantage une porte
     * fonctionnelle qu'un filtre métier — un user élevable mais sans entrée
     * `local_admin_*` n'aurait jamais reçu d'`/add`). Le portage natif retient
     * donc la condition fonctionnelle principale (`computer.elevate` global OU
     * scopé) et trace en `tech-debt-gpo.md` la perte de l'élévation temporaire
     * jusqu'à ce qu'un mécanisme dédié soit défini (Epic 7 itération future).
     *
     * @return array{interpreter: string, script: list<string>}
     */
    private function localAdminScripts(array $info): array
    {
        $os = (string) ($info['os'] ?? '');
        $action = (string) ($info['action'] ?? '');
        $userCn = (string) ($info['user']['cn'] ?? '');
        $userprofile = (string) ($info['userprofile'] ?? '');
        $machineCn = (string) ($info['machine']['cn'] ?? '');
        $se4fsName = (string) (config('sambaedu.se4fs_name') ?: '');
        $sambaDomain = (string) (config('sambaedu.samba_domain') ?: '');

        $script = '';
        $interpreter = 'cmd';

        if ($os === 'windows') {
            if ($userprofile === '') {
                // Script appelé en SYSTEM (user=machine$).
                if ($action === 'logon') {
                    $script .= "rem on doit boucler pour attendre que l'utilisateur soit bien connecte\r\n"
                        . ":user\r\n"
                        . "FOR /f \"tokens=2 delims=\\ \" %%a in ('query session ^| findstr /c:\"Acti\"') DO (\r\n"
                        . "    SET userlogin=%%a\r\n"
                        . ")\r\n"
                        . "IF [%userlogin%]==[] (\r\n"
                        . "    ping -n 1 localhost\r\n"
                        . "    GOTO user\r\n"
                        . ")\r\n";
                    $script .= "rem on boucle jusqu'a avoir un profile\r\n"
                        . "    for /f \"delims=\" %%a in ('powershell -NoLogo -NoProfile -Command \"([System.Security.Principal.NTAccount]'%userlogin%').Translate([System.Security.Principal.SecurityIdentifier]).Value\"') do (\r\n"
                        . "    SET SID=%%a\r\n"
                        . "    FOR /f \"tokens=3 delims=\" %%b in ('reg query \"HKLM\\Software\\Microsoft\\Windows NT\\CurrentVersion\\ProfileList\\%SID%\" /v ProfileImagePath ^| findstr ProfileImagePath') DO (\r\n"
                        . "        SET profile=%%b\r\n"
                        . "    )\r\n"
                        . "    IF NOT [%profile%]==[] (\r\n"
                        . "        SET USERPROFILE=%profile%\r\n"
                        . "    )\r\n"
                        . ")\r\n"
                        . "IF [%profile%]==[] (\r\n"
                        . "    SET USERPROFILE=%SystemDrive%\\Users\\%userlogin%\r\n"
                        . ")\r\n";
                    $script .= ":dl\r\n"
                        . "curl.exe -o \"%TEMP%\\applications-" . $action . "-system-%userlogin%.cmd\" -F \"action=" . $action . "-system\" -F \"os=windows\" -F \"machine=%computername%\" -F \"userprofile=%USERPROFILE%\" -F \"user=%userlogin%\" \"http://" . $se4fsName . "/gpo/applications.php\">NUL\r\n"
                        . "if exist \"%TEMP%\\applications-" . $action . "-system-%userlogin%.cmd\" (call \"%TEMP%\\applications-" . $action . "-system-%userlogin%.cmd\")\r\n";
                }
            } else {
                // Branche standard `os=windows && userprofile !== ''` : élévation
                // locale au logon, retrait systématique au logoff (parité legacy
                // `:740-751`). Le retrait au logoff est inconditionnel iso-legacy
                // — pas de check droits — pour assurer le cleanup même si les
                // droits ont changé entre logon et logoff.
                $interpreter = 'cmd';
                if ($userCn !== '' && in_array($action, ['logon', 'logoff'], true)) {
                    if ($action === 'logon' && $this->resolveLocalAdminRight($info)) {
                        $script .= 'net localgroup administrateurs "' . $sambaDomain . '\\' . $userCn . '" /add' . "\r\n"
                            . 'set admin=1' . "\r\n";
                    } elseif ($action === 'logoff') {
                        $script .= 'net localgroup administrateurs "' . $sambaDomain . '\\' . $userCn . '" /delete' . "\r\n";
                    }
                }
            }
        } else {
            // Linux : élévation via `/etc/sudoers.d/<user>` (parité legacy
            // `:754-765`). L'utilisateur réel doit pouvoir devenir sudo si
            // `computer.elevate` global ou délégué ; logoff = retrait fichier
            // sudoers (inconditionnel iso-legacy).
            $interpreter = 'bash';
            if ($userCn !== '' && in_array($action, ['logon', 'logoff'], true)) {
                // Iso-legacy : `$u = strtr($userCn, '.', '_')` — le nom du fichier
                // sudoers ne tolère pas le `.` (collision avec extension implicite).
                $u = strtr($userCn, '.', '_');
                if ($action === 'logon' && $this->resolveLocalAdminRight($info)) {
                    $script .= 'echo "' . $userCn . ' ALL=(ALL:ALL) ALL " > /etc/sudoers.d/' . $u . "\n"
                        . 'chmod 0440 /etc/sudoers.d/' . $u . "\n";
                } elseif ($action === 'logoff') {
                    $script .= '[ -f /etc/sudoers.d/' . $u . ' ] && rm -f /etc/sudoers.d/' . $u . "\n";
                }
            }
        }

        return ['interpreter' => $interpreter, 'script' => [$script]];
    }

    /**
     * Story 16.7 post-review #4 (2026-05-13) — pendant natif Epic 7 de la
     * condition legacy `have_right(SE_COMPUTER_ADMIN, $userCn)
     * || have_delegation($machineCn, SE_COMPUTER_ADMIN, $userCn)` :
     *
     *  1. Résout l'utilisateur Eloquent (`App\Models\User::findByLogin`).
     *  2. Si rôle/permission directe globale `computer.elevate` → autorise.
     *  3. Sinon, résout le `WorkstationGroup` natif lié à la machine
     *     (via la relation Eloquent `Workstation::groups` si la machine est
     *     synchronisée SQL, fallback `WorkstationGroup::whereIn('name', $parcs)`
     *     pour les parcs extraits du `memberof` LDAP iso-legacy).
     *  4. Pour chaque groupe candidat, teste
     *     `PermissionService::canOnWorkstationGroup($user, 'computer.elevate', $group)`
     *     qui prend déjà en compte les exclusions négatives (matrice §7).
     *
     * Retour `false` si user introuvable, schéma SQL absent (tests legacy), ou
     * si la résolution lance une exception — dégradation gracieuse (pas
     * d'élévation par défaut, jamais d'élévation par bug).
     *
     * @param  array<string,mixed>  $info  Contexte de la requête.
     */
    public function resolveLocalAdminRight(array $info): bool
    {
        $userCn = (string) ($info['user']['cn'] ?? '');
        $machineCn = (string) ($info['machine']['cn'] ?? '');

        if ($userCn === '' || $machineCn === '') {
            return false;
        }

        try {
            $user = User::findByLogin($userCn);
        } catch (Throwable $e) {
            Log::channel('daily')->debug('[ApplicationScriptsAssembler] User Eloquent introuvable', [
                'user' => $userCn,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($user === null) {
            return false;
        }

        // 1. Droit global Spatie (parité `have_right(SE_COMPUTER_ADMIN, $user)`).
        try {
            if ($user->hasPermissionTo(self::LOCAL_ADMIN_PERMISSION)) {
                return true;
            }
        } catch (Throwable $e) {
            // Permission absente du registre Spatie (ex. tests sans seed) :
            // on tombe sur la résolution scopée. Pas de log warning ici, c'est
            // un cas attendu hors prod.
        }

        // 2. Droit scopé sur WorkstationGroup (parité
        // `have_delegation($machineCn, SE_COMPUTER_ADMIN, $user)`).
        $groups = $this->resolveWorkstationGroupsForMachine($machineCn, $info);
        if ($groups === []) {
            return false;
        }

        $permissionService = $this->resolvePermissionService();
        if ($permissionService === null) {
            return false;
        }

        foreach ($groups as $group) {
            try {
                if ($permissionService->canOnWorkstationGroup($user, self::LOCAL_ADMIN_PERMISSION, $group)) {
                    return true;
                }
            } catch (Throwable $e) {
                Log::channel('daily')->debug('[ApplicationScriptsAssembler] canOnWorkstationGroup a échoué', [
                    'user' => $userCn,
                    'group' => $group->name ?? '?',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    /**
     * Résout la liste des `WorkstationGroup` candidats pour une machine.
     *
     * Combine deux sources iso-legacy :
     *  - Eloquent : si la machine est synchronisée SQL (`Workstation`), on lit
     *    sa relation `groups` (BelongsToMany pivot `workstation_group_workstation`).
     *  - Fallback LDAP : les noms de parcs sont déjà extraits dans `$info['parcs']`
     *    par `ApplicationScriptsGenerator::extractParcs()` (issus du `memberof`
     *    de la machine LDAP) — on les rattache aux `WorkstationGroup` natifs
     *    via la colonne `name`.
     *
     * @param  array<string,mixed>  $info
     * @return list<WorkstationGroup>
     */
    private function resolveWorkstationGroupsForMachine(string $machineCn, array $info): array
    {
        $groups = [];

        try {
            $workstation = Workstation::where('name', strtolower($machineCn))->first();
            if ($workstation !== null) {
                foreach ($workstation->groups as $group) {
                    $groups[$group->id] = $group;
                }
            }
        } catch (Throwable $e) {
            // Schema SQL absent (tests legacy) — on continue avec le fallback LDAP.
        }

        $parcs = array_values(array_filter((array) ($info['parcs'] ?? []), 'is_string'));
        if ($parcs !== []) {
            try {
                $byName = WorkstationGroup::whereIn('name', $parcs)->get();
                foreach ($byName as $group) {
                    $groups[$group->id] = $group;
                }
            } catch (Throwable $e) {
                // Idem — dégradation gracieuse.
            }
        }

        return array_values($groups);
    }

    /**
     * Résout `PermissionService` (DI explicite ou container Laravel).
     */
    private function resolvePermissionService(): ?PermissionService
    {
        if ($this->permissionService !== null) {
            return $this->permissionService;
        }
        try {
            return app(PermissionService::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Substitution `###_KEY_###` (whitelist config statique — AC4.2).
     *
     * Iso-legacy `write_param()` (`traitement_data.inc.php`). Les placeholders
     * non whitelistés restent inchangés (log warning).
     */
    public function applySubstitutions(string $template): string
    {
        $whitelist = $this->loadWhitelist();
        if ($whitelist === []) {
            return $template;
        }
        $search = [];
        $replace = [];
        foreach ($whitelist as $key => $resolver) {
            $value = $this->resolveSubstitutionValue($resolver);
            if ($value === null) {
                continue;
            }
            $search[] = '###_' . $key . '_###';
            $replace[] = (string) $value;
        }

        $result = str_replace($search, $replace, $template);

        // Détection des placeholders restants (audit F3 vecteur d'injection).
        if (preg_match_all('/###_([A-Z0-9_]+)_###/', $result, $m) > 0) {
            $unknown = array_diff(array_unique($m[1]), array_keys($whitelist));
            if ($unknown !== []) {
                Log::channel('daily')->warning('[ApplicationScriptsAssembler] unwhitelisted substitution keys ignored', [
                    'keys' => array_values($unknown),
                ]);
            }
        }

        return $result;
    }

    /**
     * Résout la valeur d'une entrée de whitelist. Supporte trois formats :
     *  - `callable(): ?string` (rétro-compat tests qui injectent des closures
     *    via `config()->set()`)
     *  - `string` (valeur littérale)
     *  - `array{config?: string, env?: string, default?: ?string, value?: ?string}`
     *    (spec déclarative sérialisable — requis pour `config:cache`)
     */
    private function resolveSubstitutionValue(mixed $spec): ?string
    {
        if (is_callable($spec)) {
            $resolved = $spec();
            return $resolved === null ? null : (string) $resolved;
        }
        if (is_string($spec)) {
            return $spec;
        }
        if (! is_array($spec)) {
            return null;
        }
        if (array_key_exists('value', $spec)) {
            return $spec['value'] === null ? null : (string) $spec['value'];
        }
        // Itère config → env → default. Une chaîne vide est traitée comme
        // "non trouvé" (iso-legacy `?:` qui retombe sur null) — sauf si une
        // `default` explicite (même vide) la fournit, ce qui permet à
        // `WPKG_URL` de produire '' au lieu de laisser le placeholder.
        $found = false;
        $value = null;
        if (isset($spec['config'])) {
            $v = config($spec['config']);
            if ($v !== null && $v !== '') {
                $value = $v;
                $found = true;
            }
        }
        if (! $found && isset($spec['env'])) {
            $v = env($spec['env']);
            if ($v !== null && $v !== false && $v !== '') {
                $value = $v;
                $found = true;
            }
        }
        if (! $found && array_key_exists('default', $spec)) {
            $value = $spec['default'];
            $found = true;
        }
        if (! $found || $value === null) {
            return null;
        }
        return (string) $value;
    }

    /**
     * @return array<string, callable(): ?string|string|array<string, mixed>>
     */
    private function loadWhitelist(): array
    {
        if ($this->substitutionsCache !== null) {
            return $this->substitutionsCache;
        }
        $config = config('sambaedu.gpo.applications.substitutions.whitelist');
        if (! is_array($config)) {
            $config = [];
        }
        $this->substitutionsCache = $config;
        return $config;
    }
}
