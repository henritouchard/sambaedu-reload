<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Story 5.2 — Service bas niveau d'encapsulation des appels `setfacl` / `getfacl`.
 *
 * Décalque fidèle des fonctions du legacy `sambaedu/includes/partages.inc.php` :
 *  - `set_acls()`        (l. 27-43)  → {@see setAcls()}
 *  - `add_acl()`         (l. 128-142) → {@see addAcl()}
 *  - `remove_acl()`      (l. 72-85)   → {@see removeAcl()}
 *  - `check_acls()`      (l. 14-25)   → {@see checkAcls()}
 *  - `get_facl()`        (l. 87-118)  → {@see getFacl()}
 *
 * Sécurité (cf. Story 5.2 AC 12) — triple garde anti-injection :
 *   1. {@see validatePath()} : regex stricte sur path absolu sous `$classesRoot`,
 *      borne profondeur ≤ 3 niveaux, refuse `..`, espaces, backticks, `;`, `|`,
 *      `$`, `\\`, points-points (cohérent legacy `partages.inc.php` qui utilise
 *      `escapeshellarg` partiellement et laisse des trous d'injection).
 *   2. `escapeshellarg` sur tous les arguments shell (path + chaque ACL).
 *   3. Whitelist sudo (à entretenir côté VM via `/etc/sudoers.d/sambaedu`,
 *      cf. story §[PROD] : `www-data ALL=(root) NOPASSWD: /usr/bin/setfacl,
 *      /usr/bin/getfacl, /bin/mkdir, /bin/mv, /bin/chown, /bin/chgrp`).
 *
 * Pattern Laravel `Process` facade plutôt qu'`exec()` direct (HomeDirService
 * 5.1a) : permet `Process::fake()` dans les tests sans toucher au filesystem
 * réel (D12=A — story §Testing Strategy).
 *
 * Convention path racine (D13=A) : property statique `$classesRoot` overridable
 * en tests, cohérent `TrashPurgeCommand::$trashDir` 5.1d. Hardcodé par défaut
 * sur `/var/sambaedu/Classes`. La méthode {@see classesRoot()} consulte aussi
 * `config('filesystem.classes_root')` si défini (flexibilité multi-tenant
 * future).
 *
 * Fail-soft : aucune exception propagée — toutes les méthodes retournent
 * `bool` (ou `array|false` pour {@see getFacl()}) et émettent `Log::error`
 * avec le préfixe `'AclService: '` (cohérent `HomeDirService:` 5.1a).
 */
class AclService
{
    /**
     * Racine canonique des partages classes. Overridable en tests (D13).
     *
     * @var string
     */
    public static string $classesRoot = '/var/sambaedu/Classes';

    /**
     * Profondeur maximale autorisée sous {@see classesRoot()} pour les paths
     * passés à `setfacl`/`getfacl`. La structure légitime est :
     *   `Classes/Classe_<nom>` (1) / `Classe_<nom>/_travail|_profs|_echange|<eleve>` (2)
     *   / `<eleve>/Archives` (3 : pattern legacy `cree_rep` D3).
     *
     * Au-delà : refus.
     */
    private const MAX_DEPTH = 3;

    /**
     * Retourne la racine canonique des partages, en prenant en compte une
     * éventuelle override Laravel `config('filesystem.classes_root')` (rare,
     * surtout multi-tenant ou tests d'intégration).
     */
    public function classesRoot(): string
    {
        return rtrim((string) config('filesystem.classes_root', static::$classesRoot), '/');
    }

