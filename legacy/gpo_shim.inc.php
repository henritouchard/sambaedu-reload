<?php

/**
 * Shim GPO — story 1bis.18g.
 *
 * Ce fichier complète le shim LDAP (`legacy/ldap.inc.php`) pour :
 *  1. Fournir un bridge Kerberos (KRB5CCNAME) côté PHP avant tout `exec(smbclient)`.
 *  2. Exposer un wrapper `_shim_gpo_exec` mockable pour les tests host-side.
 *  3. Fournir, si (et seulement si) elles ne sont pas déjà déclarées par les
 *     includes legacy chargés via bootstrap.php (samba-tool.inc.php,
 *     gpo.inc.php), des implémentations de repli des 8 fonctions GPO :
 *     - gpolistcontainers, gpogetlink, gposetlink, gpodellink
 *     - sysvol_put, read_gpo_sysvol, update_gpo_sysvol, sysvol_acl_reset
 *
 * Décision architecturale (cf. story 18g, T3.1) :
 * ----------------------------------------------
 * Les fonctions `gpolistcontainers` / `gpogetlink` / `gposetlink` /
 * `gpodellink` / `sysvol_put` / `read_gpo_sysvol` / `update_gpo_sysvol` /
 * `sysvol_acl_reset` sont DÉJÀ fournies par les includes legacy chargés
 * par `legacy/bootstrap.php` depuis la story 18a :
 *   - `sambaedu/includes/samba-tool.inc.php` (4 wrappers samba-tool GPO)
 *   - `sambaedu/includes/gpo.inc.php` (4 fonctions SYSVOL)
 * Elles utilisent déjà `escapeshellarg()` et `--use-kerberos=required`.
 * Les re-déclarer ici provoquerait une fatal error PHP (PHP interdit la
 * redéclaration de fonctions globales).
 *
 * Conséquence : la story 18g n'ajoute PAS de nouvelles implémentations de ces
 * 8 fonctions — elle se contente de :
 *  - Garantir qu'elles sont accessibles après bootstrap (déjà le cas via 18a).
 *  - Garantir que le bridge Kerberos est actif avant leurs `exec()` (T4.2).
 *  - Ajouter les fallbacks shim (guardés par `function_exists`) en cas
 *    d'absence des includes legacy (ex. environnement de test minimal).
 *
 * Tests host-side : voir `tests/Unit/LegacyGpoShimsTest.php`. Les assertions
 * portent sur :
 *  - les helpers `_shim_gpo_ldap_connect`, `_shim_gpo_search`,
 *    `_shim_gpo_modify_replace` de `legacy/ldap.inc.php` (mock via
 *    `$GLOBALS['__shim_ldap_call_override']`) ;
 *  - les fallbacks shim `_shim_gpolistcontainers`, `_shim_sysvol_put`, etc.
 *    (mock via `$GLOBALS['__shim_gpo_exec_override']`).
 *
 * Ces fallbacks ne sont utilisés en production que si les includes legacy
 * n'ont pas été chargés (fallback défensif). La VM charge toujours les
 * originaux.
 *
 * Audit sécurité exec : voir story 18g Dev Notes, section "Audit sécurité exec".
 */

// Guard : ne charger qu'une seule fois.
if (defined('LEGACY_GPO_SHIM_LOADED')) {
    return;
}
define('LEGACY_GPO_SHIM_LOADED', true);

// ─── Bridge Kerberos ─────────────────────────────────────────────────────────

