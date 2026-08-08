# Décisions — installation de postes

> **Ce que couvre cette fiche.** Pourquoi le domaine est fait ainsi. Une section
> par décision : le contexte hérité de SE4, ce qui a été tranché, ce que ça
> coûte. Le *comment* vit dans les fiches techniques.

---

## 1. PostgreSQL porte l'identité du poste, l'annuaire la reçoit

**Contexte.** Sur SE4, un poste existait d'abord dans l'annuaire. Le netboot le
cherchait par `search_machine()`, écrivait son `netbootGUID`, lisait ses actions
programmées dans ses attributs. L'annuaire était à la fois le registre, la file
d'attente et le journal.

**Décision.** La reconnaissance d'un poste au démarrage se fait **exclusivement
en base**. `WorkstationLocator` ne fait aucun appel LDAP. L'annuaire reste
l'annuaire d'authentification et la cible de projection des comptes machine — il
n'est plus interrogé pour décider ce qu'un poste voit.

**Conséquences.**
- Un poste qui démarre ne dépend plus de la disponibilité de l'annuaire.
- La recherche est un index B-tree sur deux colonnes, pas un parcours LDAP.
- **Un poste enrôlé du temps de SE4 peut porter un UUID composite** — les quatre
  premiers segments de son UUID plus un cinquième dérivé de sa MAC. La règle de
  recomposition est reproduite à l'identique, sans quoi ces postes ne se
  retrouveraient pas. C'est une dette de compatibilité, pas un choix.

## 2. Ce qui sort vers un firmware vient d'une liste blanche

**Contexte.** Un firmware iPXE exécute le texte qu'on lui renvoie, ligne par
ligne. Sur SE4, les scripts PHP interpolaient directement les valeurs reçues dans
la réponse : un nom de poste contenant un retour à la ligne injectait une
commande de démarrage. Sur un réseau d'établissement, n'importe quel poste
branché peut poster n'importe quoi.

**Décision.** Toute valeur qui atteint un gabarit passe par deux filtres :

- **les identifiants sont énumérés.** Actions démarrables, versions d'OS, étapes
  d'installation, distributions, variantes de bureau : chacune est une
  énumération PHP. Une valeur hors liste ne produit pas d'erreur créative, elle
  produit un refus ;
- **le texte libre est filtré caractère par caractère.** Hors de la plage ASCII
  imprimable, tout devient `?`. Le filtre échoue fermé : sur une chaîne UTF-8
  invalide, la valeur entière est remplacée plutôt que réinjectée telle quelle.

**Conséquences.**
- Ajouter une action, une version ou une variante demande d'éditer une
  énumération — pas de configuration seule, pas de valeur devinée.
- Les noms de postes accentués sont dégradés dans les menus. C'est le prix, et
  il est faible : les noms de machine sont de toute façon contraints à
  `[a-z0-9_\-.$]`.
- Le filtrage est appliqué **plusieurs fois sur le même chemin** — requête,
  énumération, puis rendu. C'est redondant, et volontairement : les trois
  couches ne tombent pas ensemble.

## 3. Le compte annuaire est créé avant la ligne en base

**Contexte.** L'ordre inverse — écrire en base puis projeter — est le réflexe
naturel et c'est celui qui produit des **postes fantômes** : une ligne en base
sans compte machine, donc une machine qui ne rejoindra jamais le domaine, et que
rien à l'écran ne distingue d'un poste sain.

**Décision.** À la création d'un poste par l'enrôlement, le compte annuaire est
créé et son `netbootGUID` posé **avant toute écriture en base**. Si l'annuaire
refuse, rien n'est persisté et l'opérateur voit une erreur.

**Conséquences.**
- La création annuaire doit être idempotente — elle l'est : une nouvelle
  tentative réutilise un compte déjà présent.
- **Le renommage suit la règle inverse** : la base d'abord, l'annuaire en tâche
  de fond. Un renommage ne peut pas créer d'incohérence structurelle, seulement
  un décalage temporaire ; le rendre synchrone ferait attendre un opérateur
  devant un écran de démarrage. Conséquence à connaître : le retour affiché ne
  reflète pas le succès réel côté annuaire.
