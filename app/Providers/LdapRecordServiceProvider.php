<?php

namespace App\Providers;

use App\Config\SambaEduConfig;
use App\Ldap\Fakes\ThrowingLdapConnection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use LdapRecord\Container;
use LdapRecord\Connection;

/**
 * Service Provider pour la configuration de LdapRecord
 * 
 * Utilise SambaEduConfig pour obtenir les paramètres LDAP
 * et configure la connexion LdapRecord.
 */
class LdapRecordServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Story 21.2 (T1, AC3) — GARDE-FOU STRUCTUREL anti-AD-réel en e2e.
        // En `e2e`, on n'enregistre JAMAIS la vraie connexion réseau LdapRecord :
        // on installe une connexion PIÉGÉE qui lève une exception explicite dès
        // qu'un chemin tente une opération LDAP réelle (`getLdapConnection()` /
        // `connect()`). Le vrai `samba-ad-dc` est ainsi STRUCTURELLEMENT
        // inatteignable. Les chemins e2e légitimes (auth fake, capture
        // samba-tool fake) n'utilisent pas cette connexion. Garde-fou = CODE,
        // pas config (doctrine 21.1).
        //
        // `testing` (PHPUnit/SQLite) reste INCHANGÉ : il n'entre pas dans cette
        // branche et n'a de toute façon pas de `/etc/sambaedu/*` (la connexion
        // réelle ci-dessous s'auto-désactive faute de base DN).
        if ($this->app->environment('e2e')) {
            $this->registerE2eThrowingConnection();
            return;
        }

        try {
            // Obtenir la configuration via SambaEduConfig
            /** @var SambaEduConfig $config */
            $config = app(SambaEduConfig::class);
            $ldapConfig = $config->ldap();

            // Fix install-debian — backfill des clés `sambaedu.*` consommées par
            // le preseed iPXE (LinuxPreseedService), l'install Windows, le
            // WorkstationAdSyncJob, le GPO assembler, le JWT issuer, etc. depuis
            // la source unique `/etc/sambaedu/sambaedu.conf`. Évite de dupliquer
            // les coordonnées AD dans le `.env` (SAMBAEDU_DOMAIN, _PASSWD…) :
            // l'override `.env` reste prioritaire (on ne remplit que si vide).
            $this->bridgeSambaEduConfigToLaravel($config, $ldapConfig);

            // Vérifier les paramètres essentiels
            if (empty($ldapConfig->baseDn) || empty($ldapConfig->domain)) {
                Log::warning('LdapRecordServiceProvider: Paramètres LDAP essentiels manquants', [
                    'has_base_dn' => !empty($ldapConfig->baseDn),
                    'has_domain' => !empty($ldapConfig->domain),
                ]);
                return;
            }

            // Créer la configuration de connexion LdapRecord via le DTO
            $connectionConfig = $ldapConfig->toLdapRecordConfig();

            // Stocker les DN de base dans la config Laravel
            // NOTE: Les DN spécifiques (people, groups, etc.) sont calculés dynamiquement
            // via LdapDnHelper pour prendre en compte l'établissement courant
            config([
                'sambaedu.ldap.domain' => $ldapConfig->domain,
                'sambaedu.ldap.base_dn' => $ldapConfig->baseDn,
                // DN de la corbeille (global, pas lié à un établissement)
                'sambaedu.ldap.trash_dn' => $ldapConfig->trashRdn,
            ]);

            // Créer la connexion LdapRecord
            $connection = new Connection($connectionConfig);

            // Ajouter la connexion au container LdapRecord
            Container::addConnection($connection, 'default');

            // Définir comme connexion par défaut
            Container::setDefaultConnection('default');
        } catch (\Exception $e) {
            Log::error('LdapRecordServiceProvider: Erreur lors de la configuration LDAP', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Garde-fou structurel e2e (AC3) : installe une connexion LdapRecord piégée
     * comme connexion par défaut. Toute opération LDAP réelle lèvera alors une
     * exception explicite ({@see ThrowingLdapConnection}).
     */
    private function registerE2eThrowingConnection(): void
    {
        try {
            // Config minimale : la connexion n'est JAMAIS ouverte (toute tentative
            // d'I/O lève). Les hosts/base_dn sont des placeholders inertes.
            $connection = new ThrowingLdapConnection([
                'hosts' => ['127.0.0.1'],
                'base_dn' => 'dc=e2e,dc=local',
                'username' => '',
                'password' => '',
                'port' => 389,
                'use_ssl' => false,
                'use_tls' => false,
            ]);

            Container::addConnection($connection, 'default');
            Container::setDefaultConnection('default');

            Log::info('LdapRecordServiceProvider: connexion PIÉGÉE e2e installée (AD réel interdit)');
        } catch (\Throwable $e) {
            // En e2e on préfère échouer bruyamment : si même la connexion piégée
            // ne s'installe pas, on laisse l'erreur remonter (aucune connexion
            // réelle ne doit jamais prendre sa place).
            Log::error('LdapRecordServiceProvider: échec installation connexion piégée e2e', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Backfill des clés `config('sambaedu.*')` à partir de SambaEduConfig
     * (lecture `/etc/sambaedu/sambaedu.conf` + `credentials.conf`).
     *
     * **Précédence** : un override explicite via `.env` (clés `SAMBAEDU_*`,
     * `SE4FS_*`, `SE4AD_*` mappées dans `config/sambaedu.php`) reste
     * prioritaire — on ne remplit une clé QUE si sa valeur courante est vide.
     * Ainsi les coordonnées AD (domaine, base DN, mots de passe, IP/noms
     * se4ad/se4fs) n'ont plus à être saisies à la main dans le `.env` : elles
     * dérivent de la même source que la connexion LDAP de SE5.
     *
     * Les clés à défaut NON vide dans `config/sambaedu.php` (ex. `computers_rdn`
     * = `CN=Computers`, `admin_rdn` = `CN=Users`, `ldap_admin_name`,
     * `ldap_port`) ne sont donc jamais écrasées ici — comportement voulu.
     *
     * @param  \App\Config\LdapConfig  $ldap  DTO LDAP déjà résolu (évite un 2e parse).
     */
    private function bridgeSambaEduConfigToLaravel(SambaEduConfig $config, $ldap): void
    {
        // Aucun `/etc/sambaedu/sambaedu.conf` (tests, CI, poste de dev fraîche) :
        // on ne backfill PAS — sinon les défauts non vides des DTO
        // (`NetworkConfig::se4fsName = 'se4fs'`, etc.) fuiteraient dans la
        // config et fausseraient les tests qui attendent une valeur vide.
        if ($config->all() === []) {
            return;
        }

        try {
            $network = $config->network();
            $credentials = $config->credentials();
        } catch (\Throwable $e) {
            // `/etc/sambaedu/*` absent (dev/CI) : on laisse les valeurs
            // `.env`/défaut en place, rien à backfiller.
            return;
        }

        $derived = [
            'sambaedu.domain' => $ldap->domain,
            'sambaedu.samba_domain' => $ldap->sambaDomain,
            'sambaedu.ldap_base_dn' => $ldap->baseDn,
            'sambaedu.ldap_admin_name' => $ldap->adminName,
            'sambaedu.ldap_admin_passwd' => $ldap->adminPassword,
            'sambaedu.ldap_port' => (string) $ldap->port,
            'sambaedu.computers_rdn' => $ldap->computersRdn,
            'sambaedu.admin_rdn' => $ldap->adminRdn,
            // `admin_passwd` (clé conf distincte) avec repli sur le mdp LDAP admin.
            'sambaedu.admin_passwd' => (string) $config->get('admin_passwd', $ldap->adminPassword),
            // Canal de dépôt SambaEdu : conf `depot_type`, repli `se4XP` (seul
            // canal complet pour une install jointe AD ; `main`/`stable` cassent
            // pkgsel sur winbind/ad-dc). `.env` explicite reste prioritaire.
            'sambaedu.linux.depot_type' => ((string) $config->get('depot_type', '')) ?: 'se4XP',
            'sambaedu.se4ad_ip' => $network->se4adIp,
            'sambaedu.se4ad_name' => $network->se4adName,
            'sambaedu.se4fs_ip' => $network->se4fsIp,
            'sambaedu.se4fs_name' => $network->se4fsName,
            'sambaedu.se4_pub_key' => $credentials->se4PubKey,
            // Mot de passe du compte de service `se4install` (Domain Admin utilisé
            // pour les installs auto). Le legacy le GÉNÈRE aléatoirement à la
            // première install et le persiste dans `sambaedu.conf` (section
            // `clients`) — il n'existe pas de défaut statique. On le dérive donc
            // d'ici ; un `SE4INSTALL_PASSWD` explicite dans `.env` reste
            // prioritaire (le backfill ne s'applique que si la config est vide).
            'sambaedu.se4install_passwd' => (string) $config->get('se4install_passwd', ''),
        ];

        $apply = [];
        foreach ($derived as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $current = config($key);
            if ($current === null || $current === '') {
                $apply[$key] = $value;
            }
        }

        if ($apply !== []) {
            config($apply);
        }
    }
}

