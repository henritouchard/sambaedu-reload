<?php

declare(strict_types=1);

namespace App\Config;

use Illuminate\Support\Facades\Log;

/**
 * Bridge vers les fonctions legacy de configuration SambaEdu
 * 
 * Cette classe encapsule le chargement des fichiers legacy et expose
 * les fonctions legacy pour les cas où elles sont absolument nécessaires.
 * 
 * @deprecated Utiliser les méthodes typées de SambaEduConfig autant que possible.
 *             Ce bridge est destiné à être supprimé progressivement.
 * @example
 * ```php
 * use App\Facades\SEConfig;
 * $config = SEConfig;
 * 
 * // Dernier recours : accès aux fonctions legacy
 * $legacyConfig = $config->legacy()->getConfig();
 * $user = search_user($legacyConfig, $login);
 * ```
 */
class LegacyConfigBridge
{
    private static ?array $config = null;
    private static bool $legacyLoaded = false;

    /**
     * Charge les fichiers legacy et retourne la configuration
     * 
     * @deprecated Utiliser SambaEduConfig::get() ou les DTOs typés
     * @return array Configuration legacy complète
     */
    public function getConfig(): array
    {
        $this->loadLegacyFiles();

        if (self::$config !== null) {
            return self::$config;
        }

        if (function_exists('get_config')) {
            self::$config = get_config();
        } else {
            // Configuration minimale si les fonctions legacy ne sont pas disponibles
            self::$config = [
                'login' => $_SESSION['login'] ?? '',
                'etab' => $_SESSION['etab'] ?? '0',
                'bind' => null,
            ];
            Log::warning('LegacyConfigBridge: get_config() non disponible, configuration minimale utilisée');
        }

        return self::$config;
    }

    /**
     * Met à jour la configuration en mémoire
     * 
     * @deprecated
     */
    public function setConfig(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Recharge la configuration depuis les fichiers
     * 
     * @deprecated
     */
    public function reloadConfig(): array
    {
        self::$config = null;
        return $this->getConfig();
    }

    /**
     * Vérifie si les fichiers legacy sont chargés
     */
    public function isLegacyLoaded(): bool
    {
        return self::$legacyLoaded;
    }

    /**
     * Force le rechargement des fichiers legacy
     */
    public function reloadLegacyFiles(): void
    {
        self::$legacyLoaded = false;
        $this->loadLegacyFiles();
    }

    /**
     * Recherche un utilisateur via la fonction legacy
     * 
     * @deprecated Utiliser UserRepository
     * @param string $login Login de l'utilisateur
     * @return array Données utilisateur LDAP
     */
    public function searchUser(string $login): array
    {
        $this->loadLegacyFiles();
        $config = $this->getConfig();

        if (function_exists('search_user')) {
            return search_user($config, $login) ?: [];
        }

        Log::warning('LegacyConfigBridge: search_user() non disponible');
        return [];
    }

    /**
     * Vérifie les droits d'un utilisateur via la fonction legacy
     * 
     * @deprecated Utiliser les policies Laravel
     * @param int $rights Masque de droits à vérifier
     * @param array|null $user Données utilisateur (null = utilisateur courant)
     * @param bool $checkDelegation Vérifier les délégations
     * @return bool
     */
    public function haveRight(int $rights, ?array $user = null, bool $checkDelegation = true): bool
    {
        $this->loadLegacyFiles();
        $config = $this->getConfig();

        if (function_exists('have_right')) {
            return have_right($config, $rights, $user, $checkDelegation);
        }

        Log::warning('LegacyConfigBridge: have_right() non disponible');
        return false;
    }

    /**
     * Liste les droits d'un utilisateur via la fonction legacy
     * 
     * @deprecated Utiliser les policies Laravel
     * @param array $user Données utilisateur
     * @return array Liste des droits
     */
    public function listRights(array $user): array
    {
        $this->loadLegacyFiles();
        $config = $this->getConfig();

        if (function_exists('list_rights')) {
            return list_rights($config, $user) ?: [];
        }

        Log::warning('LegacyConfigBridge: list_rights() non disponible');
        return [];
    }

    // Story 49.2 (FR-R3) — `isEleve()` / `isProf()` supprimés : shims legacy
    // `@deprecated` sans aucun appelant, qui rechargeaient toute la config
    // legacy pour interroger l'annuaire. Le rôle se lit en Postgres.

    /**
     * Récupère les établissements activés
     * 
     * @deprecated Utiliser EstablishmentRepository
     */
    public function activatedEtabs(): array
    {
        $this->loadLegacyFiles();
        $config = $this->getConfig();

        if (function_exists('activated_etabs')) {
            return activated_etabs($config) ?: [];
        }

        return [];
    }

    /**
     * Charge les shims de configuration nécessaires.
     *
     * Story 38.4 (AC2) — les fichiers sont désormais les **shims IN-REPO**
     * (`legacy/config.inc.php`, `legacy/ldap.inc.php`) qui définissent
     * nativement `get_config`, `search_user`, `have_right`, `list_rights`… —
     * plus AUCUN chemin `/var/www/sambaedu`. Le bridge reste `@deprecated`,
     * seul son backend change.
     */
    private function loadLegacyFiles(): void
    {
        if (self::$legacyLoaded) {
            return;
        }

        $files = [
            base_path('legacy/config.inc.php'),
            base_path('legacy/ldap.inc.php'),
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                try {
                    require_once $file;
                } catch (\Throwable $e) {
                    Log::error("LegacyConfigBridge: Erreur lors du chargement de {$file}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::warning("LegacyConfigBridge: Shim in-repo non trouvé: {$file}");
            }
        }

        self::$legacyLoaded = true;
    }

    /**
     * Réinitialise l'état statique (utile pour les tests)
     */
    public static function reset(): void
    {
        self::$config = null;
        self::$legacyLoaded = false;
    }
}
