<?php

declare(strict_types=1);

namespace App\Support\Help;

use App\Enums\SambaPermission;

/**
 * Registre du contenu how-to du Guide des fonctionnalités (Story 40.1).
 *
 * ============================================================================
 * Rôle
 * ============================================================================
 * Source AUTHORED (versionnée dans le code, JAMAIS en base — cf. décision de
 * cadrage 40.1 « pages statiques ») du contenu pas-à-pas de chaque
 * fonctionnalité de SambaEdu. Chaque entrée est indexée par la valeur d'un
 * {@see SambaPermission} et fournit :
 *  - `objective` : une phrase décrivant le but de la fonctionnalité ;
 *  - `steps`     : les étapes numérotées concrètes pour la réaliser ;
 *  - `route`     : le NOM de route Laravel de la vraie page (résolu par la vue
 *                  via `route(...)`, jamais ici, pour ne pas dépendre du routeur
 *                  au chargement de la classe) ;
 *  - `routeLabel`: le libellé du lien vers cette page.
 *
 * ============================================================================
 * Ancrage sur l'enum (AC6 — pas de duplication de libellés)
 * ============================================================================
 * Ce registre NE DUPLIQUE PAS `SambaPermission::label()`, `::category()` ni
 * `::categoryLabel()` : l'intitulé de la fonctionnalité et son rattachement au
 * domaine restent portés par l'enum. On n'écrit ici QUE le contenu pédagogique
 * (objectif + étapes + lien). Une permission SANS entrée reste parfaitement
 * listable : le composant d'affichage retombe alors sur un fallback
 * « Guide à venir » (les 24 permissions demeurent exhaustives dans le hub).
 *
 * ============================================================================
 * Périmètre 40.1
 * ============================================================================
 * Seul le domaine pilote `user` (6 permissions) est documenté. Les stories
 * 40.2, 40.3… enrichiront ce registre domaine par domaine en réutilisant la
 * même structure.
 */
final class FeatureGuideRegistry
{
    /**
     * Retourne le how-to d'une permission, ou `null` si non encore rédigé.
     *
     * @return array{objective: string, steps: string[], route: ?string, routeLabel: ?string}|null
     */
    public static function forPermission(SambaPermission $permission): ?array
    {
        return self::all()[$permission->value] ?? null;
    }

    /** Indique si un how-to a été rédigé pour cette permission. */
    public static function has(SambaPermission $permission): bool
    {
        return isset(self::all()[$permission->value]);
    }

    /**
     * Source de vérité DATA-DRIVEN des domaines documentés (= disposant d'une
     * page domaine dédiée dans le Guide). Indexée par CATÉGORIE de permission,
     * valeur = NOM de route Laravel de la page domaine correspondante.
     *
     * Le hub (`pages/guide/index.blade.php`) s'appuie sur cette table pour
     * décider quelles cartes sont cliquables (Story 40.2, AC4) : un domaine est
     * « disponible » s'il figure ici ET que sa route existe (`Route::has(...)`).
     * Les stories 40.3+ n'ont qu'à AJOUTER une ligne ici (aucune logique à
     * réécrire dans le hub).
     *
     * Clés ancrées sur `SambaPermission::category()` (pas de littéraux `'user'`
     * / `'computer'`) pour rester couplées à l'enum si ces valeurs évoluent.
     *
     * @return array<string, string> catégorie => nom de route de la page domaine
     */
    public static function documentedDomains(): array
    {
        return [
            // Domaine pilote « Utilisateurs » (Story 40.1).
            SambaPermission::UserRead->category()     => 'app.guide.utilisateurs',
            // Domaine « Machines » (Story 40.2).
            SambaPermission::ComputerView->category() => 'app.guide.machines',
        ];
    }

