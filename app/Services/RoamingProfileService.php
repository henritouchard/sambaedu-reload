<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
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
}
