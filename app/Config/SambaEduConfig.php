<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\EstablishmentConfig;
use App\Config\LdapConfig;
use App\Config\NetworkConfig;
use App\Config\ProxyConfig;
use App\Config\SecurityConfig;
use App\Config\PasswordPolicyConfig;
use App\Config\CredentialsConfig;
use App\Config\LegacyConfigBridge;
use Illuminate\Support\Facades\Log;

/**
 * Service de configuration SambaEdu unifié
 * 
 * Ce service charge la configuration depuis les fichiers INI de SambaEdu
 * et expose des méthodes typées pour accéder aux différents domaines de configuration.
 * 
 * Il est indépendant du code legacy PHP et lit directement les fichiers INI.
 * 
 * @example
 * ```php
 * $config = app(SambaEduConfig::class);
 * 
 * // Accès typé aux configurations
 * $ldap = $config->ldap();
 * $hosts = $ldap->getHosts();
 * 
 * $establishment = $config->establishment();
 * if ($establishment->hasSelectedEstablishment()) {
 *     // ...
 * }
 * ```
 */
class SambaEduConfig
{
    private const MAIN_CONFIG_FILE = '/etc/sambaedu/sambaedu.conf';
    private const CONFIG_DIR = '/etc/sambaedu/sambaedu.conf.d';

    /** @var array<string, mixed>|null Cache de la configuration brute */
    private static ?array $rawConfig = null;

    /** @var LdapConfig|null Cache de la configuration LDAP */
    private ?LdapConfig $ldapConfig = null;

    /** @var EstablishmentConfig|null Cache de la configuration établissement */
    private ?EstablishmentConfig $establishmentConfig = null;

    /** @var NetworkConfig|null Cache de la configuration réseau */
    private ?NetworkConfig $networkConfig = null;

    /** @var SecurityConfig|null Cache de la configuration sécurité */
    private ?SecurityConfig $securityConfig = null;

    /** @var ProxyConfig|null Cache de la configuration proxy */
    private ?ProxyConfig $proxyConfig = null;

    /** @var PasswordPolicyConfig|null Cache de la configuration politique de mot de passe */
    private ?PasswordPolicyConfig $passwordPolicyConfig = null;

    /** @var CredentialsConfig|null Cache de la configuration des identifiants */
    private ?CredentialsConfig $credentialsConfig = null;

    /** @var LegacyConfigBridge|null Bridge vers les fonctions legacy */
    private ?LegacyConfigBridge $legacyBridge = null;

    /**
     * Retourne la configuration LDAP
     */
    public function ldap(): LdapConfig
    {
        if ($this->ldapConfig === null) {
            $raw = $this->getRawConfig();

            $this->ldapConfig = new LdapConfig(
                url: $raw['ldap_url'] ?? 'ldap://localhost',
                port: (int) ($raw['ldap_port'] ?? 389),
                baseDn: $raw['ldap_base_dn'] ?? '',
                adminName: $raw['ldap_admin_name'] ?? 'Administrator',
                adminPassword: $raw['ldap_admin_passwd'] ?? '',
                domain: $raw['domain'] ?? '',
                sambaDomain: $raw['samba_domain'] ?? '',
                peopleRdn: $raw['people_rdn'] ?? 'ou=Utilisateurs',
                groupsRdn: $raw['groups_rdn'] ?? 'ou=Groups',
                computersRdn: $raw['computers_rdn'] ?? 'ou=computers',
                parcsRdn: $raw['parcs_rdn'] ?? 'ou=Parcs',
                classesRdn: $raw['classes_rdn'] ?? 'ou=classes',
                equipesRdn: $raw['equipes_rdn'] ?? 'ou=equipes',
                matieresRdn: $raw['matieres_rdn'] ?? 'ou=matieres',
                coursRdn: $raw['cours_rdn'] ?? 'ou=cours',
                projetsRdn: $raw['projets_rdn'] ?? 'ou=projets',
                otherGroupsRdn: $raw['other_groups_rdn'] ?? 'ou=autres',
                delegationsRdn: $raw['delegations_rdn'] ?? 'ou=delegations',
                equipementsRdn: $raw['equipements_rdn'] ?? 'ou=Materiels',
                rightsRdn: $raw['rights_rdn'] ?? 'ou=Rights',
                trashRdn: $raw['trash_rdn'] ?? 'ou=Trash',
                etablissementsRdn: $raw['etablissements_rdn'] ?? 'OU=etablissements',
                adminRdn: $raw['admin_rdn'] ?? 'cn=Users',
                serverIp: $raw['se4ad_ip'] ?? config('sambaedu.se4ad_ip'),
                etabServerIp: $raw['se4ad_etab_ip'] ?? config('sambaedu.se4ad_etab_ip'),
                strictLocalAd: $this->parseStrictLocalAd($raw),
            );
        }

        return $this->ldapConfig;
    }

