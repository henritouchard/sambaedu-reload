# Configuration SambaEdu

## Accès à la configuration

Utiliser la facade `SEConfig` pour accéder à la configuration de l'ensemble des éléments de config (Ldap, Établissement, Réseau, Sécurité, Politique MDP, Identifiants, Legacy...) :

```php
use App\Facades\SEConfig;

// Accès aux objets de config typés
$ldapUrl = SEConfig::ldap()->url;
$baseDn = SEConfig::ldap()->baseDn;
$etabCode = SEConfig::establishment()->currentCode;
$minLength = SEConfig::passwordPolicy()->minLength;

// Accès direct à une clé
$value = SEConfig::get('ma_cle', 'valeur_par_defaut');

// Vérifier l'existence d'une clé
if (SEConfig::has('ma_cle')) { ... }

// Récupérer toute la config brute
$all = SEConfig::all();
```

## Objets disponibles

| Méthode | Objet | Description |
|---------|-----|-------------|
| `SEConfig::ldap()` | `LdapConfig` | Connexion LDAP/AD, DN, RDN |
| `SEConfig::establishment()` | `EstablishmentConfig` | UAI, nom établissement |
| `SEConfig::network()` | `NetworkConfig` | IP, domaine, proxy |
| `SEConfig::security()` | `SecurityConfig` | Paramètres sécurité |
| `SEConfig::passwordPolicy()` | `PasswordPolicyConfig` | Règles mots de passe |
| `SEConfig::credentials()` | `CredentialsConfig` | Identifiants sensibles |
| `SEConfig::legacy()` | `LegacyConfigBridge` | Compatibilité code legacy |

## Méthodes helper de LdapConfig

```php
// Construire des DN complets
SEConfig::ldap()->etablissementDn('0123456A');  // CN=0123456A,OU=Etablissements,...
SEConfig::ldap()->peopleDn();                    // OU=People,...
SEConfig::ldap()->groupsDn();                    // OU=Groups,...
SEConfig::ldap()->classesDn();                   // OU=Classes,OU=Groups,...
SEConfig::ldap()->trashDn();                     // OU=Trash,...
```

## Source des données

La configuration est chargée depuis :
1. `/etc/sambaedu/sambaedu.conf` (fichier principal)
2. `/etc/sambaedu/sambaedu.conf.d/*.conf` (fichiers additionnels)

## Compatibilité legacy

Pour le code legacy nécessitant le tableau `$config` :

```php
// Dernier recours uniquement
$legacyConfig = SEConfig::legacy()->getConfig();
```

## Fichiers

- `app/Facades/SEConfig.php` - Facade
- `app/Config/SambaEduConfig.php` - Classe principale
- `app/Config/*.php` - Objets Config (LdapConfig, EstablishmentConfig, etc.)
