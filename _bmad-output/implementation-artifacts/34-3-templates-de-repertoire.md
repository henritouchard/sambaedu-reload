# Story 34.3 : Templates de répertoire (préfabrication d'échanges réutilisables)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## ⚖️ Arbitrages Henri (2026-06-30) — AUTORITÉ sur les défauts du corps de la story

> Ce bloc TRANCHE les questions ouvertes Q1-Q6. **Il prime sur tout « défaut proposé » écrit plus bas.** Le dev applique CES décisions ; les sections du corps qui décrivent encore les anciens défauts sont à lire à travers ce filtre.

- **Q1 — Casiers « élèves → profs » : REPORTÉ à 34.x.** Le template `élèves → profs` (rendus) **N'EST PAS livré en 34.3** (le socle ne sait pas faire de casiers par-élève ; un dépôt partagé serait un faux sens métier dangereux). **34.3 livre 4 templates** : `direction → tous`, `profs → élèves`, `user ↔ user`, `groupe`. Les vrais casiers (sous-dossier + ACL par élève = extension `NetworkShareService`) → 34.x.
- **Q2 — « tous » = `UserGroup` explicites.** Défaut retenu : multi-sélection explicite des `UserGroup` destinataires (RO). Pas de raccourci « tous les groupes classe », pas de cible parc pour l'ACL.
- **Q3 — Persistance : TABLE + SEEDER PROD (option B).** Une table `directory_templates` décrit les recettes (clé, libellé, description, spec des rôles-cibles en JSON), **peuplée par un `DirectoryTemplateSeeder` au déploiement** (iso `PermissionSeeder`). **PAS d'UI de gestion (pas de CRUD)** : l'admin n'édite pas les recettes en 34.3, il les CONSOMME. La porte reste ouverte à l'édition future (34.x = option C). `DirectoryTemplateService::materialize` lit la recette depuis la DB. ⚠️ Pré-déploiement VM : `db:seed --class=DirectoryTemplateSeeder` requis.
- **Q4 — Idempotence : ONE-SHOT.** Défaut retenu : la matérialisation crée un share neuf ; `directory_name` déjà pris → message « éditez-le depuis sa page ». Sync template↔share = 34.x.
- **Q5 — Nommage : MANUEL.** `directory_name` est **saisi à la main par l'admin** (comme 34.2), validé au format (`DIRECTORY_NAME_PATTERN`) + `unique`. **PAS d'auto-dérivation slug** (on abandonne le défaut « pré-rempli dérivé »).
- **Q6 — Mailles exposées : rôles du pattern uniquement.** Défaut retenu : par template, n'exposer que les rôles métier du pattern. L'option « visible aussi sur le parc X » (WG montage-seul) reste masquée/avancée, hors 34.3.

**Conséquences concrètes sur les livrables (priment sur le corps) :**
- Catalogue = **table `directory_templates` + migration + `DirectoryTemplateSeeder`** (PAS un enum/classe de presets en code). Recette stockée en JSON (rôles : {label, maille `User`/`UserGroup`/`type`, access ro|rw, cardinalité}).
- **Seulement 4 recettes** seedées (drop `élèves → profs`).
- UI : pas de pré-remplissage dérivé du `directory_name` (saisie manuelle).
- Tests : couvrir la lecture des recettes depuis la DB (seedées) + le seeder ; baseline `DirectoryTemplateSeeder` idempotent (re-seed sans doublon).

## Story

En tant qu'**administrateur d'établissement (refnum)**,
je veux **choisir un « template d'échange » (direction→tous, profs→élèves, élèves→profs, user↔user, groupe), renseigner ses paramètres (libellé, répertoire, lettre, cibles : classe/groupe/utilisateurs), et laisser le système matérialiser en un geste un `NetworkShare` + toutes ses assignations par maille avec le bon `access` ro|rw**,
afin que **je n'aie plus à câbler à la main chaque grant pour les patterns d'échange récurrents de l'éducation nationale, tout en réutilisant strictement la fondation 34.1 (NetworkShare + pivot + provision) et l'UI/validation 34.2 (modale, collision de lettre, WG-montage-seul)**.

## Contexte & intention

**TROISIÈME story de l'Epic 34 « Lecteurs réseau gérés ».** Cet Epic n'a PAS de narratif `epics.md` — il vit dans `backlog.data.js` + ses fichiers de story (iso Epics 28-33). La 34.1 a livré la FONDATION BACKEND (modèle `NetworkShare`, pivot polymorphe `network_share_assignables`, `NetworkShareService::provision`, extension `DrivesStateProvider`). La 34.2 a livré l'UI admin « à la main » (page liste `/app/shares`, modale de création, page détail + assignation par maille, `NetworkShareValidator` prédictif, `NetworkSharePolicy` + permissions `networkshare.*`). **34.3 = la COUCHE de préfabrication PAR-DESSUS ce socle, INCHANGÉ** : c'est le « MVP-B templates » annoncé HORS scope en 34.1/34.2.

**Ce que cette story livre :**
- **Un catalogue de templates d'échange** (recettes paramétrables) couvrant les patterns récurrents : `direction → tous` (publication descendante RO), `profs → élèves` (devoirs : profs RW, élèves RO), `élèves → profs` (rendus : élèves RW, prof RW — *voir le piège casiers ci-dessous*), `user ↔ user` (échange bilatéral RW/RW), `groupe` (espace commun d'un groupe d'utilisateurs).
- **Une UI de matérialisation** : sur `/app/shares`, une seconde entrée « Créer depuis un template » (à côté du « Nouveau répertoire » 34.2). L'admin choisit un template, le formulaire présente DYNAMIQUEMENT les paramètres requis par ce template (libellé, `directory_name`, lettre, et les cibles `UserGroup`/`User` selon les rôles « source »/« destinataire » du pattern), un APERÇU des assignations qui seront créées (cible → maille → access), puis matérialise.
- **Un service de matérialisation** `DirectoryTemplateService` qui, à partir d'un template + ses paramètres, CRÉE un `NetworkShare` et insère ses lignes `network_share_assignables` (User/UserGroup/WorkstationGroup, access ro|rw) dans une transaction, **réutilise `NetworkShareValidator` (collision de lettre, lettre réservée, WG-montage-seul) AVANT écriture**, puis appelle `NetworkShareService::provision()`. Un template = une RECETTE qui produit des `NetworkShare`+assignations ; il ne réinvente NI le provisioning, NI la projection agent, NI la validation.

**Pourquoi maintenant.** 34.1/34.2 ont posé le « répertoire unitaire + ses grants bruts ». Mais l'admin pense en PATTERNS métier (« je veux un espace de rendus pour la 6eB »), pas en assignations atomiques. 34.3 transforme l'intention métier en assignations correctes d'un geste, en encodant DANS la recette les deux axes (qui voit / qui lit-écrit) et l'invariant WG-montage-seul — réduisant le risque d'erreur d'authoring (le footgun exact que la validation prédictive 34.2 ne fait que *signaler* après coup).