    /**
     * Valide un path candidat avant tout appel shell.
     *
     * Rejette :
     *  - tout path qui ne commence pas par {@see classesRoot()} ;
     *  - tout caractère hors `[A-Za-z0-9_.-/]` (pas d'espaces, pas de
     *    `$`, `;`, `|`, `\\`, backticks, null bytes) ;
     *  - tout segment `..` (traversal explicite) ;
     *  - tout path dont la profondeur sous la racine excède `MAX_DEPTH`.
     *
     * Note : on ne fait PAS `realpath()` car le path peut ne pas exister
     * encore (création). La regex + le check explicite `..` couvre les cas.
     */
    public function validatePath(string $path): bool
    {
        $root = $this->classesRoot();

        // Doit être absolu et commencer par la racine.
        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        if (! str_starts_with($path, $root . '/') && $path !== $root) {
            return false;
        }

        // Chars autorisés : alphanum + . _ - / uniquement.
        if (! preg_match('#^/[A-Za-z0-9_./-]+$#', $path)) {
            return false;
        }

        // Refuser tout `..` segment (traversal explicite).
        $segments = explode('/', trim(substr($path, strlen($root) + 1), '/'));
        if ($path === $root) {
            $segments = [];
        }
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '..' || $seg === '.') {
                return false;
            }
        }

        // Borne profondeur sous la racine.
        if (count($segments) > self::MAX_DEPTH) {
            return false;
        }

        return true;
    }

    /**
     * Wipe les ACLs existantes (`setfacl -b`) puis ré-applique le set canonique
     * passé en argument. Décalque `partages.inc.php::set_acls()` (l. 27-43)
     * en sécurisant les arguments via `escapeshellarg`.
     *
     * Idempotent : un second appel avec le même set produit le même résultat
     * (cf. Story 5.2 AC 3).
     *
     * @param string   $path    Path absolu sous {@see classesRoot()}.
     * @param string[] $acls    Liste de chaînes ACL (ex: `'user::rwx'`,
     *                          `'group:Classe_6a:rx'`, `'default:user::rwx'`).
     * @param bool     $recurse Si `true`, applique `-R` (récursif).
     * @return bool             `true` si toutes les opérations ont réussi.
     */
    public function setAcls(string $path, array $acls, bool $recurse = true): bool
    {
        if (! $this->validatePath($path)) {
            Log::error('AclService: setAcls path invalide', ['path' => $path]);
            return false;
        }

        // `-P` (physical) en mode récursif pour ne PAS suivre les symlinks —
        // décalque legacy `partages.inc.php` l. 372/492/498/506. Sans `-P`,
        // un attaquant qui plante un symlink dans un dossier élève peut
        // faire poser des ACLs sur n'importe quel chemin (régression sécurité
        // vs legacy). Cf. review 5.2 #3.
        $option = $recurse ? '-R -P' : '';
        $escaped = escapeshellarg($path);

        // 1. Wipe : setfacl -b (équivalent legacy l. 37). En récursif on ajoute
        //    `-P` pour ne pas suivre les symlinks (cf. note plus haut).
        $wipe = sprintf('sudo setfacl %s -b %s', $option, $escaped);
        $result = Process::run($wipe);
        if (! $result->successful()) {
            Log::error('AclService: setAcls échec wipe', [
                'path' => $path,
                'command' => $wipe,
                'output' => $this->captureOutput($result),
            ]);
            return false;
        }

        // 2. Batch -m pour chaque ACL (équivalent legacy l. 39).
        $allOk = true;
        foreach ($acls as $acl) {
            // Note : `escapeshellarg` autour de l'ACL — le legacy ne le fait
            // pas et laisse passer des chaînes contenant `\\040` (espaces
            // échappés). On garde la sémantique en gardant les `\\040` dans
            // l'argument quoté (ils sont littéraux pour setfacl).
            $cmd = sprintf(
                'sudo setfacl %s -m %s %s',
                $option,
                escapeshellarg($acl),
                $escaped
            );
            $r = Process::run($cmd);
            if (! $r->successful()) {
                Log::error('AclService: setAcls échec ACL', [
                    'path' => $path,
                    'acl' => $acl,
                    'command' => $cmd,
                    'output' => $this->captureOutput($r),
                ]);
                $allOk = false;
            }
        }

        return $allOk;
    }

    /**
     * Ajoute une ACL unique via `setfacl -m`. Décalque `add_acl()` legacy.
     */
    public function addAcl(string $path, string $acl, bool $recurse = true): bool
    {
        if (! $this->validatePath($path)) {
            Log::error('AclService: addAcl path invalide', ['path' => $path]);
            return false;
        }

        // `-P` en récursif (anti symlink traversal, cf. setAcls).
        $option = $recurse ? '-R -P' : '';
        $cmd = sprintf(
            'sudo setfacl %s -m %s %s',
            $option,
            escapeshellarg($acl),
            escapeshellarg($path)
        );

        $r = Process::run($cmd);
        if (! $r->successful()) {
            Log::error('AclService: addAcl échec', [
                'path' => $path,
                'acl' => $acl,
                'output' => $this->captureOutput($r),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Supprime une ACL unique via `setfacl -x`. Décalque `remove_acl()` legacy.
     */
    public function removeAcl(string $path, string $acl, bool $recurse = true): bool
    {
        if (! $this->validatePath($path)) {
            Log::error('AclService: removeAcl path invalide', ['path' => $path]);
            return false;
        }

        // `-P` en récursif (anti symlink traversal, cf. setAcls).
        $option = $recurse ? '-R -P' : '';
        $cmd = sprintf(
            'sudo setfacl %s -x %s %s',
            $option,
            escapeshellarg($acl),
            escapeshellarg($path)
        );

        $r = Process::run($cmd);
        if (! $r->successful()) {
            Log::error('AclService: removeAcl échec', [
                'path' => $path,
                'acl' => $acl,
                'output' => $this->captureOutput($r),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Vérifie si les ACLs disque correspondent au set attendu. Décalque
     * `check_acls()` legacy (l. 14-25). Note : le legacy crée le dossier si
     * absent ; on ne reproduit PAS ce side-effect ici (responsabilité du
     * `ShareService` de créer ses dossiers).
     *
     * @param string   $path
     * @param string[] $expectedAcls
     */
    public function checkAcls(string $path, array $expectedAcls): bool
    {
        if (! $this->validatePath($path)) {
            Log::error('AclService: checkAcls path invalide', ['path' => $path]);
            return false;
        }
        if (! is_dir($path)) {
            return false;
        }

        $cmd = sprintf('sudo getfacl -c %s 2>/dev/null', escapeshellarg($path));
        $r = Process::run($cmd);
        if (! $r->successful()) {
            return false;
        }

        $disk = preg_split('/\R/', $r->output()) ?: [];
        // Le legacy fait `array_pop($disk_acls)` (dernière ligne vide
        // habituelle). On filtre simplement les lignes vides.
        $disk = array_values(array_filter($disk, fn ($l) => trim($l) !== ''));
        $expected = $expectedAcls;
        sort($disk);
        sort($expected);

        return $disk === $expected;
    }

    /**
     * Retourne les ACLs courantes parsées en tableau associatif. Décalque
     * `get_facl()` legacy (l. 87-118).
     *
     * Format de retour :
     *   [
     *     'user::'                  => ['mode' => 'rwx'],
     *     'equipe_6a'               => ['type' => 'group', 'mode' => 'r-x', 'default_mode' => 'r-x'],
     *     'classe_6a'               => ['type' => 'group', 'mode' => 'rwx'],
     *     ...
     *   ]
     *
     * @return array<string, array<string, string>>|false
     */
    public function getFacl(string $path): array|false
    {
        if (! $this->validatePath($path)) {
            Log::error('AclService: getFacl path invalide', ['path' => $path]);
            return false;
        }

        $cmd = sprintf('sudo getfacl -c -E %s', escapeshellarg($path));
        $r = Process::run($cmd);
        if (! $r->successful()) {
            return false;
        }

        $acl = [];
        $lines = preg_split('/\R/', $r->output()) ?: [];
        foreach ($lines as $line) {
            if (strlen($line) <= 1) {
                continue;
            }
            $t = explode(':', $line);
            if (! count($t)) {
                continue;
            }

            if (($t[0] ?? '') === 'default') {
                if (empty($t[2] ?? '')) {
                    // ex : default:user::rwx → key 'user::', mode at $t[3]
                    $key = ($t[1] ?? '') . '::';
                    $acl[$key]['default_mode'] = $t[3] ?? '';
                } else {
                    $name = preg_replace('/\\\\040/', ' ', (string) $t[2]);
                    $acl[$name]['type'] = $t[1] ?? '';
                    $acl[$name]['default_mode'] = $t[3] ?? '';
                }
            } else {
                if (empty($t[1] ?? '')) {
                    $key = ($t[0] ?? '') . '::';
                    $acl[$key]['mode'] = $t[2] ?? '';
                } else {
                    $name = preg_replace('/\\\\040/', ' ', (string) $t[1]);
                    $acl[$name]['type'] = $t[0] ?? '';
                    $acl[$name]['mode'] = $t[2] ?? '';
                }
            }
        }
        return $acl;
    }

    /**
     * Centralise la capture stdout+stderr d'un ProcessResult pour les logs.
     */
    private function captureOutput(ProcessResult $r): string
    {
        $out = trim($r->output());
        $err = trim($r->errorOutput());
        if ($out !== '' && $err !== '') {
            return $out . "\n" . $err;
        }
        return $out !== '' ? $out : $err;
    }
}