    /**
     * Retourne la configuration de l'établissement
     * 
     * @param string|null $sessionEtab Code établissement depuis la session (prioritaire)
     */
    public function establishment(?string $sessionEtab = null): EstablishmentConfig
    {
        // Toujours recalculer si sessionEtab est fourni car il peut changer
        if ($this->establishmentConfig === null || $sessionEtab !== null) {
            $raw = $this->getRawConfig();

            // Priorité : session > config
            $currentCode = $sessionEtab ?? session('etab') ?? $raw['etab_ou'] ?? '0';

            $this->establishmentConfig = new EstablishmentConfig(
                uai: $raw['UAI'] ?? '0000000x',
                name: $raw['etab_name'] ?? 'SambaEdu',
                currentCode: $currentCode,
            );
        }

        return $this->establishmentConfig;
    }

    /**
     * Retourne la configuration réseau
     */
    public function network(): NetworkConfig
    {
        if ($this->networkConfig === null) {
            $raw = $this->getRawConfig();

            $this->networkConfig = new NetworkConfig(
                se4fsIp: $raw['se4fs_ip'] ?? '',
                se4fsName: $raw['se4fs_name'] ?? 'se4fs',
                se4adIp: $raw['se4ad_ip'] ?? '',
                se4adName: $raw['se4ad_name'] ?? 'se4ad',
                se4adMask: $raw['se4ad_mask'] ?? '255.255.255.0',
                se4adGateway: $raw['se4ad_gw'] ?? '',
                interface: $raw['interface'] ?? 'eth0',
                address: $raw['address'] ?? '',
                mask: $raw['mask'] ?? '255.255.255.0',
                network: $raw['network'] ?? '',
                gateway: $raw['gateway'] ?? '',
                nameserver: $raw['nameserver'] ?? '',
                se4Url: $raw['se4_url'] ?? '',
            );
        }

        return $this->networkConfig;
    }

    /**
     * Retourne la configuration de sécurité
     */
    public function security(): SecurityConfig
    {
        if ($this->securityConfig === null) {
            $raw = $this->getRawConfig();

            $this->securityConfig = new SecurityConfig(
                cnPolicy: ($raw['cnPolicy'] ?? '0') === '1',
                pwdPolicy: ($raw['pwdPolicy'] ?? '0') === '1',
                checkPasswords: ($raw['check_passwords'] ?? '0') === '1',
                adCheck: ($raw['ad_check'] ?? '0') === '1',
                se4Key: $raw['se4_key'] ?? '',
                se4PubKey: $raw['se4_pub_key'] ?? '',
            );
        }

        return $this->securityConfig;
    }

    /**
     * Retourne la configuration du proxy
     */
    public function proxy(): ProxyConfig
    {
        if ($this->proxyConfig === null) {
            $raw = $this->getRawConfig();

            $this->proxyConfig = new ProxyConfig(
                type: $raw['proxy_type'] ?? 'aucun',
                address: $raw['proxy_address'] ?? '',
                port: (int) ($raw['proxy_port'] ?? 3128),
            );
        }

        return $this->proxyConfig;
    }

    /**
     * Retourne une valeur brute de configuration
     * 
     * @param string $key Clé de configuration
     * @param mixed $default Valeur par défaut
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getRawConfig()[$key] ?? $default;
    }

    /**
     * Vérifie si une clé existe dans la configuration
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->getRawConfig());
    }

    /**
     * Retourne toute la configuration brute
     * 
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->getRawConfig();
    }

    /**
     * Vide le cache et recharge la configuration
     */
    public function reload(): void
    {
        self::$rawConfig = null;
        $this->ldapConfig = null;
        $this->establishmentConfig = null;
        $this->networkConfig = null;
        $this->securityConfig = null;
        $this->proxyConfig = null;
        $this->passwordPolicyConfig = null;
        $this->credentialsConfig = null;
        $this->legacyBridge = null;
    }