**Le POURQUOI métier de chaque template (à cadrer AVANT la mécanique) :**

| Template | Qui DÉPOSE/écrit | Qui LIT | ACL (socle) | Maille(s) cible(s) |
|---|---|---|---|---|
| **direction → tous** | direction (RW) | tous les destinataires (RO) | direction=`rwx`, destinataires=`rx` | source = `UserGroup` direction/équipe ; destinataires = `UserGroup`(s) (PAS un parc — sinon montage-seul sans ACL) |
| **profs → élèves** (devoirs) | profs (RW) | élèves (RO) | prof `equipe_<classe>`=`rwx`, élèves `classe_<classe>`=`rx` | source = `UserGroup` type `equipe` ; destinataires = `UserGroup` type `classe` |
| **élèves → profs** (rendus) | élèves (RW) + prof (RW) | prof (RW) | élèves `classe_<classe>`=`rwx`, prof `equipe_<classe>`=`rwx` | idem — **⚠ piège casiers, cf. ci-dessous** |
| **user ↔ user** | les 2 users (RW) | les 2 users | `user:<a>`=`rwx`, `user:<b>`=`rwx` | 2 × `User` |
| **groupe** (espace commun) | le groupe (RW, ou RO selon param) | le groupe | `group:<unix>`=`rwx`/`rx` | 1 × `UserGroup` |

**Ce que cette story N'EST PAS :**
- **Pas de casiers / sous-espaces par-utilisateur** : le socle (`NetworkShareService::buildAcls`) pose les ACL au niveau du RÉPERTOIRE racine, pas de sous-dossier par user. Le template « élèves → profs » dans sa forme socle = un DÉPÔT PARTAGÉ où chaque élève voit/peut écraser les rendus des autres. Les vrais casiers (sous-dossier par élève, ACL fines) EXIGENT une extension du socle → **HORS scope, signalé en Question Ouverte Q1** (probable 34.x).
- **Pas de modification du socle figé** : aucune ligne de `NetworkShareService::provision`/`buildAcls`, `DrivesStateProvider` (payload/algo/`RESERVED_LETTERS`/`LETTER_POOL`), `StateCompiler`, golden `state.v1.json`, `FROZEN_STATE_HASH` PHP/Go, `agent/**` (pas de bump version agent), `contract-v1.md §7`, `ShareService`. Si une recette SEMBLE exiger une modif du socle, la SIGNALER en Question Ouverte plutôt que la planifier.
- **Pas d'AD / LdapRecord / APCu** : assignations = pivot SQL pur (User/UserGroup/WorkstationGroup), zéro CN AD (contrairement au modal raccourcis). Postgres-only (NFR7, critère Keycloak).
- **Pas de resync / archivage / suppression FS** = 34.x. La matérialisation est un SCAFFOLD one-shot : le `NetworkShare` produit s'édite ensuite via la page détail 34.2 (réutilisée telle quelle).
- **Pas de gestion `smb.conf`/`[partages]`** : export SMB = infra hors git (`[PROD]`, cadré 34.1).

## ⚠️ Pièges & tensions découverts à l'analyse (lire AVANT de coder)

1. **Piège #1 — casiers « élèves → profs » : le socle ne sait PAS faire de sous-espace par-user.** `NetworkShareService::buildAcls` pose une ACL POSIX au niveau du répertoire racine (`/var/sambaedu/Partages/<directory_name>`) ; `MAX_DEPTH=2` autorise un sous-niveau MAIS le service ne crée ni n'ACL aucun sous-dossier par élève. Un template « rendus » qui donne `classe_<classe>:rwx` au répertoire racine = tous les élèves de la classe peuvent LIRE et ÉCRASER les rendus les uns des autres. Le vrai besoin pédagogique (casier privé par élève, visible du prof seul) est IMPOSSIBLE sans extension du socle (création de N sous-dossiers + N ACL `user:<élève>`). **NE PAS implémenter de casiers ici** (ce serait modifier le socle + introduire une mécanique de réconciliation par-élève hors scope). Deux options à arbitrer (Q1) : (a) livrer « élèves → profs » comme DÉPÔT PARTAGÉ assumé (documenté : pas de cloisonnement entre élèves), ou (b) NE PAS livrer ce template en 34.3 et le renvoyer à 34.x avec les casiers. **Défaut proposé : (a) dépôt partagé**, libellé UI explicite (« dépôt commun — les élèves voient les rendus des autres »).

2. **Piège #2 — « tous » n'est pas une maille du socle.** Le template `direction → tous` veut diffuser à « tout le monde ». Or les seules mailles ACL sont `User`/`UserGroup` ; il n'existe pas de pseudo-cible « tous les utilisateurs » côté ACL POSIX. Assigner un `WorkstationGroup` « _TousLesPostes » donnerait la VISIBILITÉ (montage) mais AUCUNE ACL (invariant WG-montage-seul, `buildAcls` ignore les WG → « accès refusé »). **« tous » doit donc se matérialiser comme un (ou plusieurs) `UserGroup`(s) destinataire(s) RO** que l'admin sélectionne (ex. tous les `classe` + l'`equipe`), PAS comme un parc. **Q2 : faut-il un raccourci « sélectionner tous les groupes classe de l'étab » ?** (dépend du scope établissement — dette 34.2 Q3, pickers non scopés). Défaut : l'admin choisit explicitement les `UserGroup` destinataires (multi-sélection), pas de magie « tous ».

3. **Piège #3 — mapping `UserGroup → groupe Unix` est déjà tranché dans le socle, NE PAS le redériver.** `NetworkShareService::unixGroupFor` mappe `type='classe'`→`classe_<localPart>`, `type='equipe'`→`equipe_<localPart>` (localPart via `ShareService::aclGroupLocalPart` = nom court + suffixe étab fédéré), sinon `<localPart>`. Le template n'a donc qu'à ASSIGNER le bon `UserGroup` (la bonne `type`) au pivot avec le bon `access` ; le socle fait le reste à `provision()`. **NE PAS recalculer de nom de groupe Unix dans le template** (sinon double source de vérité + risque de divergence du suffixe étab). Le template raisonne en `UserGroup` SQL, pas en groupe Unix.

