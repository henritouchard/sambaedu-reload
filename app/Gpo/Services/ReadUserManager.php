<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Config\SambaEduConfig;
use App\Ldap\AdUserManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Gère le compte AD service `read.user{suffix}` consommé par Veyon pour son
 * bind LDAP en lecture seule.
 *
 * Cycle de vie iso-legacy (`sambaedu/gpo/veyon_out.php:29-50`) :
 *
 * 1. Si `read_ldap_password` est vide en config → générer un mot de passe ≥15
 *    chars, créer le compte AD `CN=read.user{suffix},{dn.people}`,
 *    persister le password en config.
 * 2. Si `read_ldap_password` est présent mais n'authentifie pas (drift) →
 *    re-pousser le mot de passe via `AdUserManager::setPassword`.
 *
 * **Concurrence** : N postes peuvent appeler `gpo/veyon_out.php` simultanément
 * au boot (logon scripts GPO `se4_applications`). Un lock applicatif Laravel
 * `Cache::lock('veyon-read-user-create', 30)` protège la création (timeout 30s).
 *
 * **Correctifs post-review 16.3b (2026-05-12)** — décision Henri option A complète :
 * remplacement des appels shim `create_ad_user` / `usersetpassword` / `set_config`
 * par les services natifs {@see AdUserManager} et {@see SambaEduConfig::set}.
 * Les shims `_shim_log_unimplemented` (legacy/ldap.inc.php:1245-1250, 1619-1626)
 * retournaient toujours `false` / ne persistaient pas → blockers #1 + #2 du
 * review. L'`AdUserManager` natif utilise `SambaToolRunner` (mode array, échappement
 * shell sécurisé) ; `SambaEduConfig::set` écrit atomiquement sur disque avec lock
 * `flock` exclusif.
 *
 * **Drift recovery (#M1)** : si `setPassword()` échoue lors d'un drift détecté,
 * on retourne `null` depuis `ensurePassword()` plutôt que de réutiliser l'ancien
 * mot de passe (qui ne validerait plus). Le `VeyonOutController` applique
 * l'option B (`unset BindPassword`) — Veyon retry au prochain logon, échec
 * bruyant + log error visible.
 *
 * @legacy-port path="sambaedu/gpo/veyon_out.php:29-50"
 * @since Story 16.3b
 */
class ReadUserManager
{
    private const LOCK_NAME = 'veyon-read-user-create';
    private const LOCK_TIMEOUT_SECONDS = 30;
    private const LOCK_WAIT_SECONDS = 30;
    private const DEFAULT_PASSWORD_LENGTH = 15;

    public function __construct(
        private readonly SambaEduConfig $config,
        private readonly AdUserManager $adUsers,
    ) {}

    /**
     * Garantit que le compte AD `read.user{suffix}` existe et que
     * `read_ldap_password` est en config + sync AD.
     *
     * Retourne le mot de passe en clair (consommé par
     * `VeyonConfigGenerator::encryptBindPassword`) ou `null` si la création AD
     * a échoué OU si la recovery de drift a échoué (Veyon servira sa config
     * sans `BindPassword` → option B Henri).
     */
    public function ensurePassword(): ?string
    {
        $existing = (string) $this->config->get('read_ldap_password', '');
        $suffix = (string) $this->config->get('suffix', '');
        $login = 'read.user' . $suffix;

        if ($existing !== '') {
            // Compte déjà provisionné : on vérifie juste que le password est sync AD.
            if (! $this->validatePassword($login, $existing)) {
                // Drift détecté → tentative de recovery (re-push pwd).
                // #M1 : échec bruyant si la recovery échoue (pas de réutilisation
                // silencieuse d'un mot de passe qui ne valide plus).
                if (! $this->resetPassword($login, $existing)) {
                    Log::error('[ReadUserManager] drift recovery failed, serving without password', [
                        'login' => $login,
                    ]);
                    return null;
                }
            }
            return $existing;
        }

        // Pas de password → création (sous lock anti-race).
        return $this->createReadUserUnderLock($login);
    }

    /**
     * Acquiert le lock applicatif et crée le compte AD si pas déjà fait.
     *
     * Pattern « double-checked locking » : on relit la config après acquisition
     * du lock pour gérer le cas où un autre worker a créé le compte pendant
     * qu'on attendait le lock.
     *
     * `private` (le test override désormais `AdUserManager` via DI au lieu
     * d'override cette méthode).
     */
    private function createReadUserUnderLock(string $login): ?string
    {
        $lock = Cache::lock(self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS);

        try {
            // Attente bloquante du lock (parc multi-postes au boot).
            if (! $lock->block(self::LOCK_WAIT_SECONDS)) {
                Log::error('[ReadUserManager] failed to acquire read.user create lock', [
                    'login' => $login,
                ]);
                return null;
            }

            // Re-lire en config — un autre worker a peut-être déjà créé le compte.
            $this->config->reload();
            $existing = (string) $this->config->get('read_ldap_password', '');
            if ($existing !== '') {
                return $existing;
            }

            $password = $this->generatePassword();
            $created = $this->createAdUser($login, $password);

            if (! $created) {
                Log::error('[ReadUserManager] read.user creation failed', [
                    'login' => $login,
                ]);
                return null;
            }

            $this->persistPassword($password);

            Log::info('[ReadUserManager] read.user created', [
                'login' => $login,
            ]);

            return $password;
        } finally {
            $lock->release();
        }
    }

    /**
     * Génère un mot de passe complexe ≥15 chars (parité legacy).
     *
     * Fallback PHP natif via `random_bytes` (base64), ≥15 chars garantis.
     *
     * @legacy-port path="sambaedu/includes/ldap.inc.php:470"
     */
    private function generatePassword(): string
    {
        $length = self::DEFAULT_PASSWORD_LENGTH;

        // Read password rule depuis SambaEduConfig si dispo (cf. legacy `get_password_rule`).
        $minLength = (int) $this->config->passwordPolicy()->minLength;
        if ($minLength > $length) {
            $length = $minLength;
        }

        if (function_exists('create_random_password')) {
            /** @var string $pwd */
            $pwd = (string) create_random_password($length, true, false);
            if ($pwd !== '') {
                return $pwd;
            }
        }

        // Fallback PHP natif : base64 random_bytes (toujours ≥15 chars).
        return substr(rtrim(base64_encode(random_bytes(24)), '='), 0, max($length, 15));
    }

    /**
     * Crée le compte AD `read.user{suffix}` via `AdUserManager` natif.
     *
     * Idempotent : si le compte existe déjà (race au boot parc), on considère
     * la création réussie et on enchaîne sur la persistance du password.
     *
     * @since Story 16.3b (refactor post-review : natif au lieu du shim).
     */
    private function createAdUser(string $login, string $password): bool
    {
        try {
            if ($this->adUsers->exists($login)) {
                Log::info('[ReadUserManager] read.user already exists, treating as create-success', [
                    'login' => $login,
                ]);
                return true;
            }

            return $this->adUsers->create($login, $password, [
                'description' => 'compte service pour lecture AD',
            ]);
        } catch (\Throwable $e) {
            Log::error('[ReadUserManager] AdUserManager::create threw', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Persiste `read_ldap_password` en config via `SambaEduConfig::set` natif.
     *
     * @since Story 16.3b (refactor post-review : natif au lieu du shim `set_config`).
     */
    private function persistPassword(string $password): void
    {
        try {
            $this->config->set('read_ldap_password', $password);
        } catch (\Throwable $e) {
            Log::error('[ReadUserManager] SambaEduConfig::set threw', [
                'error' => $e->getMessage(),
            ]);
            // Pas de re-throw : on a déjà créé le compte AD. Si la persistance
            // échoue, le prochain appel tentera de re-créer → l'idempotence du
            // create() (cf. AdUserManager::create avec "already exists")
            // empêche les doublons.
        }
    }

    /**
     * Valide qu'un bind LDAP avec `(login, password)` réussit.
     *
     * Délègue à `AdUserManager::validatePassword`.
     */
    public function validatePassword(string $login, string $password): bool
    {
        try {
            return $this->adUsers->validatePassword($login, $password);
        } catch (\Throwable $e) {
            Log::warning('[ReadUserManager] validatePassword threw', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
            return true; // fail-open : éviter le reset en boucle si la commande crashe.
        }
    }

    /**
     * Re-pousse un mot de passe sur le compte AD (drift recovery).
     *
     * Retourne `bool` (vs `void` legacy) : `false` signale que la recovery a
     * échoué → le caller (`ensurePassword`) renvoie `null` pour que Veyon
     * applique l'option B (unset BindPassword) — pas de réutilisation
     * silencieuse d'un password qui ne valide plus (#M1).
     *
     * @since Story 16.3b (refactor post-review : retour `bool`, échec bruyant).
     */
    public function resetPassword(string $login, string $password): bool
    {
        try {
            $ok = $this->adUsers->setPassword($login, $password);
            if ($ok) {
                Log::info('[ReadUserManager] read.user password re-synchronised', [
                    'login' => $login,
                ]);
                return true;
            }

            Log::error('[ReadUserManager] read.user password reset failed (drift not recovered)', [
                'login' => $login,
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('[ReadUserManager] resetPassword threw', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