    /**
     * Catalogue complet des how-to rédigés (indexé par valeur de permission).
     *
     * @return array<string, array{objective: string, steps: string[], route: ?string, routeLabel: ?string}>
     */
    public static function all(): array
    {
        // Catalogue authored constant sur la durée du process : on le construit
        // une seule fois (la page appelle `forPermission()` une fois par
        // permission du domaine).
        static $cache = null;

        return $cache ??= [
            // ================================================================
            // Domaine « Utilisateurs » (catégorie `user`) — pilote 40.1
            // ================================================================
            SambaPermission::UserRead->value => [
                'objective' => "Consulter l'annuaire des comptes de l'établissement et le détail d'un utilisateur.",
                'steps' => [
                    "Ouvrez la page « Utilisateurs » depuis le menu latéral.",
                    "Utilisez la barre de recherche pour filtrer par nom, prénom ou identifiant.",
                    "Appliquez au besoin les filtres d'audit (quota dépassé, mot de passe par défaut, profil itinérant volumineux).",
                    "Cliquez sur une ligne pour ouvrir la fiche détaillée du compte (classes, groupes, quota, activité).",
                ],
                'route' => 'app.users',
                'routeLabel' => 'Ouvrir la liste des utilisateurs',
            ],
            SambaPermission::UserPasswordInit->value => [
                'objective' => "Réinitialiser le mot de passe d'un ou plusieurs comptes (élèves ou personnels).",
                'steps' => [
                    "Ouvrez la page « Utilisateurs ».",
                    "Sélectionnez le ou les comptes concernés à l'aide des cases à cocher.",
                    "Choisissez l'action « Réinitialiser le mot de passe » dans la barre d'actions groupées.",
                    "Confirmez : les nouveaux mots de passe sont générés, puis récupérables via l'export PDF/CSV temporaire proposé.",
                ],
                'route' => 'app.users',
                'routeLabel' => 'Ouvrir la liste des utilisateurs',
            ],
            SambaPermission::UserModify->value => [
                'objective' => "Modifier les informations d'un compte existant (identité, classes, groupes, activation).",
                'steps' => [
                    "Ouvrez la page « Utilisateurs » puis la fiche du compte à modifier.",
                    "Mettez à jour les champs souhaités (nom, courriel, description, classe, groupes).",
                    "Activez ou désactivez le compte si nécessaire.",
                    "Enregistrez : les changements sont propagés vers l'annuaire Active Directory.",
                ],
                'route' => 'app.users',
                'routeLabel' => 'Ouvrir la liste des utilisateurs',
            ],
            SambaPermission::UserCreateTemp->value => [
                'objective' => "Créer un ou plusieurs comptes temporaires (stagiaires, remplaçants, invités).",
                'steps' => [
                    "Depuis la page « Utilisateurs », cliquez sur « Nouvel utilisateur ».",
                    "Renseignez l'identité et le profil du compte à créer.",
                    "Affectez-le à une classe et/ou aux groupes appropriés.",
                    "Validez la création : le compte et son répertoire personnel sont provisionnés.",
                ],
                'route' => 'app.users.new',
                'routeLabel' => 'Créer un utilisateur',
            ],
            SambaPermission::UserAssignRight->value => [
                'objective' => "Attribuer un profil de droits (rôle) à un utilisateur et gérer les profils applicatifs.",
                'steps' => [
                    "Ouvrez la page « Gestion des droits » depuis le menu latéral.",
                    "Dans l'onglet « Profils », consultez ou créez le profil de droits voulu.",
                    "Dans l'onglet « Recherche utilisateur », sélectionnez le compte à habiliter.",
                    "Assignez-lui le profil correspondant : ses permissions applicatives sont mises à jour immédiatement.",
                ],
                'route' => 'app.rights-management',
                'routeLabel' => 'Ouvrir la gestion des droits',
            ],
            SambaPermission::UserDelegate->value => [
                'objective' => "Déléguer un droit à un utilisateur sur un périmètre précis (parc de machines).",
                'steps' => [
                    "Ouvrez la page « Gestion des droits ».",
                    "Placez-vous dans l'onglet « Délégations ».",
                    "Choisissez l'utilisateur bénéficiaire, la permission à déléguer et le parc concerné.",
                    "Validez : la délégation périmétrée est enregistrée et tracée dans l'historique.",
                ],
                'route' => 'app.rights-management',
                'routeLabel' => 'Ouvrir la gestion des droits',
            ],

            // ================================================================
            // Domaine « Machines » (catégorie `computer`) — Story 40.2
            // ================================================================
            // Toutes les entrées pointent vers `app.parc.index` (gestion du
            // parc, sans paramètre d'URL) : la fiche machine `app.parc.machines.show`
            // exige un `{id}` — inutilisable en lien générique depuis un guide.
            SambaPermission::ComputerView->value => [
                'objective' => "Consulter l'inventaire des postes du parc et le détail d'une machine (état, groupe, session).",
                'steps' => [
                    "Ouvrez la page « Parc » depuis le menu latéral.",
                    "Placez-vous dans l'onglet « Machines » pour parcourir l'inventaire.",
                    "Filtrez par groupe de machines, nom ou état de connexion pour retrouver un poste.",
                    "Cliquez sur une ligne pour ouvrir la fiche détaillée du poste (matériel, session en cours, historique).",
                ],
                'route' => 'app.parc.index',
                'routeLabel' => 'Ouvrir la gestion du parc',
            ],
            SambaPermission::ComputerControl->value => [
                'objective' => "Prendre la main à distance sur un poste pour assister un utilisateur ou diagnostiquer un incident.",
                'steps' => [
                    "Ouvrez la page « Parc », onglet « Machines ».",
                    "Sélectionnez le poste à dépanner pour ouvrir sa fiche détaillée.",
                    "Lancez le contrôle à distance depuis les actions de la machine.",
                    "Assistez l'utilisateur, puis refermez la session de contrôle une fois l'intervention terminée.",
                ],
                'route' => 'app.parc.index',
                'routeLabel' => 'Ouvrir la gestion du parc',
            ],
            SambaPermission::ComputerElevate->value => [
                'objective' => "Accorder temporairement les droits d'administrateur local à un utilisateur sur un poste.",
                'steps' => [
                    "Ouvrez la page « Parc » et repérez le poste concerné (ou son groupe de machines).",
                    "Ouvrez la fiche du poste, ou éditez le groupe de machines pour un réglage collectif.",
                    "Activez l'option « Admin de poste » pour l'utilisateur ciblé.",
                    "Validez : l'élévation est propagée au poste (une synchronisation GPO peut être nécessaire).",
                ],
                'route' => 'app.parc.index',
                'routeLabel' => 'Ouvrir la gestion du parc',
            ],
            SambaPermission::ComputerInstall->value => [
                'objective' => "Installer ou réinstaller un poste et l'enrôler dans un groupe de machines du parc.",
                'steps' => [
                    "Ouvrez la page « Parc » et créez au besoin le groupe de machines cible.",
                    "Préparez le poste au démarrage réseau (amorçage iPXE) pour lancer l'installation.",
                    "Suivez le déroulé de l'installation, puis rattachez le poste au groupe de machines voulu.",
                    "Vérifiez que le poste remonte bien dans l'inventaire du parc une fois provisionné.",
                ],
                'route' => 'app.parc.index',
                'routeLabel' => 'Ouvrir la gestion du parc',
            ],
            SambaPermission::ComputerRemoteRdp->value => [
                'objective' => "Autoriser l'accès en Bureau à distance (RDP) sur un poste ou un groupe de machines.",
                'steps' => [
                    "Ouvrez la page « Parc » et sélectionnez le poste ou éditez le groupe de machines.",
                    "Ouvrez le réglage « Bureau à distance (RDP) ».",
                    "Autorisez ou refusez l'accès RDP pour le périmètre choisi selon la politique de l'établissement.",
                    "Validez : la règle est appliquée aux postes concernés à la prochaine synchronisation.",
                ],
                'route' => 'app.parc.index',
                'routeLabel' => 'Ouvrir la gestion du parc',
            ],
        ];
    }
}