if (!function_exists('_shim_gpo_ensure_krb5ccname')) {
    /**
     * Positionne `KRB5CCNAME` pour que les `exec(smbclient)` / `exec(samba-tool)`
     * trouvent le ticket Kerberos.
     *
     * Ordre de résolution :
     *   1. Variable d'environnement `KRB5CCNAME` déjà présente → on ne touche rien.
     *   2. Clé `$config['krb5ccname']` → `putenv`.
     *   3. Fallback sur `/tmp/krb5cc_{uid}` où uid = posix_geteuid() (défaut Samba
     *      sur Debian/Ubuntu — le ticket machine est déposé là par
     *      `/usr/share/sambaedu/sbin/renew_ticket.sh`).
     *
     * Note (story 18g T4.3) — Cas "ticket expiré" :
     *   La renouvellement du ticket est DÉLÉGUÉ à `renew_ticket.sh` (cron sur
     *   la VM). Le shim PHP ne tente PAS de relancer `kinit` : si le ticket
     *   est expiré, les `exec(smbclient ...)` remonteront une erreur
     *   (`NT_STATUS_NO_LOGON_SERVERS` ou équivalent) qui sera loggée par
     *   `ErrorLoggerService` via les wrappers samba-tool/gpo.
     */
    function _shim_gpo_ensure_krb5ccname(array $config = []): void
    {
        // 1. Déjà dans l'environnement ?
        $current = getenv('KRB5CCNAME');
        if (is_string($current) && $current !== '') {
            return;
        }
        // 2. Clé explicite dans $config ?
        if (!empty($config['krb5ccname']) && is_string($config['krb5ccname'])) {
            putenv('KRB5CCNAME=' . $config['krb5ccname']);
            return;
        }
        // 3. Fallback par uid effectif.
        if (!function_exists('posix_geteuid')) {
            // #M5 — Log explicite : ext-posix manquante, fallback silencieux
            // vers uid=0 risque de casser Kerberos (le ticket www-data ne sera
            // jamais trouvé dans /tmp/krb5cc_0).
            if (function_exists('_shim_log_unimplemented')) {
                _shim_log_unimplemented("_shim_gpo_ensure_krb5ccname: ext-posix missing, falling back to uid=0 (Kerberos may be broken)");
            }
            $uid = 0;
        } else {
            $uid = posix_geteuid();
        }
        putenv('KRB5CCNAME=/tmp/krb5cc_' . $uid);
    }
}

// ─── Wrapper exec testable ──────────────────────────────────────────────────

if (!function_exists('_shim_gpo_exec')) {
    /**
     * Wrapper autour de `exec()` permettant le mocking host-side.
     *
     * @param string $command     Commande complète (doit contenir déjà
     *                            `escapeshellarg()` sur les args utilisateur).
     * @param array  $output      (référence) Tableau rempli avec les lignes stdout/stderr.
     * @param int    $returnCode  (référence) Code de retour du processus.
     * @return bool  true si le processus a retourné 0.
     */
    function _shim_gpo_exec(string $command, array &$output, int &$returnCode): bool
    {
        $override = $GLOBALS['__shim_gpo_exec_override'] ?? null;
        if (is_callable($override)) {
            $res = $override($command);
            // Contrat de l'override : retourne ['output' => array, 'return' => int].
            $output = $res['output'] ?? [];
            $returnCode = (int) ($res['return'] ?? 0);
            return $returnCode === 0;
        }
        // Production : exec natif. On laisse PHP remplir $output/$returnCode.
        exec($command, $output, $returnCode);
        return $returnCode === 0;
    }
}

if (!function_exists('_shim_gpo_log_exec_failure')) {
    /**
     * Helper interne : log un échec d'exec SYSVOL (#3 + #6).
     *
     * Limite volontairement la sortie à 5 lignes pour éviter les fuites
     * massives de données dans les logs (ex. dump smbclient verbeux).
     *
     * @param string   $fnName  Nom de la fonction appelante (ex: "sysvol_put").
     * @param string[] $output  Lignes stdout/stderr de exec.
     * @param int      $ret     Code de retour.
     */
    function _shim_gpo_log_exec_failure(string $fnName, array $output, int $ret): void
    {
        if (!function_exists('_shim_log_unimplemented')) {
            return;
        }
        $snippet = implode("\n", array_slice($output, 0, 5));
        _shim_log_unimplemented("{$fnName}: exec failed (ret={$ret}): {$snippet}");
    }
}

if (!function_exists('_shim_gpo_safe_tmppath')) {
    /**
     * Helper interne : construit un chemin temporaire SYSVOL safe (anti path
     * traversal). Cf. #M2 — un displayname avec "../" pourrait écrire hors
     * sys_get_temp_dir() si on utilisait $gpo['displayname'] sans sanitize.
     *
     * Ordre de préférence :
     *   1. $gpo['cn']         (GUID, toujours safe — `{XXXXXXXX-...-XXXX}`)
     *   2. $gpo['displayname'] (fallback, sanitize strict)
     *   3. 'gpo'              (ultime fallback)
     *
     * Le nom final ne contient que [a-zA-Z0-9_{}.-] — tout le reste est
     * remplacé par '_'.
     */
    function _shim_gpo_safe_tmppath(array $gpo): string
    {
        $rawName = $gpo['cn'] ?? $gpo['displayname'] ?? 'gpo';
        $safeName = preg_replace('/[^a-zA-Z0-9_{}\.-]/', '_', (string) $rawName);
        if ($safeName === '' || $safeName === null) {
            $safeName = 'gpo';
        }
        return sys_get_temp_dir() . '/sambaedu_sysvol_' . $safeName;
    }
}

