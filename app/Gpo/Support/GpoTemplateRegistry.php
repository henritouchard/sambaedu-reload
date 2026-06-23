<?php

declare(strict_types=1);

namespace App\Gpo\Support;

use App\Gpo\Dto\GpoTemplate;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Inventaire des archives-template GPO livrées par le paquet `sambaedu-gpo`.
 *
 * Port natif (testable) de `sambaedu/includes/gpo.inc.php` :
 * `list_gpo_templates` + `get_gpo_template_info`. Scanne
 * `config('sambaedu.gpo.templates_dir')` et reconnaît **deux formes** d'entrée
 * (iso-legacy `get_gpo_template_info`) :
 *
 *  - une archive `<name>.zip` contenant un `GPT.INI` ;
 *  - un répertoire `<name>/` contenant un `GPT.INI` (forme dépaquetée
 *    `sambaedu-gpo/<name>/` utilisée sur la VM dev — cf. config
 *    `applications_template` D6).
 *
 * Le `displayName` (section `[General]` du `GPT.INI`, fallback basename) est la
 * clé de résolution : une GPO de l'AD est **publiable** ssi son `displayName`
 * matche celui d'une template. C'est la généralisation directe de la boucle
 * legacy `gpo-maj.php` (`foreach (list_gpo_templates_etab() as $gpo) import_gpo(...)`)
 * — aucune spécificité `se4_wpkg`, la GPO WPKG n'est qu'une template parmi
 * d'autres.
 *
 * Lecture pure : aucun side effect, aucun `exec`. Story 27.14 : la publication
 * SYSVOL de templates de config (ex-`GpoPublisher`) a été supprimée avec le
 * canal de config legacy ; ce registre subsiste comme RECONNAISSANCE de
 * publiabilité (notamment du bootstrap `se4_agent_bootstrap`, 25.4) —
 * `isPublishable()` reste l'API consommée.
 */
class GpoTemplateRegistry
{
    /** Garde-fou : nombre max d'entrées scannées (répertoire packagé borné). */
    private const MAX_ENTRIES = 500;

    /**
     * Préfixes d'archive autorisés — iso legacy `list_gpo_templates` (`se4_`)
     * et `list_gpo_templates_etab` (`etab_`). Restreint la surface aux
     * templates effectivement livrées par le paquet `sambaedu-gpo` (review F7),
     * matching insensible à la casse.
     *
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = ['se4_', 'etab_'];

    /** Mémo par instance — évite de re-scanner le disque à chaque accès (review F5). */
    private ?array $cache = null;

    /**
     * Retourne toutes les templates publiables présentes sur disque (mémoïsé).
     *
     * @return list<GpoTemplate>
     */
    public function all(): array
    {
        return $this->cache ??= $this->scan();
    }

    /**
     * Scan effectif du répertoire de templates. Reconnaît les deux formes
     * EXACTEMENT comme le legacy `unzip_gpo` / `get_gpo_template_info` (review F2) :
     *  - archive   : `<dir>/<name>.zip`            → archive transmise = `<name>.zip`
     *  - répertoire : `<dir>/sambaedu-gpo/<name>/`  → archive transmise = `<name>` (nu)
     * Une entrée n'est retenue que si (a) son nom porte un préfixe autorisé (F7)
     * et (b) son `GPT.INI` porte une section `[CSE]` avec extensions machine/user
     * — sinon `import_gpo`/`get_gpo_template_info` la rejetteraient (review F1).
     *
     * @return list<GpoTemplate>
     */
    private function scan(): array
    {
        $dir = $this->templatesDir();
        if ($dir === '' || ! is_dir($dir)) {
            return [];
        }

        $templates = [];
        $count = 0;

        // (a) Forme archive : <dir>/<name>.zip
        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (++$count > self::MAX_ENTRIES) {
                break;
            }
            if (! is_file($dir . $entry) || ! str_ends_with(strtolower($entry), '.zip')) {
                continue;
            }
            $basename = substr($entry, 0, -4);
            if (! $this->hasAllowedPrefix($basename)) {
                continue;
            }
            $info = $this->readGptIniFromZip($dir . $entry);
            if ($info === null || ! $this->hasCseExtensions($info)) {
                continue;
            }
            $templates[] = new GpoTemplate(
                displayName: $this->resolveDisplayName($info, $basename),
                archive: $entry,
                version: $this->resolveVersion($info),
            );
        }

        // (b) Forme répertoire : <dir>/sambaedu-gpo/<name>/GPT.INI
        $dirForm = $dir . 'sambaedu-gpo/';
        if (is_dir($dirForm)) {
            foreach (@scandir($dirForm) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (++$count > self::MAX_ENTRIES) {
                    break;
                }
                $gptIni = $dirForm . $entry . '/GPT.INI';
                if (! is_dir($dirForm . $entry) || ! is_file($gptIni)) {
                    continue;
                }
                if (! $this->hasAllowedPrefix($entry)) {
                    continue;
                }
                $info = $this->readGptIniFromDir($gptIni);
                if ($info === null || ! $this->hasCseExtensions($info)) {
                    continue;
                }
                $templates[] = new GpoTemplate(
                    displayName: $this->resolveDisplayName($info, $entry),
                    archive: $entry, // nu : `unzip_gpo` résout sous sambaedu-gpo/ (review F2)
                    version: $this->resolveVersion($info),
                );
            }
        }

