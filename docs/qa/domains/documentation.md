# QA Manuel — Documentation

**Domaine** : site de documentation publique SE5 — build VitePress isolé (`userDoc/`), publication Apache verrouillée sous `/doc`, intégration fail-soft à `scripts/update.sh`, socle deux parcours (« J'administre SE5 » / « J'utilise mon poste »).

**Stories couvertes** : 52.1 (socle : build isolé, alias Apache, `ensure_user_doc()` fail-soft, porte à deux parcours) ; 52.2 (gabarit de fiche, 4 encarts normalisés, glossaire, lint des règles de rédaction). _Stories 52.3 → 52.8 (contenu des fiches, recherche, captures, lien d'aide depuis l'app) à ajouter en sections suivantes quand livrées._

**Code de référence** :
- `userDoc/package.json`, `userDoc/package-lock.json` — chaîne npm ISOLÉE de l'application (jamais de dépendance croisée) ; dépendance explicite `markdown-it-container` (52.2), script `build` chaînant `node .vitepress/lint-doc.mjs && vitepress build`, script `lint` seul
- `userDoc/.vitepress/config.mjs` — `base: '/doc/'`, `lastUpdated` + repli mtime (`transformPageData`), sidebars keyées par chemin, `srcExclude` (`README.md`, `CONTRIBUTING.md`, `.templates/**` exclus du build public), `markdown.config(md)` : les 4 containers normalisés (52.2)
- `userDoc/.vitepress/theme/{index.js,custom.css}` — `theme-without-fonts` + IBM Plex embarquée ; styles `.se5-callout*` des 4 encarts sur variables `--vp-c-*` (52.2)
- `userDoc/.vitepress/lint-doc.mjs` — lint éditorial Node pur, motifs interdits + validation des ancres glossaire (52.2)
- `userDoc/{index,admin/index,poste/index}.md` — porte d'accueil + pages d'orientation des deux parcours
- `userDoc/glossaire.md` — glossaire publié, 9 entrées à ancres explicites (52.2)
- `userDoc/CONTRIBUTING.md`, `userDoc/.templates/fiche-modele.md` — doc contributeur et modèle de fiche, NON publiés (52.2)
- `scripts/setupApache.sh` — bloc `Alias /doc` (vhost SER, modèle `/wpkg/bundle`), `ErrorDocument 404`, `<FilesMatch "\.php$">` interne
- `scripts/update.sh` — `ensure_user_doc()` (fail-soft, patron `ensure_agent_build` + miroir-purge `ensure_ipxe_statics`), sentinelle `update_apache()` (`grep -q "Alias /doc "`)

---

## Pré-requis communs