4. **Piège #4 — réutiliser la validation prédictive 34.2 AVANT matérialisation, dans une transaction.** `NetworkShareValidator` (pure lecture) sait déjà : `isReservedLetter`, `assertNoLetterCollision`, `warnings` (WG-montage-seul), `suggestNextFreeLetter`. Le service de matérialisation DOIT : (a) valider la lettre réservée + format `directory_name` (`NetworkShareService::DIRECTORY_NAME_PATTERN`) comme l'UI 34.2 ; (b) créer le share + ses assignations DANS une `DB::transaction`, appeler `assertNoLetterCollision($share)` AVANT commit (la collision n'est calculable qu'une fois l'audience insérée — patron `addAssignment` 34.2 review #1), rollback + `toastError` si collision ; (c) `provision()` seulement APRÈS commit. **NE PAS dupliquer la logique du validateur** ; l'invoquer. Surfacer les `warnings()` non bloquants en `toastWarning` après matérialisation.

5. **Piège #5 — la lettre par template = un seul lecteur partagé, encourager l'explicite (cohérent Q2 de 34.2).** Comme en 34.2, pré-remplir la prochaine lettre sûre libre (`suggestNextFreeLetter()`) pour que `letter` soit renseignée en DB (stable pour toutes les sessions). NE PAS modifier l'algo d'auto-assignation du provider (`resolveLetters`). La stabilité globale auto reste 34.x.

6. **Piège #6 — persistance du template : entité DB vs preset en code = décision structurante (Q3).** « Templates modifiables » (formulation 34.1) suggère une table `directory_templates` éditable. MAIS (mémoire `no_overengineered_choices`) : si les 5 patterns sont des recettes FIGÉES paramétrables (la variabilité est dans les CIBLES, pas dans la structure de la recette), un **registre de presets en code** (enum/classe PHP `DirectoryTemplate`) suffit et évite une table + son CRUD + ses migrations. **Défaut proposé : presets en code** (zéro nouvelle table). Une table d'instances « templates créés » n'apporte rien tant qu'on ne fait pas de re-synchronisation (34.x). **À arbitrer (Q3)** : si Henri veut des templates ÉDITABLES par l'admin (créer ses propres recettes), alors table + UI de gestion = surface bien plus large (probablement une story dédiée).

7. **Piège #7 — idempotence / ré-application (Q4).** Que se passe-t-il si l'admin matérialise DEUX fois le même template avec les mêmes paramètres ? Sans garde, on crée deux `NetworkShare` (le `directory_name` `unique` fait échouer le 2ᵉ proprement — bonne nouvelle). **Défaut proposé : la matérialisation est un ONE-SHOT** (crée un share neuf ; collision `directory_name` → message clair « ce répertoire existe déjà, éditez-le depuis sa page »). La ré-application/sync d'un template sur un share existant = 34.x. NE PAS construire de mécanique de réconciliation template↔share ici.

8. **Piège #8 — nommage auto des répertoires générés (Q5).** Faut-il dériver `directory_name` automatiquement du template + cible (ex. `rendus_6eB`) ou le laisser saisir à l'admin ? Auto-dérivation = ergonomie + cohérence, mais doit respecter `DIRECTORY_NAME_PATTERN` (alphanum + `._-`, pas d'espace/accent) → translittération nécessaire des noms de classe accentués. **Défaut proposé : proposer un `directory_name` pré-rempli dérivé (slug du template + slug de la cible principale), MODIFIABLE et validé au format** ; ne pas imposer. Réutiliser/centraliser un slugifieur sûr (`Str::slug` produit des `-`, conforme au pattern).

9. **Piège #9 — sécurité : MÊME gating que 34.2.** Page/actions gardées par `NetworkSharePolicy` + permissions `networkshare.view`/`networkshare.manage` (refnum + ShareAdmin + UserAdmin + SuperAdmin). La matérialisation est une action MUTANTE → `abort_unless(Gate::allows('manage-networkshare'), 403)` sur l'action, comme les actions de `[id]/index.blade.php`. PAS de nouvelle permission. PAS de sur-attribution. AJOUTER la couverture `RoutesProtectionTest` si une nouvelle route est créée (sinon la matérialisation est une modale sur `/app/shares` déjà couverte).

10. **Piège #10 — dette 34.2 NON aggravée.** Les pickers `User`/`UserGroup`/`WorkstationGroup` restent NON scopés par établissement (dette 34.2 Q3, pas de scope établissement SQL homogène — l'AD est banni du chemin SQL). Le template les RÉUTILISE tels quels ; il ne doit pas tenter d'inventer un scope AD. La collision **cross-maille** reste non détectée (limitation 34.2 M-A assumée → 34.x). Les NOMMER dans le code/doc si un template les touche (ex. « groupe » + « direction→tous » multi-groupes amplifient mécaniquement le risque cross-maille — le signaler).

## Décisions de design

> Les décisions « TRANCHÉES » cadrent la story et sont appliquées telles quelles par le dev. Les **QUESTIONS OUVERTES** (Q1-Q6) touchent le métier/architecture et sont laissées à l'arbitrage d'Henri — le dev NE les tranche PAS seul ; il implémente l'option PAR DÉFAUT indiquée et signale l'alternative dans le Dev Agent Record si l'arbitrage n'est pas rendu avant le dev.

### TRANCHÉES (cadrage de la story)