        return $templates;
    }

    /** Le nom d'entrée porte-t-il un préfixe de template autorisé (insensible casse) ? */
    private function hasAllowedPrefix(string $name): bool
    {
        $n = mb_strtolower($name);
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($n, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Le `GPT.INI` porte-t-il une section `[CSE]` valide ? Garde iso-legacy
     * `get_gpo_template_info` (review F1) — sinon la template est invalide et
     * `import_gpo` échouerait. Clés comparées insensibles à la casse.
     *
     * @param array<string,mixed> $info
     */
    private function hasCseExtensions(array $info): bool
    {
        $cse = $info['CSE'] ?? null;
        if (! is_array($cse)) {
            return false;
        }
        $lc = array_change_key_case($cse, CASE_LOWER);
        return isset($lc['gpcmachineextensionnames']) || isset($lc['gpcuserextensionnames']);
    }

    /**
     * Résout la template correspondant à une GPO par son `displayName`.
     * Matching insensible à la casse + trim (robustesse face aux variations
     * de saisie / d'export AD). Retourne null si la GPO n'a pas de template —
     * cas d'une GPO créée à la main (RSAT/GPMC), built-in Windows (Default
     * Domain Policy) ou tierce : son contenu SYSVOL est rédigé, pas généré,
     * donc non publiable depuis SE5.
     */
    public function templateFor(string $displayName): ?GpoTemplate
    {
        $needle = mb_strtolower(trim($displayName));
        if ($needle === '') {
            return null;
        }

        foreach ($this->all() as $template) {
            if (mb_strtolower(trim($template->displayName)) === $needle) {
                return $template;
            }
        }

        return null;
    }

    /** Une GPO est-elle publiable (une template matche son displayName) ? */
    public function isPublishable(string $displayName): bool
    {
        return $this->templateFor($displayName) !== null;
    }

    private function templatesDir(): string
    {
        $dir = (string) config('sambaedu.gpo.templates_dir', '');
        if ($dir === '') {
            return '';
        }
        return str_ends_with($dir, '/') ? $dir : $dir . '/';
    }

    /**
     * Lit et parse le `GPT.INI` d'une archive `.zip`. Retourne le tableau de
     * sections (iso `parse_ini_string(..., true)`) ou null si illisible.
     *
     * @return array<string,mixed>|null
     */
    private function readGptIniFromZip(string $zipPath): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            Log::channel('gpo')->debug('GpoTemplateRegistry: archive zip illisible', ['path' => $zipPath]);
            return null;
        }

        $ini = $zip->getFromName('GPT.INI');
        $zip->close();

        if ($ini === false) {
            return null;
        }

        return $this->parseGptIni($ini);
    }

    /**
     * Lit et parse un `GPT.INI` sur disque (template en forme répertoire).
     *
     * @return array<string,mixed>|null
     */
    private function readGptIniFromDir(string $gptIniPath): ?array
    {
        $ini = @file_get_contents($gptIniPath);
        if ($ini === false) {
            return null;
        }

        return $this->parseGptIni($ini);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseGptIni(string $contents): ?array
    {
        // INI_SCANNER_RAW : ne pas interpréter les valeurs (les CSE GUIDs
        // contiennent des caractères qui troublent le parseur typé).
        $parsed = @parse_ini_string($contents, true, INI_SCANNER_RAW);
        if ($parsed === false || $parsed === []) {
            return null;
        }
        return $parsed;
    }

    /**
     * displayName depuis `[General] displayName`, fallback sur le basename de
     * l'entrée (ex. `se4_wpkg`) si la section ne le porte pas.
     *
     * @param array<string,mixed> $info
     */
    private function resolveDisplayName(array $info, string $basename): string
    {
        $general = is_array($info['General'] ?? null) ? $info['General'] : [];
        $name = trim((string) ($general['displayName'] ?? ''));
        return $name !== '' ? $name : $basename;
    }

    /**
     * Version best-effort (review F8) : champ purement informatif, NON transmis
     * à `import_gpo` (qui relit lui-même via `get_gpo_template_info`). Le `(int)`
     * tronque la forme `"user.machine"` du legacy `gpo_version()` — acceptable
     * ici car non utilisé pour décider quoi que ce soit.
     *
     * @param array<string,mixed> $info
     */
    private function resolveVersion(array $info): ?int
    {
        $general = is_array($info['General'] ?? null) ? $info['General'] : [];
        if (! isset($general['Version'])) {
            return null;
        }
        return (int) $general['Version'];
    }
}
