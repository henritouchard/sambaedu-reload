<?php

namespace App\Services;

use App\Models\CompiledShortcut;
use App\Models\Shortcut;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Service de pré-compilation des raccourcis.
 *
 * Pré-compile les raccourcis lors de leur édition pour que le téléchargement
 * par les postes Windows/Linux soit instantané.
 *
 * - Raccourcis statiques : le script complet est pré-généré et stocké.
 * - Raccourcis dynamiques : le template est pré-généré, les variables
 *   ($user, $userprofile, etc.) sont substituées au moment du téléchargement.
 */
class ShortcutCompilerService
{
    /**
     * Répertoire de stockage des fichiers pré-compilés (.lnk, .desktop).
     */
    public const COMPILED_DIR = '/etc/sambaedu/applications/shortcuts/compiled';
    /**
     * Compile un raccourci : détecte les variables dynamiques,
     * génère les fragments de script et les fichiers binaires (.lnk/.desktop)
     * pour chaque cible assignée. Les fichiers sont écrits sur le filesystem.
     */
    public function compile(Shortcut $shortcut): void
    {
        $isDynamic = $shortcut->detectDynamic();

        // S'assurer que le répertoire de compilation existe
        if (!is_dir(self::COMPILED_DIR)) {
            @mkdir(self::COMPILED_DIR, 0755, true);
        }

        // Mettre à jour le flag is_dynamic (sans déclencher l'Observer pour éviter la boucle)
        Shortcut::withoutEvents(function () use ($shortcut, $isDynamic) {
            $shortcut->update([
                'is_dynamic' => $isDynamic,
                'compiled_data' => [
                    'dynamic_variables' => $shortcut->getDynamicVariables(),
                    'has_windows' => !empty($shortcut->windows_link),
                    'has_linux' => !empty($shortcut->linux_link),
                    'is_url' => $shortcut->isUrlShortcut(),
                ],
                'compiled_at' => Carbon::now(),
            ]);
        });

        // Supprimer les anciens fichiers compilés du filesystem
        foreach ($shortcut->compiledShortcuts as $compiled) {
            if ($compiled->compiled_path && file_exists($compiled->compiled_path)) {
                @unlink($compiled->compiled_path);
            }
        }

        // Supprimer les anciennes entrées en BDD
        $shortcut->compiledShortcuts()->delete();

        // Compiler pour chaque cible assignée
        $this->compileForWorkstationGroups($shortcut);
        $this->compileForWorkstations($shortcut);
        $this->compileForAdUserGroups($shortcut);
        $this->compileForAdUsers($shortcut);

        Log::info('ShortcutCompilerService: compiled shortcut', [
            'shortcut_id' => $shortcut->id,
            'name' => $shortcut->name,
            'is_dynamic' => $isDynamic,
            'targets' => $shortcut->compiledShortcuts()->count(),
        ]);
    }

