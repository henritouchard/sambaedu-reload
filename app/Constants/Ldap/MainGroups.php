<?php

namespace App\Constants\Ldap;

/**
 * Constantes pour les groupes principaux SambaEdu
 * 
 * Ces groupes sont utilisés pour filtrer les utilisateurs selon l'approche legacy :
 * seuls les utilisateurs membres de ces groupes sont considérés comme des utilisateurs valides
 */
class MainGroups
{
    /**
     * Nom du groupe des élèves
     */
    public const ELEVES = 'Eleves';
    
    /**
     * Nom du groupe des professeurs
     */
    public const PROFS = 'Profs';
    
    /**
     * Nom du groupe des personnels administratifs
     */
    public const ADMINISTRATIFS = 'Administratifs';
    
    /**
     * Comptes système à exclure des listes d'utilisateurs
     * 
     * Ces comptes peuvent être membres des groupes principaux mais ne doivent pas
     * apparaître dans les listes d'utilisateurs car ce sont des comptes techniques/administrateurs
     * 
     * Basé sur le code legacy dans includes/ent.inc.php ligne 2200 qui marque comme "protected"
     * les comptes contenant : admin, exam, invite, test, api-
     * + comptes système spécifiques SambaEdu
     */
    public const SYSTEM_ACCOUNTS = [
        'se4install',      // Compte d'installation automatique SambaEdu
        'Administrator',   // Compte administrateur Windows
        'admin',           // Compte admin générique
        'krbtgt',          // Compte de service Kerberos
        'Guest',           // Compte invité Windows
        'www-sambaedu',    // Compte web service SambaEdu
        'Actif',           // Compte système
    ];
    
    /**
     * Patterns regex pour détecter les comptes système (basé sur le legacy)
     * 
     * Correspond au pattern dans includes/ent.inc.php ligne 2200
     * qui marque comme "protected" les comptes contenant : admin, exam, invite, test, api-
     */
    private const SYSTEM_ACCOUNT_PATTERNS = [
        '/.*admin.*/i',
        '/.*exam.*/i',
        '/.*invite.*/i',
        '/.*test.*/i',
        '/.*api-.*/i',
    ];
    
    /**
     * Liste de tous les groupes principaux
     * 
     * @return array
     */
    public static function all(): array
    {
        return [
            self::ELEVES,
            self::PROFS,
            self::ADMINISTRATIFS,
        ];
    }
    
    /**
     * Vérifie si un nom de groupe est un groupe principal
     * 
     * @param string $groupName
     * @return bool
     */
    public static function isMainGroup(string $groupName): bool
    {
        return in_array($groupName, self::all(), true);
    }
    
    /**
     * Vérifie si un login correspond à un compte système à exclure
     * 
     * Vérifie d'abord la liste exacte, puis les patterns regex (comme dans le legacy)
     * 
     * @param string $login
     * @return bool
     */
    public static function isSystemAccount(string $login): bool
    {
        // Vérification exacte
        if (in_array($login, self::SYSTEM_ACCOUNTS, true)) {
            return true;
        }
        
        // Vérification par patterns regex (comme dans le legacy)
        foreach (self::SYSTEM_ACCOUNT_PATTERNS as $pattern) {
            if (preg_match($pattern, $login)) {
                return true;
            }
        }
        
        return false;
    }
}

