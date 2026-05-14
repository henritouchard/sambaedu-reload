<?php

declare(strict_types=1);

namespace App\Ldap;

use App\Gpo\Support\SambaToolRunner;
use Illuminate\Support\Facades\Log;

/**
 * Service natif de gestion de comptes utilisateurs AD via `samba-tool user`.
 *
 * Story 16.3b (correctifs post-review 2026-05-12, décision Henri option A
 * complète) : remplacer le shim `create_ad_user` / `usersetpassword` /
 * `user_valid_passwd` non-fonctionnel (`_shim_log_unimplemented`) par une
 * implémentation native réutilisable, indépendante du legacy.
 *
 * Périmètre :
 *  - `exists()`     — `samba-tool user list` filtré (présence du compte)
 *  - `create()`     — `samba-tool user create` (mot de passe fixe)
 *  - `setPassword()` — `samba-tool user setpassword`
 *  - `validatePassword()` — `samba-tool user syncpasswords --no-cache-ldb --terminate` n'est pas adapté,
 *    on utilise plutôt une commande `samba-tool user show` + best-effort bind test.
 *
 * **Logging** : channel `gpo` retenu (opérations admin AD à effet de bord —
 * cohérence Story 16.1 AC2.3 « actions admin GPO » loguées sur le channel
 * dédié) — pas `daily` car ces appels ne se produisent **pas** par requête
 * runtime poste : ils ne sont déclenchés que sur installation vierge (1ère
 * fois) ou drift recovery, et représentent une mutation AD persistante qu'on
 * veut tracer auditablement.
 *
 * **Sécurité shell** : tous les appels passent par `SambaToolRunner` (mode
 * array, échappement automatique des arguments). Aucune concaténation de
 * string, aucun `shell_exec` direct (garde-fou archi Story 16.1 AC2.2).
 *
 * @since Story 16.3b
 * @see SambaToolRunner pour l'exécution shell sécurisée.
 */
class AdUserManager
{
    /** Caractères AD valides pour un samAccountName : `A-Z a-z 0-9 _ - . $`. */
    private const SAMACCOUNTNAME_REGEX = '/^[A-Za-z0-9_\-\.\$]+$/';

    public function __construct(
        private readonly SambaToolRunner $runner,
    ) {}

