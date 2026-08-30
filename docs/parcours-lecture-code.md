# Parcours de lecture du code SE5

> **À quoi sert ce fichier.** Reprendre la compréhension du code par petites
> séances, dans un ordre qui va du cœur vers la périphérie. Ce n'est pas de la
> référence : chaque séance **renvoie** à la fiche et au code qui font autorité.
>
> Une séance = 30 à 45 min. Une par jour suffit.

## Comment s'en servir

1. Lis la **doc** de la séance en premier (le *pourquoi*), le **code** ensuite (le *comment*).
2. Fais la **preuve** : tant que tu ne sais pas la faire, la séance n'est pas finie.
3. Coche la case.
4. Si tu veux être interrogé : dis-moi « séance N » et je te pose 3 questions sur ce que tu viens de lire.

Ordre conseillé : 1 → 9. Les séances 1 à 5 sont le socle, les 6 à 9 sont des canaux périphériques qu'on peut lire dans le désordre.

---

## Socle

### - [ ] Séance 1 — La forme du projet

**Question.** Où vit quoi, et comment une URL devient une page ?

**Lire**
- `docs/README.md` — la carte du corpus
- `CLAUDE.md` §« Arborescence et routing »
- `routes/web.php` (survole, ne lis pas les 1507 lignes)
- `resources/views/pages/parc/` — un dossier = une route, `index.blade.php` = la racine, `_partials/` = les morceaux

**Preuve.** Pars de l'URL `/parc/groups/<id>/edit` et retrouve, sans chercher, le fichier qui l'affiche.

---

### - [ ] Séance 2 — L'identité

**Question.** Qui est un utilisateur, et qui dit la vérité — PostgreSQL ou l'AD ?

**Lire**
- `docs/identite/metier.md` puis `docs/identite/sync-ad.md`
- `app/Models/User.php` (657 l. — lis les relations et les casts, pas tout)
- `app/LdapModels/LdapUser.php`
- `app/Services/AdSync/AdSyncService.php`

**À retenir.** PostgreSQL porte la vérité. L'AD est l'annuaire d'authentification et une **cible de projection**. Le sens `AD → SQL` est une migration transitoire, pas le régime permanent.

**Preuve.** Explique en une phrase pourquoi `users.role` ne donne aucun droit.

---

### - [ ] Séance 3 — Groupes, rôles, types

**Question.** Comment l'appartenance à un groupe devient un droit ?

**Lire**
- `docs/domains/groupes-roles.md`
- `docs/domains/rights-management.md`
- `app/Models/GroupType.php`, `app/Models/GroupRole.php`, `app/Models/GroupTypeRole.php`
- `app/Models/UserGroup.php` et `app/Models/Pivot/UserGroupUserPivot.php`
- `app/Services/GroupRightsProfileService.php`

**À retenir.** Le groupe porte le profil de droits : appartenir **est** l'attribution. Déclarer un rôle **ferme** le type.

**Preuve.** Trace le chemin complet : un élève est ajouté à une classe → quel droit effectif apparaît, et par quelle table ?

---

### - [ ] Séance 4 — Le parc

**Question.** Qu'est-ce qu'un poste, un groupe de postes, et lequel gagne quand deux règles s'appliquent ?

**Lire**
- `docs/domains/parc.md`
- `app/Models/Workstation.php`, `app/Models/WorkstationGroup.php`
- `app/Services/Parc/WorkstationGroupService.php`
- `app/Services/WorkstationGroupLdapService.php`

**À retenir.** Le parc **logique** prime sur le parc physique. Le permissif est un **plancher** : il est surchargé par le local ; le verrouillé est imbattable.

**Preuve.** Trouve la méthode qui arbitre la précédence entre deux candidats (cherche `specificity`).

---

### - [ ] Séance 5 — Le cœur : capacités → état compilé → agent

**Question.** Comment une case cochée dans l'UI devient un registre modifié sur un poste ?

C'est la séance la plus importante. Prends-en deux si besoin.

