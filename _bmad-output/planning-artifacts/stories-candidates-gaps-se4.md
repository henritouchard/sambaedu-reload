# Stories candidates — gaps SE4 sans story (issues de docs/features-se4-SE5.md §14)

> Artefact de planning, 2026-07-02. Aucune de ces stories n'est au backlog : ce document propose,
> pour chacun des 13 gaps, soit une story rédigée, soit une recommandation d'abandon formel ou de fusion.
> Rien n'est inscrit dans `backlog.data.js` tant que l'arbitrage n'est pas fait.

## Vue d'ensemble & recommandations

| Gap (§ features) | Proposition | Reco | Taille |
|---|---|---|---|
| UI prof coupure Internet (§4.6) | **Fusionner dans 27-13** (capacité `internet_access`), pas de story séparée | Fusion | — |
| Espace personnel quota (§2.5) | Story A1 | Porter | S |
| Workflow devoirs — collecte des copies (§8.5) | Story A2 | Porter (après 34.x casiers) | M |
| Comptes temporaires (§1.12) | Story B1 | Porter (modèle SQL natif) | S |
| Nettoyage comptes orphelins (§1.14) | Story B2 | Porter (repensé : rapport, pas purge auto) | S |
| Prof remplaçant (§1.13) | Story B3 | Reporter — besoin métier à confirmer | S |
| Suppression des jobs d'impression (§9.4) | Story C1 | Porter | XS |
| Imprimantes par groupe utilisateur (§9.3) | Story C2 | Investigation d'abord | XS (spike) |
| Édition SMTP en UI (§12.5) | Story D1 | Porter (page settings + mail de test) | S |
| Paramètres conf_params orphelins (§12.4) | Story D2 | Triage (spike décisionnel) | S |
| Actions serveur physiques (§12.6) | Story D3 | Porter le minimum (shutdown/reboot serveur) ; Redfish/console = abandon | S |
| Catalogue CAS/ENT (§2.3) | Story E1 | Porter en données, pas en code | S |
| Sync cloud Nextcloud (§8.9) | **Abandon formel** | Abandon | — |
| Import des répertoires à droits custom (hors §14 — session 2026-07-02) | Stories F1 + F2 | Porter (rattacher à l'Epic 34, PAS 36) | S + M |

Regroupement suggéré si inscription au backlog : **Epic 37 « Reliquats SE4 — self-service & vie scolaire »** (A1, A2, B1, B3), **Epic 38 « Reliquats SE4 — hygiène & admin »** (B2, C1, C2, D1, D2, D3, E1). Alternative : ventiler dans les epics existants (C1/C2 → domaine impression, E1 → 14-3 refonte CAS).

---

## Fusion recommandée (pas de story nouvelle)

### UI prof « couper Internet » → 27-13
Le mécanisme SE4 (groupe AD `no_internet` + scripts firewall logon) et la capacité `internet_access` (27-13, ready-for-dev) sont la même fonctionnalité vue de deux mailles : parc « examen » vs geste enseignant sur un groupe d'élèves. Créer une UI legacy-like séparée serait un doublon.

**Proposition** : ajouter aux AC de 27-13 (ou en story 27-13bis si trop gros) —
- La capacité `internet_access` est assignable à un **UserGroup** (pas seulement à un parc) — prérequis : l'UI d'assignation par UserGroup prévue à l'Epic 35.
- Un enseignant (droit délégué) dispose d'un geste simple bloquer/rétablir sur ses classes/groupes, avec échéance optionnelle (« jusqu'à la fin de l'heure ») pour éviter les blocages oubliés — le legacy n'avait pas d'échéance et c'était un vrai problème d'exploitation.
- Le groupe AD `no_internet` legacy reste honoré tant que le canal legacy vit (config `sambaedu.no_internet`), puis s'éteint avec 27-14.

---

## Bloc A — Self-service & vie scolaire

### Story A1 — Espace personnel : mon quota et mon espace disque
**En tant qu'** utilisateur connecté (élève, prof, administratif), **je veux** voir mon occupation disque, mon quota effectif et un avertissement clair en cas de dépassement, **afin de** comprendre pourquoi mes enregistrements échouent sans devoir solliciter un admin.

Contexte : SE4 `individuel.php` (disk_quota + affiche_quotas). SE5 expose les quotas côté admin (`/admin/quotas`, snapshot quotidien) et via l'overlay poste, mais aucune page web self-service.

Critères d'acceptation :
1. Une page `/app/home` (ou section de la future home 14-7) affiche : occupation actuelle, quota effectif (et sa provenance : règle user / groupe / défaut), barre de progression, état (ok / proche / dépassé).
2. Les données viennent du snapshot existant (`quota_snapshot`, `XfsQuotaService::getEffectiveQuota()`) — pas d'appel XFS synchrone au chargement de page.
3. Un lien « libérer de l'espace » documente les emplacements (home, corbeille) sans action destructive.
4. Accessible sans droit admin ; un délégué voit la même chose que l'utilisateur (pas de fuite des quotas d'autrui).