// ─── Fallbacks wrappers samba-tool GPO ──────────────────────────────────────
//
// Guardés par `function_exists` : ne seront définis QUE si les includes
// legacy (samba-tool.inc.php, gpo.inc.php) ne sont pas chargés — ce qui est
// le cas en environnement de test minimal. En production VM, les originaux
// prennent le dessus (ils sont chargés en premier par le bootstrap).
// Tous les arguments utilisateur sont passés à `escapeshellarg()` avant
// composition de la commande (cf. audit sécurité story 18g).

if (!function_exists('gpolistcontainers')) {
    /**
     * Fallback shim : liste les DN des containers (OU) liés à une GPO.
     *
     * @return string[]  DNs trouvés (tableau vide si aucune liaison).
     */
    function gpolistcontainers(array $config, string $gpo): array
    {
        _shim_gpo_ensure_krb5ccname($config);
        $kerb = ' --use-kerberos=required';
        $host = function_exists('ad_url') ? ' ' . ad_url($config, 'sambatool') : '';
        $command = '/usr/bin/samba-tool gpo listcontainers ' . escapeshellarg($gpo) . $kerb . $host . ' 2>&1';
        $output = [];
        $ret = 0;
        _shim_gpo_exec($command, $output, $ret);
        $dns = [];
        if ($ret === 0) {
            foreach ($output as $line) {
                if (preg_match('/^\s*DN:\s*(.*)$/i', $line, $m)) {
                    $dns[] = trim($m[1]);
                }
            }
        }
        return $dns;
    }
}

if (!function_exists('gpogetlink')) {
    /**
     * Fallback shim : liste les GPO liées à un container (OU).
     *
     * @return array<int, array{uuid:string,displayname:string,options:string}>
     */
    function gpogetlink(array $config, string $container): array
    {
        _shim_gpo_ensure_krb5ccname($config);
        $kerb = ' --use-kerberos=required';
        $host = function_exists('ad_url') ? ' ' . ad_url($config, 'sambatool') : '';
        $command = '/usr/bin/samba-tool gpo getlink ' . escapeshellarg($container) . $kerb . $host . ' 2>&1';
        $output = [];
        $ret = 0;
        _shim_gpo_exec($command, $output, $ret);
        $gpos = [];
        if ($ret === 0) {
            $key = 0;
            foreach ($output as $line) {
                if (preg_match('/^\s*(.+?)\s*:\s*(.*)$/', $line, $m)) {
                    $label = trim($m[1]);
                    $val = trim($m[2]);
                    if ($label === 'GPO') {
                        $gpos[$key]['uuid'] = $val;
                    } elseif ($label === 'Name') {
                        $gpos[$key]['displayname'] = $val;
                    } elseif ($label === 'Options') {
                        $gpos[$key]['options'] = $val;
                        $key++;
                    }
                }
            }
        }
        return $gpos;
    }
}

if (!function_exists('gposetlink')) {
    /**
     * Fallback shim : lie une GPO à un container.
     */
    function gposetlink(array $config, string $container, string $gpo, bool $enforce = false, bool $disable = false): bool
    {
        _shim_gpo_ensure_krb5ccname($config);
        $kerb = ' --use-kerberos=required';
        $host = function_exists('ad_url') ? ' ' . ad_url($config, 'sambatool') : '';
        $command = '/usr/bin/samba-tool gpo setlink '
            . escapeshellarg($container) . ' ' . escapeshellarg($gpo);
        if ($enforce) {
            $command .= ' --enforce';
        }
        if ($disable) {
            $command .= ' --disable';
        }
        $command .= $kerb . $host . ' 2>&1';
        $output = [];
        $ret = 0;
        return _shim_gpo_exec($command, $output, $ret);
    }
}

if (!function_exists('gpodellink')) {
    /**
     * Fallback shim : supprime la liaison d'une GPO à un container.
     */
    function gpodellink(array $config, string $container, string $gpo): bool
    {
        _shim_gpo_ensure_krb5ccname($config);
        $kerb = ' --use-kerberos=required';
        $host = function_exists('ad_url') ? ' ' . ad_url($config, 'sambatool') : '';
        $command = '/usr/bin/samba-tool gpo dellink '
            . escapeshellarg($container) . ' ' . escapeshellarg($gpo)
            . $kerb . $host . ' 2>&1';
        $output = [];
        $ret = 0;
        _shim_gpo_exec($command, $output, $ret);
        // Legacy : considère 0 ou 255 comme succès (cf. samba-tool.inc.php:1016).
        return ($ret === 0 || $ret === 255);
    }
}

