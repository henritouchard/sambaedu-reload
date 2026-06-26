<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Services;

use App\Ipxe\Iso\Exceptions\WinpeDriverInjectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Story 3.10 — Injection automatique, idempotente et persistante des pilotes
 * réseau (NIC) dans le `boot.wim` WinPE servi aux postes.
 *
 * Cause racine (NE PAS re-débattre) : à partir de Win11 24H2, Microsoft a
 * retiré des pilotes Intel LAN legacy (`e1d`, ex. I219) du `boot.wim`. Sur un
 * poste à NIC non-inbox, WinPE ne monte pas le réseau → le `@PING` de
 * l'`install.bat` échoue et l'install ne démarre jamais. Le SEUL levier pour
 * le NIC est le `boot.wim` lui-même (chicken-and-egg : `z:\os\drivers` de
 * l'unattend exige déjà le réseau). Cf. [[project_winpe_nic_driver_boot_wim_gap]].
 *
 * Pourquoi un service rejoué à chaque extraction et pas un DISM one-shot :
 * {@see WindowsIsoExtractor::extract()} ré-extrait l'ISO et **écrase** le
 * `boot.wim` par le stock Microsoft à chaque déploiement. Une injection
 * manuelle ne tiendrait pas (détruite à la première ré-extraction — ce qui a
 * cassé le lab le 2026-06-25). On rejoue donc l'injection juste après la copie
 * fraîche du `boot.wim` (l'extracteur appelle {@see inject()}).
 *
 * **Idempotence par construction (D4)** : chaque extraction copie un `boot.wim`
 * pristine depuis l'ISO ; l'injection s'exécute toujours sur un wim vierge de
 * toute injection antérieure → `wimlib add` est déterministe, aucune logique de
 * diff requise.
 *
 * **No-op propre (D4)** : pack absent / vide / sans `.inf` → skip + log info,
 * le `boot.wim` reste le stock Microsoft intact (zéro régression pour les parcs
 * à NIC inbox).
 *
 * Conventions iso `WindowsIsoExtractor` : `Illuminate\Support\Facades\Process`,
 * helper `runOrThrow`, channel log `ipxe`, exception dédiée portant exit+stderr.
 * L'injection tourne en www-admin SANS sudo (le `boot.wim` lui appartient déjà
 * après le `chown` de l'extraction).
 */
final class WinpeDriverInjector
{
    /**
     * Injecte le pack de pilotes NIC (+ le helper `nicload.cmd`) dans
     * l'image bootable du `boot.wim` fourni.
     *
     * @param  string  $bootWimPath  Chemin absolu du `boot.wim` cible
     *                               (`{target}/sources/boot.wim`), déjà copié
     *                               frais et `chmod 0666` par l'extracteur.
     * @param  int|null  $timeoutSeconds  Timeout par commande wimlib.
     *
     * @throws WinpeDriverInjectionException Si `wimlib-imagex` est absent, sort
     *                                       en erreur, ou si le `boot.wim` cible
     *                                       est introuvable alors qu'un pack
     *                                       non vide doit être injecté.
     */
    public function inject(string $bootWimPath, ?int $timeoutSeconds = null): void
    {
        $channel = (string) config('ipxe.log.channel', 'ipxe');
        $packPath = rtrim(
            (string) config('ipxe.iso_management.winpe_drivers_path', storage_path('install/winpe-drivers')),
            '/',
        );

        $families = $this->collectFamilies($packPath);

        // No-op propre : aucun pilote à injecter → boot.wim stock préservé.
        if ($families === []) {
            Log::channel($channel)->info('ipxe.winpe.drivers.skipped_empty', [
                'pack_path'  => $packPath,
                'boot_wim'   => $bootWimPath,
                'reason'     => 'aucun pilote NIC à injecter (pack absent, vide ou sans .inf)',
            ]);

            return;
        }

        // À ce stade un pack non vide DOIT être injecté : si le boot.wim cible
        // est absent, c'est une erreur dure (pas un demi-boot silencieux).
        if (! is_file($bootWimPath)) {
            throw new WinpeDriverInjectionException(
                "[winpe-drivers] boot.wim cible introuvable : {$bootWimPath}",
            );
        }

        $timeout = $timeoutSeconds ?? (int) config('ipxe.iso_management.extract_timeout_seconds', 1800);
        $imageIndex = (int) config('ipxe.iso_management.winpe_boot_wim_image_index', 2);

        if ($imageIndex < 1) {
            throw new WinpeDriverInjectionException(
                "[winpe-drivers] index d'image WinPE invalide : {$imageIndex} (attendu >= 1, défaut 2)",
            );
        }

        // 1) Une famille = un sous-dossier de pilotes, ajouté à
        //    `\drivers\<famille>` dans l'image (visible `X:\drivers\<famille>`
        //    dans le WinPE booté ; `nicload.cmd` les `drvload` récursivement).
        foreach ($families as $family => $infCount) {
            $source = $packPath . '/' . $family;
            $this->runWimlibUpdate(
                $bootWimPath,
                $imageIndex,
                'add ' . $this->wimlibArg($source) . ' ' . $this->wimlibArg('/drivers/' . $family),
                $timeout,
                "add-driver:{$family}",
            );
        }

        // 2) `nicload.cmd` (asset versionné) injecté à
        //    `\Windows\System32\nicload.cmd` (working dir de winpeshl
        //    LaunchApps), exécuté AVANT toute tentative réseau.
        $nicloadSource = $this->nicloadSourcePath();
        if (! is_file($nicloadSource)) {
            throw new WinpeDriverInjectionException(
                "[winpe-drivers] helper nicload.cmd introuvable : {$nicloadSource}",
            );
        }
        $this->runWimlibUpdate(
            $bootWimPath,
            $imageIndex,
            'add ' . $this->wimlibArg($nicloadSource) . ' ' . $this->wimlibArg('/Windows/System32/nicload.cmd'),
            $timeout,
            'add-nicload',
        );

        // 3) wimlib réécrit le fichier → re-poser 0666 + ownership www-admin
        //    (best-effort : le boot.wim doit rester servi en SMB/HTTP).
        @chmod($bootWimPath, 0666);
        Process::timeout($timeout)->run(
            sprintf('chown www-admin:www-admin %s', escapeshellarg($bootWimPath)),
        );

        $totalInf = array_sum($families);
        Log::channel($channel)->info('ipxe.winpe.drivers.injected', [
            'boot_wim'      => $bootWimPath,
            'image_index'   => $imageIndex,
            'families'      => $families,
            'families_count' => count($families),
            'inf_total'     => $totalInf,
            'summary'       => sprintf(
                'boot.wim index %d : injecté %s (%d .inf) + nicload.cmd',
                $imageIndex,
                implode(', ', array_keys($families)),
                $totalInf,
            ),
        ]);
    }

    /**
     * Scanne le pack et retourne les familles non vides (au moins un `.inf`).
     *
     * PUBLIC : réutilisé par l'UI Livewire (`iso-windows`) pour afficher l'état
     * exact du pack — source unique de vérité (D3, zéro duplication). L'UI et
     * l'injection comptent ainsi les `.inf` à l'identique (récursif +
     * insensible à la casse, donc les `*.INF` majuscules des packs Lenovo/Intel
     * sont comptés comme injectés).
     *
     * @return array<string, int> `<famille> => nombre de .inf` (familles
     *                            sans `.inf` exclues). Vide si pack absent.
     */
    public function collectFamilies(string $packPath): array
    {
        if ($packPath === '' || ! is_dir($packPath)) {
            return [];
        }

        $families = [];
        foreach (glob($packPath . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $infCount = count($this->findInfFiles($dir));
            if ($infCount > 0) {
                $families[basename($dir)] = $infCount;
            }
        }

        ksort($families);

        return $families;
    }

    /**
     * Localise récursivement les `.inf` sous un dossier de famille.
     *
     * @return list<string>
     */
    private function findInfFiles(string $dir): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'inf') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    /**
     * Chemin source versionné de `nicload.cmd` (dossier helpers WinPE).
     */
    private function nicloadSourcePath(): string
    {
        $source = rtrim(
            (string) config('ipxe.windows.assets_paths.winpe_source_path', resource_path('ipxe/winpe')),
            '/',
        );

        return $source . '/nicload.cmd';
    }

    /**
     * Exécute un `wimlib-imagex update <wim> <index> --command="<text>"`
     * et lève {@see WinpeDriverInjectionException} (exit + stderr) si échec.
     *
     * Le binaire `wimlib-imagex` absent → exit 127 (ou erreur shell) → exception
     * explicite (AC2.5). Calque du helper `runOrThrow` de l'extracteur.
     */
    private function runWimlibUpdate(string $bootWim, int $index, string $commandText, int $timeout, string $stage): void
    {
        $command = sprintf(
            'wimlib-imagex update %s %d --command=%s',
            escapeshellarg($bootWim),
            $index,
            escapeshellarg($commandText),
        );

        $result = Process::timeout($timeout)->run($command);

        if (! $result->successful()) {
            $err = trim($result->errorOutput() ?: $result->output());

            throw new WinpeDriverInjectionException(
                '[winpe-drivers:' . $stage . '] ' . ($err !== '' ? $err : 'wimlib-imagex en échec'),
                $result->exitCode() ?? -1,
            );
        }
    }

    /**
     * Quote un chemin pour le mini-langage des `--command` de wimlib-imagex
     * (guillemets doubles si espaces — wimlib parse ces guillemets).
     */
    private function wimlibArg(string $path): string
    {
        if (preg_match('/\s/', $path) === 1) {
            // Échapper `\` puis `"` internes pour ne pas terminer le guillemet
            // prématurément (chemin de config exotique → erreur wimlib opaque).
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $path) . '"';
        }

        return $path;
    }
}