Notes : dépend de 14-7 (home par rôle) pour l'emplacement naturel ; sinon page dédiée `pages/account/`. Taille S.

### Story A2 — Devoirs : collecte et récupération des copies
**En tant que** professeur, **je veux** déposer un sujet dans `_travail/devoirs` de ma classe puis récupérer les copies rendues par les élèves en un geste, **afin de** reproduire le circuit devoirs du legacy sans manipulation manuelle d'ACL.

Contexte : SE4 `liste_devoirs()`/`find_devoirs()` (états « à récupérer / en cours / récupéré », liens symboliques vers les copies). SE5 pose le dossier `devoirs` et ses ACL (`ShareService::createClassShare`) mais le code note : « le WORKFLOW de collecte des copies rendues reste une feature à concevoir ».

Critères d'acceptation (à affiner avec le POURQUOI métier — interroger un enseignant utilisateur avant design) :
1. Le prof voit la liste des devoirs de ses classes avec leur état (déposé / rendu partiel / collecté).
2. La collecte copie les rendus élèves vers un espace prof (pas de symlinks : choix legacy fragile), horodatée, idempotente.
3. Les ACL empêchent un élève de lire/modifier les rendus des autres — **dépend du mécanisme casiers/sous-espaces par élève (34.x)** : cette story vient APRÈS.
4. Aucune perte de données : la collecte ne supprime jamais les originaux.

Notes : M. Ne pas démarrer avant l'extension casiers 34.x ; c'est le même socle « ACL par sous-dossier élève ».

---

## Bloc B — Hygiène annuaire

### Story B1 — Comptes temporaires à échéance
**En tant qu'** admin, **je veux** créer un compte avec une date de fin de validité (stagiaire, intervenant ponctuel, invité), **afin que** ces comptes soient désactivés puis purgés automatiquement au lieu de s'accumuler.

Contexte : SE4 `delete_temp_users.php` (purge cron). SE5 n'a aucune notion d'expiration. À modéliser en SQL (source de vérité, ADR-1), pas via `accountExpires` AD.

