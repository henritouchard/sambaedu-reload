# Installation de postes (iPXE)

> **Porte d'entrée du domaine.** Cette fiche dit ce que le domaine fait, dans
> quel ordre lire les fiches, et ce qu'il ne faut jamais casser. Le détail des
> mécanismes vit dans les fiches liées.

---

## En une phrase

Un poste qui démarre sur le réseau contacte le serveur, s'identifie par sa
**MAC** et son **UUID**, et reçoit en retour un script iPXE — un menu, une
installation, un outil de diagnostic. Le serveur est la seule autorité : c'est
lui qui décide ce que le poste voit, à partir de ce que PostgreSQL sait de lui.

## Le pourquoi

Sur SE4, le netboot était un ensemble de scripts PHP servis directement par
Apache (`boot.php`, `admin.php`, `enregistrement.php`, `Win10/install.bat.php`…).
Chacun lisait l'annuaire, écrivait dans l'annuaire, et rendait du texte iPXE sans
séparation entre la décision et le rendu. Deux conséquences pratiques :

- **l'identité du poste venait de l'AD**, et un poste pouvait exister à moitié —
  compte machine sans entrée en base, ou l'inverse ;
- **aucune valeur reçue n'était filtrée**. Le firmware iPXE exécute le texte
  qu'on lui renvoie, ligne par ligne ; une valeur contenant un retour à la ligne
  injecte une commande de boot.

SE5 reprend le même protocole — mêmes URL vues du poste, mêmes paramètres — mais
inverse les responsabilités : **PostgreSQL porte la vérité**, l'AD reste la cible
de projection, et **tout ce qui sort vers un firmware passe par une liste
blanche**. Les identifiants d'actions, de versions d'OS, d'étapes d'installation
sont des énumérations PHP ; ce qui n'y figure pas n'existe pas.

## Parcours de lecture

**Tu découvres le domaine.** [Premier contact](premier-contact.md) — le poste
frappe à la porte, le serveur le reconnaît ou non. Tout part de là.

**Tu enquêtes sur un poste qui ne s'installe pas.** [Premier
contact](premier-contact.md) § *ce que le poste envoie*, puis la fiche de l'OS
concerné : [Windows](installation-windows.md) ou [Linux](installation-linux.md).

**Tu ajoutes une version de Windows.** [Images
Windows](images-windows.md) — d'où viennent les fichiers que le poste télécharge.

**Tu veux réinstaller une salle depuis l'interface.**
[Réinstallation pilotée](reinstallation-pilotee.md).

**Tu veux savoir pourquoi c'est fait ainsi.** [Décisions](metier.md).

## Carte des fiches

| Fiche | Axe | Sujet |
| --- | --- | --- |
| [premier-contact.md](premier-contact.md) | technique | Ce que le poste envoie, comment il est reconnu, quel menu il reçoit, comment l'accès aux menus sensibles est gardé |
| [enrolement.md](enrolement.md) | technique | Donner un nom à un poste, le rattacher à une salle et à des parcs |
| [installation-windows.md](installation-windows.md) | technique | De WinPE au premier ouvre-session, puis les étapes de finition |
| [installation-linux.md](installation-linux.md) | technique | Assemblage du preseed et retour de fin d'installation |
| [images-windows.md](images-windows.md) | processus | Récupérer une image Windows, l'extraire, injecter les pilotes réseau |
| [reinstallation-pilotee.md](reinstallation-pilotee.md) | processus | Armer une réinstallation depuis l'interface, la servir au prochain démarrage |
| [metier.md](metier.md) | métier | Les décisions et leurs conséquences |

Checklist de pré-production : [`qa/domains/ipxe.md`](../qa/domains/ipxe.md).

## Invariants à ne jamais casser

- **Un poste qui démarre reçoit toujours un script iPXE valide.** Jamais de
  page HTML, jamais de 500. Toute exception de rendu est rattrapée et remplacée
  par un script minimal qui rend la main au disque local. Un firmware qui reçoit
  du HTML se fige : le poste ne démarre plus du tout.
- **Ce qui sort vers un firmware vient d'une liste blanche.** Actions, versions
  d'OS, étapes d'installation, distributions : chacune est une énumération PHP.
  Une valeur hors liste ne produit pas une erreur créative — elle produit un
  refus.
- **MAC et UUID doivent être présents tous les deux.** L'un sans l'autre
  déclenche le préambule de reparamétrage, pas une reconnaissance approximative.
  C'est ce qui empêche qu'une MAC usurpée ou dupliquée serve le menu d'un autre
  poste.
