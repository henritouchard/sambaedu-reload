<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ipxe\Enums\WindowsVersion;
use App\Ipxe\Support\WindowsXmlPlaceholders;
use App\Models\Workstation;

/**
 * Story 3.5 — D7 / AC3.1.
 *
 * Service d'assemblage dynamique du script bash WinPE consommé par WinPE
 * juste après le boot wimboot+winpeshl pour monter le partage SMB et lancer
 * `setup.exe /unattend:unattend.xml`.
 *
 * **Port natif** de `sambaedu/ipxe/Win10/install.bat.php` (73 LOC).
 *
 * **Sécurité critique** :
 *
 *  - **Line endings `\r\n` STRICT** : WinPE rejette les fichiers `.bat`/`.cmd`
 *    en LF only — l'exécution s'arrête silencieusement sans erreur visible.
 *    Chaque ligne du script généré DOIT se terminer par `\r\n` (test unit
 *    `it_contains_only_crlf_line_endings`).
 *  - **Sanitization shell-arg** : tous les values interpolés (config, hostname,
 *    AD domain, passwords) passent par {@see WindowsXmlPlaceholders::sanitizeShellArg()}
 *    qui rejette les chars d'injection cmd.exe (`;`, `&`, `|`, backtick, etc.).
 *
 * **Divergence legacy assumée (2026-06-04)** : les lignes post-`setup.exe`
 * du legacy (`net use /del`, curl `etape=winpe`, `bcdboot /addlast` —
 * `install.bat.php:56-60`) ont été RETIRÉES : setup.exe lancé depuis WinPE
 * reboote lui-même sans rendre la main (même avec `/noreboot`, honoré
 * uniquement depuis un OS complet), elles n'ont donc jamais été exécutées.
 * Le début d'install est tracé server-side à la génération
 * ({@see WindowsPostInstallTracker::recordInstallBatGenerated}), la fin par
 * le curl OOBE de `unattend.xml` (`etape=oobe&ret=0`).
 */
final class WindowsInstallBatBuilder
{
    /**
     * En-tête iso-legacy `install.bat.php:13`.
     */
    private const BAT_HEADER = "::cmd";

    public function __construct(
        private readonly \App\Services\ServiceCredentials $credentials,
    ) {
    }

    /**
     * Génère le script bash WinPE pour un poste donné.
     *
     * @param  Workstation  $workstation  Poste résolu via {@see WorkstationLocator}.
     * @param  WindowsVersion  $version   Win10|Win11.
     * @param  array{bios:string, debug:int, perso:int}  $attrs
     * @return string                     Bash WinPE avec line endings `\r\n`.
     */
    public function build(
        Workstation $workstation,
        WindowsVersion $version,
        array $attrs,
    ): string {
        // `$attrs['bios']` n'est plus consommé ici depuis le retrait du
        // `bcdboot /addlast` post-setup (code mort legacy) — il reste utilisé
        // par WindowsUnattendBuilder (DiskConfiguration uefi/legacy).
        $debug = (int) ($attrs['debug'] ?? 0);

        // Sanitization stricte de toutes les valeurs interpolées (defense in
        // depth : les configs sont trusted boundary mais un .env malformé
        // peut transporter un newline).
        $se4fsIp = WindowsXmlPlaceholders::sanitizeShellArg(
            (string) config('sambaedu.se4fs_ip', ''),
        );
        $se4fsName = WindowsXmlPlaceholders::sanitizeShellArg(
            (string) config('sambaedu.se4fs_name', ''),
        );
        $se4installName = WindowsXmlPlaceholders::sanitizeShellArg(
            (string) config('sambaedu.se4install_name', ''),
        );
        // Mot de passe effectif (base+code si TOTP actif, repli config sinon)
        // — source unique via ServiceCredentials. Voir
        // [[project_se4install_credential_totp]].
        $se4installPasswd = WindowsXmlPlaceholders::sanitizeShellArg(
            $this->credentials->se4installEffectivePassword(),
        );
        $domain = WindowsXmlPlaceholders::sanitizeShellArg(
            (string) config('sambaedu.domain', ''),
        );
        $versionStr = WindowsXmlPlaceholders::sanitizeShellArg($version->value);

        $pause = $debug === 1 ? "PAUSE\r\n" : "\r\n";

        // Assemblage iso-legacy `install.bat.php:13-71`. Chaque ligne se
        // termine par `\r\n`.
        $lines = [];
        $lines[] = self::BAT_HEADER;
        $lines[] = 'wpeutil InitializeNetwork';
        $lines[] = 'wpeutil WaitForNetwork';
        $lines[] = 'wpeinit.exe';
        $lines[] = ':n';
        // Section "pause" (vide en mode normal, PAUSE en mode debug).
        $bash = implode("\r\n", $lines) . "\r\n" . $pause;

        $lines2 = [];
        $lines2[] = 'IPCONFIG /RENEW';
        $lines2[] = 'set "ERR=%ERRORLEVEL%"';
        $lines2[] = 'if [%ERR%]==[0] (goto y) else (goto n)';
        $lines2[] = ':y';
        $lines2[] = '@PING ' . $se4fsIp;
        $lines2[] = 'set "ERR=%ERRORLEVEL%"';
        $lines2[] = 'if not [%ERR%]==[0] (goto n)';
        $lines2[] = '@net use z: \\\\' . $se4fsName . '\\install /user:'
            . $se4installName . '@' . $domain . ' ' . $se4installPasswd;
        $lines2[] = 'set "ERR=%ERRORLEVEL%"';
        $lines2[] = 'if not [%ERR%]==[0] (goto n)';
        $lines2[] = '';
        $bash .= implode("\r\n", $lines2) . "\r\n" . $pause;

        // setup.exe est la DERNIÈRE commande utile : lancé depuis WinPE, il
        // reboote lui-même la machine en fin de phase et ne rend jamais la
        // main (`/noreboot` n'est honoré que depuis un OS complet — vérifié
        // le 2026-06-04 : un log post-setup sur le disque cible n'est jamais
        // créé). Les lignes legacy qui suivaient (`net use /del`, curl
        // `etape=winpe`, `bcdboot /addlast` — `install.bat.php:56-60`)
        // étaient du code mort depuis toujours et ont été retirées. Le
        // tracking du début d'install est server-side (à la génération,
        // {@see WindowsPostInstallTracker::recordInstallBatGenerated}) ; la
        // fin d'install est rapportée par le curl OOBE de `unattend.xml`.
        $bash .= 'z:\\os\\' . $versionStr . '\\sources\\setup.exe /unattend:x:\\windows\\system32\\unattend.xml'
            . "\r\n" . $pause;

        return $bash;
    }

    /**
     * Génère le body iso-legacy `diskpart.php:22-25` pour
     * `/ipxe/windows/diskpart.txt`.
     *
     * **Body strict** : `select disk O\r\nselect partition 1\r\nassign letter=U\r\n`.
     * Aucune interpolation conditionnelle (parité legacy stricte — le diskpart
     * est un fichier statique côté contenu).
     */
    public function buildDiskpart(): string
    {
        return "select disk O\r\nselect partition 1\r\nassign letter=U\r\n";
    }
}
