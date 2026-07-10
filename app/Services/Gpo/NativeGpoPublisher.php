<?php

declare(strict_types=1);

namespace App\Services\Gpo;

use App\Gpo\Dto\GpoSummary;
use App\Gpo\Services\GpoService;
use App\Gpo\Support\GpoActionLog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Port natif d'`import_gpo` (legacy `sambaedu/includes/gpo.inc.php:962`) pour
 * les templates de config packagées (forme répertoire `sambaedu-gpo/<name>/`).
 *
 * Story 38.4 (AC1) — remplace la dépendance runtime au shim legacy
 * `import_gpo` de {@see AgentBootstrapPublisher}. Reproduit la séquence :
 *   1. résolution de la GPO par displayName ({@see GpoService::findByDisplayName}) ;
 *      absente → création ({@see GpoService::create} — branche `gpocreate`) ;
 *   2. idempotence de version (template GPT.INI vs `versionNumber` AD, skip si
 *      pas plus récent et `!$force`) — **abandon de `/etc/sambaedu/applications/
 *      gpos.json`** (état de version local legacy, remplacé par la lecture AD) ;
 *   3. spécialisation des placeholders `###_<PARAM>_###` (texte ASCII pur —
 *      pas de `Registry.pol` pour `SE_agent_bootstrap`, donc pas de codec PReg) ;
 *   4. calcul de version parité legacy (`v_u*0x10000 + v_m + increment`) et
 *      réécriture du `GPT.INI` en CRLF ;
 *   5. copie SYSVOL récursive via `smbclient` sous le ccache Administrator
 *      fourni ({@see AdministratorKerberosContext}) ;
 *   6. pose des attributs GPC LDAP `versionNumber` + `gPCMachineExtensionNames`
 *      ({@see GpcDirectory} — parité `modify_ad`).
 *
 * **JAMAIS de lien** : la branche `[links]` d'`import_gpo` (qui liait la GPO à
 * la RACINE du domaine faute de section `[links]`) n'est PAS portée
 * (`project_import_gpo_auto_root_link`, AD fédéré 75 étabs). Le liage explicite
 * sur l'OU établissement reste du ressort de {@see AgentBootstrapPublisher}.
 *
 * Garde-fou archi : sous `App\Services\Gpo` (invoque `Process`/smbclient).
 */
class NativeGpoPublisher
{
    /**
     * Paramètres de spécialisation `###_<PARAM>_###` — sous-ensemble texte du
     * legacy `specialise_gpo` (`gpo.inc.php:621`). `SE_agent_bootstrap` ne
     * contient que `###_SE4FS_NAME_###`, mais on couvre la liste complète pour
     * la parité avec d'autres templates de config texte.
     *
     * @var list<string>
     */
    private const SPECIALISE_PARAMS = [
        'domain', 'samba_domain', 'se4fs_name', 'se4ad_name',
        'se4install_name', 'ldap_base_dn', 'cloud_name',
    ];

    public function __construct(
        private readonly GpoService $gpoService,
    ) {}

