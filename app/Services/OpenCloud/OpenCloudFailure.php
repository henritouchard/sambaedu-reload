<?php

declare(strict_types=1);

namespace App\Services\OpenCloud;

/**
 * LES ÉCHECS NETS, NOMMÉS ET DISTINCTS.
 *
 * La mesure du 2026-08-13 a relevé qu'OpenCloud distingue nettement ses refus,
 * et qu'il les rend en `4xx` avec un `error.code` applicatif. Les agréger en un
 * seul « ça n'a pas marché » ferait perdre exactement l'information dont
 * l'exploitant a besoin : un privilège manquant se corrige sur l'instance, une
 * cible absente se corrige dans SE5, une instance injoignable se corrige sur le
 * réseau ou par le déploiement. Trois gestes différents — donc des cas distincts.
 *
 * « Déjà conforme » **n'est pas ici** : ce n'est pas un échec, c'est un état, et
 * il se dit par {@see OpenCloudResult::conforming()}. C'est le piège symétrique
 * de celui de l'autre produit : là-bas un `200` enveloppait un refus, ici un
 * `409` enveloppe un SUCCÈS. Le croire ferait rapporter rouge un état conforme,
 * et un rejeu ne convergerait jamais.
 */
enum OpenCloudFailure: string
{
    /** 401/403 — le compte n'a pas le niveau exigé (administrateur de l'instance). */
    case Privilege = 'privilege';

    /** 404 — la cible nommée n'existe pas côté instance. */
    case Absent = 'cible_absente';

    /** Réseau, DNS, TLS, délai dépassé : on n'a pas parlé à l'instance. */
    case Injoignable = 'injoignable';

    /** L'instance a répondu, et elle a refusé (4xx/5xx hors des cas ci-dessus). */
    case Refus = 'refus';

    /** L'instance a répondu autre chose que ce que le protocole promet. */
    case Illisible = 'reponse_illisible';

    /**
     * **Mesuré le 2026-08-13.** Le modèle de rôles d'OpenCloud est SCINDÉ par
     * ressource : la racine d'un espace et un sous-dossier acceptent deux
     * familles disjointes, où deux rôles homonymes portent des identifiants
     * différents. Employer l'identifiant de l'autre famille rend
     * `400 « role not applicable to this resource »`.
     *
     * Il MÉRITE son propre cas parce que sa correction est ailleurs que les
     * autres : ni le réseau, ni le compte, ni la cible — c'est une erreur de
     * TRADUCTION, donc un défaut de SE5, et le confondre avec un « refus de
     * l'instance » enverrait l'exploitant chercher sur son serveur ce qui est
     * dans notre code.
     */
    case RoleInapplicable = 'role_inapplicable';

    /**
     * Libellé destiné à l'exploitant. Aucun code de transport n'y figure : le code
     * vit dans le résultat, pour le journal, pas dans la phrase.
     */
    public function label(): string
    {
        return match ($this) {
            self::Privilege => 'privilège insuffisant',
            self::Absent => 'cible absente côté instance',
            self::Injoignable => 'instance injoignable',
            self::Refus => 'refus de l\'instance',
            self::Illisible => 'réponse illisible',
            self::RoleInapplicable => 'rôle inapplicable à cette ressource',
        };
    }
}