    /**
     * Retourne la configuration des politiques de mot de passe
     */
    public function passwordPolicy(): PasswordPolicyConfig
    {
        if ($this->passwordPolicyConfig === null) {
            $raw = $this->getRawConfig();

            $this->passwordPolicyConfig = new PasswordPolicyConfig(
                minLength: (int) ($raw['pwd_min_length'] ?? 8),
                maxLength: (int) ($raw['pwd_max_length'] ?? 128),
                requireUppercase: ($raw['pwd_require_upper'] ?? '1') === '1',
                requireLowercase: ($raw['pwd_require_lower'] ?? '1') === '1',
                requireDigit: ($raw['pwd_require_digit'] ?? '1') === '1',
                requireSpecialChar: ($raw['pwd_require_special'] ?? '0') === '1',
                expirationDays: (int) ($raw['pwd_expiration_days'] ?? 0),
                historyCount: (int) ($raw['pwd_history_count'] ?? 0),
                checkDictionary: ($raw['pwd_check_dictionary'] ?? '0') === '1',
            );
        }

        return $this->passwordPolicyConfig;
    }

    /**
     * Retourne la configuration des identifiants sensibles
     * 
     * Les credentials peuvent être chargés depuis un fichier séparé
     * avec des permissions restreintes pour plus de sécurité.
     */
    public function credentials(): CredentialsConfig
    {
        if ($this->credentialsConfig === null) {
            $this->credentialsConfig = $this->loadCredentials();
        }

        return $this->credentialsConfig;
    }

    /**
     * Charge les credentials depuis un fichier séparé ou la config principale
     */
    private function loadCredentials(): CredentialsConfig
    {
        $credFile = '/etc/sambaedu/credentials.conf';
        $creds = [];

        if (file_exists($credFile) && is_readable($credFile)) {
            $creds = parse_ini_file($credFile) ?: [];
        }

        $raw = $this->getRawConfig();

        return new CredentialsConfig(
            ldapAdminPassword: $creds['ldap_admin_passwd'] ?? $raw['ldap_admin_passwd'] ?? '',
            se4Key: $creds['se4_key'] ?? $raw['se4_key'] ?? '',
            se4PubKey: $creds['se4_pub_key'] ?? $raw['se4_pub_key'] ?? '',
            apiMasterKey: $creds['api_master_key'] ?? null,
        );
    }

    /**
     * Retourne le bridge vers les fonctions legacy
     * 
     * @deprecated Utiliser les méthodes typées autant que possible.
     *             Ce bridge est destiné à être supprimé progressivement.
     * 
     * @example
     * ```php
     * // Dernier recours : accès aux fonctions legacy
     * $legacyConfig = $config->legacy()->getConfig();
     * $user = search_user($legacyConfig, $login);
     * ```
     */
    public function legacy(): LegacyConfigBridge
    {
        if ($this->legacyBridge === null) {
            $this->legacyBridge = app(LegacyConfigBridge::class);
        }

        return $this->legacyBridge;
    }

    /**
     * Retourne le code établissement courant pour les requêtes LDAP
     * 
     * Méthode de commodité qui centralise la logique de récupération
     * du code établissement depuis la session ou la config.
     * 
     * @return string|null Code établissement ou null si domaine entier
     */
    public function getCurrentEstablishmentCode(): ?string
    {
        return $this->establishment()->getEffectiveCode();
    }

