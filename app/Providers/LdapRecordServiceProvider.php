<?php

namespace App\Providers;

use App\Config\SambaEduConfig;
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
        try {
            // Obtenir la configuration via SambaEduConfig
            /** @var SambaEduConfig $config */
            $config = app(SambaEduConfig::class);
            $ldapConfig = $config->ldap();

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
}