    /**
     * Publie (ou republie) la GPO `$displayName` dans SYSVOL sous le ccache
     * Administrator `$ccache` fourni. Retourne le GUID de la GPO.
     *
     * @param  bool  $force  Republier même si la version SYSVOL est à jour.
     * @return string GUID `{...}` de la GPO publiée.
     * @throws RuntimeException Si la publication échoue.
     */
    public function publish(string $displayName, bool $force, string $ccache, GpoActionLog $log): string
    {
        $stagedDir = $this->stagedTemplateDir($displayName);
        if (! is_dir($stagedDir)) {
            throw new RuntimeException(sprintf('Template stagé introuvable : %s', $stagedDir));
        }

        $info = $this->readTemplateInfo($stagedDir);
        $templateVersion = (string) ($info['General']['Version'] ?? '0');

        // (1) Résolution GPO — création si absente (branche gpocreate).
        $gpo = $this->gpoService->findByDisplayName($displayName);
        if ($gpo === null) {
            $log->step('GPO absente — création native (parité gpocreate)', ['display_name' => $displayName]);
            $this->gpoService->create($displayName);
            $gpo = $this->gpoService->findByDisplayName($displayName);
            if ($gpo === null) {
                throw new RuntimeException(sprintf('GPO %s créée mais introuvable ensuite.', $displayName));
            }
        }

        $adVersion = $gpo->versionNumber ?? 0;

        // (2) Idempotence de version (parité gpo.inc.php:1000-1012).
        [$tplU, $tplM] = $this->gpoVersion($templateVersion);
        [$adU, $adM] = $this->gpoVersion((string) $adVersion);
        if (! $force && $tplU <= $adU && $tplM <= $adM) {
            $log->step('publication ignorée : version SYSVOL à jour (idempotent)', [
                'template_version' => $templateVersion,
                'ad_version' => $adVersion,
            ]);

            return $gpo->name;
        }

        // (3) Copie de travail + spécialisation des placeholders.
        $workDir = sys_get_temp_dir() . '/se_gpo_import_' . bin2hex(random_bytes(6));
        File::deleteDirectory($workDir);
        File::copyDirectory($stagedDir, $workDir);

        try {
            $this->specialise($workDir);

            // (4) Calcul de version parité legacy + réécriture GPT.INI CRLF.
            $increment = 0;
            if ($this->hasCse($info, 'gpcuserextensionnames')) {
                $increment += 0x10000;
            }
            if ($this->hasCse($info, 'gpcmachineextensionnames')) {
                $increment += 1;
            }
            $vU = max($tplU, $adU);
            $vM = max($tplM, $adM);
            $version = $vU * 0x10000 + $vM + $increment;

            $gptContent = "[General]\r\nVersion=" . $version . "\r\ndisplayName=" . $displayName . "\r\n";
            file_put_contents($workDir . '/GPT.INI', $gptContent);

            // (5) Copie SYSVOL récursive (parité sysvol_put répertoire).
            $this->sysvolPutRecursive($workDir, $gpo, $ccache, $log);

            // (6) Attributs GPC LDAP (parité modify_ad).
            $attrs = ['versionnumber' => $version];
            if (isset($info['CSE']['gpcmachineextensionnames'])) {
                $attrs['gpcmachineextensionnames'] = (string) $info['CSE']['gpcmachineextensionnames'];
            }
            if (isset($info['CSE']['gpcuserextensionnames'])) {
                $attrs['gpcuserextensionnames'] = (string) $info['CSE']['gpcuserextensionnames'];
            }
            app(GpcDirectory::class)->setAttributes($gpo->name, $attrs);

            $log->step('publication native import_gpo terminée', [
                'gpo_name' => $gpo->name,
                'version' => $version,
            ]);

            return $gpo->name;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Répertoire du template stagé (forme répertoire `sambaedu-gpo/<name>/`).
     */
    private function stagedTemplateDir(string $displayName): string
    {
        $dir = (string) config('sambaedu.gpo.templates_dir', '/usr/share/sambaedu/gpo/');
        $dir = str_ends_with($dir, '/') ? $dir : $dir . '/';

        return $dir . 'sambaedu-gpo/' . $displayName;
    }

    /**
     * Parse le `GPT.INI` du template stagé (sections `[General]` / `[CSE]`).
     *
     * @return array<string,mixed>
     */
    private function readTemplateInfo(string $stagedDir): array
    {
        $gptIni = $stagedDir . '/GPT.INI';
        $raw = @file_get_contents($gptIni);
        if ($raw === false) {
            throw new RuntimeException(sprintf('GPT.INI du template introuvable : %s', $gptIni));
        }

        // INI_SCANNER_RAW : ne pas interpréter les GUIDs des CSE.
        $parsed = @parse_ini_string($raw, true, INI_SCANNER_RAW);
        if ($parsed === false) {
            throw new RuntimeException(sprintf('GPT.INI du template illisible : %s', $gptIni));
        }

        // Normaliser la casse de la section CSE (clés AD en minuscules).
        if (isset($parsed['CSE']) && is_array($parsed['CSE'])) {
            $parsed['CSE'] = array_change_key_case($parsed['CSE'], CASE_LOWER);
        }

        return $parsed;
    }

    /** La section `[CSE]` porte-t-elle l'extension `$key` (insensible casse) ? */
    private function hasCse(array $info, string $key): bool
    {
        $cse = $info['CSE'] ?? [];

        return is_array($cse) && isset($cse[strtolower($key)]);
    }

    /**
     * Décompose une version GPO en `[user, machine]` — port `gpo_version`.
     * Forme `"user.machine"` OU entier combiné `user*0x10000 + machine`.
     *
     * @return array{0:int,1:int}
     */
    private function gpoVersion(string $version): array
    {
        $parts = explode('.', $version);
        if (isset($parts[1])) {
            return [(int) $parts[0], (int) $parts[1]];
        }

        $combined = (int) $parts[0];
        $user = intdiv($combined, 0x10000);
        $machine = $combined - $user * 0x10000;

        return [$user, $machine];
    }

    /**
     * Spécialise les placeholders `###_<PARAM>_###` dans TOUS les fichiers texte
     * du répertoire de travail (substitution de chaîne pure — le `SE_agent_bootstrap`
     * est de l'ASCII : startup.cmd, scripts.ini, GPT.INI ; pas de `Registry.pol`).
     */
    private function specialise(string $workDir): void
    {
        $substitutions = [];
        foreach (self::SPECIALISE_PARAMS as $param) {
            $value = config('sambaedu.' . $param);
            if ($value !== null) {
                $substitutions['###_' . strtoupper($param) . '_###'] = (string) $value;
            }
        }
        if ($substitutions === []) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            // On ne spécialise que les fichiers texte connus (pas de binaire pol).
            if (str_ends_with(strtolower($path), '.pol')) {
                continue;
            }
            $content = @file_get_contents($path);
            if ($content === false) {
                continue;
            }
            $replaced = strtr($content, $substitutions);
            if ($replaced !== $content) {
                file_put_contents($path, $replaced);
            }
        }
    }

    /**
     * Copie récursive de l'arborescence de travail vers
     * `//<host>/sysvol/<domain>/Policies/<cn>` via smbclient sous le ccache
     * Administrator (parité `sysvol_put` mode répertoire :
     * `recurse ON; prompt OFF; mput *`).
     *
     * @throws RuntimeException Si la copie échoue.
     */
    private function sysvolPutRecursive(string $workDir, GpoSummary $gpo, string $ccache, GpoActionLog $log): void
    {
        $host = (string) config('sambaedu.se4ad_name', '');
        if ($host === '') {
            try {
                $host = app(\App\Config\SambaEduConfig::class)->ldap()->getHosts()[0] ?? '';
            } catch (\Throwable) {
                $host = '';
            }
        }
        $domain = (string) config('sambaedu.domain', '');
        if ($host === '' || $domain === '') {
            throw new RuntimeException('domain/host SYSVOL indéterminés — publication impossible.');
        }

        $remoteDir = sprintf('%s/Policies/%s', $domain, $gpo->name);
        $command = sprintf(
            'cd "%s";lcd "%s";prompt OFF;recurse ON;mput *',
            $remoteDir,
            $workDir,
        );

        $result = Process::env(['KRB5CCNAME' => $ccache])->run([
            'smbclient', '//' . $host . '/sysvol',
            '--use-kerberos=required',
            '-c', $command,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                'Copie SYSVOL récursive échouée pour %s (exit=%d): %s',
                $gpo->displayName,
                $result->exitCode() ?? -1,
                substr($result->output() . $result->errorOutput(), 0, 400),
            ));
        }

        $log->step('copie SYSVOL récursive OK', ['remote_dir' => $remoteDir]);
    }
}
