<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Config\SambaEduConfig;
use App\Gpo\Dto\WpkgGpoSyncReport;
use App\Gpo\Enums\WpkgGpoSyncSeverity;
use App\Gpo\Support\GpoLogger;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synchronise la GPO `se4_wpkg` template officiel avec les endpoints natifs
 * Story 15.2 (`/wpkg/hosts.xml` + `/wpkg/profiles.xml`) — Story 16.6.
 *
 * Deux modes :
 *
 *  - **`audit()`** : lecture pure, aucun side effect AD/SYSVOL/FS. Retourne
 *    un {@see WpkgGpoSyncReport} structurant l'état de cohérence (GPO existe,
 *    liaisons, placeholders, couverture Bearer Phase 2).
 *  - **`publish(force)`** : write SYSVOL via shim legacy `import_gpo`.
 *    Le shim legacy enchaîne en interne `unzip_gpo` → `specialise_gpo` →
 *    `sysvol_put` ; on ne ré-invoque PAS `specialise_gpo` séparément
 *    (TD-16.6-1 corrigée — review fix #3). Lock applicatif
 *    `gpo:wpkg:sync` anti-race.
 *
 * Frontière nette : 16.6 **ne crée pas** la GPO from scratch (= Epic 17.1).
 * Il **consomme** le template officiel `/usr/share/sambaedu/gpo/se4_wpkg.zip`
 * et re-spécialise ses placeholders `###_SE4FS_NAME_###` & co.
 *
 * @see \App\Gpo\Dto\WpkgGpoSyncReport
 * @see \App\Gpo\Enums\WpkgGpoSyncSeverity
 */
class WpkgGpoSynchronizer
{
    /** Nom canonique de la GPO. Toujours `se4_wpkg` (template officiel). */
    public const GPO_DISPLAY_NAME = 'se4_wpkg';

    /** Path par défaut du template `.zip` (overridable via config T0.1). */
    public const DEFAULT_TEMPLATE_PATH = '/usr/share/sambaedu/gpo/se4_wpkg.zip';

    /** Nom de l'archive transmise au shim `import_gpo`. */
    public const TEMPLATE_ARCHIVE_NAME = 'se4_wpkg.zip';

    /** Préfixe path autorisé pour le template (défense en profondeur path traversal). */
    public const TEMPLATE_PATH_PREFIX = '/usr/share/sambaedu/gpo/';

    private const LOCK_NAME = 'gpo:wpkg:sync';
    /**
     * Valeurs par défaut du lock (TTL Redis / temps d'attente). Override
     * runtime via `config('sambaedu.gpo.wpkg_sync.lock_timeout')` /
     * `lock_wait` (review fix #10/#4 — bump 60→300 / 10→30 pour absorber un
     * `import_gpo` lent : extraction + spécialisation + `smbclient`).
     */
    private const DEFAULT_LOCK_TIMEOUT_SECONDS = 300;
    private const DEFAULT_LOCK_WAIT_SECONDS = 30;

    /** Limites garde-fou défensif sur le ZIP du template (review fix #C). */
    private const MAX_ZIP_FILES = 1000;
    private const MAX_ZIP_ENTRY_BYTES = 10 * 1024 * 1024; // 10 Mo

    /** Cap sur la liste des workstations interrogées par OU liée. */
    private const WORKSTATIONS_PER_OU_LIMIT = 200;

    /**
     * Clés `SambaEduConfig` propagées à `specialise_gpo` — iso legacy
     * `sambaedu/includes/gpo.inc.php:621-630` `$params`.
     */
    private const SPECIALISE_CONFIG_KEYS = [
        'domain',
        'samba_domain',
        'se4fs_name',
        'se4ad_name',
        'domain_sid',
        'se4install_name',
        'ldap_base_dn',
        'cloud_name',
    ];

    public function __construct(
        private readonly GpoService $gpos,
        private readonly SambaEduConfig $config,
    ) {}

    /**
     * Audit lecture pure : interroge l'AD, parse le template et résout les
     * URLs serveur attendues. **Aucun side effect** (AC1.1, AC1.6).
     */
    public function audit(?string $operationId = null): WpkgGpoSyncReport
    {
        $operationId ??= (string) Str::uuid();
        $log = GpoLogger::action('gpo.wpkg.sync.start', operationId: $operationId);
        $log->step('audit invoked', ['mode' => 'audit'], 'debug');

        try {
            $report = $this->buildAuditReport($operationId);
            $log->success([
                'severity' => $report->severity->value,
                'gpoExists' => $report->gpoExists,
                'linkedOusCount' => count($report->linkedOus),
            ]);

            // Trace de clôture symétrique (AC4.3).
            GpoLogger::action('gpo.wpkg.sync.end', operationId: $operationId, context: [
                'mode' => 'audit',
                'severity' => $report->severity->value,
            ])->success();

            return $report;
        } catch (\Throwable $e) {
            $log->failure($e);
            GpoLogger::action('gpo.wpkg.sync.end', operationId: $operationId, context: [
                'mode' => 'audit',
                'severity' => 'error',
            ])->failure($e);
            throw $e;
        }
    }

    /**
     * Publie (importe + spécialise) la GPO `se4_wpkg` dans SYSVOL via shim
     * legacy `import_gpo`. **Side effect SYSVOL**. Lock applicatif obligatoire.
     *
     * @throws RuntimeException Si le lock ne peut être acquis dans le délai
     *                          imparti (AC2.1), si le template est absent
     *                          (AC2.2), ou si `import_gpo` échoue (AC2.4 / R2).
     */
    public function publish(bool $force = false, ?string $operationId = null): WpkgGpoSyncReport
    {
        $operationId ??= (string) Str::uuid();
        $log = GpoLogger::action('gpo.wpkg.sync.start', operationId: $operationId, context: [
            'mode' => 'publish',
            'force' => $force,
        ]);

        $lockTimeout = (int) config('sambaedu.gpo.wpkg_sync.lock_timeout', self::DEFAULT_LOCK_TIMEOUT_SECONDS);
        $lockWait = (int) config('sambaedu.gpo.wpkg_sync.lock_wait', self::DEFAULT_LOCK_WAIT_SECONDS);
        $lock = Cache::lock(self::LOCK_NAME, $lockTimeout);
        $acquired = false;

        try {
            if (! $lock->block($lockWait)) {
                throw new RuntimeException(
                    'Synchronisation GPO `se4_wpkg` déjà en cours par un autre processus (lock indisponible après '
                    . $lockWait . 's).',
                );
            }
            $acquired = true;
            $log->step('lock acquired', ['lock' => self::LOCK_NAME]);

            // Pré-conditions : un audit complet pour décider du no-op (AC2.2).
            $pre = $this->buildAuditReport($operationId);

            if (! $pre->templateExists) {
                throw new RuntimeException(sprintf(
                    'Template officiel `%s` introuvable — copier `se4_wpkg.zip` dans `/usr/share/sambaedu/gpo/` puis relancer.',
                    $pre->templatePath,
                ));
            }

            if ($pre->gpoExists && ! $force && $pre->severity === WpkgGpoSyncSeverity::Ok) {
                $log->step('no-op : GPO déjà à jour (severity=ok), utiliser --force pour ré-importer', [
                    'gpo_guid' => $pre->gpoGuid,
                ]);
                $log->success(['outcome' => 'noop']);
                GpoLogger::action('gpo.wpkg.sync.end', operationId: $operationId, context: [
                    'mode' => 'publish',
                    'outcome' => 'noop',
                ])->success();

                return $this->reportWithMessage(
                    $pre,
                    'GPO `se4_wpkg` déjà à jour — aucune action (utilisez --force pour ré-importer).',
                );
            }

            // Prépare la map `$config` à passer au shim legacy. Pas d'appel
            // séparé à `specialise_gpo` : le shim `import_gpo` enchaîne en
            // interne `unzip_gpo → specialise_gpo → sysvol_put` (review fix #3 /
            // TD-16.6-1 — appel séparé qui spécialisait dans `/tmp/` puis se
            // faisait écraser par le tarball brut de `unzip_gpo` est supprimé).
            $legacyConfig = $this->resolveLegacyConfig();

            // Import : écrit SYSVOL via shim `import_gpo`. Best effort + log critical mid-failure (DO3).
            $this->invokeImport($legacyConfig, $force, $operationId);

            // Re-audit pour produire le DTO final reflet de l'état post-publish.
            $post = $this->buildAuditReport($operationId);

            $log->success([
                'gpo_guid' => $post->gpoGuid,
                'severity' => $post->severity->value,
            ]);
            GpoLogger::action('gpo.wpkg.sync.end', operationId: $operationId, context: [
                'mode' => 'publish',
                'outcome' => 'published',
                'severity' => $post->severity->value,
            ])->success();

            // Story 16.14 Q2 — invalider le cache santé pour la GPO `se4_wpkg`
            // après une republication réussie (le versionNumber a été bumpé par
            // `import_gpo` côté shim legacy).
            if ($post->gpoGuid !== null) {
                try {
                    app(\App\Gpo\Support\CachedGpoLookups::class)->forgetGpo($post->gpoGuid);
                } catch (\Throwable) {
                    // best-effort — ne doit pas masquer le succès métier.
                }
            }

            return $post;
        } catch (\Throwable $e) {
            $log->failure($e);
            GpoLogger::action('gpo.wpkg.sync.end', operationId: $operationId, context: [
                'mode' => 'publish',
                'outcome' => 'failure',
            ])->failure($e);
            throw $e;
        } finally {
            if ($acquired) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // Lock release ne doit pas masquer l'exception métier.
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function buildAuditReport(string $operationId): WpkgGpoSyncReport
    {
        $severity = WpkgGpoSyncSeverity::Ok;
        $messages = [];

        // 1. URLs attendues — Story 27.5 (D6) : le shim WPKG legacy
        // (`/wpkg/hosts.xml` + `/wpkg/profiles.xml`) est SUPPRIMÉ, la livraison
        // est NATIVE (bundle Apache statique + profil déposé par l'agent). La GPO
        // `se4_wpkg` est retirée comme déclencheur (l'agent déclenche WPKG). Ces
        // URLs sont conservées dans le DTO d'audit à titre INFORMATIF et pointent
        // désormais le bundle natif (`config('agent.wpkg_bundle_url')`) — elles ne
        // sont plus des endpoints dynamiques. Les noms de fichiers `hosts.xml` /
        // `profiles.xml` y figurent (artefacts du bundle / profil local).
        $bundleUrl = rtrim((string) config('agent.wpkg_bundle_url', ''), '/');
        $hostsXmlUrl = ($bundleUrl !== '' ? $bundleUrl : '(bundle WPKG natif non configuré)') . '/hosts.xml';
        $profilesXmlUrl = ($bundleUrl !== '' ? $bundleUrl : '(bundle WPKG natif non configuré)') . '/profiles.xml';

        // 2. Recherche de la GPO `se4_wpkg` dans l'AD.
        $gpoExists = false;
        $gpoGuid = null;
        $gpoDisplayName = null;
        $gpoPath = null;
        $linkedOus = [];

        try {
            $list = $this->gpos->list();
            foreach ($list as $gpo) {
                if ($gpo->displayName === self::GPO_DISPLAY_NAME) {
                    $gpoExists = true;
                    $gpoGuid = $gpo->name;
                    $gpoDisplayName = $gpo->displayName;
                    $gpoPath = $gpo->path;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $messages[] = 'Lecture AD impossible : ' . $e->getMessage();
            $severity = $severity->merge(WpkgGpoSyncSeverity::Error);
        }

        if (! $gpoExists) {
            $messages[] = 'GPO `se4_wpkg` introuvable dans l\'AD — publication initiale requise (« Re-publier la GPO »).';
            $severity = $severity->merge(WpkgGpoSyncSeverity::Error);
        }

        // 3. Liaisons (uniquement si la GPO existe).
        if ($gpoExists && $gpoGuid !== null) {
            try {
                $linkedOus = $this->gpos->listContainers($gpoGuid);
            } catch (\Throwable $e) {
                $messages[] = 'Lecture des liaisons impossible : ' . $e->getMessage();
                $severity = $severity->merge(WpkgGpoSyncSeverity::Warning);
            }

            if ($linkedOus === []) {
                $messages[] = 'GPO `se4_wpkg` existe mais n\'est liée à aucune OU — le pipeline WPKG n\'est déclenché sur aucun poste. Allez sur /admin/settings/gpo/'
                    . $gpoGuid . '/links pour la lier.';
                $severity = $severity->merge(WpkgGpoSyncSeverity::Warning);
            }
        }

        // 4. Template officiel : présence + parsing placeholders.
        // En production : `.zip` (is_file). En testing host sans ext-zip : un
        // dossier fixture (is_dir) — la branche `scanDirectoryPlaceholders` couvre ce cas.
        $templatePath = $this->resolveTemplatePath();
        $templateExists = is_file($templatePath) || is_dir($templatePath);
        $templateMtime = null;
        $detectedPlaceholders = [];
        $unknownPlaceholders = [];

        if (! $templateExists) {
            $messages[] = sprintf(
                'Template officiel `%s` non trouvé sur le serveur — copier `se4_wpkg.zip` depuis le paquet `sambaedu-gpo` ou un serveur de référence.',
                $templatePath,
            );
            $severity = $severity->merge(WpkgGpoSyncSeverity::Error);
        } else {
            try {
                $mtime = @filemtime($templatePath);
                if (is_int($mtime) && $mtime > 0) {
                    $templateMtime = (new DateTimeImmutable())->setTimestamp($mtime);
                }
            } catch (\Throwable) {
                // mtime best effort.
            }

            try {
                [$detectedPlaceholders, $unknownPlaceholders] = $this->scanTemplatePlaceholders($templatePath, $operationId);
                if ($unknownPlaceholders !== []) {
                    $messages[] = 'Placeholders détectés mais hors whitelist `config/sambaedu.php` (clé `gpo.applications.substitutions.whitelist`) : '
                        . implode(', ', $unknownPlaceholders) . ' — la spécialisation laissera ces marqueurs intacts (pipeline cassé côté postes).';
                    $severity = $severity->merge(WpkgGpoSyncSeverity::Error);
                }
            } catch (\Throwable $e) {
                $messages[] = 'Lecture du template `.zip` impossible : ' . $e->getMessage();
                $severity = $severity->merge(WpkgGpoSyncSeverity::Warning);
            }
        }

        // 5. Couverture Bearer Phase 2 (DO2 — lecture seule, feature flag tolérant).
        [$bearerTableAvailable, $bearerCoverage, $bearerSeverity, $bearerMessages] = $this->auditBearerCoverage($linkedOus);
        $severity = $severity->merge($bearerSeverity);
        foreach ($bearerMessages as $m) {
            $messages[] = $m;
        }

        return new WpkgGpoSyncReport(
            gpoExists: $gpoExists,
            gpoGuid: $gpoGuid,
            gpoDisplayName: $gpoDisplayName,
            gpoPath: $gpoPath,
            linkedOus: $linkedOus,
            expectedHostsXmlUrl: $hostsXmlUrl,
            expectedProfilesXmlUrl: $profilesXmlUrl,
            templatePath: $templatePath,
            templateExists: $templateExists,
            templateLastModified: $templateMtime,
            detectedPlaceholders: $detectedPlaceholders,
            unknownPlaceholders: $unknownPlaceholders,
            bearerCoverage: $bearerCoverage,
            bearerTableAvailable: $bearerTableAvailable,
            severity: $severity,
            messages: $messages,
            operationId: $operationId,
        );
    }

    /**
     * Scan ZIP — extrait l'arborescence virtuelle et liste les placeholders
     * `###_KEY_###` détectés. Compare à la whitelist `config/sambaedu.php`
     * (clé `gpo.applications.substitutions.whitelist`).
     *
     * **Mode dégradé** : si le path pointe sur un **répertoire** (cas testing
     * sans `ext-zip`), on scanne récursivement les fichiers texte. La parité
     * fonctionnelle est préservée (mêmes regex placeholders).
     *
     * @return array{0: list<string>, 1: list<string>}  [detected, unknownOutsideWhitelist]
     */
    private function scanTemplatePlaceholders(string $zipPath, ?string $operationId = null): array
    {
        if (is_dir($zipPath)) {
            return $this->scanDirectoryPlaceholders($zipPath, $operationId);
        }
        if (! class_exists(\ZipArchive::class)) {
            // ext-zip absente : scan placeholders impossible. On retourne vide
            // pour ne pas casser l'audit complet ; le contexte sera signalé
            // via un message info en amont.
            return [[], []];
        }
        $log = GpoLogger::action('gpo.wpkg.template.scan', operationId: $operationId);

        $detected = [];
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(sprintf('Impossible d\'ouvrir le template `%s`', $zipPath));
        }
        try {
            // Review fix #C — défense en profondeur : refus d'un ZIP anormalement
            // gros (zip bomb / template corrompu) avant boucle.
            if ($zip->numFiles > self::MAX_ZIP_FILES) {
                throw new RuntimeException(sprintf(
                    'Template ZIP trop volumineux (%d entrées > %d max) — refusé pour défense en profondeur.',
                    $zip->numFiles,
                    self::MAX_ZIP_FILES,
                ));
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                // Filter à des extensions susceptibles de contenir des placeholders texte
                // (le .pol binaire est aussi parsé par specialise_gpo mais on se limite ici
                // au texte pour la détection — best effort).
                if (! preg_match('/\.(xml|cmd|bat|ini|reg|inf|adm|admx|adml|txt|ps1|vbs)$/i', $name)) {
                    continue;
                }
                // Review fix #C — cap par entrée à MAX_ZIP_ENTRY_BYTES (10 Mo).
                // `getFromIndex(..., $length)` lit au max `$length` octets ; au-delà
                // on skip + warning (template anormalement gros).
                $raw = $zip->getFromIndex($i, self::MAX_ZIP_ENTRY_BYTES);
                if (! is_string($raw) || $raw === '') {
                    continue;
                }
                if (strlen($raw) >= self::MAX_ZIP_ENTRY_BYTES) {
                    $log->step('zip entry truncated', [
                        'path' => $name,
                        'limit' => self::MAX_ZIP_ENTRY_BYTES,
                    ], 'warning');
                    continue;
                }
                // Détection encodage UTF-16LE (typique des Registry.pol et fichiers MS).
                if (str_starts_with($raw, "\xFF\xFE") || (substr_count($raw, "\x00") > strlen($raw) / 4)) {
                    $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
                    if (is_string($converted) && $converted !== '') {
                        $raw = $converted;
                    } else {
                        // Review fix #D — encodage UTF-16LE annoncé mais
                        // conversion échouée : warning + fallback brut.
                        $log->step('utf16 decode failed', [
                            'path' => $name,
                            'length' => strlen($raw),
                        ], 'warning');
                    }
                }
                if (preg_match_all('/###_([A-Z][A-Z0-9_]*)_###/', $raw, $matches) > 0) {
                    foreach ($matches[1] as $key) {
                        $detected[$key] = true;
                    }
                }
            }
        } finally {
            $zip->close();
        }

        $detectedList = array_keys($detected);
        sort($detectedList);
        $unknown = $this->diffWhitelist($detectedList);

        return [$detectedList, $unknown];
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function scanDirectoryPlaceholders(string $dir, ?string $operationId = null): array
    {
        $log = GpoLogger::action('gpo.wpkg.template.scan', operationId: $operationId);
        $detected = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if (! $entry->isFile()) {
                continue;
            }
            $ext = strtolower($entry->getExtension());
            if (! in_array($ext, ['xml', 'cmd', 'bat', 'ini', 'reg', 'inf', 'adm', 'admx', 'adml', 'txt', 'ps1', 'vbs'], true)) {
                continue;
            }
            $raw = @file_get_contents($entry->getPathname());
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            if (str_starts_with($raw, "\xFF\xFE") || (substr_count($raw, "\x00") > strlen($raw) / 4)) {
                $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
                if (is_string($converted) && $converted !== '') {
                    $raw = $converted;
                } else {
                    // Review fix #D — encodage UTF-16LE annoncé mais
                    // conversion échouée : warning + fallback brut.
                    $log->step('utf16 decode failed', [
                        'path' => $entry->getPathname(),
                        'length' => strlen($raw),
                    ], 'warning');
                }
            }
            if (preg_match_all('/###_([A-Z][A-Z0-9_]*)_###/', $raw, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $detected[$key] = true;
                }
            }
        }

        $detectedList = array_keys($detected);
        sort($detectedList);
        $unknown = $this->diffWhitelist($detectedList);

        return [$detectedList, $unknown];
    }

    /**
     * @param  list<string>  $detected
     * @return list<string>
     */
    private function diffWhitelist(array $detected): array
    {
        $whitelist = array_map('strtoupper', array_keys((array) config('sambaedu.gpo.applications.substitutions.whitelist', [])));
        foreach (self::SPECIALISE_CONFIG_KEYS as $k) {
            $whitelist[] = strtoupper($k);
        }
        $whitelist = array_values(array_unique($whitelist));
        return array_values(array_filter(
            $detected,
            static fn (string $k): bool => ! in_array($k, $whitelist, true),
        ));
    }

    /**
     * @return array{0: bool, 1: array<string,bool>, 2: WpkgGpoSyncSeverity, 3: list<string>}
     */
    private function auditBearerCoverage(array $linkedOus): array
    {
        $required = (bool) config('sambaedu.gpo.wpkg_sync.bearer_required', false);

        if (! Schema::hasTable('workstation_api_secrets')) {
            // Cas standard hors Phase 2 active : informatif, ne bump pas la sévérité globale.
            return [
                false,
                [],
                WpkgGpoSyncSeverity::Ok,
                [],
            ];
        }

        if ($linkedOus === []) {
            return [true, [], WpkgGpoSyncSeverity::Ok, []];
        }

        if (! Schema::hasTable('workstations')) {
            return [
                true,
                [],
                WpkgGpoSyncSeverity::Ok,
                ['Table `workstations` absente — couverture Bearer non évaluable.'],
            ];
        }

        // Postes des OUs liées (suffix-match sur `ad_dn` — pattern iso 16.5 D02).
        $workstations = [];
        $truncated = false;
        foreach ($linkedOus as $dn) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $dn);
            $pattern = '%,' . $escaped;
            try {
                $rows = DB::table('workstations')
                    ->whereNull('archived_at')
                    ->whereRaw('ad_dn LIKE ? ESCAPE ?', [$pattern, '\\'])
                    ->limit(self::WORKSTATIONS_PER_OU_LIMIT)
                    ->pluck('name')
                    ->all();
            } catch (\Throwable) {
                $rows = [];
            }
            // Review fix #F : signaler en warning que la liste retournée par
            // l'OU a atteint le cap → la couverture Bearer affichée n'est
            // pas exhaustive (les postes au-delà du cap ne sont pas évalués).
            if (count($rows) >= self::WORKSTATIONS_PER_OU_LIMIT) {
                $truncated = true;
                GpoLogger::action('gpo.wpkg.bearer.audit')->step(
                    'workstation list truncated',
                    ['ou' => $dn, 'limit' => self::WORKSTATIONS_PER_OU_LIMIT],
                    'warning',
                );
            }
            foreach ($rows as $name) {
                if (is_string($name) && $name !== '') {
                    $workstations[$name] = true;
                }
            }
        }
        $workstations = array_keys($workstations);

        if ($workstations === []) {
            return [true, [], WpkgGpoSyncSeverity::Ok, []];
        }

        // Existence d'un secret actif par workstation_name.
        $coverage = [];
        try {
            $rows = DB::table('workstation_api_secrets')
                ->whereIn('workstation_name', $workstations)
                ->whereNull('revoked_at')
                ->pluck('workstation_name')
                ->all();
            $set = array_flip(array_map('strval', $rows));
        } catch (\Throwable) {
            return [
                true,
                [],
                WpkgGpoSyncSeverity::Warning,
                ['Lecture `workstation_api_secrets` impossible — couverture Bearer non évaluable.'],
            ];
        }

        foreach ($workstations as $name) {
            $coverage[$name] = isset($set[$name]);
        }

        $missing = count(array_filter($coverage, static fn (bool $v): bool => $v === false));
        $total = count($coverage);
        $ratio = $total > 0 ? $missing / $total : 0.0;

        $messages = [];
        $severity = WpkgGpoSyncSeverity::Ok;
        if ($missing > 0) {
            $messages[] = sprintf(
                '%d/%d postes liés sans secret Bearer Phase 2 actif (Story 15.5 `wpkg:provision-secrets`).',
                $missing,
                $total,
            );
            if ($required) {
                $severity = $ratio > 0.10
                    ? WpkgGpoSyncSeverity::Error
                    : WpkgGpoSyncSeverity::Warning;
            }
            // Mode tolérant (bearer_required=false) : message seul, pas de bump severity.
        }
        if ($truncated) {
            $messages[] = sprintf(
                'Liste de postes tronquée (>%d par OU) : la couverture Bearer affichée n\'est pas exhaustive.',
                self::WORKSTATIONS_PER_OU_LIMIT,
            );
        }

        return [true, $coverage, $severity, $messages];
    }

    /**
     * Path du template — overridable via `config('sambaedu.gpo.wpkg_sync.template_path')`
     * (utile pour les tests + mode dev). Validation `realpath()` sous le préfixe
     * autorisé `/usr/share/sambaedu/gpo/` (T6.3).
     */
    private function resolveTemplatePath(): string
    {
        $configured = (string) config('sambaedu.gpo.wpkg_sync.template_path', self::DEFAULT_TEMPLATE_PATH);
        if ($configured === '') {
            return self::DEFAULT_TEMPLATE_PATH;
        }
        // En testing : on autorise le path tel quel pour permettre l'injection
        // d'un .zip fixture sans devoir le placer sous /usr/share/sambaedu/.
        if (app()->environment('testing')) {
            return $configured;
        }
        $real = @realpath($configured);
        if ($real === false) {
            // Path inexistant — on retourne le path demandé (la vérification
            // `is_file` en aval signalera l'absence avec le bon message).
            return $configured;
        }
        if (! str_starts_with($real, self::TEMPLATE_PATH_PREFIX)) {
            throw new RuntimeException(sprintf(
                'Template `%s` résolu en `%s` qui sort du préfixe autorisé `%s` — refus pour défense en profondeur.',
                $configured,
                $real,
                self::TEMPLATE_PATH_PREFIX,
            ));
        }
        return $real;
    }

    /**
     * Construit la map `$config` legacy passée à `specialise_gpo` / `import_gpo`
     * (clés iso `sambaedu/includes/gpo.inc.php:621-630`).
     *
     * @return array<string,mixed>
     */
    private function resolveLegacyConfig(): array
    {
        $out = [];
        foreach (self::SPECIALISE_CONFIG_KEYS as $k) {
            $out[$k] = (string) ($this->config->get($k, '') ?? '');
        }
        return $out;
    }

    /**
     * Invoque le shim `import_gpo($config, 'se4_wpkg', 'se4_wpkg.zip', $update, $force)`.
     *
     * Le legacy retourne **best effort** (DO3) : `void` la plupart du temps,
     * `false` en cas d'échec. On wrap (R2) pour lever `RuntimeException`
     * sur retour falsy explicite + log `critical` mid-failure si exception.
     */
    private function invokeImport(array $legacyConfig, bool $force, string $operationId): void
    {
        $log = GpoLogger::action('gpo.wpkg.publish', operationId: $operationId, context: [
            'gpo_name' => self::GPO_DISPLAY_NAME,
            'force' => $force,
        ]);

        try {
            $result = null;

            if (app()->bound('legacy.import_gpo')) {
                $fn = app('legacy.import_gpo');
                if (! is_callable($fn)) {
                    throw new RuntimeException('Binding `legacy.import_gpo` non callable.');
                }
                $result = $fn($legacyConfig, self::GPO_DISPLAY_NAME, self::TEMPLATE_ARCHIVE_NAME, true, $force);
            } else {
                if (! function_exists('import_gpo')) {
                    $this->loadLegacyGpoIncludes();
                }
                if (! function_exists('import_gpo')) {
                    throw new RuntimeException(
                        'Fonction legacy `import_gpo` indisponible — vérifier `legacy/bootstrap.php`.',
                    );
                }
                // @legacy-port path="sambaedu/includes/gpo.inc.php (import_gpo)"
                $result = call_user_func('import_gpo', $legacyConfig, self::GPO_DISPLAY_NAME, self::TEMPLATE_ARCHIVE_NAME, true, $force);
            }

            // import_gpo retourne `void`/`null` en cas de succès et `false` en
            // cas d'échec explicite (analyse sambaedu/includes/gpo.inc.php:956+).
            if ($result === false) {
                throw new RuntimeException(
                    'Shim legacy `import_gpo` a retourné `false` — vérifier les logs samba-tool/smbclient (KRB5CCNAME, ACLs SYSVOL).',
                );
            }

            $log->success(['outcome' => 'imported']);
        } catch (\Throwable $e) {
            // DO3 / AC2.6 : best effort, pas de rollback automatique. On loggue
            // explicitement le risque d'incohérence SYSVOL pour permettre la
            // récupération manuelle.
            $log->step(
                'État SYSVOL potentiellement incohérent — la spécialisation a pu réussir avant l\'échec import. Vérifier manuellement via samba-tool gpo listall et SYSVOL.',
                ['gpo_name' => self::GPO_DISPLAY_NAME],
                'critical',
            );
            $log->failure($e);
            throw $e;
        }
    }

    /**
     * Charge `sambaedu/includes/gpo.inc.php` à la demande (cas testing où le
     * `legacy/bootstrap.php` skip est actif). Pattern iso `ShortcutsService`.
     *
     * @legacy-port path="sambaedu/includes/gpo.inc.php"
     */
    private function loadLegacyGpoIncludes(): void
    {
        $bootstrap = base_path('legacy/bootstrap.php');
        if (is_file($bootstrap)) {
            require_once $bootstrap;
        }
    }

    private function reportWithMessage(WpkgGpoSyncReport $original, string $extraMessage): WpkgGpoSyncReport
    {
        return new WpkgGpoSyncReport(
            gpoExists: $original->gpoExists,
            gpoGuid: $original->gpoGuid,
            gpoDisplayName: $original->gpoDisplayName,
            gpoPath: $original->gpoPath,
            linkedOus: $original->linkedOus,
            expectedHostsXmlUrl: $original->expectedHostsXmlUrl,
            expectedProfilesXmlUrl: $original->expectedProfilesXmlUrl,
            templatePath: $original->templatePath,
            templateExists: $original->templateExists,
            templateLastModified: $original->templateLastModified,
            detectedPlaceholders: $original->detectedPlaceholders,
            unknownPlaceholders: $original->unknownPlaceholders,
            bearerCoverage: $original->bearerCoverage,
            bearerTableAvailable: $original->bearerTableAvailable,
            severity: $original->severity,
            messages: array_values(array_unique([...$original->messages, $extraMessage])),
            operationId: $original->operationId,
        );
    }
}