- VM SER accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- `scripts/update.sh` déjà rejoué au moins une fois (pose l'alias Apache + publie `userDoc/dist/`)
- Aucun utilisateur/authentification requis : le site `/doc` est public par construction (c'est l'objet de l'AC1)
- **Point d'attention VM de dev** : cette VM peut aussi être la cible d'une boucle de synchronisation hôte→VM externe au dépôt (`inotifywait` côté poste de dev). Cette boucle ne protège pas `userDoc/dist/`, `userDoc/.vitepress/dist/`, `userDoc/.vitepress/cache/` de la suppression au même titre que `node_modules/`/`storage/`/`vendor/` — un `/doc/` qui répond 403/404 de façon intermittente sur cette VM entre deux sessions de dev n'est PAS une régression de `ensure_user_doc()`, c'est cet artefact d'environnement. Rejouer `bash scripts/update.sh` republie immédiatement. Sans incidence en production (pas de boucle host↔VM hors poste de dev).

---

## Section 1 — Socle : build, alias, fail-soft (Story 52.1)

### Scénario 1.1 — Page d'accueil publique, deux portes

1. Sans session SE5, ouvrir `http://<serveur>/doc/`.

**Attendu** :
- 200, aucune redirection vers l'écran de connexion SE5.
- Deux entrées visibles : « J'administre SE5 » (→ `/doc/admin/`) et « J'utilise mon poste » (→ `/doc/poste/`).
- Interface en français (thème, pied de page, bascule de thème).

### Scénario 1.2 — Sidebars isolées par parcours

1. Ouvrir `/doc/admin/`, inspecter la navigation latérale (`<aside>`).
2. Ouvrir `/doc/poste/`, inspecter la navigation latérale.

**Attendu** :
- La sidebar de `/doc/admin/` ne contient QUE des liens `/doc/admin/*`.
- La sidebar de `/doc/poste/` ne contient QUE des liens `/doc/poste/*`.
- Le nav du haut (persistant, hors sidebar) référence légitimement les deux portes sur toutes les pages — ce n'est pas une fuite, c'est le sélecteur de parcours.

### Scénario 1.3 — Build isolé de l'application

1. Sur l'hôte : `cd userDoc && npm install && npm run build`.
2. `git diff --stat -- package.json package-lock.json vite.config.js resources/`.

**Attendu** :
- Le site se construit dans `userDoc/.vitepress/dist` sans toucher à la chaîne de l'app.
- `git diff` VIDE sur les 4 chemins de l'application.

### Scénario 1.4 — Publication Apache verrouillée (PHP off, pas d'indexation)

1. `apache2ctl configtest`.
2. Déposer un fichier `test.php` (`<?php echo 'EXEC'; ?>`) dans `userDoc/dist/`, `curl http://localhost/doc/test.php`.
3. Retirer le fichier (`mv`/`trash`, jamais `rm -rf`).

**Attendu** :
- `Syntax OK`.
- Réponse `403 Forbidden`, contenu ne contenant JAMAIS « EXEC » (le `<FilesMatch>` interne + `SetHandler none` neutralisent le `SetHandler proxy:fcgi` global du vhost).
- `Options -Indexes` : pas de listing de répertoire.

### Scénario 1.5 — Sentinelle `update_apache()` reconfigure une instance antérieure

1. Sur une VM dont le vhost SER ne porte PAS encore `Alias /doc` (ou en simulant via un vhost antérieur à cette story), rejouer `bash scripts/update.sh`.
2. Rejouer une seconde fois.

**Attendu** :
- 1er run : log `Configuration Apache SER incomplète (... ou /doc manquant ...)` → relance de `setupApache.sh` → alias posé.
- 2e run : `Apache déjà configuré pour SER (setupApache.sh)`, pas de re-relance (idempotent).

### Scénario 1.6 — `ensure_user_doc()` fail-soft, site précédent préservé (preuve checksum/mtime)

1. Noter le MD5 et le mtime de `userDoc/dist/admin/index.html` après un build réussi.
2. Introduire un lien interne cassé dans une source (`[x](/admin/inexistant.md)`), rejouer `bash scripts/update.sh`.
3. Comparer MD5/mtime de `userDoc/dist/admin/index.html` avant/après.
4. Retirer le lien cassé, rejouer `bash scripts/update.sh`.

**Attendu** :
- Le build documentaire échoue (« N dead link(s) found », `ignoreDeadLinks` par défaut NON désactivé).
- `bash scripts/update.sh` termine quand même en exit 0, avec un `log_warning` explicite (« Build documentation échoué... site précédent conservé »), et enchaîne le reste de la mise à jour (« Mise à jour terminée avec succès » apparaît).
- `userDoc/dist/admin/index.html` **identique bit à bit** (même MD5, même mtime) avant/après l'échec — le dossier publié n'a été touché à AUCUN moment par le build en échec.
- Après retrait du lien : build à nouveau publié, `/doc/admin/` répond 200.

### Scénario 1.7 — Page fantôme de sortie purgée par le miroir

1. Retirer une page source côté VM par `mv` (jamais `rm -rf`), ex. `userDoc/poste/index.md`.
2. Rejouer `bash scripts/update.sh`.
3. Vérifier `userDoc/dist/poste/` et `curl http://localhost/doc/poste/`.
4. Restaurer la page, rejouer `bash scripts/update.sh`.

**Attendu** :
- Après le retrait : `userDoc/dist/poste/` absent (purgé par le miroir `cp -a` + purge orpheline, patron `ensure_ipxe_statics`), `/doc/poste/` → 404.
- Après restauration : `userDoc/dist/poste/index.html` republié, `/doc/poste/` → 200.

### Scénario 1.8 — Date de fraîcheur sans dépôt git (repli mtime)

1. Sur une VM dont la copie du dépôt N'EST PAS un checkout git (`.git` absent), consulter n'importe quelle page publiée.

**Attendu** :
- La date « Dernière mise à jour » s'affiche quand même (repli sur le mtime du fichier source via `transformPageData`), au lieu d'être absente.
- Aucun champ de date saisi à la main dans le frontmatter des sources.

### Scénario 1.9 — Autonomie réseau

1. `grep -RInE "https?://(fonts|cdn|unpkg|cdnjs|jsdelivr|googleapis)" userDoc/dist/`.
2. Inspecter l'onglet réseau du navigateur sur `/doc/` (poste sans accès internet si possible).

**Attendu** :
- Zéro occurrence de domaine tiers dans le dossier publié.
- Aucune requête sortante à l'affichage (polices IBM Plex, CSS, JS servis depuis `/doc/assets/`).

### Scénario 1.10 — Non-régression serveur

1. Après déploiement de cette story, curl : `/`, `/ipxe/boot.ipxe`, `/wpkg/bundle/*`, `/assets/*`.

**Attendu** :
- Tous répondent comme avant cette story (aucune modification de code PHP/Laravel).
- `/doc/inexistant` → 404 (page 404 statique du site publié, PAS une page Laravel).

### Limites connues (documentées, non corrigées ici)
- **Rendu visuel réel non couvert par cette checklist** : bascule clair/sombre, viewport mobile réduit, navigation clavier reposent sur le thème par défaut de VitePress (non custom). À confirmer visuellement dans un navigateur — non observable en ssh/curl seul.
- **Boucle de sync hôte→VM du poste de dev** : cf. Pré-requis communs — n'affecte pas la production, peut fausser une observation manuelle sur cette VM spécifique si elle intervient entre deux commandes.
- **Contenu des fiches hors périmètre** : les sections `/admin/` et `/poste/` sont volontairement quasi vides (pages d'orientation minimales) — le contenu métier arrive en 52.2 → 52.8.

### Checklist rapide Section 1
- [ ] `/doc/` 200 sans authentification, deux portes visibles, français
- [ ] Sidebar `/doc/admin/` ne contient que des liens `/admin/*` ; `/doc/poste/` que des liens `/poste/*`
- [ ] `git diff` VIDE sur `package.json`/`package-lock.json`/`vite.config.js`/`resources/` de l'app après un build doc
- [ ] `.php` déposé dans `userDoc/dist/` → 403, jamais exécuté
- [ ] Sentinelle `update_apache()` : instance sans `/doc` → relance `setupApache.sh` ; instance à jour → no-op
- [ ] Lien mort → build doc échoue, `update.sh` continue (exit 0, warning), dossier publié **identique bit à bit** avant/après
- [ ] Page source retirée (`mv`) → absente du publié après rebuild, 404 ; restaurée → republiée
- [ ] Repli mtime affiché sur VM sans `.git`
- [ ] Zéro domaine tiers dans `userDoc/dist/` (grep)
- [ ] `/`, `/ipxe/boot.ipxe`, `/wpkg/bundle/*` inchangés ; `/doc/inexistant` → 404 statique (pas Laravel)

---

## Section 2 — Gabarit de fiche, encarts, glossaire, lint (Story 52.2)

**Point d'exécution 2026-07-25** : les scénarios ci-dessous ont été vérifiés en LOCAL (build hôte) pendant que la VM `192.168.122.50` était injoignable — les vérifications marquées « VM » n'ont PAS pu être rejouées en conditions serveur réelles et restent à faire dès que la VM répond (Task 6 de la story, différée). Les preuves locales sont documentées dans le Dev Agent Record de `52-2-gabarit-fiche-encarts-glossaire.md`.

### Scénario 2.1 — Les 4 encarts rendent un HTML identique partout

1. Dans une page Markdown quelconque, écrire successivement `::: droit-requis`, `::: delai-effet immediat`, `::: attention`, `::: vue-poste` (contenu libre dans chaque bloc), fermer par `:::`.
2. `npm run build`, inspecter le HTML généré pour cette page.

**Attendu** :
- Chaque container produit `<div class="se5-callout se5-callout--<type>">` avec un `<p class="se5-callout__title">` portant le titre FIGÉ : « Droit requis », « Quand l'effet est visible » *(porté par le libellé de la valeur choisie, cf. Scénario 2.2)*, « Attention », « Ce que voit l'utilisateur du poste ».
- Le même balisage apparaît quelle que soit la page — un seul point de rendu (`markdown.config` de `.vitepress/config.mjs`).
- **VM/navigateur non vérifié** : lisibilité réelle en thème clair, thème sombre, et viewport mobile (à confirmer visuellement — la CSS s'appuie sur les variables `--vp-c-*` du thème par défaut, donc l'héritage clair/sombre est acquis par construction, mais non observé dans un navigateur réel à ce jour).

### Scénario 2.2 — `delai-effet` : les 3 libellés et le verrou de paramètre

1. Construire une page avec les trois valeurs : `::: delai-effet immediat`, `::: delai-effet session`, `::: delai-effet agent`. `npm run build`.
2. Dans une page de test, écrire `::: delai-effet` (sans valeur). `npm run build`.
3. Dans une page de test, écrire `::: delai-effet bogus`. `npm run build`.
4. Retirer la page de test, `npm run build`.

**Attendu** :
- Étape 1 : les titres rendus sont exactement « Effet immédiat », « À la prochaine ouverture de session », « Au prochain passage de l'agent sur le poste ».
- Étape 2 : le build échoue ; le message contient le fichier fautif, la valeur reçue (`(aucune)`) et la liste des valeurs admises (`immediat, session, agent`).
- Étape 3 : même échec, valeur reçue affichée = `bogus`.
- Étape 4 : build de nouveau vert.

### Scénario 2.3 — Glossaire publié et atteignable

1. `npm run build`, puis `grep -o 'id="[a-z0-9-]*"' .vitepress/dist/glossaire.html` (en local) ou `curl <serveur>/doc/glossaire.html` (VM).
2. Ouvrir `/doc/` (ou `index.html` généré) et vérifier l'accès au glossaire.
3. Ouvrir `/doc/admin/` et `/doc/poste/` : vérifier que « Glossaire » apparaît dans la nav du haut sur les deux parcours.

**Attendu** :
- Les 9 ancres sont présentes : `parc`, `groupe-de-postes`, `socle-commun`, `capacite`, `profil-applicatif`, `agent`, `depot-applications`, `espace-personnel`, `partage`.
- La page d'accueil porte un accès direct au glossaire (3e carte).
- Le lien « Glossaire » de la nav est visible depuis les deux parcours (nav globale, pas dans les sidebars par parcours).
- L'entrée « profil applicatif » distingue explicitement ses deux sens (ensemble d'applications par parc / profil Firefox-Thunderbird qui suit l'utilisateur) sans les confondre.
- Aucune entrée ne contient de numéro de story/épic, de code d'exigence, ni de donnée réelle d'établissement (vérifiable par le lint, cf. Scénario 2.5).
- **VM non vérifié** : `curl <serveur>/doc/glossaire.html` → 200 réel en conditions serveur.

### Scénario 2.4 — Doc contributeur et modèle non publiés

1. `npm run build`.
2. `find .vitepress/dist -iname "*readme*" -o -iname "*contributing*" -o -path "*templates*"` (en local) ou `curl` sur `/doc/README.html`, `/doc/CONTRIBUTING.html`, `/doc/.templates/fiche-modele.html` (VM).

**Attendu** :
- Aucun résultat / chaque URL → 404. Ni `README.md`, ni `CONTRIBUTING.md`, ni `.templates/**` n'apparaissent dans le site construit (`srcExclude` de `.vitepress/config.mjs`).

### Scénario 2.5 — Lint éditorial bloquant : preuve rouge puis verte

1. Créer un fichier Markdown temporaire dans `userDoc/` contenant : le mot « story » et « épic » et « epic », un code `FR-D1`/`NFR-D4`/`UX-DR1`, une adresse IPv4, un container `::: warning`, un container `::: danger`, un lien `/glossaire#ancre-inexistante`, et — témoins — les mots « SE4 » et « SE5 ».
2. `npm run build` (ou `npm run lint`).
3. Retirer le fichier (`mv`/`trash`, jamais `rm -rf`), `npm run build`.

**Attendu** :
- Étape 2 : le build échoue, chaque violation listée au format `fichier:ligne → motif`, une ligne par occurrence (au moins 9 violations attendues sur ce fichier de preuve) ; « SE4 » et « SE5 » ne remontent JAMAIS.
- Étape 3 : lint vert, `npm run build` termine avec succès.
- **VM non vérifié** : rejeu du même test négatif via `bash scripts/update.sh` en conditions serveur — le build doc doit échouer en fail-soft (warning, exit 0 côté `update.sh`, site précédent inchangé), cf. patron du Scénario 1.6.

### Scénario 2.6 — Non-régression du socle 52.1 après 52.2

1. `npm run build`, comparer avec les pages d'accueil/`/admin/`/`/poste/` déjà validées en Section 1.
2. `grep -RInE "https?://(fonts|cdn|unpkg|cdnjs|jsdelivr|googleapis)" .vitepress/dist/`.
3. `git diff --stat -- package.json package-lock.json vite.config.js resources/` (racine du dépôt).
4. `git status --porcelain` : vérifier qu'aucun fichier hors `userDoc/` n'apparaît modifié.

**Attendu** :
- Sidebars par parcours toujours isolées (inchangées par 52.2), nav enrichie du seul lien « Glossaire ».
- Zéro domaine tiers dans le dossier publié.
- `git diff` VIDE sur les 4 chemins de l'application.
- Aucun fichier hors `userDoc/` (config, story, `sprint-status.yaml` ligne 52.2, ce runbook) n'est touché par les changements de code applicatif.

### Limites connues (documentées, non corrigées ici)
- **Task 6 de la story différée en intégralité** : VM `192.168.122.50` injoignable pendant le développement — aucune vérification serveur réelle (inotify, `update.sh`, matrice curl, test négatif fail-soft en conditions réelles) n'a pu être rejouée. À faire dès que la VM répond.
- **Rendu visuel réel des 4 encarts non confirmé dans un navigateur** : bascule clair/sombre et viewport mobile reposent sur les variables `--vp-c-*` du thème (héritage acquis par construction), mais aucune capture ni observation visuelle réelle n'a été faite — cf. limite déjà posée en Section 1 pour le socle.
- **Mermaid non rendu nativement** : constaté par test réel (bloc `​```mermaid` produit un bloc de code coloré, pas un diagramme). Aucun plugin ajouté dans cette story ; à activer avec la première fiche qui en a réellement besoin (52.3+).
- **Contenu des fiches toujours hors périmètre** : cette story livre l'outillage éditorial, pas les fiches elles-mêmes — `/admin/` et `/poste/` restent des pages d'orientation minimales.

### Checklist rapide Section 2
- [ ] Les 4 encarts rendent `se5-callout se5-callout--<type>` + titre figé, un seul point de rendu
- [ ] `delai-effet immediat|session|agent` → 3 libellés figés ; valeur absente/inconnue → build échoue avec fichier+valeur+valeurs admises
- [ ] Glossaire : 9/9 ancres présentes, nav+accueil pointent dessus, « profil applicatif » désambiguïsé
- [ ] `README.md`/`CONTRIBUTING.md`/`.templates/**` absents du site construit
- [ ] Lint : fichier de preuve → toutes les violations listées `fichier:ligne → motif`, SE4/SE5 jamais signalés ; fichier retiré → lint vert
- [ ] Non-régression 52.1 : sidebars/nav intactes, zéro domaine tiers, `git diff` vide sur l'application
- [ ] **VM (différé)** : inotify à jour, `update.sh` rejoué vert, matrice curl serveur (`/doc/glossaire.html` 200, `.templates`/`README`/`CONTRIBUTING` 404), contrôle visuel navigateur clair/sombre/mobile, test négatif fail-soft réel

---

## Section 3 — Parcours utilisateur du poste : fiches de contenu

Scénarios liés aux fiches du parcours « J'utilise mon poste » : « Mon compte »
(`poste/mon-compte/`), « Mes fichiers » (`poste/fichiers/`), « Mes applications »
et « Imprimer » (`poste/applications.md`, `poste/impression.md`). La navigation,
les liens de la page d'orientation `poste/index.md` et cette section QA ont été
consolidés en une passe après rédaction parallèle des trois lots.

### Scénario 3.1 — Toutes les fiches du poste sont servies et reliées
**But** : aucune fiche orpheline, navigation complète.
**Étapes / attendu** :
- Après build+publication, chaque fiche répond en 200 sous `/doc/poste/…` :
  `mon-compte/` (+ `se-connecter`, `changer-mon-mot-de-passe`, `changement-impose`,
  `mot-de-passe-oublie`), `fichiers/` (+ `espace-personnel`, `espaces-partages`,
  `dun-poste-a-lautre`), `applications`, `impression`.
- La sidebar `/poste/` liste les trois sections (Mon compte · Mes fichiers ·
  Applications et impression) ; la sidebar `/admin/` reste bit à bit inchangée.
- `poste/index.md` pointe vers les quatre entrées ; plus de mention « en cours de
  rédaction ».
- La navigation précédent/suivant traverse toutes les fiches sans trou.

### Scénario 3.2 — Le build est le garde-fou d'intégrité (liens, YAML, balises)
**But** : ne pas publier une fiche cassée que le lint ne voit pas.
**Étapes / attendu** :
- `npm run build` vert = aucun lien interne mort (les renvois `/glossaire#…` de
  chaque fiche résolvent vers une ancre existante) et aucun frontmatter YAML
  invalide.
- Piège vérifié : une `description:` de frontmatter contenant un `:` doit être
  entre guillemets, sinon le build échoue (`incomplete explicit mapping pair`).
- Piège vérifié : aucune balise parasite `</content>`, `</invoke>` ou fragment
  d'appel d'outil ne doit subsister dans une fiche — VitePress compile le
  markdown comme un template Vue et échoue sur `Invalid end tag`. Grep de
  contrôle : `grep -rnE "</(content|invoke|parameter|function)>" userDoc/poste/`
  doit être vide.

### Scénario 3.3 — Règles de rédaction du parcours poste (non-informaticien)
**But** : le lecteur enseignant/élève ne rencontre ni jargon ni promesse fausse.
**Étapes / attendu** :
- Grep anti-jargon sur les fiches publiées : aucun de `WPKG`, `CUPS`, `Samba`,
  `GPO`, `UNC`, `ACL`, `SMB`, `montage`, `annuaire`, `jeton`, `LDAP`, `CAS`,
  `ENT`, `pwdlastset`, `Nextcloud`, `capacité`, `politique de fichiers`.
- Aucun délai chiffré présenté comme un engagement ; le délai d'apparition d'une
  application est qualitatif (« en général dans l'heure, poste allumé ») et porté
  par l'encart `::: delai-effet agent`.
- Mot de passe oublié : la fiche énonce qu'il n'existe aucune récupération
  automatique et renvoie au référent ; elle ne décrit aucun geste d'administration.
- Les seuils « 8 caractères / quart d'heure » n'apparaissent que dans la fiche
  `changement-impose`, jamais généralisés ailleurs.

### Scénario 3.4 — Conditionnalité des espaces de fichiers
**But** : ne présenter comme certain que ce qui l'est dans tous les cas.
**Étapes / attendu** :
- Chaque espace dont l'existence dépend d'un réglage d'établissement (espace
  personnel `K:`, lecteur « Classes » `H:`, dossier d'échange, lecteurs
  supplémentaires, suivi Firefox/Thunderbird) porte un encart `::: attention` de
  conditionnalité.
- Test de conditionnalité : masquer mentalement un espace conditionnel — la fiche
  reste vraie (elle n'affirme pas sa présence comme certaine).
- Le cas « je vois le lecteur mais l'accès est refusé » est désamorcé
  explicitement (ce n'est pas une panne).
- Le dossier personnel de l'élève (dans l'espace de classe) est distingué de
  l'espace personnel privé.

### Limites connues Section 3 (différé VM)
- **Rendu visuel réel non confirmé** : encarts `attention` et `delai-effet` en
  thème clair/sombre et sur mobile — non observés dans un navigateur (VM
  injoignable au 2026-07-25), reposent sur l'héritage des variables de thème.
- **Matrice curl serveur non rejouée** : les codes 200/404 ci-dessus sont
  attendus mais non vérifiés sur le serveur tant que la VM ne répond pas.

### Checklist rapide Section 3
- [ ] Toutes les fiches `poste/**` en 200 ; sidebars correctes ; `admin/` inchangée
- [ ] `npm run build` vert (liens, YAML, aucune balise parasite)
- [ ] Grep anti-jargon et anti-engagement-chiffré vert sur les fiches publiées
- [ ] Chaque espace conditionnel porte son encart `::: attention`
- [ ] Mot de passe oublié : renvoi référent, aucun geste admin décrit
- [ ] **VM (différé)** : matrice curl serveur + contrôle visuel encarts clair/sombre/mobile
