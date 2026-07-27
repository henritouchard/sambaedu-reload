---
title: Je viens de l'ancienne interface
description: Correspondance entre les tâches de l'ancienne interface (SE4) et leur équivalent dans SE5, domaine par domaine.
---

# Je viens de l'ancienne interface

Cette page s'adresse à l'administrateur qui connaissait déjà l'ancienne
interface (SE4) et cherche ses repères dans SE5. Retrouvez votre tâche
habituelle dans les tableaux ci-dessous (`Ctrl+F` sur un mot du menu SE4 que
vous connaissez) : chaque ligne indique où et comment ce geste se fait
aujourd'hui, avec un lien vers la fiche détaillée qui l'explique pas à pas.
Cette page ne réexplique jamais un geste que la fiche liée couvre déjà.

## Comptes et groupes

*(menu SE4 « Annuaire »)*

| Tâche courante en SE4 | Emplacement et geste dans SE5 | Fiche détaillée | Ce qui change |
|---|---|---|---|
| Rechercher et consulter un compte dans l'annuaire | Page **Utilisateurs** (menu Pilotage), recherche et filtres | [Utilisateurs](/admin/utilisateurs/) | — |
| Ajouter un utilisateur | Bouton « Nouvel utilisateur » de la liste | [Créer un compte](/admin/utilisateurs/creer-un-compte) | — |
| Modifier la fiche d'un utilisateur | Fiche utilisateur, informations et rôle | [Modifier un compte](/admin/utilisateurs/modifier-un-compte) | — |
| Réinitialiser ou modifier des mots de passe | Trois portes : fiche, sélection multiple, groupe entier | [Réinitialiser un mot de passe](/admin/utilisateurs/reinitialiser-un-mot-de-passe) | — |
| « Tester les mots de passe » (repérer les comptes restés au mot de passe initial) | Filtre d'audit « Mot de passe par défaut » de la liste, puis réinitialisation | [Réinitialiser un mot de passe](/admin/utilisateurs/reinitialiser-un-mot-de-passe) | — |
| Effacer des comptes, nettoyer l'annuaire | Fiche utilisateur : désactiver, puis supprimer définitivement | [Désactiver ou supprimer un compte](/admin/utilisateurs/desactiver-ou-supprimer-un-compte) | La suppression se fait en deux temps : on désactive d'abord (réversible), la suppression définitive n'est possible qu'ensuite. |
| Ajouter un groupe, gérer les groupes et leurs membres | Onglet **Groupes** de la page Utilisateurs | [Groupes d'utilisateurs](/admin/utilisateurs/groupes-d-utilisateurs) | — |
| Importer les comptes en masse (SIECLE, STS, ENT, GPEI, CSV) | Pas encore disponible dans SE5. | — | — |
| Chaque utilisateur change son mot de passe depuis une page web | Le changement se fait sur le poste (`Ctrl+Alt+Suppr`) | [Changer mon mot de passe](/poste/mon-compte/changer-mon-mot-de-passe) | Le mot de passe ne se change plus par une page web : chacun le change depuis son poste, par l'écran de sécurité de Windows. |
| Consulter « Mon espace personnel » depuis le navigateur | L'[espace personnel](/glossaire#espace-personnel) se retrouve sur le poste, comme lecteur personnel | [Mon espace personnel](/poste/fichiers/espace-personnel) | Les fichiers personnels se consultent depuis le poste, plus depuis une page web de l'interface d'administration. |

## Droits d'administration

*(menu SE4 « Gestion des profils de droits », délégation de parc)*

| Tâche courante en SE4 | Emplacement et geste dans SE5 | Fiche détaillée | Ce qui change |
|---|---|---|---|
| Gérer les profils de droits utilisateurs | Menu **Pilotage**, entrée **Gestion des droits** : composer des profils | [Composer un profil de droits](/admin/droits/composer-un-profil) (et [Les profils types](/admin/droits/profils-types)) | Les droits ne se cochent plus un à un par niveaux : on compose des profils de droits nommés, puis on les attribue aux personnes. |
| Donner des droits d'administration à une personne | Attribuer un profil de droits depuis Gestion des droits | [Attribuer des droits](/admin/droits/attribuer-des-droits) | — |
| Déléguer un parc à un enseignant | Déléguer un droit sur une salle | [Déléguer un droit sur une salle](/admin/droits/deleguer-sur-une-salle) | La délégation porte sur une salle, avec un périmètre limité : le délégataire agit sur sa salle, pas au-delà. |

## Parcs et postes

*(menu SE4 « Gestion des parcs »)*

Les postes de l'établissement se regroupent en [parcs](/glossaire#parc) — le
plus souvent une salle par parc.

| Tâche courante en SE4 | Emplacement et geste dans SE5 | Fiche détaillée | Ce qui change |
|---|---|---|---|
| Voir la liste des parcs et des machines | Menu **Parc & postes**, entrée **Gestion du parc** | [Gestion du parc](/admin/parc/) | — |
| Rechercher une machine, consulter et modifier sa fiche | Fiche du poste dans Gestion du parc | [Lire l'état d'un poste](/admin/parc/lire-l-etat-d-un-poste) (geste : [Agir sur un poste](/admin/parc/agir-sur-un-poste)) | — |
| Agir sur un parc (allumer, éteindre, redémarrer une salle) | Actions d'alimentation sur un groupe de postes | [Agir sur un groupe](/admin/parc/agir-sur-un-groupe) | — |
| Programmer l'allumage des postes | Programmer les allumages et extinctions d'une salle (même fiche) | [Agir sur un groupe](/admin/parc/agir-sur-un-groupe) | — |
| Créer, renommer, supprimer un parc | Constituer et faire évoluer les groupes de postes | [Constituer les groupes](/admin/parc/constituer-les-groupes) (et [Salle ou parc logique](/admin/parc/salle-ou-parc-logique)) | — |
| Importer des machines par CSV | Import de configuration depuis la page réseau | [Les adresses réseau des postes](/admin/reglages/reseau-dhcp) | — |
| Lancer une installation ou un clonage sur les postes d'un parc | Réinstaller un poste par le démarrage réseau | [Réinstaller un poste](/admin/installer/reinstaller-un-poste) | On ne diffuse plus une image clonée : le poste se réinstalle par le démarrage réseau, depuis les systèmes préparés sur le serveur. |
| Mettre en place les sources Windows | Préparer les systèmes d'installation | [Préparer les systèmes](/admin/installer/preparer-les-systemes) | — |

## Applications et environnement des postes

*(menus SE4 « Clients et applications », « Applications Windows »)*

| Tâche courante en SE4 | Emplacement et geste dans SE5 | Fiche détaillée | Ce qui change |
|---|---|---|---|
| Mettre à jour le dépôt d'applications | Le catalogue et le [dépôt d'applications](/glossaire#depot-applications) | [Le catalogue et le dépôt](/admin/applications/catalogue-et-depot) | — |
| Affecter des applications aux parcs et aux postes | Affecter une application à un parc | [Affecter une application](/admin/applications/affecter-une-application) | Le parc est l'unité d'affectation : les applications (et les réglages) se rattachent au parc, chaque poste reçoit ce que porte son parc. |
| Retirer une application | Retirer une application d'un parc | [Retirer une application](/admin/applications/retirer-une-application) | — |
| Suivre l'état d'installation des parcs et des postes | Lire la conformité d'un poste ou d'un groupe | [Un poste en règle ou en retard](/admin/reglages/poste-en-regle-ou-en-retard) | — |
| Configurer les fonds d'écran | Fonds d'écran | [Fonds d'écran](/admin/applications/fonds-d-ecran) | — |
| Configurer les raccourcis | Raccourcis | [Raccourcis](/admin/applications/raccourcis) | — |
| Configurer Firefox (et Thunderbird) | Paramétrer Firefox et Thunderbird | [Paramétrer Firefox et Thunderbird](/admin/applications/parametrer-firefox-et-thunderbird) | — |
| Gérer les stratégies des clients Windows | Les réglages de postes se posent dans des [capacités](/glossaire#capacite), par portée | [Capacités et portées](/admin/reglages/capacites-et-portees) | Ce que vous régliez par des stratégies Windows se règle maintenant dans SE5 et s'applique par l'[agent](/glossaire#agent). |
| Configurer les applications Wine (postes Linux) | Pas encore disponible dans SE5. | — | — |

## Fichiers, partages et quotas

*(menu SE4 « Gestion des partages », quotas)*

| Tâche courante en SE4 | Emplacement et geste dans SE5 | Fiche détaillée | Ce qui change |
|---|---|---|---|
| Répertoires de classes | Le [partage](/glossaire#partage) de classe | [Le partage de classe](/admin/fichiers/partage-de-classe) | — |
| Créer d'autres répertoires partagés | Créer un partage | [Créer un partage](/admin/fichiers/creer-un-partage) | — |
| Droits sur fichiers | Gérer les accès d'un partage | [Gérer les accès d'un partage](/admin/fichiers/gerer-les-acces-d-un-partage) | — |
| Dossier échange | L'échange prof-élèves passe par le partage de classe | [Le partage de classe](/admin/fichiers/partage-de-classe) | Il n'y a plus de dossier d'échange unique : l'échange se fait dans le partage de la classe concernée. |
| Fixer des quotas, consulter les quotas effectifs | Limiter l'espace de stockage | [Limiter l'espace de stockage](/admin/fichiers/limiter-l-espace-de-stockage) | — |

## Imprimantes

*(menu SE4 « Gestion des imprimantes »)*

| Tâche courante en SE4 | Emplacement et geste dans SE5 |
|---|---|
| Gérer les imprimantes (liste, ajout, configuration, suppression, pilotes Windows) | Onglet **Imprimantes** de la page Gestion du parc (bouton « Ajouter une imprimante », dépôt des pilotes) — disponible dans SE5, mais ce guide n'a pas encore de fiche dédiée sur le sujet. |

## Serveur et supervision

*(menus SE4 « Configuration générale », « Informations système », « Serveur DHCP »)*

| Tâche courante en SE4 | Emplacement et geste dans SE5 | Fiche détaillée |
|---|---|---|
| Paramètres serveur | Les réglages de l'établissement | [Les réglages de l'établissement](/admin/reglages/reglages-de-l-etablissement) |
| Diagnostic, test des connexions, informations générales | L'état du système (contrôles et verdicts) | [L'état du système](/admin/reglages/etat-du-systeme) |
| Suivre l'activité de l'établissement (historique, connexions) | Le tableau de bord (tuiles, compteurs, activité récente) | [Le tableau de bord](/admin/reglages/tableau-de-bord) |
| Statistiques détaillées des postes | Pas encore disponible dans SE5. | — |
| Action serveur (relancer des services depuis l'interface) | Pas encore disponible dans SE5. | — |
| Configurer le serveur DHCP, gérer les baux | Les adresses réseau des postes | [Les adresses réseau des postes](/admin/reglages/reseau-dhcp) |

## Modules SE4 sans équivalent dans SE5

| Tâche courante en SE4 | Emplacement et geste dans SE5 |
|---|---|
| Bureau distant (se connecter à un poste à distance) | Pas encore disponible dans SE5. |
| Visioconférences (salons, enregistrements) | Pas encore disponible dans SE5. |
| Affichage dynamique (écrans d'affichage de l'établissement) | Pas encore disponible dans SE5. |