Critères d'acceptation :
1. Champ `expires_at` nullable sur `users`, exposé dans le formulaire de création/édition (label au-dessus, hint en tooltip).
2. Un scheduler quotidien désactive les comptes échus (même chemin que la désactivation manuelle : archivage home compris), puis les purge après un délai de grâce configurable (défaut 30 j), en réutilisant `TrashPurgeCommand`/`HomeDirService`.
3. La liste users affiche un filtre « expire bientôt / expiré ».
4. Journalisation : chaque désactivation/purge automatique est tracée (réutiliser le canal d'audit existant).

Notes : S. Synergie avec Epic 20 (identités externes ont déjà une rétention/purge — s'aligner sur ce pattern, `FederatedPurgeIdentitiesCommand`).

### Story B2 — Rapport de comptes orphelins (sync AD↔SQL)
**En tant qu'** admin, **je veux** un rapport des incohérences annuaire (comptes SQL sans objet AD, objets AD hors périmètre SE5, homes sans compte), **afin de** nettoyer en connaissance de cause — sans purge automatique.

Contexte : SE4 `ldap_cleaner.php` (central uniquement, purge). En SE5, SQL est la source de vérité et la purge auto serait dangereuse pendant la coexistence legacy/SE5 (AD partagé). Repenser en **détection + action manuelle**.

Critères d'acceptation :
1. Un check doctor (ou une commande `users:orphans-report`) liste les trois classes d'orphelins avec leur cause probable (résolution par `ad_guid`, jamais par cn).
2. Depuis la page sync-from-ad, l'admin voit le rapport et peut traiter unitairement (archiver le home, supprimer la ligne SQL) — aucune action de masse.
3. Zéro écriture AD : SE5 ne supprime jamais un objet AD user (ADR-6).

Notes : S. À caler sur `AdSyncChecker` existant.

### Story B3 — Professeur remplaçant (à confirmer avant rédaction)
SE4 `remplace.php` rattachait un remplaçant aux classes/équipes d'un titulaire. Avant de rédiger : **le besoin existe-t-il encore ?** Avec le modèle SE5, ajouter le remplaçant aux mêmes UserGroups (geste déjà possible en 2 clics dans la page groupe) couvre peut-être le besoin, le seul manque étant le geste « copier les appartenances de X vers Y » et le détachement en fin de remplacement.

Si confirmé, story minimale : action « rattacher comme remplaçant de… » sur la fiche user (copie des memberships classes/équipes du titulaire, borne de fin optionnelle réutilisant le mécanisme B1, détachement automatique). Taille S. Sinon : abandon formel documenté.

---

## Bloc C — Impression, compléments

### Story C1 — Suppression des travaux d'impression
**En tant qu'** admin ou délégué impression, **je veux** voir et supprimer les jobs en attente d'une imprimante, **afin de** débloquer une file encombrée sans passer par la ligne de commande CUPS.

Contexte : SE4 `printer_jobs.php`. SE5 : `CupsPrinterService::getJobsCount()` en lecture seule.

Critères d'acceptation :
1. Sur la fiche imprimante, la liste des jobs (id, user, taille, âge) avec action « annuler » unitaire et « vider la file ».
2. Implémentation via `cancel`/`lprm` encapsulé dans `CupsPrinterService` (mêmes exceptions typées que le reste du service).
3. Gate par la permission impression existante, scopée délégation.

Notes : XS.

### Story C2 — Spike : imprimantes par groupe utilisateur, besoin réel ?
Le legacy permettait de filtrer une imprimante par groupe **utilisateur** (Printers.xml userContext=1) ; SE5 ne connaît que la maille poste (« l'imprimante de la salle »). Avant toute story : vérifier sur un établissement réel (lab1) si des déploiements par groupe user existent dans les GPO legacy (`Printers.xml` avec FilterGroup sur des groupes non-machines).
- Si non → documenter l'abandon dans features-se4-SE5.md, terminé.
- Si oui → story de portage : pivot `printer_user_group` + résolution dans `PrintersStateProvider` côté session utilisateur (le compagnon de session pose l'imprimante). Attention : c'est le premier provider imprimante à maille user — coût de design non trivial, ne le faire que sur besoin avéré.

Notes : XS (spike), décision documentée dans tous les cas.

---

## Bloc D — Admin serveur, reliquats

### Story D1 — Réglages messagerie sortante (SMTP) en UI
**En tant qu'** admin, **je veux** configurer la messagerie sortante (relais, port, from, auth, TLS, destinataire des mails root) depuis les réglages, avec un bouton « envoyer un mail de test », **afin de** ne pas éditer `/etc/msmtprc` à la main en SSH.

Contexte : SE4 `conf_smtp.php` écrivait msmtprc + aliases. SE5 lit msmtprc une fois au provisioning (`create-env.sh`) — toute modification ultérieure est manuelle et désynchronise le `.env`.

Critères d'acceptation :
1. Onglet « Messagerie » dans `/admin/settings` : host, port, from, domaine, auth on/off + credentials, TLS/STARTTLS, vérification certificat, alias root.
2. À l'enregistrement : écriture de `/etc/msmtprc` (0600) et `/etc/aliases` + `newaliases`, et mise à jour de la config mailer Laravel (system_settings prioritaire sur .env) — une seule source de vérité, plus de re-parse au provisioning.
3. Bouton « mail de test » avec retour d'erreur SMTP lisible (toast via WithToasts).
4. Credentials stockés via le mécanisme `service_credentials` existant (chiffré), jamais en clair dans system_settings.
5. Check doctor « messagerie sortante » (relais joignable).

Notes : S. Attention aux droits d'écriture de `/etc/msmtprc` par www-admin (sudoers ciblé, même pattern que DHCP reload).

### Story D2 — Spike : triage des paramètres conf_params orphelins
Passer la liste des paramètres SE4 sans équivalent (UAI, politique de mot de passe globale, proxy WPAD, choix du dépôt apt, IP Amon, mail d'alerte, partages publics, admin_w…) et produire pour chacun une décision : **déjà couvert ailleurs / à porter (mini-story) / obsolète**. Livrable : tableau de décision annexé à features-se4-SE5.md + mini-stories créées pour les « à porter ».

Pré-analyse indicative : UAI → probablement Epic 11/controlHub ; politique mdp → pertinent (complexité AD, à exposer dans settings) ; WPAD/proxy → recouvre les policies Firefox + capacité registre proxy, vérifier le proxy système Windows ; dépôt apt → hors app (update.sh) ; IP Amon → probablement obsolète ; mail d'alerte → dépend de D1.

Notes : S, purement décisionnel.

### Story D3 — Arrêt/redémarrage du serveur depuis l'UI
**En tant qu'** admin, **je veux** redémarrer ou éteindre proprement le serveur SE5 depuis la page system-status, avec confirmation forte, **afin de** couvrir le cas « pas d'accès console » (coupure électrique annoncée, maintenance).

Contexte : SE4 `action_serv.php`. Périmètre volontairement réduit : **shutdown/reboot du serveur uniquement**. L'extinction Proxmox/Redfish et la console root SSH sont proposées à l'**abandon formel** (outillage d'hyperviseur, hors rôle de SE5 ; la console est remplacée par SSH standard).

Critères d'acceptation :
1. Deux actions sur `/admin/settings/system-status`, réservées au rôle admin plein (pas délégable), avec modale de confirmation (saisie du hostname).
2. Exécution via sudoers ciblé (`/sbin/shutdown`), trace en audit avant exécution.
3. Le doctor/status affiche un bandeau « redémarrage programmé » tant que l'action est en cours.

Notes : S. Si l'arbitrage est « on assume SSH », transformer en abandon formel documenté — coût zéro.

---

## Bloc E — Auth

### Story E1 — Catalogue CAS/ENT en données + chaîne de proxy
**En tant qu'** admin d'établissement, **je veux** choisir mon ENT dans une liste (Kosmos, OZE, itslearning, Envole, Toutatice…) au lieu de saisir manuellement l'URL/port/base CAS, **afin de** brancher le SSO sans connaître les détails techniques de chaque académie.

Contexte : SE4 embarquait ~150 serveurs CAS préconfigurés (`$tab_serveur_cas`) + `allowProxyChain`. SE5 attend une config CAS unique saisie à la main. La refonte native CAS est la story 14-3 (en pause) — E1 peut en être le périmètre de reprise.

Critères d'acceptation :
1. Le catalogue est un fichier de données versionné (json/php config), extrait du legacy par script — **pas de code par ENT**.
2. L'UI settings auth propose : choix dans le catalogue (pré-remplit et verrouille les champs) ou saisie manuelle.
3. La chaîne de proxy CAS (`allowProxyChain`) est portée si au moins un ENT du catalogue l'exige — sinon documenter l'abandon.
4. La config choisie alimente l'`initCasClient()` existant sans changement de comportement pour les instances déjà configurées.

Notes : S. À raccrocher à 14-3 plutôt qu'à un nouvel epic.

---

## Bloc F — Migration SE4→SE5 : adoption des répertoires à droits custom

> Hors liste §14 — issu de la session « import fs_acls from se4 » (2026-07-02). Contexte :
> l'arborescence classe SE4 est une projection **verrouillée** du modèle flexible SE5
> (`directory_templates` + `network_shares`/assignables). Pour installer SE5 sur un serveur
> SE4 existant sans casser les droits posés à la main par l'admin, il faut pouvoir **désigner**
> ces répertoires custom et les importer dans le modèle. Rattachement : **Epic 34 (34.x)** —
> c'est de l'ACL POSIX serveur, PAS le mécanisme `fs_acl` de l'Epic 36 (ACL NTFS du poste,
> homonymie trompeuse).
>
> Décision Henri actée le même jour : l'arborescence classe reste **iso-legacy** (pas de
> refonte du schéma _travail/_profs/_echange — les pistes d'amélioration : sticky bit sur
> _echange, canal rendu/, archives hors arbre vivant, sont une carte SE6, pas un préalable).

### Story F1 — Audit d'adoption : inspecter un répertoire custom et rapporter sa mappabilité
**En tant qu'** admin migrant un serveur SE4, **je veux** désigner un répertoire porteur de droits custom et obtenir un rapport « ce que SE5 sait exprimer / ce qui bloque », **afin de** savoir avant migration ce qui sera importé tel quel et ce que je dois résoudre.

Contexte : les primitives existent — `AclInspectionService` (lecture `getfacl` + classement mappable/non-mappable vers User/UserGroup SQL, zéro écriture) et `NetworkShareService::computeDrift()`. F1 est quasi entièrement de l'assemblage.

Critères d'acceptation :
1. L'admin désigne un path (UI ou commande artisan) ; l'inspection descend l'arbre et rapporte, par répertoire : entrées ACL mappables (cible SQL résolue, accès ro/rw dérivé), entrées non mappables (groupe AD disparu, user unix local, default ACL non exprimable), et si l'arbre est **plat** (un seul jeu d'ACL → une ligne `network_share`) ou **différencié** (sous-dossiers à droits distincts → nécessite F2/template).
2. Lecture seule stricte : aucune écriture FS, aucune écriture SQL.
3. Le rapport est actionnable : pour chaque entrée non mappable, la cause et le geste de résolution suggéré (créer le groupe SQL, nettoyer l'ACE, …).
4. Dépend de sync-from-ad joué (les cibles se résolvent contre le SQL).

Notes : S. Réutilisation directe de `AclInspectionService::inspect()`/`classify()`.

### Story F2 — Adoption : importer le répertoire dans le modèle SE5 sans toucher au FS
**En tant qu'** admin, **je veux** convertir un répertoire audité en objet géré SE5 (partage + assignations, ou template custom si arbre différencié), **afin que** SE5 devienne propriétaire de ses droits (drift STRICT) sans déplacer ni réécrire les données.

Critères d'acceptation (à affiner — deux questions structurantes en tête de design) :
1. **[STRUCTURANT]** Arbre différencié : l'adoption snapshote la structure + droits par sous-dossier en `directory_template` custom + instance (généralisation du seeder 34.3), OU se limite aux racines plates en v1 — à arbitrer avant rédaction ; les vrais cas custom sont presque toujours des arbres.
2. **[STRUCTURANT]** Localisation : les répertoires custom vivent hors `/var/sambaedu/Partages` — assouplir `validateSharePath` pour des « racines adoptées » (flag distinguant partage-né-SE5 / partage-adopté), plutôt que déplacer les données (chemins UNC, raccourcis postes).
3. Politique ACE non mappables : **strict** — on n'adopte que ce que le modèle exprime ; le reste doit être résolu ou nettoyé avant (cohérent doctrine drift STRICT, pas d'entrées « hors contrat »).
4. Adoption = écriture SQL uniquement ; `computeDrift()` doit rendre zéro juste après (preuve que le modèle décrit fidèlement l'existant). Toute convergence FS ultérieure est un geste explicite séparé.
5. Après adoption, le répertoire est un objet 34.x de plein droit : drift check doctor, resync, déprovision.

Notes : M. Après F1 ; même famille que l'extension casiers 34.x (socle « ACL par sous-dossier »).

---

## Abandon formel recommandé

### Sync cloud Nextcloud/Seafile (§8.9)
Le module SE4 (`rep_cloud.php`, `cloud.inc.php`, lecteur S:) était semi-expérimental (README = document de conception), couplé à un déploiement Seadrive par WPKG+GPO aujourd'hui caduc. Aucun signal de besoin depuis le début du refactoring. Recommandation : **acter l'abandon** dans features-se4-SE5.md (statut 🚫) et ne rien porter. Si un besoin cloud ressurgit, il se traitera comme intégration nouvelle (WebDAV côté agent drives), pas comme portage.

### Rappel des abandons déjà actés (pas de story à créer)
Google Workspace, OpenID Connect (jamais fini), MFA SMS admin ENT, public_key.js, création native de GPO (16-4), extinction des écrans display (jamais implémenté SE4).