1. **Scope = couche de préfabrication PAR-DESSUS 34.1/34.2.** Réutilisation STRICTE : `NetworkShare` + pivot + `NetworkShareService::provision` + `NetworkShareValidator` + `DrivesStateProvider` + `NetworkSharePolicy` + page détail 34.2. Un template = recette qui produit des `NetworkShare`+assignations.
2. **Pivot SQL pur** : cibles `User`/`UserGroup`/`WorkstationGroup` SQL, zéro CN AD, zéro LdapRecord, zéro APCu.
3. **WG = montage-seul** (invariant 34.1) : un template basé parc ne donne QUE de la visibilité (jamais d'ACL). Les templates d'échange (direction/profs/élèves/user/groupe) portent leurs grants sur des mailles `User`/`UserGroup` (axe ACL). Un éventuel paramètre « visible aussi sur le parc X » = assignation WG additionnelle MONTAGE-SEUL, clairement étiquetée.
4. **Réutilisation du validateur** : `isReservedLetter` + `assertNoLetterCollision` (dans transaction, patron `addAssignment`) + `warnings` (WG-montage-seul) + `suggestNextFreeLetter`. Format `directory_name` via `NetworkShareService::DIRECTORY_NAME_PATTERN`. AUCUNE règle nouvelle dupliquée.
5. **Provisioning SYNCHRONE → toast** (cohérent Q1 de 34.2 : `NetworkShareService::provision()` dans l'action, mappage `bool`→toast). Pas de job.
6. **Sécurité** : `NetworkSharePolicy` + `networkshare.view`/`networkshare.manage` réutilisées. Pas de nouvelle permission. Double garde (route `can:` si nouvelle route + `abort_unless` sur l'action mutante).

### QUESTIONS OUVERTES — arbitrage Henri (métier/architecture)

- **Q1 — Casiers « élèves → profs » (piège #1, le plus important).** Le socle pose l'ACL au répertoire racine, pas de sous-dossier par élève. **Défaut : livrer « élèves → profs » en DÉPÔT PARTAGÉ assumé** (élèves `classe:rwx`, prof `equipe:rwx`, libellé UI « dépôt commun, pas de cloisonnement »). Alternative : NE PAS livrer ce template en 34.3 et renvoyer les vrais casiers (sous-dossier+ACL par élève = extension `NetworkShareService`) à 34.x. *Décision structurante : livre-t-on un dépôt partagé maintenant, ou attend-on les casiers ?*
- **Q2 — Résolution de « tous » pour `direction → tous` (piège #2).** « tous » = mailles `UserGroup` explicitement sélectionnées (pas de parc, sinon montage-seul sans ACL). **Défaut : multi-sélection `UserGroup` destinataires (RO), choix explicite de l'admin.** Alternative : un raccourci « tous les groupes `classe` de l'établissement » (dépend du scope étab — dette 34.2 Q3 ; non disponible en SQL homogène). *Garde-t-on le choix explicite, ou ajoute-t-on un raccourci (et lequel) ?*
- **Q3 — Persistance des templates (piège #6).** **Défaut : presets en CODE** (enum/classe `DirectoryTemplate`, zéro table). Alternative : table `directory_templates` éditable (l'admin crée ses propres recettes) = surface bien plus large, probablement une story dédiée. *Presets figés suffisent-ils, ou Henri veut-il des templates éditables ?*
- **Q4 — Idempotence / ré-application (piège #7).** **Défaut : matérialisation ONE-SHOT** (crée un share neuf ; `directory_name` déjà pris → message « éditez-le depuis sa page »). La sync template↔share = 34.x. *Confirme-t-on le one-shot ?*
- **Q5 — Nommage auto du répertoire généré (piège #8).** **Défaut : `directory_name` pré-rempli dérivé (slug template+cible), modifiable, validé au format.** Alternative : saisie 100 % manuelle (comme 34.2). *Auto-dérive-t-on (et sur quelle base : template+cible principale) ?*
- **Q6 — Mailles paramétrables exposées.** **Défaut : par template, exposer UNIQUEMENT les rôles métier du pattern** (ex. « rendus » → un picker `classe` + un picker `equipe` ; « user↔user » → deux pickers `User`). Une assignation WG additionnelle « visible aussi sur le parc X » (montage-seul) = option AVANCÉE, masquée par défaut. *Expose-t-on l'option parc montage-seul dès 34.3 ou la réserve-t-on ?*

## Acceptance Criteria

### AC1 — Catalogue de templates d'échange (recettes)

**Given** le besoin de préfabriquer les patterns d'échange récurrents
**When** le système expose le catalogue de templates
**Then** un registre (defaut Q3 : presets en code, ex. enum/classe `App\Services\Filesystem\DirectoryTemplate` ou `App\Enums\DirectoryTemplate`) décrit AU MOINS : `direction → tous`, `profs → élèves`, `élèves → profs`, `user ↔ user`, `groupe`
**And** chaque template porte : un `key` stable, un libellé FR, une description métier (qui dépose / qui lit), et la SPÉCIFICATION de ses rôles-cibles (quelle(s) maille(s) `User`/`UserGroup` attendre, et l'`access` ro|rw associé à chaque rôle)
**And** aucun template ne porte de grant ACL sur un `WorkstationGroup` (invariant WG-montage-seul) — un parc, s'il est exposé (Q6), n'est qu'une visibilité additionnelle clairement étiquetée
**And** le mapping `UserGroup → groupe Unix` n'est PAS redérivé (le template assigne le `UserGroup` ; `NetworkShareService::unixGroupFor` mappe à `provision()` — piège #3).

### AC2 — Service de matérialisation (transaction + validation réutilisée)

**Given** un template choisi + ses paramètres (libellé, `directory_name`, lettre, cibles résolues en `User`/`UserGroup`)
**When** `DirectoryTemplateService::materialize($template, $params, ?performedBy)` est appelé
**Then** un `NetworkShare` est créé (`created_by_user_id` = refnum courant) et ses lignes `network_share_assignables` (cible polymorphe + `access` selon la recette) sont insérées DANS une `DB::transaction`
**And** AVANT commit, `NetworkShareValidator::assertNoLetterCollision($share)` est invoqué (audience désormais peuplée) — sur `NetworkShareLetterCollisionException`, la transaction est ROLLBACK (aucune écriture partielle) et l'erreur remonte pour mappage en `toastError`
**And** le format `directory_name` (`NetworkShareService::DIRECTORY_NAME_PATTERN`) et la lettre réservée (`NetworkShareValidator::isReservedLetter`) sont validés AVANT toute écriture
**And** APRÈS commit, `NetworkShareService::provision($share)` est appelé (synchrone) ; les `warnings()` non bloquants (WG-montage-seul) sont retournés pour surfaçage en `toastWarning`
**And** seuls les types de `NetworkShare::ALLOWED_ASSIGNABLE_TYPES` sont insérés ; zéro AD/LdapRecord ; lecture/écriture Postgres-only.

### AC3 — UI : « Créer depuis un template » (formulaire dynamique + aperçu)

**Given** un refnum avec `networkshare.manage`
**When** il ouvre « Créer depuis un template » sur `/app/shares` (modale réutilisable `x-molecules.modal`, ou page `/app/shares/from-template` selon le patron retenu)
**Then** il choisit un template ; le formulaire affiche DYNAMIQUEMENT les paramètres requis par CE template : `name`, `directory_name` (pré-rempli dérivé si Q5 défaut, validé au format + `unique`), `letter` (pré-remplie `suggestNextFreeLetter()`, refus si réservée), et les pickers de cibles correspondant aux rôles du pattern (pickers SQL `User`/`UserGroup`, zéro CN AD — réutilisent ceux de 34.2)
**And** un APERÇU liste les assignations qui seront créées (cible → maille → access) AVANT validation, et affiche le warning WG-montage-seul/limitation casiers le cas échéant
**And** à la soumission, `DirectoryTemplateService::materialize(...)` est appelé ; succès → `toastSuccess` + redirection/retour vers la page détail du share créé (ou la liste) ; collision de lettre → `toastError` (pas de création) ; `directory_name` déjà pris → message clair (« éditez-le depuis sa page », Q4)
**And** la page/action est gardée par `NetworkSharePolicy` (`abort_unless(Gate::allows('manage-networkshare'), 403)`), via `WithToasts`.

### AC4 — Conformité socle figé (non-régression garantie)

**Then** la story ne modifie AUCUNE ligne de : `NetworkShareService::provision`/`buildAcls`/`unixGroupFor`, `DrivesStateProvider` (payload `{letter,unc,label}`, `resolveLetters`, `RESERVED_LETTERS`, `LETTER_POOL`), `StateCompiler`, golden `state.v1.json`, `FROZEN_STATE_HASH` PHP/Go, `agent/**` (pas de bump version agent), `contract-v1.md §7`, `ShareService`
**And** `NetworkShareValidator` et `NetworkSharePolicy` sont RÉUTILISÉS (exposition possible d'un helper public si strictement nécessaire, sans changer le comportement — à justifier en review)
**And** `--filter ContractV1`, `--filter DrivesStateProvider`, `--filter NetworkShare`, `--filter Agent` restent verts (golden inchangé), baseline relevée AVANT (filtres ciblés, jamais run massif VM).

### AC5 — Tests (HÔTE php8.4 + sqlite, filtres ciblés)

**Then** tests unitaires `DirectoryTemplateService` : matérialisation de CHAQUE template (assignations + access corrects par maille), collision de lettre → rollback (aucune ligne `network_shares`/pivot persistée), lettre réservée refusée, format `directory_name` invalide refusé, WG jamais grant ACL, zéro AD
**And** tests Livewire de l'UI template (choix du template, formulaire dynamique, aperçu, matérialisation valide/invalide, `directory_name` déjà pris, gating policy)
**And** test que le catalogue couvre les 5 patterns et que chaque recette respecte l'invariant WG-montage-seul
**And** non-régression GARANTIE : golden `state.v1.json` + `FROZEN_STATE_HASH` PHP/Go INCHANGÉS ; baselines `ContractV1`/`DrivesStateProvider`/`NetworkShare`/`Agent` re-validées APRÈS ; sur l'HÔTE (mémoire `phpunit_test_env_host_vs_vm`), filtres ciblés (mémoire `vm_phpunit_bulk_run_false_failures`).

### AC6 — Documentation + backlog (append-only)

**Then** `docs/qa/domains/filesystem.md` enrichi (Story 34.3, scénarios templates + limitation casiers + WG-montage-seul) ; `docs/agent/state-providers.md` reste cohérent (les templates produisent des `NetworkShare` standards, projection inchangée)
**And** `_bmad-output/backlog.data.js` : story `34-3` ajoutée dans l'Epic 34 (status suivi via `sprint-status.yaml`) ; fichiers backlog committés ensemble (mémoire `backlog_split_multifile`)
**And** restent **INTOUCHÉS** : socle figé (cf. AC4), canal legacy partages, `ShareService`, `NetworkShareService::provision`. La dette 34.2 (pickers non scopés étab ; collision cross-maille) est NOMMÉE, pas aggravée.

## Tasks / Subtasks

- [x] **T1 — Catalogue de templates (recettes)** (AC1 ; Q3/Q6)
  - [x] **TABLE `directory_templates` (Q3 option B, arbitrage Henri)** : migration additive `2026_06_30_120000_create_directory_templates_table.php` (key/label/description/roles_spec JSON) + modèle `App\Models\DirectoryTemplate` + `DirectoryTemplateSeeder` (4 recettes, idempotent iso `PermissionSeeder`). La recette est LUE en DB par le service (pas d'enum en dur).
  - [x] Chaque recette = liste de « rôles » {key, label, maille (`User`/`UserGroup`), group_type (`classe`/`equipe`/null), access ro|rw, cardinalité one|many}. Invariant : `ALLOWED_ROLE_MAILLES` = User|UserGroup (aucun grant ACL sur WG) + `respectsMountOnlyInvariant()`.
  - [x] POURQUOI métier documenté par recette dans le seeder ; `élèves → profs`/casiers REPORTÉ 34.x (Q1 — 4 recettes seulement, drop du 5e).

- [x] **T2 — `DirectoryTemplateService::materialize` (transaction + validation réutilisée)** (AC2 ; pièges #3/#4)
  - [x] `app/Services/Filesystem/DirectoryTemplateService.php` : `materialize(DirectoryTemplate $template, array $params, ?string $performedBy): TemplateMaterializationResult` (DTO {share, warnings, provisioned}).
  - [x] Valide format `directory_name` (`NetworkShareService::isValidDirectoryName`) + lettre réservée (`NetworkShareValidator::isReservedLetter`) AVANT écriture (→ `InvalidArgumentException`).
  - [x] `DB::transaction` : crée `NetworkShare` + insère les `network_share_assignables` (cible + access selon la recette) → `assertNoLetterCollision($share->fresh())` AVANT commit → rollback + propagation `NetworkShareLetterCollisionException` si collision (patron `addAssignment` 34.2). Cardinalité + typage `group_type` + existence cibles + anti-doublon validés.
  - [x] APRÈS commit : `NetworkShareService::provision($share, $performedBy)` ; retourne `warnings()`. AUCUN nom de groupe Unix recalculé (piège #3).

- [x] **T3 — UI « Créer depuis un template »** (AC3 ; pièges #5/#8/#9)
  - [x] 2e modale réutilisable `x-molecules.modal` sur `resources/views/pages/shares/index.blade.php` (bouton « Créer depuis un template », iso création 34.2).
  - [x] Sélecteur de template → formulaire DYNAMIQUE (pickers SQL `User`/`UserGroup` selon les rôles de la recette LUE en DB ; `UserGroup` filtré par `group_type` ; zéro CN AD).
  - [x] `directory_name` saisi MANUELLEMENT (Q5 — pas d'auto-slug), validé format + unique ; `letter` pré-remplie `suggestNextFreeLetter()` (refus réservée).
  - [x] APERÇU des assignations (cible→maille→access) + warning WG-montage-seul/limitation casiers.
  - [x] Action `createFromTemplate()` : `abort_unless(Gate::allows('manage-networkshare'),403)` → `validate` (champ format/unique/réservée) → `materialize` → toasts (succès/collision/`directory_name` déjà pris Q4) → redirection page détail.
  - [x] PAS de nouvelle route (modale sur `/app/shares` déjà couverte par `RoutesProtectionTest`).

- [x] **T4 — Tests** (AC5)
  - [x] `tests/Unit/Services/Filesystem/DirectoryTemplateServiceTest.php` (`Process::fake()`) : chaque template → assignations+access corrects ; collision lettre → rollback (0 ligne) ; lettre réservée refusée ; format invalide refusé ; cardinalité/typage/cible introuvable refusés ; WG jamais grant ACL ; zéro AD. (12 tests)
  - [x] `tests/Feature/Livewire/Shares/SharesFromTemplateTest.php` : formulaire dynamique, aperçu, matérialisation valide/invalide, `directory_name` déjà pris, collision, gating policy. (11 tests)
  - [x] `tests/Unit/Database/DirectoryTemplateSeederTest.php` : 4 recettes + `élèves→profs` ABSENT + invariant WG + idempotence re-seed. (4 tests)
  - [x] Baselines APRÈS ciblées : `ContractV1` 5/104, `DrivesStateProvider`+`NetworkShare`+`RoutesProtection`+`Policy` 96, `Agent` 540/22skip — golden inchangé.

- [x] **T5 — Documentation + backlog** (AC6)
  - [x] `docs/qa/domains/filesystem.md` (Section Story 34.3, scénarios 34.3-1..8 + checklist + note casiers HORS 34.3) + `docs/qa/README.md` (ligne filesystem enrichie).
  - [x] `_bmad-output/backlog.data.js` (34-3 → review, `node --check` OK) + `sprint-status.yaml` (34-3 → review). Fichiers backlog ensemble.
  - [x] Socle figé INTOUCHÉ vérifié (git status) : `DrivesStateProvider`, golden, `FROZEN_STATE_HASH`, `agent/**`, `StateCompiler`, `ShareService`, `NetworkShareService::provision`.

## Dev Notes

### Patterns à RÉUTILISER (chemins réels — ne pas réinventer)

- **Fondation 34.1 (NE PAS modifier)** : `app/Models/NetworkShare.php` (`assignments()`, `users()/userGroups()/workstationGroups()` morphedByMany `withPivot('access')`, `ALLOWED_ASSIGNABLE_TYPES`, `TYPE_DRIVES`, `effectiveLabel()`), `app/Models/NetworkShareAssignable.php` (`ACCESS_RO`/`ACCESS_RW`, `isWritable()`), `app/Services/Filesystem/NetworkShareService.php` (`provision($share, ?performedBy): bool`, `DIRECTORY_NAME_PATTERN`, `isValidDirectoryName`, `buildAcls` [WG ignoré], `unixGroupFor` [mapping classe_/equipe_+suffixe étab — NE PAS redériver]).
- **Validation prédictive 34.2 (RÉUTILISER)** : `app/Services/Filesystem/NetworkShareValidator.php` — `isReservedLetter`, `warnings`, `letterCollisions`, `assertNoLetterCollision`, `suggestNextFreeLetter`, `isAllowedAssignableType`, `bareLetter`. Exception `app/Exceptions/Filesystem/NetworkShareLetterCollisionException.php` (`fromCollisions`).
- **Patron transaction + assertNoLetterCollision** : `resources/views/pages/shares/[id]/index.blade.php::addAssignment` (review 34.2 #1) — `DB::transaction { updateOrCreate; assertNoLetterCollision } catch { toastError }`. À reproduire pour la matérialisation (audience peuplée → collision calculable).
- **UI / SFC Volt + modale** : `resources/views/pages/shares/index.blade.php` (modale de création `x-molecules.modal`, `suggestNextFreeLetter()` pré-remplissage, validation lettre réservée, `createShare`), `resources/views/pages/shares/[id]/index.blade.php` (pickers SQL `User`/`UserGroup`/`WorkstationGroup`, `describeAssignable`, gating `abort_unless(Gate::allows('manage-networkshare'))`).
- **Policy** : `app/Policies/NetworkSharePolicy.php` (gates `view-networkshare`/`manage-networkshare`) + permissions `networkshare.view`/`networkshare.manage` (`app/Enums/SambaPermission.php`, `app/Enums/SambaRole.php`). Réutiliser, ne PAS créer de permission.
- **Notifications** : `app/Components/Traits/WithToasts.php` (`toastSuccess/Error/Warning`, `session()->flash('toast', ...)` pour survie redirect).
- **Routing filesystem-based** : routes EXPLICITES dans `routes/web.php` (groupe `app`, à côté des routes `shares`). `RoutesProtectionTest` couvre déjà `/app/shares` + `/app/shares/{id}`.
- **UserGroup typé** : `app/Models/UserGroup.php` — `type` (`'classe'`, `'equipe'`, …), `scopeByType($type)`, pivot `is_head_teacher`. Le template « profs→élèves »/« élèves→profs » cible un `UserGroup` type `equipe` (profs) + un type `classe` (élèves) ; le socle mappe au groupe Unix à `provision()`.
- **Slug sûr** : `Illuminate\Support\Str::slug()` produit des segments `[a-z0-9-]` conformes à `DIRECTORY_NAME_PATTERN` (Q5).

### Contraintes d'environnement (mémoires)

- **Tests sur l'HÔTE** (php8.4 + pdo_sqlite ; VM sans pdo_sqlite) — `phpunit_test_env_host_vs_vm`. **Filtres ciblés, jamais run massif VM** — `vm_phpunit_bulk_run_false_failures`.
- **Worktree** : ne PAS interagir avec la VM/serveurs depuis ce worktree — `feedback_worktree_no_vm_sync` ; code sync via inotify (ne pas sync manuellement).
- **PHP-FPM = www-admin** : `provision()` pose déjà `chown www-admin` — `php_fpm_user_www_admin`.
- **`Cache::lock()` + APCu** : `provision()` utilise déjà `Cache::store('file')->lock()` — `apcu_cache_no_lock`.
- **Racine projet = Laravel** (`artisan`/`app/` à la racine) — `root_is_laravel`.
- **Livewire** : JAMAIS d'action nommée `upload` (réservée) — `livewire_reserved_upload_method`.
- **Pas de sur-conception** : règle dérivable → l'énoncer et avancer — `no_overengineered_choices` (cf. Q3 presets en code vs table).
- **Métier d'abord** : cadrer le POURQUOI de chaque template avant la mécanique — `understand_business_before_design`.

### [PROD] — Infra serveur (hors git, rappel 34.1)

- Le RO/RW réel dépend de l'export SMB `[partages]` → `/var/sambaedu/Partages` que SE5 NE gère PAS (hors git, iso `[users]`/`[classes]`). Les templates produisent des `NetworkShare` standards ; ils n'ajoutent aucune exigence infra nouvelle. Sudoers déjà couvert (34.1).

### Project Structure Notes

- Catalogue : `app/Services/Filesystem/DirectoryTemplate.php` (ou `app/Enums/DirectoryTemplate.php`) — presets en code (Q3 défaut).
- Service : `app/Services/Filesystem/DirectoryTemplateService.php`.
- UI : modale sur `resources/views/pages/shares/index.blade.php` (privilégié) ou page `resources/views/pages/shares/from-template/index.blade.php`.
- Tests : `tests/Unit/Services/Filesystem/DirectoryTemplateServiceTest.php`, `tests/Feature/Livewire/Shares/*`.
- AUCUNE migration (Q3 défaut presets en code). Si Q3 → table, migration additive `directory_templates`.

### Decompose — reste de l'Epic 34 (hors scope 34.3, pour cadrage)

- **34.x** : casiers/sous-espaces par-utilisateur (extension `NetworkShareService` : sous-dossiers + ACL par élève) — débloque les vrais « rendus » cloisonnés (Q1).
- **34.x** : commande de resync (`shares:resync-network`), réconciliation FS, archivage/suppression FS deux temps, stabilité globale de la lettre auto, fermeture collision cross-maille (limitation 34.2 M-A), templates ÉDITABLES (si Q3 → table).

### References

- [Source: _bmad-output/implementation-artifacts/34-1-fondations-lecteurs-reseau-geres.md] — fondation backend, modèle d'accès 2 axes, invariant WG-montage-seul, section « Decompose » cadrant 34.3 (« templates = recettes d'assignation+ACL préconfigurées »).
- [Source: _bmad-output/implementation-artifacts/34-2-ui-admin-lecteurs-reseau-geres.md] — UI admin, `NetworkShareValidator`, `NetworkSharePolicy`, permissions `networkshare.*`, dette pickers non scopés (Q3 34.2), patron transaction+collision.
- [Source: _bmad-output/codeReviews/34-1.md] — invariant WG-montage-seul (M5), collision 2-répertoires déléguée à la validation prédictive.
- [Source: _bmad-output/codeReviews/34-2.md] — patron `addAssignment` (transaction + assertNoLetterCollision, finding #1), limitation cross-maille assumée (M-A → 34.x).
- [Source: app/Services/Filesystem/NetworkShareService.php] — `provision`, `buildAcls` (WG ignoré), `unixGroupFor` (mapping classe_/equipe_+suffixe étab), `DIRECTORY_NAME_PATTERN`, `MAX_DEPTH=2`.
- [Source: app/Services/Filesystem/NetworkShareValidator.php] — `assertNoLetterCollision`, `isReservedLetter`, `warnings`, `suggestNextFreeLetter`, `audienceKeys` (limitation cross-maille documentée).
- [Source: resources/views/pages/shares/index.blade.php + [id]/index.blade.php] — modale création, pickers SQL, patron transaction, gating policy.
- [Source: app/Models/UserGroup.php] — `type` (classe/equipe), `scopeByType`, pivot `is_head_teacher`.
- [Source: memory/project_native_drive_management_direction.md] — direction native, golden figé, lettres K/H/I/L.
- [Source: memory/project_network_shares_342_design_traps.md] — WG=montage-seul + lettre stable.
- [Source: memory/project_acl_equipe_group_missing_etab_suffix.md] — suffixe établissement fédéré sur les groupes ACL.
- [Source: memory/feedback_no_overengineered_choices.md] — presets en code vs table (Q3).
- [Source: memory/feedback_understand_business_before_design.md] — cadrer le POURQUOI métier de chaque template.

## Dev Agent Record

### Agent Model Used

Opus 4.8 (1M context) — `claude-opus-4-8[1m]`

### Debug Log References

- Piège Volt : `use InvalidArgumentException;` (nom non-composé) dans un composant Volt en namespace GLOBAL lève `ErrorException` (« use statement … has no effect »). Corrigé en référençant `\InvalidArgumentException` directement dans le `catch` (pas de `use`).
- `Process::fake()` configuré une seule fois par test (handlers au 1er appel) — pattern hérité de `NetworkShareServiceTest`.

### Completion Notes List

**Arbitrages Henri (2026-06-30) appliqués intégralement :**
- **Q1** — Le template `élèves → profs` (casiers/rendus par-élève) N'EST PAS livré : seulement **4 recettes** (`direction_to_all`, `profs_to_eleves`, `user_to_user`, `group_space`). Les vrais casiers (sous-dossier + ACL par élève = extension du socle) sont reportés à 34.x, documenté code + QA.
- **Q3** — Option B : **table `directory_templates` + `DirectoryTemplateSeeder`** (PAS d'enum de presets en dur, PAS de CRUD UI). `DirectoryTemplateService::materialize` LIT la recette depuis la DB. Seeder idempotent (`updateOrCreate` sur `key`, iso `PermissionSeeder`).
- **Q5** — `directory_name` saisi **manuellement** (validé format + unique), pas d'auto-dérivation slug.
- **Q2/Q4/Q6** — défauts : « tous » = `UserGroup` explicites (multi-sélection RO) ; matérialisation one-shot (`directory_name` déjà pris → message « éditez-le depuis sa page ») ; seuls les rôles du pattern exposés (option parc montage-seul masquée).

**Réutilisation stricte du socle figé (INTOUCHÉ) :** `NetworkShare`/pivot, `NetworkShareService::provision`/`buildAcls`/`unixGroupFor`, `NetworkShareValidator` (`isReservedLetter`/`assertNoLetterCollision`/`warnings`/`suggestNextFreeLetter`), `NetworkSharePolicy` + permissions `networkshare.*`, `NetworkShareLetterCollisionException`. Le service n'a fait qu'ASSIGNER le bon `UserGroup`/`User` au pivot ; le mapping de maille → groupe Unix reste au socle (`unixGroupFor` à `provision()`, piège #3).

**Invariant WG-montage-seul** : aucune recette ne porte de maille `WorkstationGroup` (`DirectoryTemplate::ALLOWED_ROLE_MAILLES` = User|UserGroup, `respectsMountOnlyInvariant()` vérifié en test) ; le service refuse toute maille hors User/UserGroup (defense-in-depth).

**⚠️ PRÉ-DÉPLOIEMENT VM (seed prod requis)** : `php artisan db:seed --class=DirectoryTemplateSeeder` doit être exécuté sur la VM pour peupler les 4 recettes (idempotent, re-jouable). Tant que ce seed n'est pas joué, le sélecteur de template est vide. (Le seeder est aussi câblé dans `DatabaseSeeder` pour un `db:seed` global.)

**Non-régression / golden** : `state.v1.json` + `FROZEN_STATE_HASH` (PHP + Go) **INCHANGÉS** ; `agent/**` intouché (pas de bump version agent) ; `StateCompiler`/`DrivesStateProvider`/`ShareService`/`contract-v1 §7` intouchés (git status). UI ajoutée comme 2e modale sur `/app/shares` ⇒ **pas de nouvelle route** (couverture `RoutesProtectionTest` existante suffit).

**Résultats des tests HÔTE (php8.4.5 + sqlite, filtres ciblés) :**
- Net-new : `DirectoryTemplateServiceTest` 12 ✓ (38 assert) ; `DirectoryTemplateSeederTest` 4 ✓ ; `SharesFromTemplateTest` 10 ✓ (51 assert).
- Non-régression : `ContractV1|DrivesStateProvider|NetworkShare|RoutesProtection` (+ `NetworkSharePolicy`) 96 ✓ (315 assert) ; `Agent` 540 ✓ / 22 skip (ext-zip absent, vert sur /vm).

**Dette laissée (non aggravée) :** pickers `User`/`UserGroup` NON scopés par établissement (dette 34.2 Q3) ; collision cross-maille best-effort (M-A 34.2) ; pickers de cibles sans recherche dédiée (limite 100, alphabétique) — la page détail 34.2 garde la recherche complète pour l'édition fine. Le template `group_space` est fixé en RW (espace commun collaboratif) ; le paramètre « RW ou RO selon param » du corps de story n'a pas été exposé (simplicité, dérivable — `no_overengineered_choices`).

### File List

**Créés :**
- `database/migrations/2026_06_30_120000_create_directory_templates_table.php`
- `app/Models/DirectoryTemplate.php`
- `database/seeders/DirectoryTemplateSeeder.php`
- `app/Services/Filesystem/DirectoryTemplateService.php`
- `app/Services/Filesystem/TemplateMaterializationResult.php`
- `tests/Unit/Services/Filesystem/DirectoryTemplateServiceTest.php`
- `tests/Unit/Database/DirectoryTemplateSeederTest.php`
- `tests/Feature/Livewire/Shares/SharesFromTemplateTest.php`

**Modifiés :**
- `database/seeders/DatabaseSeeder.php` (câblage `DirectoryTemplateSeeder`)
- `resources/views/pages/shares/index.blade.php` (2e modale « Créer depuis un template » + état/méthodes Volt)
- `docs/qa/domains/filesystem.md` (Section Story 34.3 + checklist)
- `docs/qa/README.md` (ligne filesystem enrichie 34.2/34.3)
- `_bmad-output/backlog.data.js` (34-3 → review)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (34-3 → review)
- `_bmad-output/implementation-artifacts/34-3-templates-de-repertoire.md` (cette story : checkboxes + Dev Agent Record + Status review)

### Change Log

- 2026-06-30 — Story 34.3 implémentée (dev-story, Opus 4.8) : table `directory_templates` + seeder (4 recettes, Q3 option B) + `DirectoryTemplateService::materialize` (transaction + validation réutilisée 34.2) + 2e modale « Créer depuis un template » sur `/app/shares`. Socle figé INTOUCHÉ ; casiers « élèves→profs » reportés 34.x (Q1). 26 tests net-new verts, non-régression OK. Status → review.

## Recommandation Modèle Dev

**Reco : `opus`.**

Comme 34.2, 34.3 ressemble en surface à de l'UI/CRUD Livewire « sonnet-friendly » (une modale de plus sur `/app/shares`, des pickers déjà existants). Mais la valeur — et le risque — sont ailleurs :

1. **La conception des recettes est un problème de modélisation métier, pas de CRUD.** Chaque template encode DEUX axes (visibilité/ACL), le mapping de maille correct, et un invariant non trivial (WG = jamais d'ACL). Le piège « élèves → profs » (le socle ne fait pas de casiers par-user) est exactement le genre de gap où un modèle moins capable livrerait silencieusement un dépôt partagé en le présentant comme des casiers cloisonnés — un faux sens métier dangereux. Tenir la frontière « ce que le socle sait faire vs ce qu'il faut signaler comme gap » exige du jugement.
2. **Le socle figé reste un champ de mines adjacent.** Réutiliser `provision`/`buildAcls`/`unixGroupFor`/`RESERVED_LETTERS`/le validateur SANS rien modifier du contrat agent figé, et résister à la tentation de « juste redériver le nom de groupe Unix » ou « ajouter une cible tous » — c'est le même réflexe « ce qu'il ne faut PAS toucher » qui a fait recommander opus en 34.1/34.2.
3. **Six décisions de design ouvertes (Q1-Q6)** demandent un dev capable de tenir l'option par défaut tout en signalant proprement les alternatives, sans sur-concevoir (mémoire `no_overengineered_choices` : presets en code, pas de table prématurée).

Le formulaire dynamique pur serait sonnet ; la modélisation des recettes à deux axes + la préservation du socle figé + le piège casiers + les six arbitrages font basculer en **opus**.

## Dépendances

- **34-1** « Fondations des lecteurs réseau gérés » — statut `review` (to-validate, `codeReviews/34-1.md`). Fournit `NetworkShare` + pivot + `NetworkShareService::provision`/`buildAcls`/`unixGroupFor` + extension `DrivesStateProvider`. RÉUTILISÉ tel quel, INTOUCHÉ.
- **34-2** « UI admin des lecteurs réseau gérés » — statut `review` (to-validate, `codeReviews/34-2.md`). Fournit `NetworkShareValidator`, `NetworkShareLetterCollisionException`, `NetworkSharePolicy`, permissions `networkshare.view`/`networkshare.manage`, page liste/détail `/app/shares`, pickers SQL, patron transaction+collision. RÉUTILISÉ tel quel.

> Les deux dépendances sont en `review` (non encore `done`). Le code de la branche unifiée est présent et utilisable. Si 34.1/34.2 reçoivent des corrections de finalisation avant le dev de 34.3, re-vérifier les signatures réutilisées (`provision`, `assertNoLetterCollision`, `DIRECTORY_NAME_PATTERN`, gates `manage-networkshare`).

## Questions ouvertes pour Henri

1. **Q1 — Casiers « élèves → profs » (LE point structurant).** Livre-t-on « élèves → profs » en **dépôt partagé assumé** (élèves se voient/écrasent mutuellement, libellé UI explicite) [défaut], ou **reporte-t-on ce template à 34.x** avec les vrais casiers (sous-dossier + ACL par élève = extension du socle `NetworkShareService`) ?
2. **Q2 — Résolution de « tous » pour `direction → tous`.** Multi-sélection explicite de `UserGroup` destinataires (RO) [défaut, car un parc donnerait montage-seul sans ACL], ou ajout d'un raccourci « tous les groupes `classe` de l'établissement » (qui dépend du scope établissement absent en SQL — dette 34.2 Q3) ?
3. **Q3 — Persistance des templates.** Presets FIGÉS en code (enum/classe, zéro table) [défaut, anti sur-conception], ou table `directory_templates` ÉDITABLE permettant à l'admin de créer ses propres recettes (surface bien plus large, probable story dédiée) ?
4. **Q4 — Idempotence / ré-application.** Matérialisation ONE-SHOT (crée un share neuf ; `directory_name` déjà pris → « éditez-le depuis sa page ») [défaut], la sync template↔share restant 34.x — confirmé ?
5. **Q5 — Nommage auto du répertoire généré.** `directory_name` pré-rempli dérivé (slug template+cible, modifiable, validé format) [défaut], ou saisie 100 % manuelle comme 34.2 ?
6. **Q6 — Mailles paramétrables exposées.** Par template, exposer UNIQUEMENT les rôles métier du pattern [défaut], l'option « visible aussi sur le parc X » (assignation WG montage-seul) étant masquée/avancée — ou l'exposer dès 34.3 ?
