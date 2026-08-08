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
| `documentation/CLI/COMMANDES_ARTISAN.md` | Obsolète (une poignée de commandes sur près de 90) — **à refaire depuis le code**, pas à récupérer | Exploitation |

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