    /**
     * Compile tous les raccourcis (batch).
     */
    public function compileAll(): int
    {
        $count = 0;
        Shortcut::with(['workstationGroups', 'workstations'])->chunk(50, function ($shortcuts) use (&$count) {
            foreach ($shortcuts as $shortcut) {
                $this->compile($shortcut);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Résout les raccourcis pour un poste donné (identifié par nom machine)
     * et un utilisateur donné, pour un OS et une action GPO.
     *
     * Retourne le script complet (.cmd ou .sh) prêt à être exécuté.
     */
    public function resolveForMachine(
        string $machineName,
        string $userName,
        string $os,
        string $action,
        string $userprofile = ''
    ): string {
        $workstation = Workstation::where('name', $machineName)->first();

        // Collecter tous les shortcut IDs applicables
        $shortcutIds = collect();

        if ($workstation) {
            // Raccourcis assignés directement au poste
            $shortcutIds = $shortcutIds->merge(
                $workstation->shortcuts()->pluck('shortcuts.id')
            );

            // Raccourcis assignés aux groupes du poste
            $groupIds = $workstation->groups()->pluck('workstation_groups.id');
            if ($groupIds->isNotEmpty()) {
                $wgShortcutIds = \DB::table('shortcut_assignables')
                    ->where('assignable_type', WorkstationGroup::class)
                    ->whereIn('assignable_id', $groupIds)
                    ->pluck('shortcut_id');
                $shortcutIds = $shortcutIds->merge($wgShortcutIds);
            }
        }

        // Raccourcis assignés à l'utilisateur AD
        if (!empty($userName)) {
            $userShortcutIds = Shortcut::whereJsonContains('ad_users', $userName)->pluck('id');
            $shortcutIds = $shortcutIds->merge($userShortcutIds);
        }

        // Raccourcis assignés aux groupes AD de l'utilisateur
        $userGroups = $this->getUserAdGroups($userName);
        if (!empty($userGroups)) {
            foreach ($userGroups as $group) {
                $groupShortcutIds = Shortcut::whereJsonContains('ad_user_groups', $group)->pluck('id');
                $shortcutIds = $shortcutIds->merge($groupShortcutIds);
            }
        }

        $shortcutIds = $shortcutIds->unique();

        if ($shortcutIds->isEmpty()) {
            return $this->getScriptHeader($os, $action);
        }

        // Charger les raccourcis
        $shortcuts = Shortcut::whereIn('id', $shortcutIds)->get();

        // Générer le script
        return $this->buildScript($shortcuts, $os, $action, $userName, $userprofile);
    }

    /**
     * Génère le fichier .lnk binaire pour un raccourci Windows.
     * Substitue les variables dynamiques si nécessaire.
     */
    public function generateWindowsLnk(
        Shortcut $shortcut,
        string $user = '',
        string $userprofile = ''
    ): ?string {
        if (empty($shortcut->windows_link)) {
            return null;
        }

        $link = $this->substituteVariables($shortcut->windows_link, $user, $userprofile);
        $args = $this->substituteVariables($shortcut->windows_args ?? '', $user, $userprofile);
        $path = $this->substituteVariables($shortcut->windows_path ?? '', $user, $userprofile);
        $icon = $shortcut->windows_icon;

        // Icône uploadée : handleIconUpload() stocke le nom NU du raccourci dans
        // windows_icon (le .ico réel vit dans /etc/sambaedu/applications/shortcuts/
        // et est téléchargé par le script de logon dans %temp%\<name>.ico). Un nom
        // nu inscrit tel quel dans l'IconLocation du .lnk est irrésoluble par
        // Windows → icône « feuille blanche ». On le résout donc, comme le cas
        // vide, vers le chemin absolu où le script dépose le .ico.
        // On PRÉSERVE en revanche les références explicites — chemin, extension,
        // index de ressource ou variable d'environnement (ex. firefox.exe,0,
        // %APPDATA%\app.ico, C:\...) — détectées par la présence d'un séparateur
        // de chemin, d'un point, d'une virgule ou d'un %.
        if (empty($icon) || !preg_match('#[\\\\/.,%]#', $icon)) {
            $icon = $userprofile . "\\AppData\\Local\\Temp\\" . $shortcut->name . ".ico";
        }

        // Gestion des raccourcis URL (navigateur par défaut / Edge)
        if ($link === 'default') {
            $link = 'c:\\Windows\\System32\\Rundll32.exe';
            $args = 'url.dll,FileProtocolHandler ' . $args;
        } elseif ($link === 'microsoft-edge') {
            $link = 'c:\\Windows\\System32\\Rundll32.exe';
            $args = 'url.dll,FileProtocolHandler microsoft-edge:' . $args;
        }

        // Passer les chaînes vides (pas null) pour workingDir et arguments
        // afin de reproduire exactement le comportement legacy qui active
        // les flags HasWorkingDir/HasArguments même pour des valeurs vides.
        return WindowsLnkGenerator::generate(
            $link,
            $shortcut->name,
            $path,
            $args,
            !empty($icon) ? $icon : null
        );
    }

    /**
     * Génère le fichier .desktop pour un raccourci Linux.
     * Substitue les variables dynamiques si nécessaire.
     */
    public function generateLinuxDesktop(
        Shortcut $shortcut,
        string $user = ''
    ): ?string {
        if (empty($shortcut->linux_link)) {
            return null;
        }

        $link = $this->substituteVariables($shortcut->linux_link, $user);
        $args = $this->substituteVariables($shortcut->linux_args ?? '', $user);
        $path = $this->substituteVariables($shortcut->linux_path ?? '', $user);

        $out = "#!/usr/bin/env xdg-open\n";
        $out .= "[Desktop Entry]\n";
        $out .= "Encoding=UTF-8\n";
        $out .= "Type=Application\n";
        $out .= "Terminal=false\n";
        $out .= "StartupNotify=true\n";
        $out .= "Categories=Application\n";
        $out .= "Exec={$link} {$args}\n";
        $out .= "Hidden=false\n";
        $out .= "Name={$shortcut->name}\n";
        $out .= "Comment=Raccourci ajouté par Sambaedu\n";

        if (!empty($shortcut->linux_startupwmclass)) {
            $out .= "StartupWMClass={$shortcut->linux_startupwmclass}\n";
        }

        $iconBase = "/home/{$user}/.local/share/icons/{$shortcut->name}.png";
        switch ($shortcut->place) {
            case Shortcut::PLACE_STARTUP:
                $out .= "X-GNOME-Autostart-enabled=true\n";
                $out .= "Icon={$iconBase}\n";
                break;
            case Shortcut::PLACE_DESKTOP:
            case Shortcut::PLACE_TASKBAR:
                $out .= "Icon={$iconBase}\n";
                break;
        }

        if (!empty($path)) {
            $out .= "Path={$path}\n";
        }

        return $out;
    }

    /**
     * Retourne le chemin vers l'icône d'un raccourci dans le format demandé.
     * Convertit PNG → ICO si nécessaire pour Windows.
     */
    public function getIconPath(string $shortcutName, string $os): ?string
    {
        $basePath = '/etc/sambaedu/applications/shortcuts/';
        $sharePath = '/usr/share/sambaedu/applications/shortcuts/';

        // Copier depuis share si nécessaire
        if (file_exists($sharePath . $shortcutName . '.png') && !file_exists($basePath . $shortcutName . '.png')) {
            @copy($sharePath . $shortcutName . '.png', $basePath . $shortcutName . '.png');
        }

        if ($os === 'windows') {
            // Essayer .ico d'abord
            if (file_exists($basePath . $shortcutName . '.ico')) {
                return $basePath . $shortcutName . '.ico';
            }
            // Convertir PNG → ICO si Imagick disponible
            if (file_exists($basePath . $shortcutName . '.png') && class_exists('Imagick')) {
                try {
                    $imagick = new \Imagick($basePath . $shortcutName . '.png');
                    $imagick->resizeImage(128, 128, \Imagick::FILTER_QUADRATIC, 1);
                    $imagick->setImageFormat('png');
                    $imagick->setImageFormat('ico');
                    $imagick->writeImage($basePath . $shortcutName . '.ico');
                    return $basePath . $shortcutName . '.ico';
                } catch (\ImagickException $e) {
                    Log::warning('ShortcutCompilerService: Imagick conversion failed', [
                        'shortcut' => $shortcutName,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            // Fallback : icône générique embarquée (parité legacy). Évite que le
            // script de logon écrive une page d'erreur 404 dans %temp%\<name>.ico
            // → Windows afficherait une « feuille blanche ».
            $fallback = public_path('elements/images/system-run.ico');
            return file_exists($fallback) ? $fallback : null;
        }

        // Linux : PNG
        if (file_exists($basePath . $shortcutName . '.png')) {
            return $basePath . $shortcutName . '.png';
        }

        $fallback = public_path('elements/images/system-run.png');
        return file_exists($fallback) ? $fallback : null;
    }

    // ========================================================================
    // Méthodes privées
    // ========================================================================

    private function compileForWorkstationGroups(Shortcut $shortcut): void
    {
        foreach ($shortcut->workstationGroups as $wg) {
            foreach (['windows', 'linux'] as $os) {
                foreach (['logon', 'startup'] as $action) {
                    $this->compileEntry($shortcut, 'workstation_group', (string) $wg->id, $os, $action);
                }
            }
        }
    }

    private function compileForWorkstations(Shortcut $shortcut): void
    {
        foreach ($shortcut->workstations as $ws) {
            foreach (['windows', 'linux'] as $os) {
                foreach (['logon', 'startup'] as $action) {
                    $this->compileEntry($shortcut, 'workstation', (string) $ws->id, $os, $action);
                }
            }
        }
    }

    private function compileForAdUserGroups(Shortcut $shortcut): void
    {
        $groups = $shortcut->ad_user_groups ?? [];
        foreach ($groups as $groupCn) {
            foreach (['windows', 'linux'] as $os) {
                foreach (['logon', 'startup'] as $action) {
                    $this->compileEntry($shortcut, 'ad_user_group', $groupCn, $os, $action);
                }
            }
        }
    }

    private function compileForAdUsers(Shortcut $shortcut): void
    {
        $users = $shortcut->ad_users ?? [];
        foreach ($users as $userCn) {
            foreach (['windows', 'linux'] as $os) {
                foreach (['logon', 'startup'] as $action) {
                    $this->compileEntry($shortcut, 'ad_user', $userCn, $os, $action);
                }
            }
        }
    }

    private function compileEntry(
        Shortcut $shortcut,
        string $targetType,
        string $targetId,
        string $os,
        string $action
    ): void {
        $scriptFragment = $this->buildScriptFragment($shortcut, $os, $action);
        $compiledPath = null;

        // Pour les raccourcis statiques, pré-générer les fichiers sur disque
        if (!$shortcut->is_dynamic) {
            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $shortcut->name);
            $fileBase = self::COMPILED_DIR . "/{$shortcut->id}_{$safeName}";

            if ($os === 'windows' && !empty($shortcut->windows_link)) {
                $filePath = "{$fileBase}.lnk";
                $content = $this->generateWindowsLnk($shortcut);
                if ($content && file_put_contents($filePath, $content) !== false) {
                    $compiledPath = $filePath;
                }
            }
            if ($os === 'linux' && !empty($shortcut->linux_link)) {
                $filePath = "{$fileBase}.desktop";
                $content = $this->generateLinuxDesktop($shortcut);
                if ($content && file_put_contents($filePath, $content) !== false) {
                    $compiledPath = $filePath;
                }
            }
        }

        CompiledShortcut::updateOrCreate(
            [
                'shortcut_id' => $shortcut->id,
                'target_type' => $targetType,
                'target_identifier' => $targetId,
                'os' => $os,
                'action' => $action,
            ],
            [
                'script_fragment' => $scriptFragment,
                'compiled_path' => $compiledPath,
            ]
        );
    }

    /**
     * Génère un fragment de script pour un raccourci donné.
     * Ce fragment est la partie du script qui crée/télécharge le raccourci.
     */
    private function buildScriptFragment(Shortcut $shortcut, string $os, string $action): string
    {
        $serverName = config('sambaedu.se4fs_name', request()->getHost());
        // Racine = projet Laravel : pas de préfixe /laravel/public (obsolète).
        // Iso aux autres endpoints poste (gpo/applications.php, wallpaper_out…).
        $apiBase = "http://{$serverName}/api/v1/shortcuts/export";

        if ($os === 'windows' && !empty($shortcut->windows_link)) {
            return $this->buildWindowsFragment($shortcut, $action, $apiBase);
        }

        if ($os === 'linux' && !empty($shortcut->linux_link)) {
            return $this->buildLinuxFragment($shortcut, $action, $apiBase);
        }

        return '';
    }

    private function buildWindowsFragment(Shortcut $shortcut, string $action, string $apiBase): string
    {
        $script = '';
        $nameIso = $shortcut->name; // On garde UTF-8, le script gère l'encodage

        if ($action === 'logon') {
            $path = match ($shortcut->place) {
                Shortcut::PLACE_STARTUP => '%userprofile%\\AppData\\Roaming\\Microsoft\\Windows\\Start Menu\\Programs\\Startup\\',
                Shortcut::PLACE_TASKBAR => '%userprofile%\\AppData\\Roaming\\Microsoft\\Internet Explorer\\Quick Launch\\User Pinned\\TaskBar\\',
                // Pansement temporaire (Bug C) : défaut = bureau RÉSEAU (poste partagé),
                // en attendant la story PosteEnvironment (shared_local/personal_local/nomade).
                // Le port natif avait figé la branche locale (legacy `port_perdir`) → le
                // bureau étant redirigé vers le réseau, `%userprofile%\Bureau` n'existe pas
                // → curl(23) sur les postes partagés, aucun raccourci posé.
                default => '\\\\%se4fs%\\users\\%username%\\Bureau\\',
            };

            // Télécharger le .lnk
            $script .= "curl.exe --output \"{$path}{$nameIso}.lnk\" ";
            $script .= "\"{$apiBase}/file?shortcut_id={$shortcut->id}&os=windows&user=%username%&userprofile=%userprofile%\"\r\n";

            // Enregistrer pour nettoyage
            $script .= "echo {$path}{$nameIso}.lnk>>\"%userprofile%\\AppData\\Roaming\\shortcuts.txt\"\r\n";

            // Télécharger l'icône
            $script .= "curl.exe --output \"%temp%\\{$nameIso}.ico\" ";
            $script .= "\"{$apiBase}/icon?name={$nameIso}&os=windows\"\r\n";
        }

        return $script;
    }

    private function buildLinuxFragment(Shortcut $shortcut, string $action, string $apiBase): string
    {
        $script = '';
        $name = $shortcut->name;

        // Télécharger l'icône si elle existe
        $iconPath = '/etc/sambaedu/applications/shortcuts/' . $name . '.png';
        if (file_exists($iconPath)) {
            $script .= "mkdir -p \${HOME}/.local/share/icons\n";
            $icon = "\${HOME}/.local/share/icons/{$name}.png";
            $script .= "[ -f \"{$icon}\" ] || curl -s -o \"{$icon}\" \"{$apiBase}/icon?name={$name}&os=linux\"\n";
        }

        if ($action === 'logon') {
            $path = match ($shortcut->place) {
                Shortcut::PLACE_STARTUP => '${HOME}/.config/autostart/',
                Shortcut::PLACE_TASKBAR => '${HOME}/.local/share/applications/',
                default => '${HOME}/Bureau/',
            };

            // Gestion barre des tâches GNOME
            if ($shortcut->place === Shortcut::PLACE_TASKBAR) {
                $script .= "dconf read /org/gnome/shell/favorite-apps | grep -q \"{$name}.desktop\"\n";
                $script .= "if [ \$? -eq 1 ]; then\n";
                $script .= "list=\$(dconf read /org/gnome/shell/favorite-apps|sed \"s/\\[\\(.*\\)\\]/[\\1, '{$name}.desktop']/\")\n";
                $script .= "dconf write /org/gnome/shell/favorite-apps \"\$list\"\n";
                $script .= "fi\n";
            }

            $script .= "mkdir -p \"{$path}\"\n";
            $script .= "/usr/bin/curl -s -o \"{$path}{$name}.desktop\" ";
            $script .= "\"{$apiBase}/file?shortcut_id={$shortcut->id}&os=linux&user=\$(whoami)\"\n";
            $script .= "chmod 755 \"{$path}{$name}.desktop\"\n";
            $script .= "dbus-launch gio set \"{$path}{$name}.desktop\" \"metadata::trusted\" true\n";
        }

        return $script;
    }

    /**
     * Construit le script complet pour un ensemble de raccourcis.
     */
    private function buildScript(
        \Illuminate\Database\Eloquent\Collection $shortcuts,
        string $os,
        string $action,
        string $user,
        string $userprofile
    ): string {
        $script = $this->getScriptHeader($os, $action);

        // Nettoyage des anciens raccourcis (Windows logon)
        if ($os === 'windows' && $action === 'logon') {
            $script .= "chcp 28605\r\n";
            $script .= "if exist \"%userprofile%\\AppData\\Roaming\\shortcuts.txt\" (for /F \"usebackq delims=\" %%A in (\"%userprofile%\\AppData\\Roaming\\shortcuts.txt\") do (del /f /q \"%%A\"))\r\n";
            $script .= "if exist \"%userprofile%\\AppData\\Roaming\\shortcuts.txt\" (del /f /q \"%userprofile%\\AppData\\Roaming\\shortcuts.txt\")\r\n";
        }

        if ($os === 'windows' && $action === 'startup') {
            $script .= "chcp 28605\r\n";
            $script .= "if exist \"%TEMP%\\shortcuts.txt\" (for /F \"usebackq delims=\" %%A in (\"%TEMP%\\shortcuts.txt\") do (del /f /q \"%%A\"))\r\n";
            $script .= "if exist \"%TEMP%\\shortcuts.txt\" (del /f /q \"%TEMP%\\shortcuts.txt\")\r\n";
        }

        foreach ($shortcuts as $shortcut) {
            $fragment = $this->buildScriptFragment($shortcut, $os, $action);
            if (!empty($fragment)) {
                $script .= $fragment;
            }
        }

        return $script;
    }

    private function getScriptHeader(string $os, string $action): string
    {
        if ($os === 'windows') {
            return "::cmd\r\n::{$action}\r\n:: script de configuration des raccourcis Windows\r\n";
        }
        return "#!/bin/bash\n#{$action}\n# script de configuration des raccourcis Linux\n";
    }

    /**
     * Substitue les variables dynamiques dans une chaîne.
     */
    private function substituteVariables(string $value, string $user = '', string $userprofile = ''): string
    {
        if (empty($value)) {
            return $value;
        }

        // Ordre critique : substituer les variables les plus longues d'abord
        // pour éviter que $user ne corrompe $userprofile.
        // C'est le même ordre que le legacy (make_shortcut fait $userprofile avant $user).
        $replacements = [
            '$userprofile' => $userprofile,
            '$HOME' => "/home/{$user}",
            '$home' => "/home/{$user}",
            '$user' => $user,
        ];

        foreach ($replacements as $var => $replacement) {
            $value = str_replace($var, $replacement, $value);
        }

        return $value;
    }

    /**
     * Récupère les groupes AD d'un utilisateur.
     * Utilise le cache APCu si disponible (compatibilité legacy).
     */
    private function getUserAdGroups(string $userName): array
    {
        if (empty($userName)) {
            return [];
        }

        // Essayer le cache APCu (données legacy, alimenté par applications.inc.php)
        $cacheKey = "apps.groups.{$userName}";
        if (function_exists('apcu_fetch')) {
            $groups = apcu_fetch($cacheKey);
            if ($groups !== false) {
                return is_array($groups) ? $groups : [];
            }
        }

        // Essayer via la table users si elle existe
        try {
            $user = \DB::table('users')
                ->where('sam_account_name', $userName)
                ->first();
            if ($user && !empty($user->member_of)) {
                $memberOf = is_string($user->member_of) ? json_decode($user->member_of, true) : $user->member_of;
                return is_array($memberOf) ? $memberOf : [];
            }
        } catch (\Exception $e) {
            // Table users peut ne pas exister
        }

        return [];
    }
}