    /**
     * Vérifie la connexion à MariaDB
     * 
     * @return array{status: bool, message: string, details: array<string, mixed>}
     */
    public function checkMariaDbConnection(): array
    {
        try {
            $raw = $this->getRawConfig();

            // Vérifier que les informations de connexion sont disponibles
            if (!isset($raw['sql_passwd'])) {
                return [
                    'status' => false,
                    'message' => 'Mot de passe SQL non configuré',
                    'details' => [
                        'error' => 'sql_passwd manquant dans la configuration'
                    ]
                ];
            }

            // Tenter la connexion directe
            $host = 'localhost';
            $user = 'sambaedu';
            $password = $raw['sql_passwd'];
            $database = 'sambaedu';

            $db_link = @mysqli_connect($host, $user, $password, $database);

            if ($db_link === false || $db_link === null) {
                $error = mysqli_connect_error() ?? 'Erreur inconnue';
                $errno = mysqli_connect_errno() ?? 0;

                return [
                    'status' => false,
                    'message' => 'Connexion MariaDB échouée',
                    'details' => [
                        'error' => $error,
                        'errno' => $errno,
                        'host' => $host,
                        'user' => $user,
                        'database' => $database
                    ]
                ];
            }

            // Vérifier que la connexion est vraiment active
            if (!mysqli_ping($db_link)) {
                mysqli_close($db_link);
                return [
                    'status' => false,
                    'message' => 'Connexion MariaDB perdue',
                    'details' => [
                        'error' => 'La connexion ne répond pas'
                    ]
                ];
            }

            // Tester une requête simple
            $result = @mysqli_query($db_link, "SELECT 1");
            if ($result === false) {
                $error = mysqli_error($db_link);
                mysqli_close($db_link);
                return [
                    'status' => false,
                    'message' => 'Requête test échouée',
                    'details' => [
                        'error' => $error
                    ]
                ];
            }

            // Vérifier que la table connexions existe
            $tableCheck = @mysqli_query($db_link, "SHOW TABLES LIKE 'connexions'");
            $tableExists = $tableCheck && mysqli_num_rows($tableCheck) > 0;

            mysqli_close($db_link);

            return [
                'status' => true,
                'message' => 'Connexion MariaDB opérationnelle',
                'details' => [
                    'host' => $host,
                    'user' => $user,
                    'database' => $database,
                    'table_connexions' => $tableExists ? 'existe' : 'manquante'
                ]
            ];

        } catch (\Exception $e) {
            Log::error("Erreur lors de la vérification MariaDB: " . $e->getMessage());
            return [
                'status' => false,
                'message' => 'Erreur lors de la vérification',
                'details' => [
                    'error' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Vérifie rapidement si MariaDB est accessible
     */
    public function isMariaDbAvailable(): bool
    {
        $check = $this->checkMariaDbConnection();
        return $check['status'];
    }

    /**
     * Parse la valeur de strict_local_ad depuis la config ou l'environnement
     * Par défaut, le mode strict est ACTIVÉ pour la sécurité
     */
    private function parseStrictLocalAd(array $raw): bool
    {
        // Priorité 1: fichier de config SambaEdu
        if (isset($raw['strict_local_ad'])) {
            return $raw['strict_local_ad'] === '1' || $raw['strict_local_ad'] === 'true';
        }

        // Priorité 2: variable d'environnement Laravel (via config pour compatibilité cache)
        $envValue = config('sambaedu.strict_local_ad', true);
        if (is_string($envValue)) {
            return $envValue === '1' || strtolower($envValue) === 'true';
        }

        // Par défaut: mode strict activé
        return (bool) $envValue;
    }

    /**
     * Charge et retourne la configuration brute depuis les fichiers INI
     * 
     * @return array<string, mixed>
     */
    private function getRawConfig(): array
    {
        if (self::$rawConfig !== null) {
            return self::$rawConfig;
        }

        self::$rawConfig = [];

        // Charger le fichier principal
        if (file_exists(self::MAIN_CONFIG_FILE) && is_readable(self::MAIN_CONFIG_FILE)) {
            $mainConfig = parse_ini_file(self::MAIN_CONFIG_FILE);
            if ($mainConfig !== false) {
                self::$rawConfig = $mainConfig;
            }
        } else {
            Log::warning('SambaEduConfig: Fichier de configuration principal non trouvé', [
                'file' => self::MAIN_CONFIG_FILE,
            ]);
        }

        // Charger les fichiers de configuration supplémentaires
        if (is_dir(self::CONFIG_DIR)) {
            $files = glob(self::CONFIG_DIR . '/*.conf');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_readable($file)) {
                        $moduleConfig = parse_ini_file($file);
                        if ($moduleConfig !== false) {
                            self::$rawConfig = array_merge(self::$rawConfig, $moduleConfig);
                        }
                    }
                }
            }
        }

        return self::$rawConfig;
    }
}
