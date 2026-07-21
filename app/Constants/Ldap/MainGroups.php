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
     * Comptes réservés : logins que SambaEdu s'attribue et qui ne désignent
     * jamais une personne. Comparaison par ÉGALITÉ (insensible à la casse).
     *
     * Le legacy (`includes/ent.inc.php:2447`) détectait ces comptes par
     * sous-chaîne non ancrée — motifs « admin », « exam », « invite »,
     * « test ». Ces motifs capturaient de vrais patronymes :
     * « badminton.leo » contient « admin », « examine.paul » contient « exam ».
     * Un tel compte était alors exclu de la SYNCHRO AD→SQL
     * (`UserSyncService`) — donc sans ligne `users`, sans rôle Spatie, cassé
     * partout — en plus d'être invisible dans les listes.
     *
     * D'où la liste explicite : on ne peut réserver un login qu'en le nommant.
     */
    public const SYSTEM_ACCOUNTS = [
        'se4install',      // Compte d'installation automatique SambaEdu
        'Administrator',   // Compte administrateur Windows
        'admin',           // Compte admin générique
        'krbtgt',          // Compte de service Kerberos
        'Guest',           // Compte invité Windows
        'invite',          // Compte invité (nom francisé)
        'exam',            // Compte de session d'examen
        'test',            // Compte de test générique
        'www-sambaedu',    // Compte web service SambaEdu
        'Actif',           // Compte système
    ];

    /**
     * Préfixes réservés aux comptes techniques.
     *
     * ANCRÉS en début de login, contrairement aux motifs legacy : un préfixe ne
     * peut donc pas être capturé au milieu d'un patronyme. C'est une convention
     * de nommage assumée — tout compte créé sous ce préfixe est technique.
     */
    public const SYSTEM_ACCOUNT_PREFIXES = [
        'api-',
    ];

    /**
     * Comptes de service : ils n'ouvrent JAMAIS de session interactive.
     *
     * À ne pas confondre avec `SYSTEM_ACCOUNTS` / `isSystemAccount()`, qui
     * traduit le flag `protected` du legacy (`includes/ent.inc.php`, classement
     * pour la corbeille) : celui-ci protège de la SUPPRESSION, il ne dit rien de
     * la visibilité. Un écran qui attribue quelque chose à une session
     * (raccourci, lecteur réseau…) doit filtrer sur CETTE liste-ci : `admin` a
     * une vraie session et doit rester attribuable, `krbtgt` non.
     */
    public const NON_INTERACTIVE_ACCOUNTS = [
        'krbtgt',          // Compte de service Kerberos
        'www-sambaedu',    // Compte web service SambaEdu
        'Guest',           // Compte invité Windows (désactivé)
        'Actif',           // Compte système
    ];

    /**
     * Le compte n'ouvre jamais de session : rien ne peut lui être attribué.
     */
    public static function isNonInteractiveAccount(string $login): bool
    {
        foreach (self::NON_INTERACTIVE_ACCOUNTS as $account) {
            if (strcasecmp($login, $account) === 0) {
                return true;
            }
        }

        $lowered = strtolower($login);
        foreach (self::SYSTEM_ACCOUNT_PREFIXES as $prefix) {
            if (str_starts_with($lowered, strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

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
     * Vérifie si un login est un compte réservé SambaEdu.
     *
     * Égalité insensible à la casse sur `SYSTEM_ACCOUNTS`, plus les préfixes
     * ancrés de `SYSTEM_ACCOUNT_PREFIXES`. Aucune correspondance par
     * sous-chaîne : un patronyme ne doit jamais être capturé par accident
     * (cf. le commentaire de `SYSTEM_ACCOUNTS`).
     */
    public static function isSystemAccount(string $login): bool
    {
        foreach (self::SYSTEM_ACCOUNTS as $account) {
            if (strcasecmp($login, $account) === 0) {
                return true;
            }
        }

        $lowered = strtolower($login);
        foreach (self::SYSTEM_ACCOUNT_PREFIXES as $prefix) {
            if (str_starts_with($lowered, strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }
}