**Lire**
- `docs/agent/README.md` puis `docs/agent/metier.md`
- `docs/agent/contract-v1.md` — le format du contrat
- `docs/agent/state-providers.md` — qui produit quoi
- `app/Models/Capability.php`, `app/Models/CapabilityProjection.php`
- `app/Services/Agent/StateCompiler.php` (423 l.) et `StateContract.php`
- **un seul** provider en entier : `app/Services/Agent/Providers/WallpaperStateProvider.php`
- `app/Http/Controllers/Api/V1/Agent/StateController.php`

**À retenir.** Le serveur **compile** l'état cible `f(poste, user)` ; l'agent ne décide rien. Une capacité « off » doit écrire une **vraie valeur**, pas rien (règle des maps symétriques).

**Preuve.** Suis un fond d'écran de bout en bout : la table qui le stocke → le provider → la clé JSON dans le contrat → le handler Go qui l'applique.

---

## Canaux périphériques

### - [ ] Séance 6 — L'agent Go, côté poste

**Question.** Que fait le binaire une fois qu'il a reçu son état ?

**Lire**
- `agent/README.md`
- `agent/shared/contract.go` — le miroir Go du contrat PHP
- `agent/shared/engine.go` — la boucle test / apply / report
- `agent/windows/handler_wallpaper_windows.go` — le pendant de la séance 5
- `docs/agent/enrollment.md` et `docs/agent/release-distribution.md`

**À retenir.** Chaque handler fait `Test` puis `Apply`. Un handler absent du binaire publié = un état sans effet, en silence. Toute édition dans `agent/**` impose de bumper `version.go`.

**Preuve.** Trouve où l'agent décide qu'il n'a **rien** à faire.

---

### - [ ] Séance 7 — Le plan de fichiers

**Question.** Qui a le droit de lire quoi, et qui pose ce droit ?

**Lire**
- `docs/filesystem/metier.md`, puis `emplacements.md` et `backends.md`
- `app/Services/Filesystem/Plan/FilePlan.php`, `PlanNode.php`, `PlanGrant.php`
- `app/Services/Filesystem/Backend/FileBackendRegistry.php` — POSIX / Nextcloud / OpenCloud
- `app/Services/Filesystem/AclService.php`
- `app/Models/FolderAccessRule.php`

**À retenir.** Le serveur décrit un **plan** neutre ; le backend le traduit. `setfacl` casse au-delà d'environ 5457 entrées, d'où le groupe dérivé (une entrée par groupe, pas par élève).

**Preuve.** Explique pourquoi un retrait d'accès accepté par Nextcloud peut rester **sans effet**.

---

### - [ ] Séance 8 — Les canaux hérités : GPO, WPKG, iPXE

**Question.** Ce qui existait avant l'agent, et ce qui reste allumé.

**Lire**
- `app/Gpo/README.md` et `app/Services/Gpo/SysvolPolicyService.php`
- `docs/runbooks/gpo-se4-agent-bootstrap.md` — comment l'agent s'amorce par GPO
- `app/Wpkg/README.md`
- `docs/ipxe/premier-contact.md` puis `docs/ipxe/enrolement.md`

**À retenir.** Ces canaux sont en extinction, pas en maintenance. Le kill-switch existe : `LEGACY_CONFIG_CHANNEL_ENABLED=false`.

**Preuve.** Dis quel canal amorce un poste vierge, dans l'ordre : iPXE → ? → ?

---

### - [ ] Séance 9 — Ce qui vient d'ailleurs : controlHub, extensions, SSO

**Question.** Ce que SE5 subit d'un tiers, et ce qu'il ouvre à des tiers.

**Lire**
- `docs/controlhub-schema-echange.md`
- `app/Services/ControlHub/ControlHubContractIngestionService.php`
- `docs/extensions/metier.md` puis `cycle-de-vie.md`
- `app/Services/Extensions/ExtensionInstallService.php`
- `docs/auth/fournisseur-oidc.md`

**À retenir.** Le contrat amont fait **autorité** : il verrouille ou laisse permissif, et le parc l'applique. Les claims OIDC sont gelés (`sub` = login).

**Preuve.** Explique ce qui se passe localement quand le lien controlHub est rompu.

---

## Après

Quand les 9 séances sont cochées, la relecture utile n'est plus un parcours : c'est
`git log` sur le domaine que tu t'apprêtes à toucher.