    /**
     * Vérifie qu'un compte utilisateur AD existe par son `samAccountName`.
     *
     * Délègue à `samba-tool user list` et cherche le login dans la sortie.
     *
     * @param  string  $samaccountname  Nom de compte AD (`read.user`, `read.user-1234567a`, etc.)
     * @return bool  `true` si le compte existe, `false` sinon (ou si la commande échoue).
     */
    public function exists(string $samaccountname): bool
    {
        if (! $this->isValidSamaccountname($samaccountname)) {
            Log::channel('gpo')->warning('[AdUserManager] invalid samaccountname for exists()', [
                'samaccountname' => $samaccountname,
            ]);
            return false;
        }

        try {
            $result = $this->runner->run(['user', 'list']);
        } catch (\Throwable $e) {
            Log::channel('gpo')->error('[AdUserManager] exists() samba-tool threw', [
                'samaccountname' => $samaccountname,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($result->exitCode() !== 0) {
            Log::channel('gpo')->error('[AdUserManager] exists() non-zero exit', [
                'samaccountname' => $samaccountname,
                'exit_code' => $result->exitCode(),
                'stderr' => $this->truncate($result->errorOutput()),
            ]);
            return false;
        }

        // `samba-tool user list` output = un nom par ligne. Test strict (≠
        // substring) pour éviter qu'un `read.user` matche `read.user-1234567a`.
        $lines = preg_split('/\r?\n/', trim((string) $result->output())) ?: [];
        foreach ($lines as $line) {
            if (trim($line) === $samaccountname) {
                return true;
            }
        }

        return false;
    }

    /**
     * Crée un compte utilisateur AD avec mot de passe fixe.
     *
     * Commande : `samba-tool user create <login> <password> --use-username-as-cn --description=<desc>`.
     *
     * @param  string  $samaccountname  Login AD (validé regex stricte).
     * @param  string  $password  Mot de passe initial (transmis en argv —
     *                            traité confidentiellement par `SambaToolRunner`,
     *                            jamais loggué).
     * @param  array<string, string>  $attributes  Attributs additionnels facultatifs :
     *  - `description` : description AD.
     *  - `givenname` / `surname` / `mail` : champs people (ignorés ici, posés ailleurs).
     * @return bool  `true` si création OK, `false` sinon.
     */
    public function create(string $samaccountname, string $password, array $attributes = []): bool
    {
        if (! $this->isValidSamaccountname($samaccountname)) {
            Log::channel('gpo')->error('[AdUserManager] invalid samaccountname for create()', [
                'samaccountname' => $samaccountname,
            ]);
            return false;
        }

        if ($password === '') {
            Log::channel('gpo')->error('[AdUserManager] empty password rejected', [
                'samaccountname' => $samaccountname,
            ]);
            return false;
        }

        $args = ['user', 'create', $samaccountname, $password, '--use-username-as-cn'];

        if (isset($attributes['description']) && $attributes['description'] !== '') {
            $args[] = '--description=' . $attributes['description'];
        }

        try {
            $result = $this->runner->run($args);
        } catch (\Throwable $e) {
            Log::channel('gpo')->error('[AdUserManager] create() samba-tool threw', [
                'samaccountname' => $samaccountname,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($result->exitCode() !== 0) {
            // Tolérer le cas « compte déjà existant » comme un succès idempotent.
            // `samba-tool user create` renvoie « already exists » sur stderr — on
            // se base sur ce message + un `exists()` re-check pour confirmer.
            $stderr = (string) $result->errorOutput();
            if (stripos($stderr, 'already exists') !== false && $this->exists($samaccountname)) {
                Log::channel('gpo')->info('[AdUserManager] create() user already exists (idempotent)', [
                    'samaccountname' => $samaccountname,
                ]);
                return true;
            }

            Log::channel('gpo')->error('[AdUserManager] create() failed', [
                'samaccountname' => $samaccountname,
                'exit_code' => $result->exitCode(),
                'stderr' => $this->truncate($stderr),
            ]);
            return false;
        }

        Log::channel('gpo')->info('[AdUserManager] user created', [
            'samaccountname' => $samaccountname,
        ]);
        return true;
    }

    /**
     * Pousse un nouveau mot de passe sur un compte AD existant.
     *
     * Commande : `samba-tool user setpassword <login> --newpassword=<pwd>`.
     *
     * @param  string  $samaccountname  Login AD (validé regex stricte).
     * @param  string  $password  Nouveau mot de passe (jamais loggué).
     * @return bool  `true` si réussite, `false` sinon.
     */
    public function setPassword(string $samaccountname, string $password): bool
    {
        if (! $this->isValidSamaccountname($samaccountname)) {
            Log::channel('gpo')->error('[AdUserManager] invalid samaccountname for setPassword()', [
                'samaccountname' => $samaccountname,
            ]);
            return false;
        }

        if ($password === '') {
            Log::channel('gpo')->error('[AdUserManager] empty password rejected for setPassword()', [
                'samaccountname' => $samaccountname,
            ]);
            return false;
        }

        $args = ['user', 'setpassword', $samaccountname, '--newpassword=' . $password];

        try {
            $result = $this->runner->run($args);
        } catch (\Throwable $e) {
            Log::channel('gpo')->error('[AdUserManager] setPassword() samba-tool threw', [
                'samaccountname' => $samaccountname,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($result->exitCode() !== 0) {
            Log::channel('gpo')->error('[AdUserManager] setPassword() failed', [
                'samaccountname' => $samaccountname,
                'exit_code' => $result->exitCode(),
                'stderr' => $this->truncate($result->errorOutput()),
            ]);
            return false;
        }

        Log::channel('gpo')->info('[AdUserManager] password updated', [
            'samaccountname' => $samaccountname,
        ]);
        return true;
    }

    /**
     * Valide qu'un couple `(login, password)` permet un bind LDAP.
     *
     * Implémentation best-effort : utilise `ldap_bind` natif PHP contre
     * `ldap://localhost` (le DC AD local) — n'expose pas de risque shell,
     * pas de `samba-tool`. Si le client LDAP n'est pas dispo, retourne `true`
     * pour ne pas déclencher de reset en boucle (fail-open).
     *
     * @param  string  $samaccountname  Login AD.
     * @param  string  $password  Mot de passe à tester.
     * @return bool  `true` si bind OK ou client LDAP indisponible, `false` si bind rejeté.
     */
    public function validatePassword(string $samaccountname, string $password): bool
    {
        if (! $this->isValidSamaccountname($samaccountname) || $password === '') {
            return false;
        }

        if (! function_exists('ldap_connect') || ! function_exists('ldap_bind')) {
            // Client LDAP PHP non installé → fail-open (cohérent avec la
            // logique legacy `user_valid_passwd` qui retombe en best-effort).
            return true;
        }

        $host = (string) config('sambaedu.legacy_ldap.host', 'localhost');
        $port = (int) config('sambaedu.legacy_ldap.port', 389);
        $domain = (string) config('sambaedu.legacy_ldap.domain', '');

        $conn = @ldap_connect('ldap://' . $host . ':' . $port);
        if ($conn === false) {
            return true; // fail-open
        }

        @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

        $bindDn = $domain !== ''
            ? $samaccountname . '@' . $domain
            : $samaccountname;

        $ok = @ldap_bind($conn, $bindDn, $password);
        @ldap_unbind($conn);

        return $ok === true;
    }

    /**
     * Garde-fou regex : caractères AD valides pour un samAccountName.
     */
    private function isValidSamaccountname(string $name): bool
    {
        return $name !== '' && (bool) preg_match(self::SAMACCOUNTNAME_REGEX, $name);
    }

    /**
     * Tronque une sortie stdout/stderr à 2 Ko pour les logs (parité
     * `GpoActionLog::sambaToolExec` à 8 Ko, plus restrictif ici car on log
     * uniquement les erreurs).
     */
    private function truncate(string $value): string
    {
        $max = 2048;
        return strlen($value) > $max ? substr($value, 0, $max) . '…[truncated]' : $value;
    }
}
