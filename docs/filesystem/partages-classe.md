# L'arbre de classe historique et les gestes système

> **Ce que couvre cette fiche.** L'arborescence de classe telle qu'elle est
> **réellement servie** aujourd'hui, ses droits POSIX, et les gardes sous
> lesquelles SE5 écrit sur le disque.
>
> Ce qu'elle ne couvre pas : l'arbre neuf, décrit par une recette
> ([recettes-et-plan.md](recettes-et-plan.md)).

---

## 1. En une phrase

**C'est l'arbre que les établissements utilisent**, écrit directement par les
services de partage, sous un chemin figé — et il coexiste volontairement avec
l'arbre neuf.

## 2. Deux arborescences, deux zones disjointes

| | Arbre **historique** | Arbre **neuf** |
| --- | --- | --- |
| Racine | la racine des classes | la racine des répertoires gérés |
| Qui l'écrit | les services de partage et de listes d'accès | une recette → un plan → un backend |
| Chemin | **figé** | porté par la recette, traduit tout en bas |
| Statut | **servi aux établissements** | peuplé, comparé, pas encore servi |

**Les deux voies ne doivent surtout pas être factorisées** : elles gouvernent des
zones différentes, avec des cycles de vie différents et des autorités
différentes. Deux commandes distinctes les peuplent, et l'une ne doit ni appeler
l'autre ni la remplacer.

## 3. Le schéma de droits d'un partage de classe

Pour une classe donnée, la racine porte le propriétaire en écriture, l'équipe
enseignante en lecture-exécution, le groupe de la classe en lecture-exécution, les
administrateurs du domaine en écriture, et **rien pour les autres** — le tout
répliqué en entrées par défaut pour l'héritage.

| Sous-dossier | Ce qu'il porte |
| --- | --- |
| dossier de travail | le groupe de la classe en **lecture seule** |
| dossier réservé aux enseignants | hérité de la racine — privé de l'équipe |
| dossier d'échange | le groupe de la classe en **écriture**, ou **aucun accès** quand l'échange est désactivé |
| dossier au nom d'un élève | l'élève **nominativement** en écriture, plus l'équipe et les administrateurs |

**Le nominatif est réservé aux dossiers par membre.** Les listes d'accès POSIX ont
une limite physique d'entrées : un groupe de plusieurs centaines de membres en
nominatif ne passe pas, et la traduction d'un rôle en accès partagé se fait par un
**groupe dérivé**, jamais par une énumération de personnes. La mesure qui fonde
cette règle est en [recettes-et-plan.md](recettes-et-plan.md) § 6.

> **Convention de nommage héritée** : les noms de groupe dans les listes d'accès
> sont en minuscules, espaces échappés ; le chemin sur disque, lui, conserve la
> casse d'origine. Un nom de classe contenant un espace n'est **pas supporté** —
> la validation le refuse plutôt que de produire un chemin bancal.

## 4. Le changement de classe d'un élève

Deux canaux déclenchent la même synchronisation, et il faut les connaître tous les
deux :

- l'**import et l'édition d'annuaire**, qui désactive l'observateur pendant la
  passe puis appelle la synchronisation explicitement ;
- l'**attachement ou le détachement ponctuel** d'un élève à un groupe, capté par un
  observateur sur la table de liaison, filtré sur les groupes de type classe.

L'effet est le même : l'accès nominatif est retiré du dossier de l'ancienne classe
— **le dossier de l'élève n'est jamais supprimé, ses données sont préservées** —,
un dossier est créé dans la nouvelle, et l'ancien y est déplacé sous un
sous-dossier d'archive. Si une archive existe déjà, l'ancienne est supprimée
plutôt qu'écrasée, et l'événement est journalisé.

Un rattrapage idempotent existe en commande : il ré-applique les droits sur toutes
les classes ou sur une seule, sans risque à la relance.

## 5. Ce qui écrit sur le disque, et sous quelles gardes

Les gestes système passent par des binaires appelés avec élévation — pose et
lecture de listes d'accès, création, déplacement, changement de propriétaire,
suppression. **Trois gardes, cumulées :**

1. **validation de chemin** — préfixe de racine obligatoire, jeu de caractères
   restreint, refus des remontées de répertoire, **profondeur bornée** ;
2. **échappement systématique** du chemin et de chaque argument ;
3. **liste blanche de binaires** dans le fragment d'élévation attendu sur le
   serveur.

Une garde jumelle protège l'arbre neuf, transposée du même patron.

> ⚠️ **Le fragment d'élévation des partages n'est pas posé par le dépôt.** Il est
> attendu sur le serveur ; aucun fichier du dépôt ne le génère. C'est un prérequis
> d'installation, pas une conséquence du déploiement.

## 6. Le partage SMB n'est pas généré par l'application

**Aucun code applicatif n'écrit la configuration Samba.** La section qui expose les
répertoires est un fichier versionné hors de l'application, déposé par le script de
mise à jour. Le seul lien depuis l'application est un contrôle de diagnostic **en
lecture seule**, qui interroge la configuration effective et pointe le fichier à
corriger.

Ajouter un répertoire ne demande donc aucun rechargement de service : la section
expose les sous-dossiers de sa racine.

## 7. Carte du code

| Sujet | Où |
| --- | --- |
| Arbre de classe, droits et changement de classe | `app/Services/Filesystem/ShareService.php` |
| Pose et lecture des listes d'accès, gardes | `app/Services/Filesystem/AclService.php` |
| Inspection des listes d'accès | `app/Services/Filesystem/Acl/` |
| Garde de chemin de l'arbre neuf | `app/Services/Filesystem/Backend/Posix/PosixPathGuard.php` |
| Compilation POSIX d'un plan | `app/Services/Filesystem/Backend/Posix/PosixAclCompiler.php` |
| Contrôle de diagnostic du partage SMB | `app/Doctor/Checks/Filesystem/PartagesShareCheck.php` |
| Rattrapage de l'arbre historique | `app/Console/Commands/SharesResyncClassCommand.php` |
| Peuplement de l'arbre neuf | `app/Console/Commands/MaterializeClassTreesCommand.php` |

## Aller plus loin

- L'arbre neuf et son modèle : [recettes-et-plan.md](recettes-et-plan.md)
- Le contrat que le backend POSIX honore : [backends.md](backends.md)
- Les plafonds posés sur ces répertoires :
  [quotas-et-corbeille.md](quotas-et-corbeille.md)
