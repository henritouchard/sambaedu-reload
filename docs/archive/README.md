# Archive documentaire

> **Rien ici n'est maintenu.** Ces documents ont servi à amorcer SE5 : analyses du
> legacy SE4, plans de refonte depuis exécutés, notes de travail, procédures
> ponctuelles. Ils sont conservés parce qu'ils portent parfois une **connaissance du
> legacy qui n'existe nulle part ailleurs** — mais aucun n'est une description
> fiable du code d'aujourd'hui.
>
> **Ne jamais raisonner sur ces fiches sans vérifier dans le code.** La référence
> vivante est indexée par [`../README.md`](../README.md).

---

## Pourquoi ces fichiers sont ici

Trois motifs, cumulables :

- **Plan exécuté** — le document décrivait un travail à faire, ce travail est fait
  et le code fait désormais foi.
- **Analyse du legacy** — description du fonctionnement de SE4, utile comme
  archéologie, sans valeur prescriptive pour SE5.
- **Orphelin** — aucun renvoi depuis le code, aucune mise à jour depuis l'import
  initial du dépôt.

## Substance à récupérer

Ce qui mérite d'être distillé dans la référence vivante quand le domaine
correspondant sera repris au gabarit :

| Fichier archivé | Substance | Destination |
| --- | --- | --- |
| `USER_CREATE.md` | Règles de génération du login et politique de mot de passe côté SE4 | Identité |
| `EDIT_USER_rights_legacy.md` | Stockage des droits en groupes LDAP dans SE4 — le point de départ dont on est parti | Droits |
| `rightManagementPlan.md`, `testRightManagement4.6.md` | Raisonnement ayant conduit au modèle de permissions et aux délégations périmétrées | Droits — décisions |
| `explications_wpkg.md` | Exposé pédagogique de l'imbrication WPKG / GPO / scripts dans SE4 | Déploiement applicatif |
| `applications.md`, `wpkgTodo.md` | Analyse du canal de déploiement legacy et de la découpe parc / profil | Déploiement applicatif |
| `documentation/misc/gpo.md` | Fonctionnement du partage SYSVOL et des GPO — le domaine GPO n'a aucune fiche de référence | GPO |
| `documentation/architecture/dataFlow.md` | Flux entre AD central, AD d'établissement et SQL dans SE4 | Identité — décisions |
| `documentation/architecture/ControlHubTasks.md` | Modèle d'exécution des tâches ordonnées par l'amont | Lien amont |
| `documentation/CLI/workers-systemd.md` | Découpe des files d'attente en deux services système | Exploitation |
| `group-model-multivertical-orientation.md` | **Plan largement exécuté — voir l'encart ci-dessous.** Le rôle porté par l'arête est livré ; la déclaration des zones et de la matrice, elle, ne vit **pas** sur le type de groupe comme le plan l'annonçait, mais dans une **recette** d'arborescence qui **s'accroche** à un type (un type, une recette). Le cloud annoncé en « option future » est livré. Reste utile pour l'archéologie : la matrice d'accès SE4 croisée fiche à fiche, l'asymétrie d'héritage des entrées par défaut, l'ACL posée à la main que SE4 lit parfois. La limite physique du nombre d'entrées POSIX — d'où le groupe dérivé plutôt qu'une énumération de personnes — est reprise dans [`../filesystem/recettes-et-plan.md`](../filesystem/recettes-et-plan.md) | Plan de fichiers · Groupes & droits |
| `documentation/CLI/COMMANDES_ARTISAN.md` | Obsolète (une poignée de commandes sur près de 90) — **à refaire depuis le code**, pas à récupérer | Exploitation |

### ⚠️ `group-model-multivertical-orientation.md` — trois orientations qu'il portait et qui n'ont PAS été exécutées

Ce document part en archive comme « plan exécuté ». Trois de ses orientations ne
le sont pas, et elles n'ont **aucun autre foyer écrit** : sans cette liste, ce
sont des décisions perdues, qu'on rouvrira de bonne foi.

1. **Le vocabulaire d'accès borné `none | read | read-write | admin`, et sa
   dégradation `admin` → `read-write` affichée à l'écran en POSIX.** Le plan
   voulait des **niveaux** ordonnés. Le livré parle **verbes** — lire, éditer,
   créer, supprimer —, il n'y a **pas de niveau `admin`**, et donc rien à
   dégrader ni à afficher comme dégradé. **Décision abandonnée**, jamais
   consignée comme telle jusqu'ici.
2. **La nature de zone `workflow`** — un dossier réservé dont les droits
   appartiennent à une fonctionnalité applicative, avec une ligne de matrice
   inerte. Les natures livrées sont au nombre de quatre et **aucune n'en porte
   l'équivalent** : partagée, activable, par membre, à contenu libre.
3. **Le nommage générique des groupes dérivés.** La projection vers l'annuaire
   reste sur le **trio historique** de préfixes de classe et d'équipe. Une
   fabrique de groupes dérivés génériques existe par ailleurs, mais la projection
   des groupes d'utilisateurs, elle, n'y est pas passée.

## Sans valeur à récupérer

`TODO-update-user.md` (vide) · `USER_UPDATE.md`, `laravelProdTodo.md` (listes de
tâches faites) · `LDAPRECORD_MIGRATION.md` (migration achevée) ·
`applicationBridge.md` (pont vers le legacy, canal éteint) ·
`EDIT_USER_rights_New.md` (proposition d'architecture non retenue telle quelle) ·
`wallpaper-legacy-disable.md`, `wallpaper-smoke-test.md` (procédures ponctuelles) ·
`controlhub-workstation-groups-api.md` (doublon ancien de
[`../api-controlhub-workstation-groups.md`](../api-controlhub-workstation-groups.md)) ·
`documentation/Laravel/`, `documentation/databases/LDAPRecord.md` (tutoriels de
bibliothèques tierces, mieux servis par leur documentation officielle) ·
`documentation/architecture/routes.md`, `documentation/CLI/nouvelle fonctionnalité.md`
(vides ou d'une ligne).