- L'appartenance d'un poste à un parc logique **n'est plus projetée du tout**.
  Le pivot en base est la seule source de vérité.

## 4. Le serveur porte la progression, jamais le poste

**Contexte.** Une installation Windows n'est pas un aller-retour : le poste
redémarre plusieurs fois, change de compte de session, et perd son contexte à
chaque reprise.

**Décision.** Le poste rappelle le serveur à chaque étape avec deux
paramètres — l'étape et un compteur de tour — et **le serveur répond ce qui
correspond au tour en cours**. Ce que le poste ne renvoie pas, le serveur le
relit dans ce qu'il a persisté au premier tour.

**Conséquences.**
- Un poste qui redémarre au mauvais moment reprend là où le serveur l'attend.
- Les écritures d'avancement se font sous transaction avec verrou sur la ligne
  du poste : deux appels simultanés sur la même machine ne se marchent pas
  dessus.
- **L'avancement ne s'écrit pas dans `workstations.status`.** Cette colonne a un
  domaine fermé de 20 caractères ; y écrire une phrase d'étape produisait une
  erreur SQL, donc une réponse 500, donc un poste jamais marqué. L'avancement
  passe par `progress`, `programmed_action`, `machine_boot_logs` et le journal.
  Effet de bord bienvenu : un poste `protected` le reste pendant toute une
  installation.

## 5. Les fichiers servis aux postes sont produits par le code

**Contexte.** Sur SE4, les fichiers d'installation venaient d'un paquet Debian :
les aides WinPE, le gabarit de réponses, les fragments de préconfiguration
Linux, le script d'extraction des images. Aucun n'était sous gestion de version
avec l'application, et le script d'extraction codait ses chemins en dur.

**Décision.** Ces artefacts sont **dans le dépôt** — `resources/ipxe/` — et les
opérations qui les manipulent sont du code PHP testé, non des scripts shell
externes.

**Conséquences.**
- On sait ce qui a changé, et quand.
- Les chemins sont sous configuration, plus en dur.
- Le déploiement des fichiers vers l'arborescence servie aux postes passe par
  une **route** avec racines autorisées, non par un alias Apache non versionné.
  Ajouter un emplacement est une modification de code, pas de configuration
  serveur.
- **Les pilotes réseau WinPE font exception** : leur pack vit hors dépôt, sous
  `storage/`. Ce sont des binaires tiers, volumineux et propres au matériel de
  chaque établissement. Le revers est qu'une instance neuve n'en a aucun, et que
  rien ne le signale avant le premier poste concerné.

## 6. Pas de session : l'authentification est rejouée à chaque appel

**Contexte.** Un firmware iPXE n'a pas de système d'exploitation : ni cookie, ni
jeton porté, ni en-tête d'autorisation. Le mécanisme d'authentification du reste
de SE5 — jeton signé par poste — ne s'applique pas ici.

**Décision.** L'opérateur saisit ses identifiants **une fois** dans le menu ; ils
sont propagés de chaînage en chaînage et **revalidés à chaque appel** : liaison
sur l'annuaire, puis vérification de la permission `computer.install` en base.

**Conséquences.**
- Un compte valide dans l'annuaire mais dépourvu de la permission est refusé.
  L'appartenance à l'annuaire ne suffit pas.
- Le mot de passe transite en base64 à chaque requête, sur un réseau restreint
  aux adresses privées et sous limitation de débit. C'est une contrainte du
  protocole, pas un choix.
- **Aucun mot de passe n'entre dans un journal**, sous aucune forme. Les traces
  ne portent que des préfixes.
- Une seule dérogation existe — l'action pré-autorisée par une réinstallation
  armée, décrite en section 7.

## 7. La réinstallation à distance vit dans sa propre table

**Contexte.** Réinstaller un poste supposait de s'y déplacer. Le besoin —
réinstaller une salle entière — est un geste d'administration, pas un geste de
technicien devant un écran.

**Décision.** L'armement écrit dans `workstation_reinstall_requests`, une table
dédiée, **jamais dans `Workstation::programmed_action`** qui sert au marqueur de
fin d'installation. L'armement se fait derrière la permission
`computer.install` ; c'est lui qui porte l'autorisation, ce qui permet de servir
l'action au démarrage **sans identifiants** — il n'y a personne devant l'écran
pour en saisir.