// ─── Fallbacks SYSVOL ────────────────────────────────────────────────────────

if (!function_exists('sysvol_put')) {
    /**
     * Fallback shim : pousse un fichier ou arborescence dans SYSVOL.
     *
     * @param array            $config
     * @param array            $gpo      Entrée GPO (avec clé 'cn').
     * @param string|array     $source   Chemin local OU descripteur ['path','tmppath','file'].
     * @param array|null       $message  (référence) Lignes de sortie smbclient.
     */
    function sysvol_put(array $config, array $gpo, $source, ?array &$message = null): bool
    {
        _shim_gpo_ensure_krb5ccname($config);
        if ($message === null) {
            $message = [];
        }
        $domain = $config['domain'] ?? '';
        $gpoCn = $gpo['cn'] ?? '';
        $host = function_exists('ad_url') ? ad_url($config, 'dns') : $domain;

        if (is_array($source)) {
            $inner = 'cd "' . $domain . '/Policies/' . $gpoCn . ($source['path'] ?? '') . '";'
                . 'lcd "' . ($source['tmppath'] ?? '') . '";'
                . 'prompt OFF;mput "' . ($source['file'] ?? '') . '"';
        } else {
            $inner = 'cd "' . $domain . '/Policies/' . $gpoCn . '";'
                . 'lcd "' . $source . '";'
                . 'prompt OFF;recurse ON;mput *';
        }
        $command = 'smbclient "//' . $host . '/sysvol" --use-kerberos=required -c '
            . escapeshellarg($inner) . ' 2>&1';
        $message[] = $inner;
        $output = [];
        $ret = 0;
        $ok = _shim_gpo_exec($command, $output, $ret);
        foreach ($output as $line) {
            $message[] = $line;
        }
        if (!$ok) {
            // #3 + #6 — Log explicite (ex. NT_STATUS_NO_LOGON_SERVERS = ticket
            // Kerberos expiré, NT_STATUS_ACCESS_DENIED = ACL, etc.).
            _shim_gpo_log_exec_failure('sysvol_put', $output, $ret);
        }
        return $ok;
    }
}

if (!function_exists('read_gpo_sysvol')) {
    /**
     * Fallback shim : lit un fichier SYSVOL relatif à une GPO.
     *
     * @return string|false
     */
    function read_gpo_sysvol(array $config, array $gpo, array $file, bool $export = false)
    {
        _shim_gpo_ensure_krb5ccname($config);
        $domain = $config['domain'] ?? '';
        $host = function_exists('ad_url') ? ad_url($config, 'dns', true) : $domain;
        // #M2 — Safe path construction (anti path traversal via displayname).
        $tmppath = _shim_gpo_safe_tmppath($gpo);
        if (!is_dir($tmppath)) {
            @mkdir($tmppath, 0700, true);
        }
        $tmpFile = $tmppath . '/' . ($file['file'] ?? 'file');
        if (!file_exists($tmpFile)) {
            $inner = 'cd "' . $domain . '/Policies/' . ($gpo['cn'] ?? '') . ($file['path'] ?? '') . '";'
                . 'lcd "' . $tmppath . '";'
                . 'get ' . ($file['file'] ?? '');
            $command = 'smbclient "//' . $host . '/sysvol" --use-kerberos=required -c '
                . escapeshellarg($inner) . ' 2>&1';
            $output = [];
            $ret = 0;
            $ok = _shim_gpo_exec($command, $output, $ret);
            if (!$ok) {
                // #6 — Log smbclient failure (Kerberos expiré, share absent, etc.).
                // On ne return pas false ici : le contrat historique est de
                // retourner le contenu du fichier local s'il existe déjà
                // (cache de lecture). On retombe donc sur le file_exists
                // ci-dessous qui renverra false si rien n'a été téléchargé.
                _shim_gpo_log_exec_failure('read_gpo_sysvol', $output, $ret);
            }
        }
        if (file_exists($tmpFile)) {
            $data = file_get_contents($tmpFile);
            @unlink($tmpFile);
            return $data;
        }
        return false;
    }
}

