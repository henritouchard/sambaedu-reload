<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nature d'un poste de travail vue par un parc (Story 26.1, FR28).
 *
 * Déclarée **par parc** (groupe logique OU physique) sur `workstation_groups`,
 * cette donnée du domaine pilotera le comportement des handlers de l'Epic 27
 * (bureau, profils navigateur, `clean_profiles`, raccourcis 27.1, profils 27.4).
 *
 * - `SharedLocal` (`shared_local`) : poste **partagé**. Bureau réseau, profils
 *   redirigés — c'est le défaut implicite du parc historique (salle de classe).
 * - `PersonalLocal` (`personal_local`) : modèle **perdir / direction**. Bureau
 *   local à l'utilisateur, données sur le home réseau (poste nominatif mais pas
 *   déconnecté).
 * - `Nomade` (`nomade`) : **tout local avec synchronisation** (offline / resync,
 *   réalisé en Story 26.2). Le poste fonctionne déconnecté puis réconcilie.
 *
 * Identifiants figés (NFR12) : une valeur publiée ne se renomme jamais — ils
 * peuvent être persistés en base et lus par les handlers de l'Epic 27.
 *
 * ⚠️ AUCUNE méthode de rang / précédence ici (parallèle exact à `StateMaille` :
 * « AUCUNE méthode de rang »). Un poste appartient à N parcs ; résoudre UN
 * environnement (précédence `nomade > personal_local > shared_local`, défaut
 * `shared_local`) est le travail du `WorkstationEnvironmentResolver` SEUL —
 * dupliquer la précédence ici la ferait fuiter vers les consommateurs.
 * Seule une `label()` lisible UI vit sur l'enum.
 */
enum WorkstationEnvironment: string
{
    case SharedLocal = 'shared_local';
    case PersonalLocal = 'personal_local';
    case Nomade = 'nomade';

    /**
     * Libellé humain pour le `<select>` d'édition de l'environnement d'un parc
     * (formulaire d'édition de groupe). Pas de logique métier — pure présentation.
     */
    public function label(): string
    {
        return match ($this) {
            self::SharedLocal => 'Partagé (bureau réseau, profils redirigés)',
            self::PersonalLocal => 'Personnel (bureau local, home réseau)',
            self::Nomade => 'Nomade (tout local avec synchronisation)',
        };
    }

    /**
     * Libellé compact pour les contextes contraints (badge de la fiche groupe,
     * entrées du menu d'action groupée) où la `label()` complète est trop longue.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::SharedLocal => 'Partagé',
            self::PersonalLocal => 'Personnel',
            self::Nomade => 'Nomade',
        };
    }

    /**
     * Icône FontAwesome (glyphe seul, sans couleur) pour les contrôles d'édition
     * et les badges. Pure présentation — parallèle à {@see label()}.
     */
    public function icon(): string
    {
        return match ($this) {
            self::SharedLocal => 'fa-users',
            self::PersonalLocal => 'fa-user',
            self::Nomade => 'fa-laptop',
        };
    }

    /**
     * Description courte affichée en tooltip à côté de chaque option du sélecteur
     * d'environnement. Pure présentation (reprend la doc de l'enum) — aucune
     * sémantique de précédence, qui reste l'affaire du WorkstationEnvironmentResolver.
     */
    public function description(): string
    {
        return match ($this) {
            self::SharedLocal => 'Poste partagé (salle de classe) : bureau réseau et profils redirigés. Défaut du parc historique.',
            self::PersonalLocal => 'Modèle direction / perdir : bureau local à l\'utilisateur, données sur le home réseau. Poste nominatif mais connecté.',
            self::Nomade => 'Tout local avec synchronisation : le poste fonctionne déconnecté puis réconcilie (offline / resync).',
        };
    }
}