- **Les routes natives sont déclarées avant l'attrape-tout.** Le fichier de
  routes se termine par une route générique qui capture tout `/ipxe/*` ; une
  route native déclarée après elle ne serait jamais atteinte. Un test
  d'architecture le vérifie
  (`tests/Architecture/IpxeNamespaceTest.php`).
- **Aucun mot de passe n'entre dans un journal.** Les traces ne portent que des
  préfixes : trois caractères de l'identifiant, six de la MAC, huit de l'UUID.
- **Les chaînages sont des URL absolues.** Une cible relative depuis une route
  de profondeur supérieure à un niveau se résout mal côté firmware et finit en
  404, ce qui rejette le poste dans une boucle de démarrage.

## Manques connus

- **Linux s'installe mais ne se gère pas.** Ce qui suit l'installation d'un poste
  Linux n'est pas porté : la boucle d'exécution à distance n'a plus de
  destinataire (point d'entrée natif sans appelant, chemin du CD de secours en
  404). Ce n'est pas une régression, c'est un plan non construit — cf.
  [installation Linux](installation-linux.md).
- **Le modèle de préparation au clonage est un gabarit minimal.**
  `/ipxe/windows/sysprep.xml` répond une structure valide mais sans
  personnalisation.
- **Aucune déduplication des traces de démarrage.** Un poste qui reprend son
  installation plusieurs fois écrit autant de lignes dans `machine_boot_logs`.
- **Les valeurs sensibles injectées dans une ligne de commande noyau ne sont pas
  filtrées** — le mot de passe root de l'image de secours notamment. La
  configuration est considérée comme une frontière de confiance ; la contrainte
  (ASCII strict, sans espace) n'est portée que par un commentaire
  (`config/ipxe.php:236-244`).
- **Les pilotes réseau WinPE sont livrés hors dépôt.** Le pack vit sous
  `storage/`, alimenté à la main ou par une commande d'ingestion ; rien ne
  garantit qu'une instance neuve en ait un.

## Carte du code

**Point d'entrée et orchestration**

| Rôle | Fichier |
| --- | --- |
| Orchestrateur du démarrage et des menus | `app/Ipxe/Services/IpxeService.php` |
| Reconnaissance d'un poste | `app/Ipxe/Services/WorkstationLocator.php` |
| Garde d'accès aux menus sensibles | `app/Ipxe/Services/IpxeAuthService.php` |
| Rendu des menus | `app/Ipxe/Services/IpxeMenuRenderer.php` |
| Résolution d'une action vers son script de boot | `app/Ipxe/Services/IpxeActionResolver.php` |

**Listes blanches**

| Rôle | Fichier |
| --- | --- |
| Actions démarrables | `app/Ipxe/Enums/IpxeAdminAction.php` |
| Étapes d'installation Windows | `app/Ipxe/Enums/WindowsInstallStep.php` |
| Versions Windows | `app/Ipxe/Enums/WindowsVersion.php` |
| Distributions et variantes de bureau Linux | `app/Ipxe/Enums/LinuxDistribution.php`, `LinuxDesktopVariant.php` |

**Fabrication des fichiers servis aux postes**

| Rôle | Fichier |
| --- | --- |
| Script d'installation Windows | `app/Ipxe/Services/WindowsInstallBatBuilder.php` |
| Réponses d'installation sans surveillance | `app/Ipxe/Services/WindowsUnattendBuilder.php` |
| Scripts de finition post-installation | `app/Ipxe/Services/WindowsActionCmdBuilder.php` |
| Fichier de préconfiguration Linux | `app/Ipxe/Services/LinuxPreseedService.php` |

**Images et pilotes** — `app/Ipxe/Iso/` (téléchargement, extraction, injection de
pilotes réseau dans l'image de démarrage WinPE).

**Réinstallation armée** — `app/Services/Parc/WorkstationReinstallService.php`,
table `workstation_reinstall_requests`.

**Configuration** — `config/ipxe.php` (unique fichier ; chaque valeur est
surchargeable par variable d'environnement, sauf la liste des modèles matériel
forcés en UEFI).

**Routes** — `routes/web.php`, blocs `/ipxe/*`, toutes sous le filtre
`auth.v1.lan-only` (adresses privées uniquement) et une limitation de débit.

**Tests** — 61 fichiers : `tests/Feature/Ipxe/`, `tests/Unit/Ipxe/`,
`tests/Architecture/IpxeNamespaceTest.php`,
`tests/Architecture/IpxeStaticAliasTest.php`.