if (!function_exists('update_gpo_sysvol')) {
    /**
     * Fallback shim : met à jour un fichier dans SYSVOL de manière atomique.
     *
     * Atomicité (story 18g #9i) :
     *   1. Écriture dans un fichier temporaire `{tmppath}/{file}.tmp.{pid}.{rand}`.
     *   2. `rename()` atomique vers le chemin final local.
     *   3. Upload via `sysvol_put` (si $commit=true).
     *   4. Suppression du fichier local.
     *
     * @param array  $config
     * @param array  $gpo       (référence) Entrée GPO — peut être décorée
     *                          avec increment_user/machine après commit.
     * @param array  $file      Descripteur ['file', 'path', 'type', 'target'].
     * @param mixed  $data      Contenu à écrire.
     * @param bool   $commit    Si true, upload immédiat via smbclient.
     */
    function update_gpo_sysvol(array $config, array &$gpo, array $file, $data = null, bool $commit = false): bool
    {
        _shim_gpo_ensure_krb5ccname($config);
        // #M2 — Safe path construction (anti path traversal via displayname).
        $tmppath = _shim_gpo_safe_tmppath($gpo);
        if (!is_dir($tmppath)) {
            @mkdir($tmppath, 0700, true);
        }
        $file['tmppath'] = $tmppath;
        $finalFile = $tmppath . '/' . ($file['file'] ?? 'file');

        if ($data !== null) {
            // Écriture ATOMIQUE : temp + rename (cf. feedback_atomic_write).
            $tempFile = $finalFile . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
            if (is_string($data)) {
                $written = @file_put_contents($tempFile, $data);
            } elseif (is_object($data) && method_exists($data, 'save')) {
                // DOMDocument ou équivalent avec méthode save($path).
                $data->save($tempFile);
                $written = file_exists($tempFile) ? filesize($tempFile) : false;
            } else {
                $written = @file_put_contents($tempFile, (string) $data);
            }
            if ($written === false) {
                return false;
            }
            if (!@rename($tempFile, $finalFile)) {
                @unlink($tempFile);
                return false;
            }
        }

        // #8 — Alignement legacy : si $data=null et aucun fichier local n'a
        // été préparé au préalable, retourner false (pas true silencieux).
        if (!file_exists($finalFile)) {
            return false;
        }

        if ($commit) {
            $message = [];
            $ok = sysvol_put($config, $gpo, $file, $message);
            if ($ok) {
                $target = $file['target'] ?? '';
                if ($target === 'user') {
                    $gpo['increment_user'] = true;
                } elseif ($target === 'machine') {
                    $gpo['increment_machine'] = true;
                }
                @unlink($finalFile);
                return true;
            }
            // #6 — Échec sysvol_put (logging fait dans sysvol_put lui-même,
            // mais on log aussi ici le contexte update_gpo_sysvol pour
            // traçabilité dans les logs agrégés).
            _shim_gpo_log_exec_failure('update_gpo_sysvol', $message, 1);
            return false;
        }

        return true;
    }
}

if (!function_exists('sysvol_acl_reset')) {
    /**
     * Fallback shim : réapplique les ACLs SYSVOL par défaut via smbcacls.
     *
     * Note : le legacy `sambaedu/includes/gpo.inc.php:1241` retourne `true`
     * inconditionnellement (fonction notée "obsolete, ne pas utiliser").
     * Le fallback shim fait un vrai appel `smbcacls`, mais ne casse pas le
     * flux si ça échoue (log + return false).
     */
    function sysvol_acl_reset(array $config, string $path, ?array &$message = null): bool
    {
        _shim_gpo_ensure_krb5ccname($config);
        if ($message === null) {
            $message = [];
        }
        $domain = $config['domain'] ?? '';
        $host = function_exists('ad_url') ? ad_url($config, 'dns', true) : $domain;
        $sddl = defined('GPO_SDDL') ? GPO_SDDL : '';
        // `$path` est issu d'un flux interne (jamais directement d'un POST
        // utilisateur — voir audit sécurité story 18g). On l'escape quand
        // même par précaution via une réécriture de l'URI SMB.
        $uri = '//' . $host . '/sysvol/' . $domain . '/Policies/' . $path;
        $command = 'smbcacls ' . escapeshellarg($uri)
            . ' --use-kerberos=required --sddl --set=' . escapeshellarg($sddl)
            . ' 2>&1';
        $output = [];
        $ret = 0;
        $ok = _shim_gpo_exec($command, $output, $ret);
        foreach ($output as $line) {
            $message[] = $line;
        }
        if (!$ok) {
            // #3 + #6 — Log explicite si smbcacls échoue.
            _shim_gpo_log_exec_failure('sysvol_acl_reset', $output, $ret);
        }
        return $ok;
    }
}