**Conséquences.**
- Confondre les deux mécanismes ferait qu'un poste fraîchement installé se
  réarmerait tout seul.
- La dérogation d'authentification est étroite : le poste doit porter une demande
  active dont la cible est **exactement** l'action appelée. Toute erreur de
  résolution retombe sur la garde normale.
- Le catalogue exposé est filtré aux installations. Remise en état d'usine,
  clonage et diagnostic n'y figurent pas : ce ne sont pas des réinstallations
  d'OS, et un déclenchement à distance ne doit pas pouvoir les atteindre.
- Trois garde-fous bornent la boucle : durée de vie de la demande, plafond de
  démarrages servis, plafond de concurrence. Aucun ne suffit seul.

## 8. Un poste doit toujours recevoir un écran

**Contexte.** Un firmware qui reçoit une page HTML ou un code d'erreur se fige.
Le poste ne démarre plus — ni sur le réseau, ni sur son disque. Une erreur
serveur ne dégrade pas le service : elle immobilise la machine.

**Décision.** Tout rendu est enveloppé dans un rattrapage d'exception qui
garantit une réponse en texte brut. En cas d'échec, le poste reçoit un script
minimal qui affiche un message et rend la main au disque local.

**Conséquences.**
- Les écritures secondaires — traces, journaux, marqueurs — sont **au mieux** :
  leur échec est journalisé, jamais propagé.
- Les erreurs répondent **200 avec un corps vide**, pas un code d'erreur. C'est
  contraire aux usages HTTP et c'est délibéré ; l'interlocuteur n'est pas un
  client HTTP, c'est un firmware.
- Le retour au disque local passe par le gestionnaire de démarrage UEFI plutôt
  que par un démarrage direct : cela réinitialise la sortie graphique. Sans
  quoi le tampon vidéo du menu reste en place et Windows démarre sur un écran
  corrompu.

## 9. Les pilotes réseau sont réinjectés à chaque déploiement d'image

**Contexte.** Depuis Windows 11 24H2, Microsoft a retiré de l'image de démarrage
des pilotes Intel LAN devenus anciens. Sur un poste dont la carte n'est pas
reconnue d'origine, WinPE ne monte pas le réseau et **l'installation ne démarre
jamais**. Le fichier de réponses sait charger des pilotes depuis un partage — mais
il faut le réseau pour l'atteindre.

**Décision.** L'injection dans `boot.wim` est **enchaînée à l'extraction**, dans
le même service, à chaque déploiement.

**Conséquences.**
- Une injection manuelle ne tient pas : l'extraction suivante écrase le fichier
  par la version d'origine. C'est arrivé en laboratoire.
- L'idempotence est acquise **par construction** — le fichier fraîchement copié
  est toujours vierge — donc aucune logique de comparaison n'est nécessaire.
- Le pack doit vivre **hors de l'arborescence servie aux postes**, que
  l'extraction commence par supprimer.
- Pack absent ou vide : l'injection est sautée et l'image d'origine reste
  intacte. Aucune régression pour un parc dont les cartes sont reconnues.

## 10. Un poste protégé est refusé plusieurs fois

**Contexte.** Sur SE4, un indicateur protégeait un poste de la suppression, mais
pas de la réinstallation.

**Décision.** Un poste `protected` est refusé à l'armement, au démarrage — où
toute demande active est annulée — et à la pré-autorisation de l'action.

**Conséquences.**
- Les trois contrôles sont redondants, et c'est le but : le poste peut devenir
  protégé **après** l'armement, et aucun des trois seul ne couvre cette fenêtre.
- L'annulation au démarrage n'est pas passive : elle nettoie la demande plutôt
  que de la laisser expirer.

## Aller plus loin

Les mécanismes : [premier contact](premier-contact.md) ·
[enrôlement](enrolement.md) · [installation Windows](installation-windows.md) ·
[installation Linux](installation-linux.md) ·
[images Windows](images-windows.md) ·
[réinstallation pilotée](reinstallation-pilotee.md)
