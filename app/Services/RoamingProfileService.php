<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Service de gestion des profils itinérants (story 1bis.18f).
 *
 * Décalqué sur `App\Services\GpoSyncService` : appelle les fonctions legacy
 * (read_gpo_sysvol, update_gpo_sysvol, increment_gpo_sysvol, get_pol_key,
 * change_pol_key, write_gpo_json, search_ad) via `function_exists()` après
 * chargement idempotent de `legacy/bootstrap.php`.
 *
 * Bridge SYSVOL : la GPO `redirections` (User Configuration / Registry.pol)
 * stocke la clé `ExcludeProfileDirs` lue par winlogon.exe au login Windows.
 * On ne peut pas remplacer la persistance SYSVOL par Eloquent sans casser
 * le contrat GPO — d'où ce service qui orchestre les wrappers 18g.
 *
 * Stats `/tmp/du.txt` : réimplémentées en pur PHP (decoupling complet de
 * `partages.inc.php:roaming_profiles_stats`).
 *
 * Sécurité path-traversal : double validation (à l'écriture setExclusions
 * ET à la génération generatePurgeScript) via regex `^[\w\-./ ]+$`.
 */
class RoamingProfileService
{
    /**
     * Regex stricte de validation des entrées d'exclusion (anti path-traversal
     * + anti injection bash). Autorise [A-Za-z0-9_], `-`, `.`, `/`, ` `.
     * Rejette `;`, `$()`, backtick, `|`, `&`, `<`, `>`, `'`, `"`, `\`, etc.
     *
     * Note : la regex autorise `.` (utile pour des extensions ex `.cache`) ;
     * la séquence `..` (path traversal) est rejetée explicitement par
     * `isValueSafe()` qui combine cette regex et un veto sur `..`.
     */
    public const VALUE_REGEX = '/^[\w\-.\/ ]+$/';

    /**
     * Combine la regex `VALUE_REGEX` ET un veto explicite sur la séquence `..`
     * (que la regex laisse passer car elle autorise `.`).
     *
     * Defense-in-depth : appelée à la fois par `setExclusions()` (refus à
     * l'écriture) ET `generatePurgeScript()` (refus à la génération bash).
     */
    public static function isValueSafe(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (str_contains($value, '..')) {
            return false;
        }
        return (bool) preg_match(self::VALUE_REGEX, $value);
    }

    /**
     * Garde dédiée aux NOMS DE DOSSIER de premier niveau (`/home/profiles/<x>`).
     *
     * Contrairement à `VALUE_REGEX` (conçue pour des chemins relatifs
     * d'exclusion GPO, donc `/` autorisé), un nom de dossier de profil ne doit
     * JAMAIS contenir de séparateur. Regex stricte sans `/`, veto `..`/`.`,
     * pour ne pas s'appuyer sur le seul `str_contains` en aval (modèle de
     * sécurité explicite — cf. review 26.3 #6).
     */
    public static function isSafeProfileDirName(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }
        if (str_contains($name, '..')) {
            return false;
        }
        return (bool) preg_match('/^[\w\-. ]+$/', $name);
    }

    /**
     * Charge le bootstrap legacy de manière idempotente.
     *
     * `legacy/bootstrap.php` est lui-même guardé par `defined('LEGACY_BOOTSTRAP_LOADED')`
     * — double appel sans effet.
     */
    private function ensureBootstrap(): void
    {
        require_once base_path('legacy/bootstrap.php');
    }

    /**
     * Vérifie qu'une fonction legacy est disponible après bootstrap, sinon log
     * critique et lève une exception (environnement dégradé).
     */
    private function requireFunction(string $name): void
    {
        if (!function_exists($name)) {
            Log::critical('[RoamingProfileService] Fonction legacy manquante après bootstrap', [
                'function' => $name,
            ]);
            throw new RuntimeException("Fonction legacy `{$name}` indisponible après bootstrap.");
        }
    }

    /**
     * Récupère la liste plate des `ExcludeProfileDirs` lus depuis la GPO
     * `redirections` (User Configuration / Registry.pol).
     *
     * Comportement graceful :
     *  - GPO introuvable → retour `[]` + log warning.
     *  - Politique illisible → retour `[]` + log warning.
     *  - Exception sur appel legacy → retour `[]` + log error.
     *
     * @return array<int, string>
     */
    public function getExclusions(): array
    {
        try {
            $this->ensureBootstrap();

            $this->requireFunction('search_ad');
            $this->requireFunction('read_gpo_sysvol');
            $this->requireFunction('get_pol_key');
            $this->requireFunction('get_config');

            if (!defined('USER_GPO')) {
                Log::warning('[RoamingProfileService] Constante USER_GPO non définie après bootstrap');
                return [];
            }

            $config = get_config();
            $gpos = search_ad($config, 'redirections', 'gpo');
            $gpo = (is_array($gpos) && isset($gpos[0]) && is_array($gpos[0])) ? $gpos[0] : null;

            if ($gpo === null) {
                Log::warning('[RoamingProfileService] GPO redirections introuvable', [
                    'op' => 'getExclusions',
                ]);
                return [];
            }

            $policy = read_gpo_sysvol($config, $gpo, USER_GPO);
            if (!is_array($policy)) {
                Log::warning('[RoamingProfileService] Politique illisible (read_gpo_sysvol n\'a pas retourné un tableau)', [
                    'op' => 'getExclusions',
                ]);
                return [];
            }

            $values = get_pol_key($policy, 'ExcludeProfileDirs');
            if (!is_array($values)) {
                return [];
            }

            // Cohérence avec setExclusions : on filtre aussi à la lecture
            // les valeurs héritées non-conformes (ex. backslash Windows non
            // normalisé, caractères spéciaux). Évite la divergence affichage
            // /persistance lors d'un `applyToGpo` qui re-persiste toute la
            // liste — sinon `setExclusions` les retirerait silencieusement.
            $clean = [];
            foreach ($values as $v) {
                if (!is_string($v) || $v === '') {
                    continue;
                }
                if (!self::isValueSafe($v)) {
                    Log::warning('[RoamingProfileService] Valeur d\'exclusion héritée filtrée à la lecture (regex anti path-traversal)', [
                        'op' => 'getExclusions',
                        'value' => $v,
                    ]);
                    continue;
                }
                $clean[] = $v;
            }
            return array_values($clean);
        } catch (\Throwable $e) {
            Log::error('[RoamingProfileService] Erreur getExclusions', [
                'op' => 'getExclusions',
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Persiste la liste des exclusions sur la GPO `redirections`.
     *
     * Sécurité :
     *  - Chaque valeur est validée via `self::VALUE_REGEX`. Les valeurs
     *    invalides sont skippées (pas de fatal) avec log warning ; un
     *    appelant strict (UI Livewire) pré-valide aussi en amont pour
     *    afficher un toast user-friendly.
     *
     * @param  array<int, string>  $values  Valeurs candidates (admin POST).
     * @param  bool  $applyVersionBump  Si true, incrémente la GPO et écrit le JSON applicatif.
     */
    public function setExclusions(array $values, bool $applyVersionBump = false): void
    {
        try {
            $this->ensureBootstrap();

            $this->requireFunction('search_ad');
            $this->requireFunction('read_gpo_sysvol');
            $this->requireFunction('change_pol_key');
            $this->requireFunction('update_gpo_sysvol');
            $this->requireFunction('get_config');

            if (!defined('USER_GPO')) {
                throw new RuntimeException('Constante USER_GPO non définie après bootstrap legacy.');
            }

            // Filtrage sécurité : refuse silencieusement les valeurs malformées.
            $clean = [];
            foreach ($values as $v) {
                if (!is_string($v) || $v === '') {
                    continue;
                }
                if (!self::isValueSafe($v)) {
                    Log::warning('[RoamingProfileService] Valeur d\'exclusion ignorée (regex anti path-traversal)', [
                        'op' => 'setExclusions',
                        'value' => $v,
                    ]);
                    continue;
                }
                $clean[] = $v;
            }

            // Garde anti-effacement total : si l'appelant a fourni des valeurs
            // mais qu'aucune n'est valide, on refuse plutôt que d'écrire une
            // politique vide (qui, combinée à applyVersionBump, écraserait la
            // GPO en l'incrémentant). Cas edge improbable mais explicite.
            if ($values !== [] && $clean === []) {
                Log::warning('[RoamingProfileService] Refus écriture GPO : toutes les valeurs ont été filtrées', [
                    'op' => 'setExclusions',
                    'input_count' => count($values),
                ]);
                throw new RuntimeException('Aucune valeur d\'exclusion valide à persister.');
            }

            $config = get_config();
            $gpos = search_ad($config, 'redirections', 'gpo');
            $gpo = (is_array($gpos) && isset($gpos[0]) && is_array($gpos[0])) ? $gpos[0] : null;
            if ($gpo === null) {
                Log::warning('[RoamingProfileService] GPO redirections introuvable', [
                    'op' => 'setExclusions',
                ]);
                throw new RuntimeException('GPO redirections introuvable.');
            }

            $policy = read_gpo_sysvol($config, $gpo, USER_GPO);
            if (!is_array($policy)) {
                throw new RuntimeException('Lecture de la politique GPO impossible.');
            }

            $data = change_pol_key($policy, 'ExcludeProfileDirs', $clean);
            update_gpo_sysvol($config, $gpo, USER_GPO, $policy);

            if ($applyVersionBump) {
                if (function_exists('write_gpo_json')) {
                    write_gpo_json($gpo, USER_GPO, 'ExcludeProfileDirs', $data);
                }
                if (function_exists('increment_gpo_sysvol')) {
                    increment_gpo_sysvol($config, $gpo, USER_GPO);
                }

                // Story 16.14 Q2 — invalider le cache santé GPO après bump version.
                // Le `$gpo` legacy ne nous donne pas un GUID au format Microsoft
                // exploitable directement → flush global (acceptable car action
                // admin rare). Best-effort silencieux.
                try {
                    app(\App\Gpo\Support\CachedGpoLookups::class)->forgetAll();
                } catch (\Throwable) {
                    // pas d'impact métier.
                }
            }
        } catch (\Throwable $e) {
            Log::error('[RoamingProfileService] Erreur setExclusions', [
                'op' => 'setExclusions',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Statistiques globales des profils itinérants — parsing natif de
     * `/tmp/du.txt` (cron exploitation hors scope SER, héritage legacy
     * `clean_profiles.sh` qui produit `du --max-depth=1 -b -t <size> /home/profiles/*`
     * — option `-b` ⇒ valeurs en **bytes**).
     *
     * Format de retour (compatible avec l'usage UI legacy `table_roam_stats`) :
     *   [path => ['sum' => Mo, 'average' => Mo, 'nb' => int, 'user' => [user => Mo]]]
     * Trié par taille moyenne décroissante.
     *
     * Retourne `[]` si `/tmp/du.txt` est absent (CI / env dégradé).
     *
     * @return array<string, array{sum:int|float, average:float, nb:int, user:array<string, float>}>
     */
    public function getProfileStatsGlobal(): array
    {
        return $this->parseDuStats();
    }

    /**
     * Drill-down par chemin : retourne la liste des utilisateurs et tailles
     * pour un sous-arbre donné (ex: "AppData/Local").
     *
     * @return array<int, array{user:string, size_mb:float}>
     */
    public function getProfileStatsForPath(string $path): array
    {
        $stats = $this->parseDuStats();
        if (!isset($stats[$path]) || !isset($stats[$path]['user'])) {
            return [];
        }

        $rows = [];
        foreach ($stats[$path]['user'] as $user => $sizeMb) {
            $rows[] = ['user' => (string) $user, 'size_mb' => (float) $sizeMb];
        }
        // Tri décroissant par taille.
        usort($rows, fn ($a, $b) => $b['size_mb'] <=> $a['size_mb']);
        return $rows;
    }

    /**
     * Génère le script bash de purge des dossiers du profil itinérant.
     *
     * Format byte-fidèle au legacy `sambaedu/gpo/del_roam.php:18-26` :
     *   - 1ʳᵉ ligne : `# suppression des dossiers trop gros\n`
     *   - lignes dynamiques : `rm -fr "/home/profiles/${username}/<value>" 2>/dev/null\n`
     *     (avec ${username} interpolation **shell**, value-sanitized)
     *   - dernière ligne : `rm -fr "/home/profiles/${username}/AppData/Roaming/Mozilla/Firefox/Profiles" 2>/dev/null\n`
     *
     * Sécurité : chaque valeur est validée par `self::VALUE_REGEX`. Les
     * valeurs malformées (héritées d'une ancienne GPO ou injection) sont
     * **skippées** avec log warning (defense-in-depth — la regex est aussi
     * appliquée à l'écriture par setExclusions).
     */
    public function generatePurgeScript(): string
    {
        $values = $this->getExclusions();

        $script = "# suppression des dossiers trop gros\n";

        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            // Convertit les `\` Windows (saisis dans la GPO côté admin Windows)
            // en `/` Unix avant validation. Cohérent legacy del_roam.php:21.
            $normalized = str_replace('\\', '/', $value);

            if (!self::isValueSafe($normalized)) {
                Log::warning('[RoamingProfileService] Valeur d\'exclusion ignorée à la génération (regex)', [
                    'op' => 'generatePurgeScript',
                    'value' => $value,
                ]);
                continue;
            }

            $script .= 'rm -fr "/home/profiles/${username}/' . $normalized . '" 2>/dev/null' . "\n";
        }

        $script .= 'rm -fr "/home/profiles/${username}/AppData/Roaming/Mozilla/Firefox/Profiles" 2>/dev/null' . "\n";

        return $script;
    }

    /**
     * Parsing natif `/tmp/du.txt`. Réimplémente `partages.inc.php:653`
     * (decoupling complet — pas d'appel à `roaming_profiles_stats()` legacy).
     *
     * Format attendu de `/tmp/du.txt` (issu de `du -b /home/profiles/*` cron) :
     *   <bytes>\t<user>/<path>
     * Conversion bytes → Mo : `/ 1024 / 1024` (cohérent legacy
     * `partages.inc.php:664-668`).
     *
     * @return array<string, array{sum:int|float, average:float, nb:int, user:array<string, float>}>
     */
    private function parseDuStats(): array
    {
        $file = '/tmp/du.txt';
        if (!file_exists($file)) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $res = [];
        foreach ($lines as $line) {
            $val = explode("\t", $line);
            if (count($val) < 2) {
                continue;
            }
            $kbytes = (int) $val[0];
            $dir = explode('/', $val[1], 2);
            if (count($dir) < 2) {
                continue;
            }
            $user = $dir[0];
            $path = $dir[1];

            if (!isset($res[$path])) {
                $res[$path] = ['sum' => 0, 'nb' => 0, 'user' => []];
            }
            $res[$path]['sum'] += $kbytes;
            $res[$path]['user'][$user] = round($kbytes / 1024 / 1024, 1);
            $res[$path]['nb']++;
        }

        foreach ($res as $path => $val) {
            $res[$path]['average'] = $val['nb'] > 0
                ? round($val['sum'] / $val['nb'] / 1024 / 1024, 1)
                : 0.0;
            $res[$path]['sum'] = (int) round($val['sum'] / 1024 / 1024, 0);
        }

        // Tri par average décroissant (cohérent legacy array_multisort).
        uasort($res, fn ($a, $b) => $b['average'] <=> $a['average']);

        return $res;
    }

    // =========================================================================
    // Story 26.3 — Scan natif /home/profiles + détection orphelins + cache + purge
    // =========================================================================

    /**
     * Racine du store des profils itinérants. Toute suppression DOIT rester
     * confinée sous ce préfixe (vérifié par realpath dans `purgeOrphanProfiles`).
     */
    public const PROFILES_ROOT = '/home/profiles';

    /**
     * Dossier corbeille où les profils orphelins sont DÉPLACÉS (réversible)
     * plutôt que supprimés — décalque le legacy `do=3&mode_clean=mv`
     * (`/home/admin/_Trash_users`). Règle projet : `trash`/déplacement plutôt
     * que `rm -rf` quand c'est possible.
     */
    public const TRASH_ROOT = '/home/admin/_Trash_users';

    /**
     * Clé SystemSetting où la liste des profils orphelins (dossiers sans compte
     * user) est persistée par le job nocturne. Les orphelins n'ont pas de ligne
     * `users` → ils ne peuvent pas être badgés dans le tableau ni stockés dans
     * la colonne `profile_snapshot` ; d'où ce stockage global séparé.
     */
    public const ORPHANS_SETTING_KEY = 'profiles.orphans';

    /**
     * Seuil « profil volumineux » (en Mo) au-delà duquel le tableau /app/users
     * affiche une pastille d'alerte. Choix : 200 Mo — nettement au-dessus du
     * seuil d'affichage 8 Mo de l'onglet admin (qui sert au drill-down par
     * sous-dossier), car ici on veut repérer les comptes franchement
     * consommateurs à l'échelle du profil complet.
     */
    public const LARGE_PROFILE_THRESHOLD_MB = 200.0;

    /**
     * Scan FS premier niveau de `/home/profiles` via `du --max-depth=1 -b`.
     *
     * COÛTEUX — destiné EXCLUSIVEMENT au job nocturne `profiles:snapshot`.
     * JAMAIS appelé au render Livewire (contrainte perf, invariant de
     * conception). L'UI lit le cache (`getProfileSizeForLogin`, `getOrphans*`).
     *
     * Réutilise `Process::run` (fakeable en test, cohérent QuotaSnapshotCommand).
     * Retourne une map `[dirName => sizeBytes]` (le dossier racine lui-même est
     * filtré). `null` si le scan échoue (du absent, /home/profiles absent…) —
     * le job conserve alors le snapshot précédent (fail-soft).
     *
     * @return array<string, int>|null
     */
    public function scanProfileSizes(): ?array
    {
        $root = self::PROFILES_ROOT;

        if (!is_dir($root)) {
            Log::error('[RoamingProfileService] Racine profils absente — scan ignoré', [
                'op' => 'scanProfileSizes',
                'root' => $root,
            ]);
            return null;
        }

        $safeRoot = escapeshellarg($root);

        try {
            $result = Process::run("du --max-depth=1 -b {$safeRoot} 2>&1");
        } catch (\Throwable $e) {
            Log::error('[RoamingProfileService] Echec du (exception)', [
                'op' => 'scanProfileSizes',
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // `du` sort en code ≠ 0 dès qu'UN sous-dossier est illisible (ACL, profil
        // en cours d'écriture pendant le scan…) tout en imprimant des tailles
        // VALIDES pour tous les dossiers accessibles. Sur un /home/profiles réel
        // (centaines de profils, ACL hétérogènes), exiger un code 0 ferait
        // échouer le snapshot en permanence. On parse donc la sortie disponible
        // (les lignes d'erreur `du: …` n'ont pas de tabulation → ignorées par
        // parseDuOutput) ; on ne renonce QUE si rien n'est exploitable.
        $sizes = $this->parseDuOutput((string) $result->output(), $root);

        if (!$result->successful()) {
            // Succès partiel toléré si au moins une entrée a été parsée ;
            // échec total (sortie vide / illisible) → fail-soft (snapshot conservé).
            $level = $sizes === [] ? 'error' : 'warning';
            Log::log($level, '[RoamingProfileService] du en code non-zéro', [
                'op' => 'scanProfileSizes',
                'code' => $result->exitCode(),
                'parsed_dirs' => count($sizes),
                'output' => $result->output(),
            ]);
            if ($sizes === []) {
                return null;
            }
        }

        return $sizes;
    }

    /**
     * Parse la sortie brute de `du --max-depth=1 -b <root>` en map
     * `[dirName => bytes]`. La ligne du dossier racine lui-même est filtrée.
     *
     * Extrait pour testabilité (le scan FS dépend de `is_dir`, absent en CI).
     *
     * @return array<string, int>
     */
    public function parseDuOutput(string $output, string $root): array
    {
        $sizes = [];
        $rootNormalized = rtrim($root, '/');

        foreach (preg_split("/\r?\n/", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Format `du -b` : `<bytes>\t<path>`.
            $parts = preg_split("/\t/", $line, 2);
            if ($parts === false || count($parts) < 2) {
                continue;
            }

            $bytes = (int) $parts[0];
            $path = rtrim($parts[1], '/');

            // Ligne du dossier racine lui-même → ignorée (on ne garde que les
            // sous-dossiers de premier niveau).
            if ($path === $rootNormalized) {
                continue;
            }

            $dir = basename($path);
            if ($dir === '' || $dir === '.' || $dir === '..') {
                continue;
            }

            $sizes[$dir] = $bytes;
        }

        return $sizes;
    }

    /**
     * Extrait le login d'un nom de dossier de profil itinérant.
     *
     * Les profils Windows itinérants sont versionnés : `alice.V6`, `bob.V2`…
     * (cf. legacy `clean_profiles` regex `/$user(\.V[0-9]).?/`). On retire le
     * suffixe `.V<N>` éventuel pour retrouver le login. Un dossier sans suffixe
     * est interprété tel quel.
     */
    public function loginFromProfileDir(string $dir): string
    {
        return (string) preg_replace('/\.V\d+.*$/', '', $dir);
    }

    /**
     * Détecte les profils ORPHELINS : dossiers de `/home/profiles` dont le
     * login extrait ne correspond à AUCUN compte `User` (résolu `LOWER(login)`,
     * NFR7 Postgres-only — JAMAIS l'AD).
     *
     * @param  array<int, string>  $dirs  Noms de dossiers (premier niveau).
     * @return array<int, string>  Sous-ensemble de $dirs sans compte user.
     */
    public function detectOrphans(array $dirs): array
    {
        if ($dirs === []) {
            return [];
        }

        // Une seule requête : on récupère tous les logins existants (lower).
        $logins = User::query()
            ->whereNotNull('login')
            ->pluck('login')
            ->map(fn ($l) => strtolower((string) $l))
            ->filter(fn ($l) => $l !== '')
            ->flip();

        $orphans = [];
        foreach ($dirs as $dir) {
            $login = strtolower($this->loginFromProfileDir($dir));
            if ($login === '') {
                continue;
            }
            if (!$logins->has($login)) {
                $orphans[] = $dir;
            }
        }

        return array_values($orphans);
    }

    // ------------------------------------------------------------------ Lecteurs cache

    /**
     * Taille (Mo) du profil itinérant d'un login, lue UNIQUEMENT depuis le
     * cache persistant (`users.profile_snapshot`). Aucun FS. `null` si pas de
     * snapshot pour ce login.
     */
    public function getProfileSizeForLogin(string $login): ?float
    {
        $user = User::findByLogin($login);
        if ($user === null) {
            return null;
        }

        $snap = $user->profile_snapshot;
        if (!is_array($snap) || !isset($snap['size_mb'])) {
            return null;
        }

        return (float) $snap['size_mb'];
    }

    /**
     * Liste des profils orphelins persistée par le dernier snapshot, lue depuis
     * `SystemSetting` (aucun FS). Le cache peut dater de la veille — la purge
     * RE-VÉRIFIE l'absence de compte avant toute suppression.
     *
     * @return array<int, string>
     */
    public function getOrphanProfiles(): array
    {
        $raw = SystemSetting::get(self::ORPHANS_SETTING_KEY, []);
        $list = is_array($raw) ? ($raw['dirs'] ?? $raw) : [];

        if (!is_array($list)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($v) => is_string($v) ? $v : null, $list),
            fn ($v) => $v !== null && $v !== ''
        ));
    }

    /**
     * Nombre de profils orphelins (lecture cache, aucun FS).
     */
    public function getOrphanCount(): int
    {
        return count($this->getOrphanProfiles());
    }

    // ------------------------------------------------------------------ Écriture snapshot

    /**
     * Persiste le résultat d'un scan nocturne (appelé par `profiles:snapshot`).
     *
     * - Tailles par-login → colonne `users.profile_snapshot` (badge tableau).
     * - Liste des orphelins → `SystemSetting` clé `profiles.orphans`.
     *
     * Un dossier dont le login matche plusieurs versions (ex. `alice.V6` ET
     * `alice.V2`) cumule les tailles sur le login. Le snapshot n'efface PAS les
     * users absents du scan : on ne touche que les logins présents (cohérent
     * fail-soft quota:snapshot).
     *
     * @param  array<string, int>  $sizesByDir  [dirName => bytes] (sortie scanProfileSizes)
     * @return array{users_updated:int, orphans:int}
     */
    public function persistSnapshot(array $sizesByDir): array
    {
        $capturedAt = Carbon::now()->toIso8601String();

        // 1) Agrège bytes par login (cumul multi-versions) + garde le dernier dir vu.
        $byLogin = [];
        foreach ($sizesByDir as $dir => $bytes) {
            $login = strtolower($this->loginFromProfileDir((string) $dir));
            if ($login === '') {
                continue;
            }
            if (!isset($byLogin[$login])) {
                $byLogin[$login] = ['bytes' => 0, 'dir' => (string) $dir];
            }
            $byLogin[$login]['bytes'] += (int) $bytes;
            $byLogin[$login]['dir'] = (string) $dir;
        }

        $usersUpdated = 0;
        foreach ($byLogin as $login => $agg) {
            $user = User::findByLogin($login);
            if ($user === null) {
                continue; // orphelin — traité plus bas.
            }

            $bytes = (int) $agg['bytes'];
            $user->forceFill([
                'profile_snapshot' => [
                    'size_bytes' => $bytes,
                    'size_mb' => round($bytes / 1024 / 1024, 1),
                    'dir' => $agg['dir'],
                    'captured_at' => $capturedAt,
                ],
            ])->save();
            $usersUpdated++;
        }

        // 2) Détecte + persiste les orphelins (dossiers sans compte user).
        $orphans = $this->detectOrphans(array_keys($sizesByDir));
        SystemSetting::set(self::ORPHANS_SETTING_KEY, [
            'dirs' => $orphans,
            'captured_at' => $capturedAt,
        ]);

        return [
            'users_updated' => $usersUpdated,
            'orphans' => count($orphans),
        ];
    }

    // ------------------------------------------------------------------ Purge sécurisée

    /**
     * Purge native des profils orphelins (réimplémentation de `clean_profiles('*')`
     * / `ldap_cleaner.php?do=3`). NE route JAMAIS vers le legacy.
     *
     * Sécurité (anti-désastre `rm -rf`) :
     *   - chaque candidat est RE-VÉRIFIÉ orphelin au moment de l'action (le
     *     cache peut dater — un compte recréé entre-temps NE doit PAS perdre
     *     son profil) ;
     *   - nom validé par `isValueSafe` (anti path-traversal, veto `..`) ;
     *   - `realpath` résolu et vérifié sous `PROFILES_ROOT` (anti-symlink) ;
     *   - jamais de glob ; on n'agit que sur les dossiers explicitement listés.
     *
     * Mode par défaut : DÉPLACEMENT vers `_Trash_users` (réversible). Si le
     * déplacement échoue, l'entrée est comptée en erreur (jamais de fallback
     * `rm -rf` silencieux).
     *
     * @return array{moved:int, skipped:int, errors:int}
     */
    public function purgeOrphanProfiles(): array
    {
        $candidates = $this->getOrphanProfiles();

        $moved = 0;
        $skipped = 0;
        $errors = 0;

        if ($candidates === []) {
            return ['moved' => 0, 'skipped' => 0, 'errors' => 0];
        }

        // Re-vérification fraîche contre la BDD (le cache peut dater).
        $stillOrphans = array_flip($this->detectOrphans($candidates));

        // S'assure que la corbeille existe (best-effort).
        if (!is_dir(self::TRASH_ROOT)) {
            @mkdir(self::TRASH_ROOT, 0750, true);
        }

        foreach ($candidates as $dir) {
            // Garde 1 — nom de dossier safe (regex stricte sans `/`, veto `..`).
            if (!self::isSafeProfileDirName($dir)) {
                Log::warning('[RoamingProfileService] Purge : nom de dossier rejeté (unsafe)', [
                    'op' => 'purgeOrphanProfiles',
                    'dir' => $dir,
                ]);
                $skipped++;
                continue;
            }

            // Garde 2 — re-vérification orphelin (compte recréé entre-temps ?).
            if (!isset($stillOrphans[$dir])) {
                Log::info('[RoamingProfileService] Purge : dossier ignoré (compte réapparu)', [
                    'op' => 'purgeOrphanProfiles',
                    'dir' => $dir,
                ]);
                $skipped++;
                continue;
            }

            $target = self::PROFILES_ROOT . '/' . $dir;

            // Garde 3 — realpath confiné sous PROFILES_ROOT (anti-symlink/traversal).
            $real = realpath($target);
            $rootReal = realpath(self::PROFILES_ROOT) ?: self::PROFILES_ROOT;
            if ($real === false || !str_starts_with($real, rtrim($rootReal, '/') . '/')) {
                Log::warning('[RoamingProfileService] Purge : chemin hors PROFILES_ROOT — refusé', [
                    'op' => 'purgeOrphanProfiles',
                    'dir' => $dir,
                ]);
                $skipped++;
                continue;
            }

            // Déplacement réversible vers _Trash_users (suffixe horodaté pour
            // éviter d'écraser une purge précédente du même login ; on garantit
            // l'unicité au cas où deux purges du même dossier tomberaient dans
            // la même seconde).
            $stamp = date('YmdHis');
            $dest = self::TRASH_ROOT . '/' . $dir . '.' . $stamp;
            $suffix = 0;
            while (file_exists($dest)) {
                $dest = self::TRASH_ROOT . '/' . $dir . '.' . $stamp . '-' . (++$suffix);
            }
            $ok = $this->moveToTrash($real, $dest);

            if ($ok === false) {
                Log::error('[RoamingProfileService] Purge : déplacement échoué', [
                    'op' => 'purgeOrphanProfiles',
                    'dir' => $dir,
                ]);
                $errors++;
                continue;
            }

            Log::info('[RoamingProfileService] Purge : profil orphelin déplacé en corbeille', [
                'op' => 'purgeOrphanProfiles',
                'dir' => $dir,
            ]);
            $moved++;
        }

        // Met à jour le cache orphelins : ne conserve que les candidats
        // toujours présents sur disque (les déplacés ont disparu) ET toujours
        // sans compte (re-vérifié).
        $remaining = array_values(array_filter(
            $this->detectOrphans($candidates),
            fn ($d) => is_dir(self::PROFILES_ROOT . '/' . $d)
        ));
        SystemSetting::set(self::ORPHANS_SETTING_KEY, [
            'dirs' => $remaining,
            'captured_at' => Carbon::now()->toIso8601String(),
        ]);

        return ['moved' => $moved, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Déplace un dossier de profil vers la corbeille, de façon robuste au
     * partitionnement disque.
     *
     * `rename(2)` échoue avec `EXDEV` si la source (`/home/profiles`) et la
     * destination (`_Trash_users`) sont sur des FILESYSTEMS distincts — config
     * courante en prod où `/home/profiles` est un volume dédié. On bascule alors
     * sur `mv` (copie + suppression, gère le cross-device). Ce repli n'intervient
     * QUE dans le chemin d'action admin de la purge (jamais au render) : le
     * shellout n'enfreint pas l'invariant perf.
     *
     * Les deux chemins sont déjà confinés/validés en amont (realpath sous
     * `PROFILES_ROOT`, nom validé `isSafeProfileDirName`, destination sous
     * `TRASH_ROOT`) ; `escapeshellarg` en défense supplémentaire.
     *
     * @return bool  true si le dossier a bien quitté son emplacement d'origine.
     */
    protected function moveToTrash(string $source, string $dest): bool
    {
        if (@rename($source, $dest)) {
            return true;
        }

        // Repli cross-device (EXDEV) : `mv` gère copie+suppression entre FS.
        try {
            $mv = Process::run('mv -f ' . escapeshellarg($source) . ' ' . escapeshellarg($dest));
        } catch (\Throwable $e) {
            Log::error('[RoamingProfileService] Purge : repli mv (exception)', [
                'op' => 'moveToTrash',
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        // Succès confirmé seulement si la source a réellement disparu.
        return $mv->successful() && !is_dir($source);
    }
}
