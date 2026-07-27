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

---

## Section 4 — Lien d'aide depuis l'application (Story 52.8)

Scénarios liés au point d'entrée d'aide dans l'interface authentifiée SE5 (icône
« ? » de la navbar) qui ouvre `/doc/` dans un nouvel onglet. C'est la seule
story de l'epic 52 qui touche le code applicatif Laravel (`config/sambaedu.php`,
`resources/views/components/organisms/navbar.blade.php`) — aucune route
Laravel créée, aucun fichier `userDoc/` ni `scripts/` touché.

**Code de référence** :
- `config/sambaedu.php` — clé `doc.index_file` (défaut
  `base_path('userDoc/dist/index.html')`, surchargeable par
  `SAMBAEDU_DOC_INDEX_FILE`)
- `resources/views/components/organisms/navbar.blade.php` — bloc `@if
  (is_file(config('sambaedu.doc.index_file')))` dans le cluster de droite,
  href en dur `/doc/`, `target="_blank" rel="noopener"`
- `tests/Feature/NavbarHelpLinkTest.php` — rendu Blade isolé des deux états
  (fichier présent / absent), non-VM

### Scénario 4.1 — Le bouton d'aide ouvre `/doc/` dans un nouvel onglet (AC1)
**But** : un utilisateur connecté atteint la doc sans perdre son contexte SE5.
**Étapes / attendu** :
- Sur `/vm`, `userDoc/dist/index.html` doit exister (52.1 déployée).
- Ouvrir une page quelconque de l'interface authentifiée : l'icône « ? »
  (`fa-circle-question`) est visible en haut à droite, à côté de la bascule de
  thème et des notifications.
- Cliquer dessus : un NOUVEL onglet s'ouvre sur `/doc/` ; l'onglet SE5 d'origine
  garde exactement la même URL et le même état (pas de rechargement, pas de
  déconnexion).
- `curl -s -o /dev/null -w '%{http_code}' http://localhost/doc/` → 200.
- **VM non vérifiée au 2026-07-25** (VM injoignable pendant le développement) —
  différé ; les tests PHPUnit hôte (Scénario 4.3) font foi en attendant.

### Scénario 4.2 — Pas de lien mort quand la doc n'est pas publiée (AC2)
**But** : un serveur sans site construit ne présente jamais un bouton qui mène
à un 404/catchall.
**Étapes / attendu** :
- Sur `/vm`, déplacer temporairement `userDoc/dist/index.html` (UNIQUEMENT ce
  fichier, jamais le dossier `dist/` — les ACL et l'`ErrorDocument 404` en
  dépendent) : `mv userDoc/dist/index.html /tmp/...`.
- Recharger une page authentifiée : le bouton d'aide a disparu, aucune erreur
  visible, le reste de la navbar (thème, notifications, menu utilisateur) est
  intact.
- Restaurer le fichier immédiatement : recharger → le bouton d'aide réapparaît
  sans autre intervention (pas de cache applicatif à vider).
- Sur l'hôte de dev où `userDoc/dist/` n'existe généralement pas (build jamais
  lancé localement) : le bouton est absent par construction — normal, ce n'est
  pas une régression.
- **VM non vérifiée au 2026-07-25** — différé.

### Scénario 4.3 — Non-régression visuelle de la navbar (AC3)
**But** : les éléments existants gardent leur position et leur comportement,
avec ou sans le bouton d'aide.
**Étapes / attendu** :
- `php artisan test --filter=NavbarHelpLink` → les deux cas (doc présente / doc
  absente) passent ; dans les deux cas le HTML rendu contient toujours le bloc
  Notifications (preuve que le `@if` n'avale pas le reste du cluster).
- `php artisan test --filter=Navbar` et `php artisan test --filter=Gpo`
  (composant voisin `GpoBackLinkComponentTest`, même patron de test Blade
  isolé) : verts, aucune régression introduite par le changement de largeur du
  conteneur (`w-36` → `w-fit`).
- Contrôle visuel réel (bureau ET mobile/drawer replié) : bouton menu
  hamburger, recherche, thème, aide, notifications, avatar tous visibles sans
  chevauchement ni débordement — **non observé dans un navigateur réel au
  2026-07-25 (VM injoignable)**, différé.

### Limites connues Section 4 (différé VM)
- **Task 4 de la story différée en intégralité** : VM `192.168.122.50`
  injoignable pendant le développement — aucune vérification serveur réelle
  (clic réel ouvrant `/doc/`, matrice curl, contre-épreuve `mv`/restauration,
  rendu visuel clair/sombre/mobile) n'a pu être rejouée. À faire dès que la VM
  répond.
- **Tests PHPUnit hôte seuls disponibles en attendant** : les deux états (doc
  publiée / absente) sont couverts par simulation filesystem
  (`tempnam()` / chemin inexistant) via la clé de config, pas par le vrai
  dossier `userDoc/dist/` de la VM.
- **Fenêtre résiduelle « build présent / alias Apache pas encore posé »** : le
  garde `is_file(userDoc/dist/index.html)` teste la présence du build, pas la
  disponibilité de `/doc/` sous Apache. Le seul cas où le lien s'afficherait pour
  un `/doc/` non servi serait un `dist/` posé HORS de `scripts/update.sh` (qui,
  lui, fait build + reconfiguration Apache dans la même passe). Cas jugé
  théorique — à vérifier au premier `update.sh` sur une instance neuve.

### Checklist rapide Section 4
- [ ] `NavbarHelpLinkTest` : doc présente → `href="/doc/"` + `target="_blank"` +
      `rel="noopener"` ; doc absente → pas de `href="/doc/"`, voisins (Notifications) intacts
- [ ] `php artisan test --filter=Navbar` et `--filter=Gpo` verts (non-régression `w-36`→`w-fit`)
- [ ] **VM (différé)** : clic réel nouvel onglet, curl `/doc/` → 200, contre-épreuve `mv`/restauration, contrôle visuel bureau+mobile

---

## Section 5 — Convention de captures d'écran (Story 52.7)

Scénarios liés à la convention de captures (établissement fictif « Collège de
Brumeville », thème/cadrage, nommage, alt obligatoire) et à son mécanisme
d'insertion : règle `image` de markdown-it surchargée en un point unique dans
`.vitepress/config.mjs`, styles `.se5-capture`/`.se5-capture-placeholder` dans
`theme/custom.css`, motifs image ajoutés à `lint-doc.mjs`. **Aucune image
réelle n'est produite par cette story** : l'état livré est le placeholder
« Illustration à venir » sur les fiches qui référencent une capture — c'est
l'état publié assumé tant que la production manuelle des images n'a pas eu
lieu.

**Code de référence** :
- `userDoc/.vitepress/config.mjs` — `registerCaptureImageRule(md)` dans
  `markdown.config(md)` : capture la règle `image` par défaut, délègue tout
  `src` hors `/captures/` ; fichier présent sous `userDoc/public/captures/...`
  → `<img class="se5-capture" src="/captures/...">` (le préfixe `base` `/doc/`
  est appliqué par la transformation d'assets de `@vitejs/plugin-vue`, PAS par
  une concaténation dans la règle — cf. point fragile ci-dessous) ; absent →
  `<div class="se5-capture-placeholder">` (« Illustration à venir » + alt)
- `userDoc/.vitepress/theme/custom.css` — `.se5-capture` (cadre fin,
  `max-width:100%`), `.se5-capture-placeholder` (bloc discret), variables
  `--vp-c-*` uniquement (clair/sombre hérité)
- `userDoc/.vitepress/lint-doc.mjs` — `checkImagePatterns()` : alt vide,
  cible hors `/captures/` (URL externe incluse), extension ≠ `.png`, segment
  non kebab-case, balise `<img>` brute ; `reportMissingCaptures()` : sortie
  informative non bloquante des captures référencées sans fichier
- `userDoc/public/captures/.gitkeep` — emplacement matérialisé, aucune image
- `userDoc/CONTRIBUTING.md` — section « Captures d'écran » (jeu fictif
  ratifié, thème/cadrage, nommage + règle de remplacement, alt, checklist de
  production), non publiée
- `userDoc/.templates/fiche-modele.md` — exemple d'insertion à la position du
  gabarit, non publié
- Fiches modifiées (référence de capture posée, alt conforme) :
  `poste/mon-compte/se-connecter.md`, `poste/mon-compte/changer-mon-mot-de-passe.md`
  (2 gestes → 1 capture), `poste/mon-compte/changement-impose.md` (2 captures,
  une par sous-scénario poste/navigateur), `poste/fichiers/espace-personnel.md`,
  `poste/fichiers/espaces-partages.md`, `poste/impression.md`

### Scénario 5.1 — Repli textuel : fichier absent → placeholder avec l'alt
**But** : l'état livré (aucune image produite) reste utilisable et accessible.
**Étapes / attendu** :
- `npm run build` (aucune image sous `userDoc/public/captures/`).
- Sur chacune des 6 fiches listées ci-dessus, le HTML publié contient
  `<div class="se5-capture-placeholder">` avec `<p
  class="se5-capture-placeholder__label">Illustration à venir</p>` suivi du
  texte alternatif exact de la fiche.
- La fiche reste complète et actionnable en l'ignorant : relue sans l'image,
  chaque fiche dit tout ce qu'il faut pour agir (règle AC4).
- Vérifié en LOCAL 2026-07-25 : `changement-impose.html` porte bien 2 blocs
  placeholder (2 captures référencées sur cette fiche).

### Scénario 5.2 — Remplacement sans retouche : dépôt puis retrait d'un PNG factice
**But** : le contrat central de la convention (UX-DR5) — remplacer une
illustration ne doit JAMAIS modifier le texte d'une fiche.
**Étapes / attendu** :
1. Noter le MD5 du `.md` d'une fiche référençant une capture.
2. Déposer un PNG factice (1×1, décodé depuis base64, aucun outil graphique)
   au nom exact déjà référencé sous `userDoc/public/captures/...`.
3. `npm run build` : le HTML publié contient désormais
   `<img class="se5-capture" src="/doc/captures/...">` avec le même alt,
   `loading="lazy"`.
4. Retirer le fichier (`mv`/`trash`, jamais `rm -rf` — fantôme d'inotify).
5. `npm run build` : le placeholder revient, alt identique.
6. MD5 du `.md` identique aux étapes 1 et 5.

**Attendu** : MD5 IDENTIQUE avant/après tout le cycle ; le seul delta est la
présence/absence du fichier PNG sous `public/`.
**Vérifié en LOCAL 2026-07-25** (cycle complet rejoué sur
`poste/mon-compte/se-connecter.md`, MD5 `c33502106c60e705dfa6e37133b950c8`
inchangé aux deux extrémités du cycle).

### ⚠️ Point fragile détecté en cours de développement — préfixe `base`
**La story anticipait (piège #4) qu'un `src` émis en HTML brut ne serait
JAMAIS préfixé du `base` `/doc/` automatiquement, imposant une concaténation
manuelle dans la règle.** Vérifié FAUX par un build réel : concaténer `base`
dans la règle (`src="/doc/captures/..."`) fait échouer le build
(`Rollup failed to resolve import "/doc/captures/..."` — Rollup cherche un
fichier sous `public/doc/captures/...`, qui n'existe pas). En cause : le HTML
produit par la règle personnalisée est splicé dans le même template Vue que
le rendu markdown natif, et la transformation d'assets de `@vitejs/plugin-vue`
(`transformAssetUrls`) s'applique donc IDENTIQUEMENT aux deux — tout `src`
absolu (`/...`) est réécrit en import résolu contre `publicDir`, et c'est
CETTE étape qui injecte `base` au build, pas la règle elle-même. **La règle
émet donc `src="/captures/..."` (site-root, SANS préfixe manuel)** ; le HTML
publié final porte bien `/doc/captures/...` (vérifié dans `dist/`), mais par
ce mécanisme, pas par concaténation. Sans incidence sur AC2 (le résultat final
est conforme) — documenté ici pour qu'une future story ne réintroduise pas la
concaténation manuelle en la croyant nécessaire.

### Scénario 5.3 — Lint bloquant : chaque motif image individuellement, puis vert
**But** : aucun des 5 motifs image ne doit pouvoir passer inaperçu.
**Étapes / attendu** :
1. Créer un fichier Markdown temporaire sous `userDoc/poste/` portant, une
   ligne chacun : une image `/captures/...` à alt vide ; une image externe
   `https://...` ; une image `/captures/....jpg` (mauvaise extension) ; une
   image `/captures/.../Segment_Non_Kebab/...png` ; une balise `<img src=...>`
   brute.
2. `npm run build` (ou `npm run lint`).
3. Retirer le fichier (`mv`/`trash`), `npm run build`.

**Attendu** :
- Étape 2 : 5 violations, une par motif, format `fichier:ligne → motif`,
  exit ≠ 0.
- Étape 3 : lint vert ; la sortie informative des captures manquantes revient
  exactement à la liste des 7 références posées par cette story (aucune
  n'existe encore sous `public/`).
- **Vérifié en LOCAL 2026-07-25** : les 5 motifs déclenchés exactement comme
  prévu sur un fichier de preuve dédié, puis lint vert après retrait.

### Scénario 5.4 — Non-régression
**But** : la convention de captures ne casse rien du socle existant.
**Étapes / attendu** :
- `npm run build` complet vert (lint + `vitepress build`).
- `grep -RInE "https?://(fonts|cdn|unpkg|cdnjs|jsdelivr|googleapis)" .vitepress/dist/`
  → vide (les captures sont des assets locaux, aucune requête tierce).
- `find .vitepress/dist -iname "*readme*" -o -iname "*contributing*" -o -path "*templates*"`
  → vide (CONTRIBUTING.md et `.templates/` toujours non publiés).
- `git diff --stat -- package.json package-lock.json vite.config.js resources/`
  (racine du dépôt) et `-- userDoc/package.json userDoc/package-lock.json` →
  VIDE sur les deux (zéro dépendance nouvelle, chaîne npm isolée intacte).
- Les 4 encarts (52.2) et la recherche (52.6) continuent de fonctionner sans
  changement de comportement (zones distinctes dans `markdown.config(md)` et
  `CONTRIBUTING.md`).

### Limites connues Section 5 (différé VM)
- **Task 6 de la story différée en intégralité** : VM `192.168.122.50`
  injoignable pendant le développement (2026-07-25) — `bash scripts/update.sh`,
  matrice curl serveur, et surtout le **contrôle visuel réel** (placeholder en
  thème clair/sombre/mobile, cadre `.se5-capture` une fois une image
  déposée) n'ont pas pu être rejoués en conditions serveur. Les preuves
  ci-dessus sont des équivalents locaux (build hôte + lecture du HTML/CSS
  généré), pas une observation dans un navigateur réel.
- **Aucune image de capture réelle n'existe à la livraison** : c'est l'état
  ASSUMÉ de cette story (AC5) — la production des images (environnement de
  prise de vue aux données fictives, capture au niveau hyperviseur pour les
  écrans du *secure desktop* Windows) est une action manuelle ultérieure, hors
  périmètre. La sortie informative du lint (Scénario 5.3) est la liste
  d'entrée de cette action.
- **Décision d'audit AC4 non re-vérifiable mécaniquement** : le choix des 6
  fiches retenues (et des 2 captures sur `changement-impose`) repose sur une
  lecture éditoriale (« cette fiche décrit-elle un écran à identifier ? »),
  pas sur un critère automatisable — une revue humaine reste utile si de
  nouvelles fiches poste sont ajoutées.

### Checklist rapide Section 5
- [ ] 6 fiches ciblées → placeholder + alt exact quand aucune image n'existe
- [ ] Cycle dépôt/retrait PNG factice : `<img class="se5-capture">` puis
      placeholder de retour ; MD5 du `.md` identique aux deux extrémités
- [ ] Lint : 5 motifs image déclenchés individuellement puis lint vert ;
      sortie informative = exactement les captures manquantes
- [ ] Build complet vert ; grep autonomie réseau vide ; CONTRIBUTING/.templates
      non publiés ; `git diff` vide sur `package.json`/lockfile (app ET
      `userDoc/`)
- [ ] **VM (différé)** : `update.sh` rejoué vert, matrice curl, contrôle
      visuel réel clair/sombre/mobile du placeholder ET de l'image factice

---

## Section 6 — Page d'orientation admin : plan + comment lire une fiche (Story 53.1)

Scénarios liés à la réécriture de la page d'orientation du parcours
« J'administre SE5 » (`userDoc/admin/index.md`) : le **plan des sept domaines**
avec leur chemin de navigation réel, le **schéma besoins → domaines** (HTML/CSS
sans dépendance, option B), la section **« Comment lire une fiche »** (encarts
réels `::: droit-requis` et trois `::: delai-effet`), et la **convention de
sidebar additive** posée en commentaire sans lien mort. Aucune page nouvelle,
aucun code applicatif : la story tient sur `admin/index.md`, plus les classes
de schéma dans `theme/custom.css` et le commentaire dans `config.mjs`.

**Code de référence** :
- `userDoc/admin/index.md` — page réécrite (plan 7 domaines, schéma `se5-plan-*`, section lecture de fiche)
- `userDoc/.vitepress/theme/custom.css` — classes `.se5-plan`, `.se5-plan__pair`, `.se5-plan__need`, `.se5-plan__domain`, `.se5-plan__arrow` (variables `--vp-c-*`, grille responsive < 640 px)
- `userDoc/.vitepress/config.mjs` — commentaire de convention additive au-dessus du bloc sidebar `'/admin/'` (aucune entrée nouvelle ; `'/poste/'` intouché)
- `resources/views/components/organisms/sidebar.blade.php` — menu réel de l'application dont sont tirés les chemins de navigation

### Scénario 6.1 — Le plan des sept domaines est présent, chemins réels
**But** : la page d'orientation cartographie tout le produit sans lien mort.
**Étapes / attendu** :
- Après build+publication, `/doc/admin/` répond 200 avec, dans le HTML, les sept domaines : utilisateurs et groupes, parc et postes, applications et personnalisation des postes, fichiers et partages, droits et délégation, installation et déploiement d'un poste, réglages et supervision.
- Chaque domaine porte un chemin de navigation citant les libellés RÉELS des menus (« menu Pilotage, entrée Utilisateurs » ; « menu Parc & postes, entrée Gestion du parc / Applications » ; « menu Serveur, entrée Réglages » ; « menu Pilotage, entrée Tableau de bord ») — vérifiables contre `sidebar.blade.php`.
- Une phrase signale que certaines entrées (le menu Réglages) ne sont visibles qu'aux personnes détenant le droit correspondant, sans nommer de permission technique.
- **Aucun lien interne de doc vers une page de domaine inexistante** : les domaines sont en TEXTE. `npm run build` vert = preuve d'absence de lien mort (`ignoreDeadLinks` au défaut strict).
- Le placeholder « en cours de rédaction » a disparu ; frontmatter `title`/`description` mis à jour.

### Scénario 6.2 — Schéma besoins → domaines rendu et responsive
**But** : relier un besoin courant à son domaine, lisible clair/sombre et mobile, sans ressource externe.
**Étapes / attendu** :
- Le HTML de `/doc/admin/` contient 7 paires `se5-plan__pair` (besoin · flèche · domaine), chacune avec un `se5-plan__need`, un `se5-plan__arrow` (`aria-hidden`) et un `se5-plan__domain`.
- Aucune balise `<img>` dans la page (le lint la refuserait) ; le schéma est 100 % HTML/CSS.
- Les styles `se5-plan-*` vivent uniquement dans `theme/custom.css` et n'emploient que des variables `--vp-c-*` (héritage clair/sombre par construction).
- **Contrôle visuel (VM/navigateur)** : en largeur mobile la paire passe en pile verticale (aucun débordement horizontal de page), la flèche pivote vers le bas ; lisible en thème clair ET sombre.

### Scénario 6.3 — « Comment lire une fiche » : encarts réels et 3 temporalités mot pour mot
**But** : le lecteur voit les vrais encarts et les trois délais nommés exactement comme dans les fiches.
**Étapes / attendu** :
- La section « Comment lire une fiche » explique les trois informations : droit requis, résultat observable, moment de visibilité.
- Le HTML porte un encart `se5-callout--droit-requis` d'exemple (rendu réel du container, jamais une imitation manuelle).
- Les trois `se5-callout--delai-effet` rendent EXACTEMENT « Effet immédiat », « À la prochaine ouverture de session », « Au prochain passage de l'agent sur le poste » (libellés de `DELAI_EFFET_LABELS`), chacun avec une phrase d'explication en langage courant.
- La temporalité « agent » porte le lien glossaire `/doc/glossaire.html#agent` à sa première occurrence.

### Scénario 6.4 — Convention de sidebar additive, sans lien mort
**But** : la sidebar `/admin/` ne référence que des pages existantes ; la convention pour les domaines à venir est écrite.
**Étapes / attendu** :
- La sidebar `/doc/admin/` ne contient QUE « Vue d'ensemble » (→ `/admin/`). Aucun groupe de domaine, aucun lien vers une page absente.
- Un commentaire au-dessus du bloc `'/admin/'` dans `config.mjs` énonce la règle additive (chaque domaine ajoute son groupe quand ses pages existent, jamais par pré-remplissage).
- La sidebar `/doc/poste/` est bit à bit inchangée.

### Scénario 6.5 — Charte, lint et autonomie réseau
**But** : la page respecte les règles de rédaction et n'introduit aucune dépendance réseau.
**Étapes / attendu** :
- `npm run lint` (ou `node .vitepress/lint-doc.mjs`) vert : aucun mot interdit (« story »/« épic »/codes d'exigence/IPv4), aucune ancre glossaire inexistante, aucun container natif `warning`/`danger`, aucune balise `<img>`.
- Aucune fonctionnalité non livrée présentée comme disponible ; aucune liste des manques (les sujets écartés de l'épic ne figurent pas sur la page).
- `grep -RInE "https?://(fonts|cdn|unpkg|cdnjs|jsdelivr|googleapis)" userDoc/.vitepress/dist/` → vide (schéma sans image ni ressource externe).
- `/doc/`, `/doc/poste/`, `/doc/glossaire.html` inchangés ; `git diff` vide hors `userDoc/` et `docs/qa/`.

### Limites connues Section 6 (différé VM)
- **Contrôle visuel réel non couvert en ssh-only** : rendu du schéma et des encarts d'exemple en thème clair/sombre et en viewport mobile — reposent sur l'héritage des variables `--vp-c-*` et sur une grille sans largeur fixe (pas de scroll horizontal par construction), mais non observés dans un navigateur réel. Même statut que les sections précédentes de ce runbook.
- **Matrice curl serveur non rejouée** : les codes 200/inchangés ci-dessus sont attendus depuis le build local autoritaire, non vérifiés sur le serveur tant que la VM n'a pas été sollicitée pour cette story.

### Checklist rapide Section 6
- [ ] `/doc/admin/` : 7 domaines en texte, chemins de navigation réels, phrase de visibilité conditionnelle, aucun lien de doc mort
- [ ] Schéma : 7 paires `se5-plan__pair`, aucune `<img>`, styles `se5-plan-*` sur `--vp-c-*` uniquement
- [ ] Section lecture : encart `droit-requis` + 3 encarts `delai-effet` aux libellés exacts, lien `#agent` à la 1re occurrence
- [ ] Sidebar `/admin/` = « Vue d'ensemble » seule + commentaire de convention additive ; `/poste/` inchangée
- [ ] Lint vert, autonomie réseau vide, `git diff` limité à `userDoc/` + `docs/qa/`
- [ ] **VM (différé)** : matrice curl serveur + contrôle visuel schéma/encarts clair/sombre/mobile

---

## Section 7 — Domaine admin « Utilisateurs et groupes » (Story 53.2)

**Portée** : premières fiches de domaine du guide « J'administre SE5 », sous `userDoc/admin/utilisateurs/` — page d'entrée + six fiches (créer / modifier / réinitialiser un mot de passe / désactiver ou supprimer / groupes / en cas de problème). Sidebar `'/admin/'` enrichie d'un seul groupe (additif) ; lien de domaine posé sur la page d'orientation `admin/index.md`. Rédaction adossée à la table de faits métier de la story (comptes, mots de passe éphémères, suppression en deux temps, groupes), aucune écriture de code.

**Code / sources de référence** :
- `userDoc/admin/utilisateurs/{index,creer-un-compte,modifier-un-compte,reinitialiser-un-mot-de-passe,desactiver-ou-supprimer-un-compte,groupes-d-utilisateurs,en-cas-de-probleme}.md` — les 7 nouvelles pages
- `userDoc/.vitepress/config.mjs` — groupe sidebar `'/admin/'` « Utilisateurs et groupes » (additif, sous le commentaire de convention posé par la fondation du guide)
- `userDoc/admin/index.md` — sous-section « Utilisateurs et groupes » dont le titre devient un lien vers `/admin/utilisateurs/`

### Scénario 7.1 — Les 7 pages du domaine existent et se rendent
**But** : le domaine est complet et publié sous `/doc/admin/utilisateurs/`.
**Étapes / attendu** :
- Le build produit `admin/utilisateurs/{index,creer-un-compte,modifier-un-compte,reinitialiser-un-mot-de-passe,desactiver-ou-supprimer-un-compte,groupes-d-utilisateurs,en-cas-de-probleme}.html`.
- **Matrice curl (VM)** : les 7 pages en 200 ; `/doc/admin/` en 200 ; `/doc/`, `/doc/poste/`, `/doc/glossaire.html` inchangés.
- La page d'entrée `index.md` liste les six fiches et ne redéveloppe pas leur contenu.

### Scénario 7.2 — Sidebar `/admin/` additive et sans lien mort ; `/poste/` intouchée
**But** : le seul ajout à la navigation admin est le groupe du domaine, et il ne pointe que vers des pages existantes.
**Étapes / attendu** :
- La sidebar `/doc/admin/` porte, après « Administration SE5 », le groupe « Utilisateurs et groupes » avec ses 6 entrées + le lien de tête vers `/admin/utilisateurs/`.
- Chaque entrée pointe vers une page réellement construite (build strict VitePress vert = preuve d'absence de lien mort).
- La sidebar `/doc/poste/` est bit à bit inchangée ; aucun groupe n'apparaît sous `'/poste/'`.
- Le commentaire de convention additive au-dessus du bloc `'/admin/'` est conservé (aucune réécriture ni réordonnancement).

### Scénario 7.3 — Lien de domaine sur la page d'orientation
**But** : la mention texte du domaine devient un lien, sans autre retouche de la page.
**Étapes / attendu** :
- Sur `/doc/admin/`, le titre de la sous-section « Utilisateurs et groupes » est un lien vers `/admin/utilisateurs/`.
- Les six autres sous-sections de domaine restent en texte (leurs pages n'existent pas encore) ; le reste de la page (schéma besoin→domaine, « Comment lire une fiche ») est inchangé.

### Scénario 7.4 — Gabarit, encarts et valeurs `delai-effet`
**But** : chaque fiche « comment faire » respecte le gabarit et n'emploie que les encarts normalisés avec des valeurs valides.
**Étapes / attendu** :
- Chaque fiche d'action porte : titre = la tâche, phrase d'intention, « Où ça se passe » (menu **Pilotage** → **Utilisateurs**), gestes numérotés, résultat observable.
- Encarts `droit-requis` en langage métier (« administrateur des utilisateurs », « droit de réinitialisation des mots de passe ») — jamais de clé technique.
- Valeurs `delai-effet` conformes : `immediat` (créer, réinitialiser), `session` (modifier, désactiver/supprimer, groupes). Une valeur invalide ferait échouer le build (validation du container).
- Encarts `attention` présents là où prévu : mot de passe affiché une seule fois (créer), mot de passe jamais conservé + export 20 min (réinitialiser), suppression irréversible (désactiver/supprimer), suppression de groupes (groupes).
- Encarts `vue-poste` sur les gestes à conséquence poste (créer : 1re connexion = changement de mot de passe).

### Scénario 7.5 — Exactitude métier : points sensibles énoncés sans ambiguïté
**But** : les verdicts centraux de la story sont fidèlement rendus.
**Étapes / attendu** :
- **Suppression en deux temps** : la fiche désactiver/supprimer structure le geste (désactiver = réversible, dossier archivé ; supprimer = seulement sur compte désactivé, irréversible) ; les comptes système ne se désactivent ni ne se suppriment ; une session ouverte n'est pas coupée (`delai-effet session`).
- **Mot de passe éphémère** : restitution écran pour un seul compte, export PDF/CSV obligatoire (lien 20 min) pour plusieurs ou un groupe ; jamais conservé ; aucune promesse de ré-affichage.
- **Portée enseignant** : la fiche réinitialiser précise en une phrase qu'un enseignant n'agit que sur les élèves de ses classes.
- **Trois portes de réinitialisation** décrites (fiche, sélection multiple, groupe entier).
- Renvoi croisé vers `/poste/mon-compte/changement-impose` présent (créer et réinitialiser).

### Scénario 7.6 — « En cas de problème » : symptômes → vérifications interface, zéro commande
**But** : la page d'aide reste dans l'interface, sans manipulation serveur.
**Étapes / attendu** :
- Trois symptômes couverts : compte introuvable (chips de filtres + **Tout effacer**, recherche à partir de 2 caractères, badge **Inactif**), connexion refusée (badge **Inactif**, filtre d'audit **Mot de passe par défaut**, solution = réinitialiser), groupe sans effet (composition sur la fiche, effet à la prochaine ouverture de session, dernier recours **Resynchroniser AD**).
- Aucune commande shell, aucun chemin serveur, aucune clé technique dans la page.

### Scénario 7.7 — Aucun vestige legacy ni compte fédéré documenté
**But** : la doc n'expose que le livré et attesté.
**Étapes / attendu** :
- Aucune fiche ne mentionne « Régénérer profil Windows », « Créer dossier personnel », ni aucune page de création/gestion de comptes externes/fédérés.
- Les surfaces d'autres domaines (capacités, quota, partage de classe) sont signalées d'une phrase au plus sur la fiche groupes, **sans lien** (aucune page cible n'existe encore).
- Les libellés d'interface réels cités le sont tels quels quand la fiche décrit le bouton (« Resynchroniser AD », « Réinitialiser les mdp du groupe », « Nouvel utilisateur », « Nouveau groupe », « Nommer un professeur principal »…).

### Scénario 7.8 — Lint, glossaire et autonomie réseau
**But** : les règles de rédaction et l'autonomie sont tenues.
**Étapes / attendu** :
- `node .vitepress/lint-doc.mjs` vert : aucun mot interdit (« story »/« épic »/codes d'exigence/IPv4), aucun container natif `warning`/`danger`, aucune balise `<img>` brute.
- Seul lien glossaire employé : `/glossaire#espace-personnel` (dossier personnel créé/archivé) ; « annuaire », « classe », « groupe d'utilisateurs » restent en langage courant, sans lien.
- `grep -RInE "https?://(fonts|cdn|unpkg|cdnjs|jsdelivr|googleapis)" userDoc/.vitepress/dist/` → vide.
- Références de captures posées (formulaire de création, fenêtre de réinitialisation, fiche groupe) sous `/captures/admin/utilisateurs/...` en kebab-case, avec alt complet issu du jeu fictif ; images non produites (repli « Illustration à venir » attendu).

### Limites connues Section 7 (différé VM)
- **Contrôle visuel réel non couvert en ssh-only** : rendu des fiches et des encarts en thème clair/sombre et en viewport mobile non observé dans un navigateur — même dette d'environnement que les sections précédentes de ce runbook.
- **Matrice curl serveur non rejouée** : les codes 200/inchangés ci-dessus sont attendus depuis le build local autoritaire (lint + build VitePress strict verts), non vérifiés sur le serveur tant que la VM n'a pas été sollicitée pour ce domaine.

### Checklist rapide Section 7
- [ ] 7 pages du domaine construites ; page d'entrée = sommaire, sans redite
- [ ] Sidebar `/admin/` = un seul groupe additif « Utilisateurs et groupes » ; commentaire de convention conservé ; `/poste/` inchangée
- [ ] Titre de la sous-section « Utilisateurs et groupes » de `/admin/` devenu un lien ; reste de la page inchangé
- [ ] Gabarit tenu : droits en métier, encarts normalisés seuls, valeurs `delai-effet` valides
- [ ] Points sensibles exacts : deux-temps de la suppression, mot de passe éphémère (écran mono / export 20 min), portée enseignant, trois portes de réinitialisation
- [ ] « En cas de problème » : trois symptômes, vérifications interface, zéro commande
- [ ] Aucun vestige legacy ni compte fédéré documenté ; sections voisines signalées sans lien
- [ ] Lint vert, seul lien glossaire `#espace-personnel`, autonomie réseau vide
- [ ] **VM (différé)** : matrice curl serveur + contrôle visuel fiches clair/sombre/mobile

## Section 8 — Domaine admin « Parc et postes » (Story 53.3)

**Portée** : domaine « Parc et postes » du guide « J'administre SE5 », sous `userDoc/admin/parc/` — page d'entrée + six fiches (lire l'état d'un poste / agir sur un poste / agir sur un groupe / constituer les groupes / salle ou parc logique / en cas de problème). Sidebar `'/admin/'` enrichie d'un seul groupe (additif) ; lien de domaine posé sur la page d'orientation `admin/index.md`. Rédaction adossée à la table de faits métier de la story (présence dérivée de l'agent, actions à retour immédiat + suivi, groupes physiques/logiques, règle de priorité), aucune écriture de code.

**Code / sources de référence** :
- `userDoc/admin/parc/{index,lire-l-etat-d-un-poste,agir-sur-un-poste,agir-sur-un-groupe,constituer-les-groupes,salle-ou-parc-logique,en-cas-de-probleme}.md` — les 7 nouvelles pages
- `userDoc/.vitepress/config.mjs` — groupe sidebar `'/admin/'` « Parc et postes » (additif, sous le groupe « Utilisateurs et groupes »)
- `userDoc/admin/index.md` — sous-section « Parc et postes » dont le titre devient un lien vers `/admin/parc/`

### Scénario 8.1 — Les 7 pages du domaine existent et se rendent
**But** : le domaine est complet et publié sous `/doc/admin/parc/`.
**Étapes / attendu** :
- Le build produit `admin/parc/{index,lire-l-etat-d-un-poste,agir-sur-un-poste,agir-sur-un-groupe,constituer-les-groupes,salle-ou-parc-logique,en-cas-de-probleme}.html`.
- **Matrice curl (VM)** : les 7 pages en 200 ; `/doc/admin/` en 200 ; `/doc/`, `/doc/poste/`, `/doc/glossaire.html` inchangés.
- La page d'entrée `index.md` liste les six fiches et ne redéveloppe pas leur contenu.

### Scénario 8.2 — Sidebar `/admin/` additive et sans lien mort ; `/poste/` intouchée
**But** : le seul ajout à la navigation admin est le groupe du domaine, et il ne pointe que vers des pages existantes.
**Étapes / attendu** :
- La sidebar `/doc/admin/` porte, après le groupe « Utilisateurs et groupes », le groupe « Parc et postes » avec ses 6 entrées + le lien de tête vers `/admin/parc/`.
- Chaque entrée pointe vers une page réellement construite (build strict VitePress vert = preuve d'absence de lien mort).
- La sidebar `/doc/poste/` est bit à bit inchangée ; le groupe « Utilisateurs et groupes » n'est ni réordonné ni modifié.
- Le commentaire de convention additive au-dessus du bloc `'/admin/'` est conservé.

### Scénario 8.3 — Lien de domaine sur la page d'orientation
**But** : la mention texte du domaine devient un lien, sans autre retouche de la page.
**Étapes / attendu** :
- Sur `/doc/admin/`, le titre de la sous-section « Parc et postes » est un lien vers `/admin/parc/`.
- Les autres sous-sections de domaine sans page publiée restent en texte ; le reste de la page (schéma besoin→domaine, « Comment lire une fiche ») est inchangé.

### Scénario 8.4 — Gabarit, encarts et valeurs `delai-effet`
**But** : chaque fiche respecte le gabarit et n'emploie que les encarts normalisés avec des valeurs valides.
**Étapes / attendu** :
- Chaque fiche d'action porte : titre = la tâche, phrase d'intention, « Où ça se passe » (menu **Parc & postes** → **Gestion du parc**), gestes ou actions, résultat observable.
- Encarts `droit-requis` en langage métier : « Voir les machines » (lire l'état), « Contrôle à distance » (agir sur un poste / sur un groupe), « Installer un poste » (constituer les groupes). Fiches « salle ou parc logique » et « en cas de problème » sans droit-requis.
- Valeurs `delai-effet` conformes : `immediat` pour les actions d'alimentation (agir sur un poste / sur un groupe), avec la précision « l'ordre part aussitôt ; le résultat dépend du poste » ; `agent` pour « Forcer la synchro » (agir sur un poste) et pour les appartenances de groupe (constituer). Aucune fiche de compréhension (salle ou parc logique) ne porte de `delai-effet`.
- Encarts `attention` : extinction forcée = travail non sauvegardé perdu (agir sur un poste, agir sur un groupe) ; bascule automatique une-salle-max (constituer) ; limite « de l'ordre de l'heure » de la présence (lire l'état).
- Encart `vue-poste` sur l'extinction/redémarrage visible côté utilisateur (agir sur un poste).

### Scénario 8.5 — Exactitude métier : présence, actions et priorité
**But** : les verdicts centraux de la story sont fidèlement rendus.
**Étapes / attendu** :
- **Présence** : quatre états nommés (« Allumé », « Éteint », « Éteint ou injoignable » = probablement éteint sans certitude, « Présence inconnue » pour un poste sans agent) ; détection du silence « de l'ordre de l'heure », jamais présentée comme du temps réel.
- **Retour immédiat + suivi** : chaque fiche d'action distingue le message de lancement (départ de l'action, autres actions désactivées pendant le suivi sauf l'accès distant) et ce qu'il faut attendre (suivi jusqu'à « disponible » ou « non joignable » après environ deux minutes) ; la disponibilité = « le poste répond sur le réseau », jamais « session ouvrable ».
- **Allumage à distance** : formulation prudente (« dans la plupart des installations… si le poste ne s'allume jamais, rapprochez-vous de la personne qui gère le réseau »), jamais de promesse ferme.
- **Cinq actions** décrites (allumer, éteindre, forcer l'extinction, redémarrer, accès distant) ; accès distant exclu du geste groupé.
- **Programmations** : allumage/extinction seulement, récurrente ou date unique, historique, duplication d'une date passée.
- **Règle de priorité** relue mot à mot : « réglage propre à l'utilisateur > groupe d'utilisateurs > poste > parc logique > salle physique > défaut d'établissement » ; le parc logique l'emporte sur la salle ; un exemple concret (fond d'écran salle vs parc → le poste des deux affiche celui du parc). Aucune mention de tiers « amont » ni de départage entre deux parcs logiques.
- **Groupes** : nom affiché saisi + identifiant technique automatique et définitif ; type choisi à la création (physique = salle/bâtiment hiérarchisable ; logique = parc de machines, sélection libre) ; nature Partagé/Personnel/Nomade avec héritage Nomade > Personnel > Partagé ; une salle au plus par poste (bascule automatique) mais plusieurs parcs logiques ; groupes verrouillés non modifiables.

### Scénario 8.6 — « En cas de problème » : symptômes → vérifications interface, zéro commande
**But** : la page d'aide reste dans l'interface, sans manipulation serveur.
**Étapes / attendu** :
- Trois symptômes couverts : poste qui ne répond pas (état de présence + limite, poste réellement allumé, prudence réveil réseau, pare-feu local sobre) ; action sans effet visible (attendre la fin du suivi ~2 min, lire le message de fin, poste « déjà en cours » ignoré, historique d'une programmation, « Forcer la synchro » pour la configuration) ; poste absent de la liste (réinitialiser les filtres et tuiles, périmètre de délégation, « Synchroniser depuis l'AD », encart d'état de synchronisation).
- Chaque piste se termine sur un geste ou un endroit exact dans l'interface. Aucune commande shell, aucun chemin serveur, aucun fichier de log.

### Scénario 8.7 — Frontières de domaine tenues
**But** : la doc ne déborde pas sur les domaines voisins.
**Étapes / attendu** :
- **Réinstallation d'un poste** : absente des six fiches (ni décrite, ni liée, ni listée parmi les actions).
- **Imprimantes** : nommées uniquement dans l'énumération des trois onglets (page d'entrée + fiche « lire l'état ») ; aucune fiche ne les documente.
- **Applications / réglages / personnalisation** : mentionnés au plus d'une demi-phrase d'orientation (fiche « lire l'état »), **sans lien interne** (les domaines cibles ne sont pas publiés) ; leur contenu n'est pas décrit.

### Scénario 8.8 — Lint, glossaire et autonomie réseau
**But** : les règles de rédaction et l'autonomie sont tenues.
**Étapes / attendu** :
- `node .vitepress/lint-doc.mjs` vert : aucun mot interdit (« story »/« épic »/codes d'exigence/IPv4), aucun container natif `warning`/`danger`, aucune balise `<img>` brute.
- Vocabulaire technique interdit absent des fiches (AD, LDAP, OU, CN, GPO, WPKG, WOL, broadcast, VLAN, ping, batch, toast, pivot…) — seule exception : le libellé d'interface « Synchroniser depuis l'AD », cité tel quel.
- Chiffres internes traduits en formules sobres (« environ deux minutes », « de l'ordre de l'heure »), jamais 120 s / 3 s / 2 × ttl.
- Liens glossaire employés : `/glossaire#parc` et `/glossaire#groupe-de-postes` (fiches index, lire l'état, salle ou parc logique), à première occurrence ; ancres existantes.
- `grep -RInE "https?://(fonts|cdn|unpkg|cdnjs|jsdelivr|googleapis)" userDoc/.vitepress/dist/` → vide.
- Aucune référence de capture posée dans ce domaine (fiches sans image) : la liste informative de production du lint reste inchangée pour `admin/parc/`.

### Limites connues Section 8 (différé VM)
- **Contrôle visuel réel non couvert en ssh-only** : rendu des fiches et des encarts en thème clair/sombre et en viewport mobile non observé dans un navigateur — même dette d'environnement que les sections précédentes de ce runbook.
- **Matrice curl serveur non rejouée** : les codes 200/inchangés ci-dessus sont attendus depuis le build local autoritaire (lint + build VitePress strict verts), non vérifiés sur le serveur tant que la VM n'a pas été sollicitée pour ce domaine.
- **Vérification des libellés d'interface à l'écran** : les libellés cités (onglets, actions, boutons, états de présence, cartes de type, natures de postes) sont confirmés depuis le code source (blades et enums), pas observés dans une session navigateur — à recaler visuellement lors de la levée de la dette VM.

### Checklist rapide Section 8
- [ ] 7 pages du domaine construites ; page d'entrée = sommaire, sans redite
- [ ] Sidebar `/admin/` = un groupe additif « Parc et postes » ; commentaire de convention conservé ; « Utilisateurs et groupes » et `/poste/` inchangés
- [ ] Titre de la sous-section « Parc et postes » de `/admin/` devenu un lien ; reste de la page inchangé
- [ ] Gabarit tenu : droits en métier, encarts normalisés seuls, valeurs `delai-effet` valides (`immediat` alimentation, `agent` synchro/appartenances)
- [ ] Présence : 4 états + limite « ordre de l'heure », pas de temps réel
- [ ] Actions : retour immédiat + suivi ~2 min, allumage prudent, 5 actions, accès distant hors geste groupé
- [ ] Règle de priorité mot à mot (parc logique > salle), exemple concret, zéro amont ni départage intra-rang
- [ ] Groupes : nom/identifiant, type à la création, nature + héritage, une salle max, verrouillés non modifiables
- [ ] « En cas de problème » : 3 symptômes, vérifications interface, zéro commande
- [ ] Frontières : zéro réinstallation, imprimantes seulement nommées, applications/réglages sans lien
- [ ] Lint vert, vocabulaire interdit absent, liens glossaire `#parc` / `#groupe-de-postes`, autonomie réseau vide
- [ ] **VM (différé)** : matrice curl serveur + contrôle visuel fiches clair/sombre/mobile + recalage des libellés

## Section 9 — Domaine admin « Applications et personnalisation des postes » (Story 53.4)

**Portée** : domaine « Applications et personnalisation des postes » du guide « J'administre SE5 », sous `userDoc/admin/applications/` — page d'entrée + **sept** fiches (catalogue et dépôt / affecter une application / retirer une application / fonds d'écran / raccourcis / paramétrer Firefox et Thunderbird / en cas de problème). La huitième fiche prévue (« Messages aux utilisateurs ») est **volontairement exclue** : la page « Infos à transmettre » (overlay-messages) est orpheline de menu, on ne documente pas un accès par URL directe. Sidebar `'/admin/'` enrichie d'un seul groupe (additif) ; lien de domaine posé sur `admin/index.md`. Rédaction adossée à la table de faits F1-F27, aucune écriture de code.

**Code / sources de référence** :
- `userDoc/admin/applications/{index,catalogue-et-depot,affecter-une-application,retirer-une-application,fonds-d-ecran,raccourcis,parametrer-firefox-et-thunderbird,en-cas-de-probleme}.md` — les 8 nouvelles pages
- `userDoc/.vitepress/config.mjs` — groupe sidebar `'/admin/'` « Applications et personnalisation » (additif, sous « Parc et postes »)
- `userDoc/admin/index.md` — sous-section « Applications et personnalisation des postes » dont le titre devient un lien vers `/admin/applications/`
- Libellés confirmés depuis le code : `resources/views/pages/parc-settings/index.blade.php` (onglets, bandeau amont), `_partials/applications-tab.blade.php` (Déployer sur un groupe, Supprimer l'installation, branches), `_partials/shortcuts-tab.blade.php` (emplacements, badge ControlHub), `pages/parc/groups/[id]/index.blade.php` (vignettes fonds d'écran), `pages/admin/settings/parc-defaults/index.blade.php` (onglets), `routes/web.php` (route overlay-messages orpheline)

### Scénario 9.1 — Les 8 pages du domaine existent et se rendent
**But** : le domaine est publié sous `/doc/admin/applications/`, avec la fiche messages exclue à dessein.
**Étapes / attendu** :
- Le build produit `admin/applications/{index,catalogue-et-depot,affecter-une-application,retirer-une-application,fonds-d-ecran,raccourcis,parametrer-firefox-et-thunderbird,en-cas-de-probleme}.html`.
- **Aucun** `messages-aux-utilisateurs.html` généré (fiche exclue).
- **Matrice curl (VM)** : les 8 pages en 200 ; `/doc/admin/` en 200 ; `/doc/`, `/doc/poste/`, `/doc/glossaire.html` inchangés.
- La page d'entrée `index.md` liste les sept fiches et ne redéveloppe pas leur contenu.

### Scénario 9.2 — Sidebar `/admin/` additive et sans lien mort ; `/poste/` intouchée
**But** : le seul ajout à la navigation admin est le groupe du domaine, pointant vers des pages existantes.
**Étapes / attendu** :
- La sidebar `/doc/admin/` porte, après « Parc et postes », le groupe « Applications et personnalisation » avec ses 7 entrées + le lien de tête vers `/admin/applications/`.
- Aucune entrée « Messages aux utilisateurs » dans la sidebar.
- Chaque entrée pointe vers une page réellement construite (build strict VitePress vert = preuve d'absence de lien mort).
- La sidebar `/doc/poste/` est bit à bit inchangée ; les groupes « Utilisateurs et groupes » et « Parc et postes » ne sont ni réordonnés ni modifiés ; le commentaire de convention additive est conservé.

### Scénario 9.3 — Lien de domaine sur la page d'orientation
**But** : la mention texte du domaine devient un lien, sans autre retouche.
**Étapes / attendu** :
- Sur `/doc/admin/`, le titre de la sous-section « Applications et personnalisation des postes » est un lien vers `/admin/applications/`.
- Le reste de la page (autres sous-sections, schéma besoin→domaine, « Comment lire une fiche ») est inchangé.

### Scénario 9.4 — Gabarit, encarts et valeurs `delai-effet`
**But** : chaque fiche respecte le gabarit et n'emploie que les encarts normalisés avec des valeurs valides.
**Étapes / attendu** :
- Chaque fiche d'action porte : titre = la tâche, phrase d'intention, « Où ça se passe » (menu **Parc & postes** → **Applications** le plus souvent), gestes, résultat observable.
- Encarts `droit-requis` en langage métier et POSITIF : « administrateur des applications du parc » (catalogue, affecter, retirer), « délégable salle par salle » (affecter, retirer), « gestion des fonds d'écran » (fonds d'écran), « gestion des raccourcis » (raccourcis), « personnalisation des applications » (Firefox/Thunderbird). Fiche « en cas de problème » sans `droit-requis`.
- Valeurs `delai-effet` : **`agent`** partout (affecter, retirer, fonds d'écran, raccourcis, Firefox/Thunderbird) ; **AUCUN** `delai-effet` sur « catalogue et dépôt » (l'ajout au catalogue seul ne change rien sur les postes) ni sur « en cas de problème ». **Aucune fiche `delai-effet session`** dans ce domaine (la seule qui l'aurait porté — messages — est exclue).
- Encart `attention` : « Supprimer l'installation » irréversible + cascade (catalogue) ; « retirer = désinstaller » + non-ingérence hors-SE5 (retirer). Pas d'`attention` ailleurs.
- Encart `vue-poste` : menu Démarrer (affecter, retirer), fond/verrouillage (fonds d'écran), raccourci posé (raccourcis).

### Scénario 9.5 — Exactitude métier : verdicts centraux
**But** : les formulations de vérité sensibles sont fidèlement rendues.
**Étapes / attendu** :
- **Catalogue depuis le dépôt** : le catalogue n'a pas de saisie manuelle ; toute application vient d'un dépôt (branches Stable/Testing/Manuel, Stable = choix normal) ; colonne « Déploiement » (installés/visés) présentée comme premier signe serveur.
- **Contrat amont** : décrit côté « ce que voit l'admin » (bandeau « Dépôts gérés par l'autorité amont », mention « (imposé par l'autorité amont) », actions de dépôt désactivées, dépôt qui se met à jour tout seul) ; ajout au catalogue borné MAIS **affectation entièrement la main de l'établissement** ; verrous « tant que le lien est actif », rien sur la mécanique de rupture. Aucun « central », aucun « controlHub » hors citation du badge « Géré par ControlHub » (fiche raccourcis).
- **Trois voies d'affectation** : profil applicatif (voie de référence, héritage parent→sous-groupes en une phrase), direct sur groupe (deux portes : « Déployer sur un groupe » + cartes de l'onglet Applications), direct sur poste ; plus le socle commun (Réglages → Configuration par défaut du parc → Applications, réservé à l'administration du serveur).
- **Délai sans SLA** : « le poste récupère la décision tout seul… en général dans l'heure qui suit, poste allumé » ; aucun « toutes les 60 minutes » garanti, aucun nom de réglage, aucun numéro de version.
- **Symétrie ajout/retrait** : « retirer = désinstaller » énoncé sans ambiguïté ; garde « un logiciel installé hors SE5 n'est jamais touché ».
- **Distinction des deux retraits** : « Supprimer l'installation » (catalogue, tout l'établissement, cascade) vs retrait d'affectation (postes concernés seulement) ; les deux fiches se renvoient l'une à l'autre.
- **Fonds d'écran** : Bureau vs écran de verrouillage ; trois surfaces réelles (défaut établissement / vignettes de salle / fond personnel utilisateur) ; « le plus spécifique gagne » ; verrouillage **jamais par utilisateur ni groupe d'utilisateurs** ; pas de fond « par parc logique » inventé (piège n°7).
- **Firefox/Thunderbird** : bouton « Paramétrer » + badge « Personnalisé », défaut d'établissement + plus spécifique gagne, couplage à l'installation, délai en deux temps (agent puis prochain lancement). **Aucune** allusion au suivi des réglages d'un poste à l'autre (piège n°2).

### Scénario 9.6 — « En cas de problème » : symptômes → vérifications interface, zéro commande
**But** : la page d'aide reste dans l'interface, sans manipulation serveur.
**Étapes / attendu** :
- Trois symptômes couverts : application qui ne s'installe pas (erreur au catalogue + « Réessayer l'installation » ; onglet Échecs + journal « Erreur »/« Non installé » ; poste allumé + « Forcer la synchro ») ; application qui ne se retire pas (toutes les voies : profils qui la portent, direct/hérité de la fiche poste, socle commun ; même patience/forçage ; cas « hors SE5, on n'y touche pas ») ; écart demandé/constaté (carte « Déploiement sur les postes » poste par poste, forcer la synchro, signaler).
- Chaque piste se termine sur un geste ou un endroit exact dans l'interface. Aucune commande shell, aucun chemin serveur, aucun fichier de log.

### Scénario 9.7 — Frontières de domaine tenues et page orpheline exclue
**But** : la doc ne déborde pas sur les domaines voisins et n'expose pas de surface morte.
**Étapes / attendu** :
- **Capacités / registre / outils agent / associations / état cible** : jamais décrits ni liés (domaines « Réglages et supervision » et « Parc et postes »). Les onglets « Registre / capacités » et « Outils agent » de la Configuration par défaut du parc ne sont pas documentés.
- **Lecteurs réseau, politiques de fichiers, associations de fichiers, mode examen, personnalisations Linux** : absents.
- **Suivi des réglages d'un poste à l'autre (roaming)** : aucune allusion nulle part (piège n°2) ; l'homonymie « profil applicatif » est désambiguïsée par le seul lien glossaire.
- **Tableau de bord « Déploiement WPKG »** : non documenté (orphelin de menu + hors périmètre) ; le suivi passe par la colonne « Déploiement » et la carte « Déploiement sur les postes ».
- **Messages overlay / « Infos à transmettre »** : **aucune** page publiée n'y fait allusion (page orpheline de menu, exclue à dessein).

### Scénario 9.8 — Lint, glossaire et autonomie réseau
**But** : les règles de rédaction et l'autonomie sont tenues.
**Étapes / attendu** :
- `node .vitepress/lint-doc.mjs` vert : aucun mot interdit (« story »/« épic »/codes d'exigence/IPv4), aucun container natif `warning`/`danger`, aucune balise `<img>` brute.
- Vocabulaire technique interdit absent (WPKG, AD, LDAP, provider, handler, desired state, pivot, clés de permission…) ; seuls les libellés d'interface se citent tels quels (onglets, « Déployer sur un groupe », « Supprimer l'installation », « Appliquer par défaut », branches, « Géré par ControlHub », « imposé par l'autorité amont »).
- Chiffres internes traduits en formules sobres (« dans l'heure qui suit, poste allumé », « dans la minute qui suit un contact »), jamais 3600 s ni « toutes les 60 minutes » garanti.
- Liens glossaire employés : `/glossaire#depot-applications`, `#socle-commun`, `#parc`, `#profil-applicatif`, `#agent`, à première occurrence ; ancres existantes.
- Références de captures posées (4) : `catalogue-et-depot` (onglet-catalogue, modale-depot), `affecter-une-application` (fiche-application), `fonds-d-ecran` (vignettes-salle) ; fichiers PNG absents → placeholders, listés par la sortie informative du lint.
- `grep -RInE "https?://(fonts|cdn|unpkg|cdnjs|jsdelivr|googleapis)" userDoc/.vitepress/dist/` → vide.

### Limites connues Section 9 (différé VM)
- **Contrôle visuel réel non couvert en ssh-only** : rendu des fiches et des encarts en thème clair/sombre et en viewport mobile non observé dans un navigateur — même dette d'environnement que les sections précédentes.
- **Matrice curl serveur non rejouée** : les codes 200/inchangés ci-dessus sont attendus depuis le build local autoritaire (lint + build VitePress strict verts), non vérifiés sur le serveur.
- **Vérification des libellés d'interface à l'écran** : les libellés cités sont confirmés depuis le code source (blades, enums, routes), pas observés dans une session navigateur — à recaler visuellement lors de la levée de la dette VM.
- **Gap produit signalé (hors périmètre doc)** : « Infos à transmettre » (overlay-messages) livrée et gardée mais orpheline de navigation ; non documentée tant qu'aucune entrée de menu n'existe.

### Checklist rapide Section 9
- [ ] 8 pages du domaine construites (7 fiches + index) ; aucune fiche « messages » ; page d'entrée = sommaire sans redite
- [ ] Sidebar `/admin/` = un groupe additif « Applications et personnalisation » (7 entrées) ; commentaire de convention conservé ; groupes existants et `/poste/` inchangés
- [ ] Titre de la sous-section « Applications et personnalisation des postes » de `/admin/` devenu un lien ; reste de la page inchangé
- [ ] Gabarit tenu : droits en métier et positifs, encarts normalisés seuls, `delai-effet agent` (jamais `session` ici), pas de `delai-effet` sur catalogue ni en cas de problème
- [ ] Catalogue alimenté par le dépôt (pas de saisie manuelle), branches Stable/Testing/Manuel
- [ ] Contrat amont : ce que voit l'admin, ajout borné mais affectation = sa main, « tant que le lien est actif », zéro mécanique de rupture
- [ ] Trois voies + socle commun (Réglages, admin serveur) ; délai sans SLA
- [ ] Symétrie « retirer = désinstaller » + garde hors-SE5 ; distinction « Supprimer l'installation » vs retrait d'affectation (renvois croisés)
- [ ] Fonds d'écran : Bureau/verrouillage, 3 surfaces, plus spécifique gagne, verrouillage jamais par utilisateur, pas de fond « par parc logique »
- [ ] Firefox/Thunderbird : « Paramétrer », défaut + plus spécifique, couplage installation, délai en deux temps, zéro roaming
- [ ] « En cas de problème » : 3 symptômes, vérifications interface, zéro commande
- [ ] Frontières : zéro capacité/registre/outils, zéro lecteur réseau/association, zéro tableau de bord de déploiement, zéro message overlay, zéro roaming
- [ ] Lint vert, vocabulaire interdit absent, liens glossaire `#depot-applications`/`#socle-commun`/`#parc`/`#profil-applicatif`/`#agent`, autonomie réseau vide
- [ ] **VM (différé)** : matrice curl serveur + contrôle visuel fiches clair/sombre/mobile + recalage des libellés

## Section 10 — Domaine admin « Fichiers et partages » (Story 53.5)

**Portée** : domaine « Fichiers et partages » du guide « J'administre SE5 », sous `userDoc/admin/fichiers/` — page d'entrée + **six** fiches (régler la politique de fichiers / le partage de classe / créer un partage / gérer les accès d'un partage / limiter l'espace de stockage / en cas de problème). Sidebar `'/admin/'` enrichie d'un seul groupe (additif, après « Applications et personnalisation ») ; lien de domaine posé sur `admin/index.md`. Rédaction adossée à la table de faits F1-F20, aucune écriture de code (rien hors `userDoc/` + ce runbook).

**Code / sources de référence** :
- `userDoc/admin/fichiers/{index,politique-de-fichiers,partage-de-classe,creer-un-partage,gerer-les-acces-d-un-partage,limiter-l-espace-de-stockage,en-cas-de-probleme}.md` — les 7 nouvelles pages
- `userDoc/.vitepress/config.mjs` — groupe sidebar `'/admin/'` « Fichiers et partages » (additif, sous « Applications et personnalisation »)
- `userDoc/admin/index.md` — sous-section « Fichiers et partages » dont le titre devient un lien vers `/admin/fichiers/` (le chemin « menu Serveur, entrée Réglages » déjà publié est laissé tel quel)
- Libellés confirmés depuis le code : `pages/admin/settings/files/index.blade.php` (onglets « Personnels et partagés » / « Lecteurs réseaux », titre « Gestion des fichiers »), `_partials/personnels-partages-tab.blade.php` (les 3 interrupteurs, « Web uniquement », « Au prochain logon », Nextcloud désactivé), `pages/admin/shares/index.blade.php` (« Nouveau répertoire », « Créer depuis un template »), `pages/admin/shares/[id]/index.blade.php` (types d'assignation, Resynchroniser, suppression), `app/Models/NetworkShareAssignable.php` (libellés « Lire »/« Modifier »), `app/Services/Filesystem/NetworkShareValidator.php` (texte d'avertissement montage-seul), `database/seeders/DirectoryTemplateSeeder.php` (les 4 modèles), `class-share-section.blade.php` (bascule échange), `quota-section.blade.php` + `group-quota-section.blade.php` (Actualiser, Modifier le quota, Hérité (défaut)/Illimité)

### Scénario 10.1 — Les 7 pages du domaine existent et se rendent
**But** : le domaine est publié sous `/doc/admin/fichiers/`.
**Étapes / attendu** :
- Le build produit `admin/fichiers/{index,politique-de-fichiers,partage-de-classe,creer-un-partage,gerer-les-acces-d-un-partage,limiter-l-espace-de-stockage,en-cas-de-probleme}.html`.
- **Matrice curl (VM)** : les 7 pages en 200 ; `/doc/admin/` en 200 ; `/doc/`, `/doc/poste/`, `/doc/glossaire.html` inchangés.
- La page d'entrée `index.md` liste les six fiches et ne redéveloppe pas leur contenu.

### Scénario 10.2 — Sidebar `/admin/` additive et sans lien mort ; `/poste/` intouchée
**But** : le seul ajout à la navigation admin est le groupe du domaine, pointant vers des pages existantes.
**Étapes / attendu** :
- La sidebar `/doc/admin/` porte, après « Applications et personnalisation », le groupe « Fichiers et partages » avec ses 6 entrées + le lien de tête vers `/admin/fichiers/`.
- Chaque entrée pointe vers une page réellement construite (build strict VitePress vert = preuve d'absence de lien mort, y compris les renvois `/poste/fichiers/…` et le lien `/admin/utilisateurs/`).
- La sidebar `/doc/poste/` est bit à bit inchangée ; les groupes « Utilisateurs et groupes », « Parc et postes » et « Applications et personnalisation » ne sont ni réordonnés ni modifiés ; le commentaire de convention additive est conservé.

### Scénario 10.3 — Lien de domaine sur la page d'orientation
**But** : la mention texte du domaine devient un lien, sans autre retouche.
**Étapes / attendu** :
- Sur `/doc/admin/`, le titre de la sous-section « Fichiers et partages » est un lien vers `/admin/fichiers/`.
- Le chemin « menu Serveur, entrée Réglages » et le reste de la page (autres sous-sections, schéma besoin→domaine, « Comment lire une fiche ») sont inchangés.

### Scénario 10.4 — Gabarit, encarts et valeurs `delai-effet`
**But** : chaque fiche respecte le gabarit et n'emploie que les encarts normalisés avec des valeurs valides.
**Étapes / attendu** :
- Chaque fiche d'action porte : titre = la tâche, phrase d'intention, « Où ça se passe », gestes, résultat observable.
- Encarts `droit-requis` en langage métier : « administrateur du serveur » (politique, créer, accès, limiter), « droit de gestion des partages » (partage de classe). Fiche « en cas de problème » sans `droit-requis`.
- Valeurs `delai-effet` : **`session`** (politique, créer un partage, gérer les accès — avec nuance retrait immédiat côté serveur) ; **`immediat`** (partage de classe : bascule échange + phrase « nouvel élève = prochaine session » ; limiter l'espace). **AUCUN** `delai-effet` sur « en cas de problème ».
- Encart `attention` : politique (désactiver ≠ supprimer), partage de classe (échange désactivé = contenu invisible mais conservé), gérer les accès (salle = lettre visible sans accès ; suppression = accès révoqués, fichiers conservés serveur). Pas d'`attention` sur créer / limiter / en cas de problème.
- Encart `vue-poste` : présent sur les cinq fiches d'action, absent sur « en cas de problème ».

### Scénario 10.5 — Exactitude métier : verdicts centraux
**But** : les formulations de vérité sensibles sont fidèlement rendues.
**Étapes / attendu** :
- **Politique = 3 interrupteurs GLOBAUX** : « Répertoire personnel (K:) », « Partages réseau (H:) », « Nextcloud natif », valables pour tout l'établissement, **jamais** présentés comme un réglage par salle ou par parc ; effet concret par interrupteur + renvois `/poste/fichiers/…` ; « web uniquement » quand tout est désactivé ; enregistrement immédiat (pas de bouton Enregistrer, indicateur « Enregistré »). Le mot **« capacité »** n'est **jamais** employé pour ces interrupteurs ; aucun lien `/glossaire#capacite`.
- **Nextcloud** : une seule phrase (« option visible mais pas encore activable »), jamais présenté comme disponible, jamais d'URL ni de « bientôt ».
- **Lecture ≠ écriture** : chaque énoncé d'accès porte son niveau (**Lire** = consultation seule / **Modifier** = consultation + écriture) ET son périmètre (les membres du groupe ; pour une classe, les élèves ; équipe enseignante = groupe distinct).
- **Lettre visible ≠ accès réel** : les deux axes distingués ; une assignation à une salle (parc) est « montage seul » (lettre visible, aucun accès) ; le texte d'avertissement de l'écran est cité verbatim.
- **Partage de classe** : tableau des 4 sous-dossiers (travail / réservé enseignants / échange / au nom de l'élève) en lecture/écriture ; le dossier au nom de l'élève n'est PAS l'espace personnel privé ; bascule échange (désactivé = contenu conservé mais invisible) ; réappliquer les accès = rattrapage sans danger.
- **Créer un partage** : création directe (nom, nom de dossier contraint, libellé facultatif, lettre facultative pré-suggérée, lettres réservées refusées avec message — sans recopier la liste) + 4 modèles (qui dépose = écriture, qui lit = lecture) ; seules `K:`/`H:` fixes, une lettre automatique peut changer → repérer par le nom.
- **Limiter l'espace** : quota d'un compte (section Quotas disque : Actualiser, Modifier le quota) + quota d'un groupe (Hérité (défaut) / Illimité / valeur propre, Modifier) ; « la règle la plus favorable au compte s'applique » (une phrase) ; vécu au dépassement (enregistrement échoue, averti à la connexion) ; filtre d'audit « Quota dépassé » renvoyé au domaine Utilisateurs. **Aucun** réglage de quotas par défaut, de grâce ni de corbeille.

### Scénario 10.6 — « En cas de problème » : symptômes → vérifications interface, zéro commande
**But** : la page d'aide reste dans l'interface, sans manipulation serveur.
**Étapes / attendu** :
- Deux symptômes couverts : « un espace n'apparaît pas » (interrupteur global actif ? personne/groupe/salle dans les assignations ? session rouverte ? lettre automatique susceptible d'avoir changé ?) ; « accès refusé malgré le groupe » (niveau « Lire » au lieu de « Modifier » ? partage assigné qu'à des salles ? appartenance datant de la session en cours ? conformité en écart → Resynchroniser ? dossier d'échange désactivé pour une classe ?).
- Pistes ordonnées du plus probable au plus rare ; chaque piste se termine sur un geste ou un endroit exact dans l'interface. Aucune commande shell, aucun chemin serveur.

### Scénario 10.7 — Frontières de domaine tenues et surfaces mortes exclues
**But** : la doc ne déborde pas et n'expose aucune surface sans point d'entrée.
**Étapes / attendu** :
- **Quotas par défaut / période de grâce / corbeille / « profils itinérants »** : jamais documentés (onglets orphelins, partials non inclus — F19).
- **Règles d'accès aux dossiers** (`/app/folder-rules`) : jamais documentées (aucune entrée de menu — F20, gap consigné à l'orchestrateur).
- **Impression / imprimantes** : aucune mention (hors épic 53).
- **Composition des groupes, capacités et profils applicatifs, tiroir des droits** : au plus signalés d'une phrase et renvoyés, jamais documentés.
- Les fiches `/poste/fichiers/…` (52.4) sont **liées, jamais réécrites** ; aucune n'est modifiée.

### Scénario 10.8 — Lint, glossaire et autonomie réseau
**But** : les règles de rédaction et l'autonomie sont tenues.
**Étapes / attendu** :
- `node .vitepress/lint-doc.mjs` vert : aucun mot interdit (« story »/« épic »/codes d'exigence/IPv4), aucun container natif `warning`/`danger`, aucune balise `<img>` brute.
- Vocabulaire technique interdit absent (ACL, POSIX, UNC, SMB, setfacl, provisionner/provisioning, « ro/rw », pivot, assignable, chemins serveur, clés de permission `server.admin`/`networkshare.manage`…) ; seuls les libellés d'interface se citent tels quels (onglets, interrupteurs, « Lire »/« Modifier », « Parc (montage seul) », « Nouveau répertoire », « Créer depuis un template », « Resynchroniser », « Hérité (défaut) »/« Illimité »/« Actualiser »/« Modifier le quota »).
- Liens glossaire employés : `/glossaire#partage`, `#espace-personnel`, `#parc`, `#groupe-de-postes`, à première occurrence ; ancres existantes ; **jamais** `#capacite`.
- Aucune référence de capture posée dans ce domaine (fiches complètes sans image) → la sortie informative du lint ne cite aucune capture de `admin/fichiers/`.
- `grep -RInE "https?://(fonts|cdn|unpkg|cdnjs|jsdelivr|googleapis)" userDoc/.vitepress/dist/` → vide.

### Limites connues Section 10 (différé VM)
- **Contrôle visuel réel non couvert en ssh-only** : rendu des fiches et des encarts en thème clair/sombre et en viewport mobile non observé dans un navigateur — même dette d'environnement que les sections précédentes.
- **Matrice curl serveur non rejouée** : les codes 200/inchangés ci-dessus sont attendus depuis le build local autoritaire (lint + build VitePress strict verts), non vérifiés sur le serveur.
- **Vérification des libellés d'interface à l'écran** : les libellés cités sont confirmés depuis le code source (blades, enums, seeder, validator), pas observés dans une session navigateur — à recaler visuellement lors de la levée de la dette VM. Consigne mémoire respectée : aucun interrupteur de la politique n'a été basculé sur la VM pour « tester ».
- **Gap produit signalé (hors périmètre doc)** : les « Règles d'accès aux dossiers » (`/app/folder-rules`, module 36.4) sont livrées mais orphelines de navigation (atteignables par URL directe seule) ; non documentées tant qu'aucune entrée de menu n'existe.

### Checklist rapide Section 10
- [ ] 7 pages du domaine construites (6 fiches + index) ; page d'entrée = sommaire sans redite
- [ ] Sidebar `/admin/` = un groupe additif « Fichiers et partages » (6 entrées) ; commentaire de convention conservé ; groupes existants et `/poste/` inchangés
- [ ] Titre de la sous-section « Fichiers et partages » de `/admin/` devenu un lien ; chemin « Serveur → Réglages » et reste de la page inchangés
- [ ] Gabarit tenu : droits en métier, encarts normalisés seuls, `delai-effet` = `session` (politique/créer/accès) ou `immediat` (classe/limiter), aucun sur « en cas de problème »
- [ ] Politique = 3 interrupteurs GLOBAUX (jamais « par salle »), enregistrement immédiat, « web uniquement », mot « capacité » proscrit
- [ ] Nextcloud = une phrase (« pas encore activable »), jamais « disponible »
- [ ] Chaque énoncé d'accès porte niveau (Lire/Modifier) ET périmètre exact
- [ ] Lettre visible ≠ accès réel ; parc = montage seul ; avertissement d'écran cité verbatim
- [ ] Partage de classe : 4 sous-dossiers en lecture/écriture, dossier élève ≠ espace personnel, bascule échange, réappliquer = rattrapage
- [ ] Créer : direct (contraintes nom/lettre, réservées refusées) + 4 modèles ; seules K:/H: fixes, lettre auto peut changer → repérer par nom
- [ ] Limiter : quota compte + quota groupe (Hérité/Illimité/valeur), règle la plus favorable, vécu au dépassement, filtre « Quota dépassé » renvoyé ; rien sur défauts/grâce/corbeille
- [ ] « En cas de problème » : 2 symptômes, vérifications interface ordonnées, zéro commande
- [ ] Frontières : zéro quotas par défaut/grâce/corbeille, zéro règles d'accès aux dossiers, zéro impression, fiches 52.4 liées jamais réécrites
- [ ] Lint vert, vocabulaire interdit absent (ACL/POSIX/provisioning…), liens glossaire `#partage`/`#espace-personnel`/`#parc`/`#groupe-de-postes`, jamais `#capacite`, autonomie réseau vide
- [ ] **VM (différé)** : matrice curl serveur + contrôle visuel fiches clair/sombre/mobile + recalage des libellés

---

## Section 11 — Domaine admin « Droits et délégation » (Story 53.6)

**Portée** : domaine « Droits et délégation » du guide « J'administre SE5 », sous `userDoc/admin/droits/` — page d'entrée + **six** fiches (comprendre le modèle de droits / les profils types / composer un profil / attribuer des droits / déléguer sur une salle / en cas de problème). Sidebar `'/admin/'` enrichie d'un seul groupe (additif, après « Fichiers et partages ») ; lien de domaine posé sur `admin/index.md`. Rédaction adossée à la table de faits F1-F24, aucune écriture de code (rien hors `userDoc/` + ce runbook). **Point de vérité central** : cloisonnement honnête — seuls deux droits ont un effet réel par salle.

**Code / sources de référence** :
- `userDoc/admin/droits/{index,comprendre-le-modele-de-droits,profils-types,composer-un-profil,attribuer-des-droits,deleguer-sur-une-salle,en-cas-de-probleme}.md` — les 7 nouvelles pages
- `userDoc/.vitepress/config.mjs` — groupe sidebar `'/admin/'` « Droits et délégation » (additif, sous « Fichiers et partages »)
- `userDoc/admin/index.md` — sous-section « Droits et délégation » dont le titre devient un lien vers `/admin/droits/` (le chemin « menu Pilotage, entrée Gestion des droits » déjà publié est laissé tel quel)
- Libellés confirmés depuis le code : `app/Enums/SambaRole.php` (les 9 profils : Élève, Professeur, Admin élèves, Admin partages, Admin utilisateurs, Technicien, Référent numérique, Admin machines, Super administrateur ; leurs `permissions()`), `app/Enums/SambaPermission.php` (libellés français des droits : « Voir les machines », « Contrôle à distance », « Admin de poste », « Installer un poste », « Bureau à distance (RDP) », « Affecter des applications », « Ajouter des applications », « Créer des recettes WPKG » ; `isDelegatable()` = catégories `computer` + `wpkg`)

### Scénario 11.1 — Les 7 pages du domaine existent et se rendent
**But** : le domaine est publié sous `/doc/admin/droits/`.
**Étapes / attendu** :
- Le build produit `admin/droits/{index,comprendre-le-modele-de-droits,profils-types,composer-un-profil,attribuer-des-droits,deleguer-sur-une-salle,en-cas-de-probleme}.html`.
- **Matrice curl (VM)** : les 7 pages en 200 ; `/doc/admin/` en 200 ; `/doc/`, `/doc/poste/`, `/doc/glossaire.html` inchangés.
- La page d'entrée `index.md` liste les six fiches et ne redéveloppe pas leur contenu.

### Scénario 11.2 — Sidebar `/admin/` additive et sans lien mort ; `/poste/` intouchée
**But** : le seul ajout à la navigation admin est le groupe du domaine, pointant vers des pages existantes.
**Étapes / attendu** :
- La sidebar `/doc/admin/` porte, après « Fichiers et partages », le groupe « Droits et délégation » avec ses 6 entrées + le lien de tête vers `/admin/droits/`.
- Chaque entrée pointe vers une page réellement construite (build strict VitePress vert = preuve d'absence de lien mort, y compris les renvois `/admin/utilisateurs/`, `/admin/parc/`, `/admin/applications/`).
- La sidebar `/doc/poste/` est bit à bit inchangée ; les groupes « Utilisateurs et groupes », « Parc et postes », « Applications et personnalisation » et « Fichiers et partages » ne sont ni réordonnés ni modifiés ; le commentaire de convention additive est conservé.

### Scénario 11.3 — Lien de domaine sur la page d'orientation
**But** : la mention texte du domaine devient un lien, sans autre retouche.
**Étapes / attendu** :
- Sur `/doc/admin/`, le titre de la sous-section « Droits et délégation » est un lien vers `/admin/droits/`.
- Le chemin « menu Pilotage, entrée Gestion des droits » et le reste de la page (autres sous-sections, schéma besoin→domaine, « Comment lire une fiche ») sont inchangés.

### Scénario 11.4 — Gabarit, encarts et valeurs `delai-effet`
**But** : chaque fiche respecte le gabarit et n'emploie que les encarts normalisés.
**Étapes / attendu** :
- Chaque fiche d'action porte : titre = la tâche, phrase d'intention, « Où ça se passe », gestes ou explication, résultat observable.
- Encarts `droit-requis` en langage métier (« le droit d'attribuer des droits ») sur : composer un profil, attribuer des droits, déléguer sur une salle. Absents de : comprendre le modèle, profils types, en cas de problème.
- Valeurs `delai-effet` : **`immediat`** uniquement, une fois par fiche sur comprendre le modèle / composer / attribuer / déléguer ; formulation « dès le prochain chargement de page de la personne concernée » — l'effet est dans l'interface d'administration, jamais côté session Windows (F24). **AUCUN** `delai-effet` sur profils types ni sur « en cas de problème ».
- **AUCUN encart `vue-poste` dans tout le domaine** (F24) : `grep -rn "vue-poste" userDoc/admin/droits/` doit être vide.
- Encart `attention` : profils types (initiaux verrouillés), composer (suppression irréversible), attribuer (compte protégé irrétractable), déléguer (2 encarts : exclusion prime sur tout ; autres droits restant globaux). Aucun sur comprendre / en cas de problème.

### Scénario 11.5 — Cloisonnement honnête (LE verdict central)
**But** : la doc ne promet pas de cloisonnement plus fin que la réalité.
**Étapes / attendu** :
- La fiche « déléguer sur une salle » ne prête un effet limité à la salle qu'à **deux** droits : « **Voir les machines** » (parc restreint aux salles déléguées, regroupements logiques exclus) et « **Affecter des applications** » (affectation/retrait sur la salle et ses postes). L'exclusion joue sur ces deux mêmes points et prive même un droit d'établissement.
- Les **six autres** droits proposés par la fenêtre (contrôle à distance, admin de poste, installer un poste, bureau à distance, ajouter des applications, créer des recettes) sont énoncés comme **restant au niveau de l'établissement** : « déléguer un tel droit sur une salle ne le restreint pas à cette salle ». **Aucune** promesse d'effet, **aucun** « à venir ».
- **Aucune mention** du badge « GPO », d'écriture de stratégie, ni de mécanique interne (F19).
- Grep de contrôle : `grep -rniE "GPO|stratégie|Spatie|Gate|Policy|LDAP" userDoc/admin/droits/` doit être vide.

### Scénario 11.6 — Exactitude métier : verdicts sensibles
**But** : les formulations de vérité sont fidèlement rendues.
**Étapes / attendu** :
- **Assignation d'un profil = AJOUT** : la fiche « attribuer » décrit « assigner » (ajoute aux profils existants) et « retirer » comme deux gestes distincts ; **jamais** « le rôle remplace les rôles existants » (texte de volet inexact, F11).
- **Droit reçu par un profil = non retirable individuellement** : énoncé (« retirez le profil qui le porte »).
- **Compte protégé irrétractable** : formulé « ses droits d'administration ne peuvent pas lui être retirés », ignoré au retrait avec le message de l'écran ; pas de sur-généralisation en « il peut tout ».
- **Profils initiaux verrouillés** : ni renommables, ni modifiables, ni supprimables ; message « Rôle initial — permissions gérées par le système » cité ; édition décrite pour les profils personnalisés seulement.
- **Portée de classe** (Professeur, Admin élèves) : présentée comme propriété du profil décidée côté serveur, **pas** comme une délégation.
- **Règle de priorité** énoncée sans ambiguïté : exclusion active > droit d'établissement > délégation ; échéance éteint.
- **Catégorie de compte n'ouvre aucun droit** (F14) : une phrase sobre dans « comprendre le modèle ».
- **Tableau des 9 profils** : ne liste que des capacités attestées (F4) ; les droits de F19 n'y apparaissent pas comme des capacités opérantes.

### Scénario 11.7 — « En cas de problème » : symptômes → vérifications interface, zéro commande
**But** : la page d'aide reste dans l'interface, sans manipulation serveur.
**Étapes / attendu** :
- Deux symptômes couverts : « une personne ne voit pas une page attendue » (Gestion des droits visible mais « accès refusé » sans le droit ; entrée de menu absente = droit non confié ; délégué ne voit que ses salles, pas les regroupements logiques → onglet « Droits d'un utilisateur ») ; « une action refusée malgré un profil attribué » (exclusion active sur la salle ; délégation échue ; portée de classe d'un professeur ; droit restant au niveau de l'établissement ; journal Historique pour retracer).
- Chaque piste se termine sur un endroit exact de l'interface. Aucune commande shell, aucun chemin serveur.

### Scénario 11.8 — Lint, glossaire et autonomie réseau
**But** : les règles de rédaction et l'autonomie sont tenues.
**Étapes / attendu** :
- `node .vitepress/lint-doc.mjs` vert : aucun mot interdit (« story »/« épic »/codes d'exigence/IPv4), aucun container natif `warning`/`danger`, aucune balise `<img>` brute. **Vérifié en LOCAL 2026-07-27** (lint OK, 51 fichiers ; build VitePress strict vert).
- Vocabulaire technique interdit absent (Spatie, Gate, Policy, LDAP, AD, GPO, bitmask, SQL/PostgreSQL, clés `user.assign.right`/`computer.view`/`wpkg.assign`/`app.customize`, noms techniques de profils `super-admin`/`referent-numerique`…) ; seuls les libellés d'interface se citent tels quels (onglets « Profils »/« Droits d'un utilisateur »/« Délégations actives »/« Historique », boutons « Nouveau profil »/« Gérer les droits »/« Déléguer un droit sur une salle »/« Lever l'exclusion », libellés de droits et de profils).
- « Droit », « profil de droits », « délégation », « exclusion » restent en langage courant **sans lien** glossaire. Seul lien glossaire employé : `/glossaire#groupe-de-postes` (pour « salle »), à première occurrence, ancre existante.
- Aucune référence de capture posée dans ce domaine (fiches complètes sans image) → la sortie informative du lint ne cite aucune capture de `admin/droits/`.
- `grep -RInE "https?://(fonts|cdn|unpkg|cdnjs|jsdelivr|googleapis)" userDoc/.vitepress/dist/` → vide.

### Scénario 11.9 — Sujets écartés de l'épic : silence
**But** : la doc ne présente aucun sujet hors épic comme disponible.
**Étapes / attendu** :
- **Aucune mention** du login fédéré, du technicien externe de la collectivité, du portail d'utilisateurs externes, du badge « Externe ».
- Grep de contrôle : `grep -rniE "fédér|externe|technicien de la|portail" userDoc/admin/droits/` — les seules occurrences admises portent sur « au niveau de l'établissement » et non sur ces sujets.

### Limites connues Section 11 (différé VM)
- **Contrôle visuel réel non couvert en ssh-only** : rendu des fiches et des encarts en thème clair/sombre et en viewport mobile non observé dans un navigateur — même dette d'environnement que les sections précédentes.
- **Matrice curl serveur non rejouée** : les codes 200/inchangés ci-dessus sont attendus depuis le build local autoritaire (lint + build VitePress strict verts), non vérifiés sur le serveur.
- **Vérification des libellés d'interface à l'écran** : les libellés cités sont confirmés depuis le code source (enums `SambaRole`/`SambaPermission`, table de faits fichier:ligne), pas observés dans une session navigateur — à recaler visuellement lors de la levée de la dette VM.

### Checklist rapide Section 11
- [ ] 7 pages du domaine construites (6 fiches + index) ; page d'entrée = sommaire sans redite
- [ ] Sidebar `/admin/` = un groupe additif « Droits et délégation » (6 entrées) ; commentaire de convention conservé ; groupes existants et `/poste/` inchangés
- [ ] Titre de la sous-section « Droits et délégation » de `/admin/` devenu un lien ; chemin « Pilotage → Gestion des droits » et reste de la page inchangés
- [ ] Gabarit tenu : droits en métier, encarts normalisés seuls, `delai-effet immediat` sur 4 fiches, aucun sur profils types / en cas de problème, AUCUN `vue-poste` du domaine
- [ ] Cloisonnement honnête : SEULS « Voir les machines » + « Affecter des applications » à effet par salle ; six autres droits énoncés comme globaux, aucune promesse
- [ ] Aucun badge GPO/stratégie/mécanique interne ; grep GPO/Spatie/Gate/Policy/LDAP vide sur le domaine
- [ ] Assignation = AJOUT (jamais « remplace ») ; droit via profil non retirable individuellement
- [ ] Compte protégé irrétractable (formulation exacte) ; profils initiaux verrouillés (message cité) ; édition = personnalisés seuls
- [ ] Portée de classe = propriété de profil, pas délégation ; règle de priorité exclusion > établissement > délégation ; catégorie de compte n'ouvre rien
- [ ] Tableau des 9 profils = capacités attestées seules (F4) ; droits de F19 jamais présentés comme opérants
- [ ] « En cas de problème » : 2 symptômes, vérifications interface, zéro commande
- [ ] Sujets fédérés/technicien externe/portail externe : silence total
- [ ] Lint vert, vocabulaire technique absent, seul lien glossaire `#groupe-de-postes`, « droit »/« délégation »/« exclusion » sans lien, autonomie réseau vide
- [ ] **VM (différé)** : matrice curl serveur + contrôle visuel fiches clair/sombre/mobile + recalage des libellés

## Section 12 — Domaine admin « Installer et déployer un poste » (Story 53.7)

**Portée** : domaine « Installer et déployer un poste » du guide « J'administre SE5 », sous `userDoc/admin/installer/` — page d'entrée + **six** fiches (prérequis / préparer les systèmes / installer un poste neuf / réinstaller un poste / vérifier la mise en service / en cas de problème). Sidebar `'/admin/'` enrichie d'un seul groupe (additif, après « Droits et délégation ») ; lien de domaine posé sur `admin/index.md`. Rédaction adossée à la table de faits F1-F21, aucune écriture de code (rien hors `userDoc/` + ce runbook). **Point de vérité central** : partition à trois plans — gestes **dans l'interface web**, gestes **à l'écran du poste** (menus de démarrage rendus par le serveur), et **prérequis d'exploitation** énoncés sans procédure ; la **déclaration** d'un poste neuf ne se fait PAS dans l'interface, l'ordre **déclarer puis installer** est imposé, la **réinstallation** se pilote entièrement depuis l'interface et **efface le disque**.

**Code / sources de référence** :
- `userDoc/admin/installer/{index,prerequis,preparer-les-systemes,installer-un-poste-neuf,reinstaller-un-poste,verifier-la-mise-en-service,en-cas-de-probleme}.md` — les 7 nouvelles pages
- `userDoc/.vitepress/config.mjs` — groupe sidebar `'/admin/'` « Installer et déployer un poste » (additif, après « Droits et délégation »)
- `userDoc/admin/index.md` — sous-section « Installation et déploiement d'un poste » dont le titre devient un lien vers `/admin/installer/` (chemin « menu Serveur, entrée Réglages » déjà publié laissé tel quel)
- Libellés confirmés depuis le code : `resources/views/ipxe/menu/{default,admin,known,installation-windows}.blade.php` et `resources/views/ipxe/enrollment/name.blade.php` (écrans du poste, libellés sans accents : « Acces au menu d'administration », « Nommer le poste (enregistrement) », « Affecter a une salle physique », « Installation Windows (Win10/Win11) », « Installation Linux (Debian/Ubuntu) », « OK ! nom … reserve », « ERREUR ! nom … indisponible », « ATTENTION : sync AD echouee - verifiez avec admin SE5 ») ; `resources/views/pages/parc/_partials/reinstall-modal.blade.php` (fenêtre : « Maintenant »/« Planifier », « Le poste sera forcé à redémarrer au prochain tick (≤ 60 s)… », « Cette opération EFFACE le disque et réinstalle l'OS choisi. Irréversible. ») ; `app/Models/WorkstationReinstallRequest.php` (six libellés de suivi) ; `resources/views/pages/admin/settings/os/index.blade.php`, `.../ipxe/iso-windows/index.blade.php`, `.../network/dhcp/_partials/service-status-banner.blade.php`, `app/Doctor/Checks/Ipxe/IpxeConfigCheck.php` (pages de préparation et contrôles)

### Scénario 12.1 — Les 7 pages du domaine existent et se rendent
**But** : le domaine est publié sous `/doc/admin/installer/`.
**Étapes / attendu** :
- Le build produit `admin/installer/{index,prerequis,preparer-les-systemes,installer-un-poste-neuf,reinstaller-un-poste,verifier-la-mise-en-service,en-cas-de-probleme}.html`.
- **Matrice curl (VM)** : les 7 pages en 200 ; `/doc/admin/` en 200 ; `/doc/`, `/doc/poste/`, `/doc/glossaire.html` inchangés.
- La page d'entrée `index.md` déroule le parcours en **liste ordonnée** de 5 étapes (chacune avec sa condition de réussite observable et son lien), annonce le double plan interface-web / écran-du-poste, et renvoie la réinstallation vers sa fiche. **Aucun bloc mermaid** (décision : liste ordonnée).

### Scénario 12.2 — Sidebar `/admin/` additive et sans lien mort ; `/poste/` intouchée
**But** : le seul ajout à la navigation admin est le groupe du domaine, pointant vers des pages existantes.
**Étapes / attendu** :
- La sidebar `/doc/admin/` porte, après « Droits et délégation », le groupe « Installer et déployer un poste » avec ses 6 entrées + le lien de tête vers `/admin/installer/`.
- Chaque entrée pointe vers une page réellement construite (build strict VitePress vert = preuve d'absence de lien mort, y compris les renvois internes vers `/admin/parc/lire-l-etat-d-un-poste` et `/admin/parc/`).
- La sidebar `/doc/poste/` est bit à bit inchangée ; les cinq groupes admin existants (Utilisateurs et groupes, Parc et postes, Applications et personnalisation, Fichiers et partages, Droits et délégation) ne sont ni réordonnés ni modifiés ; le commentaire de convention additive est conservé.

### Scénario 12.3 — Lien de domaine sur la page d'orientation
**But** : la mention texte du domaine devient un lien, sans autre retouche.
**Étapes / attendu** :
- Sur `/doc/admin/`, le titre de la sous-section « Installation et déploiement d'un poste » est un lien vers `/admin/installer/`.
- Le chemin « menu Serveur, entrée Réglages », le paragraphe de la sous-section et le reste de la page (autres sous-sections, schéma besoin→domaine, « Comment lire une fiche ») sont inchangés.

### Scénario 12.4 — Partition à trois plans (LE verdict central)
**But** : la doc distingue sans ambiguïté ce qui se fait dans l'interface, à l'écran du poste, et ce qui relève de l'exploitation.
**Étapes / attendu** :
- **Écran du poste** : la fiche « installer un poste neuf » dit explicitement que ses gestes se font « à l'écran du poste lui-même », **pas** dans l'interface web ; elle affirme qu'il n'existe **aucun** bouton « Nouvelle machine ». Aucune procédure de déclaration « dans l'interface » n'est inventée.
- **Ordre imposé** : la fiche énonce « on déclare d'abord, on installe ensuite » et que le menu **refuse un poste non enregistré**.
- **Interface web** : préparer les systèmes, réinstaller, vérifier la mise en service sont décrits en gestes numérotés situés dans l'interface.
- **Exploitation** : l'orientation du démarrage par le réseau vers le serveur est énoncée comme prérequis « mis en place à l'installation du serveur », renvoyée à « la personne qui exploite le serveur », **sans aucune commande ni chemin serveur**.

### Scénario 12.5 — Réinstallation : sécurité, protégés, suivi
**But** : la fiche la plus dangereuse rend fidèlement les garde-fous.
**Étapes / attendu** :
- **Trois portes** : fiche du poste (menu Actions → section « Système » → « Réinstaller le poste »), salle/groupe (« Réinstaller la salle »), sélection multiple (« Réinstaller la sélection »).
- **Double sécurité** citée : avertissement « Cette opération EFFACE le disque et réinstalle l'OS choisi. Irréversible. » + confirmation chiffrée « Vous allez EFFACER N poste(s) et réinstaller … Irréversible. ».
- **Postes protégés jamais réinstallés** : badge « Protégé », « Poste protégé — non réinstallable », « Poste protégé — réinstallation impossible », refus serveur, annulation d'office si le poste devient protégé.
- **Ignorés + compte rendu** : « N poste(s) armé(s), N déjà en cours, N protégé(s) ignoré(s). » ; « Ce poste a déjà une réinstallation en cours. ».
- **Six libellés de suivi mot pour mot** : « Réinstallation programmée », « Réinstallation démarrée », « Installation en cours », « Réinstallation terminée », « Réinstallation échouée », « Réinstallation annulée » ; panneau « Réinstallations en cours (N) » (colonnes Poste / OS / État / Planifiée + Annuler).
- **Annuler / relancer** : annulation possible tant que l'installation n'a pas commencé, disparaît à « Installation en cours » ; « Relancer la réinstallation » (« La tentative en cours sera abandonnée et le poste redémarrera pour repartir de zéro. ») ; échec automatique par garde-fou de délai.
- **Encarts** : `droit-requis`, `attention` (efface le disque, irréversible), `delai-effet agent` (poste joignable ou réveillable ; réveil réseau non garanti sur tout segment), `vue-poste` (redémarrage + installation sans intervention, session perdue).

### Scénario 12.6 — Deux droits, formulation honnête du droit global
**But** : les habilitations sont distinguées et « Installer un poste » n'est pas présenté comme délégable par salle.
**Étapes / attendu** :
- La fiche « prérequis » porte un encart `droit-requis` **double** : « Installer un poste » (déclarer/installer/réinstaller) **et** administration du serveur (préparer/contrôler).
- « Installer un poste » est formulé comme un droit **à l'échelle de l'établissement**, « une délégation limitée à une seule salle ne l'ouvre pas » — répété sur les fiches « installer un poste neuf » et « réinstaller un poste ».
- La préparation (« OS installables », « Gestion ISO Windows », « Réseau DHCP », « État du système ») est rattachée au droit d'**administration du serveur** ; le menu **Réglages** n'apparaît qu'à qui le détient.

### Scénario 12.7 — Retenue sur les sujets écartés
**But** : rien hors périmètre de l'épic n'est présenté comme disponible.
**Étapes / attendu** :
- **INSTALLATION** Linux documentée (préparation, menu, réinstallation) ; **GESTION** du poste Linux installé, environnement Windows par défaut, dépannage à distance : **silence** — aucune promesse au-delà de « le poste est installé et joint à l'établissement ».
- **Outils de maintenance** du menu du poste, écran d'enregistrement invité, shell de démarrage : **non documentés** (`grep -rniE "maintenance|invité|byod|shell" userDoc/admin/installer/` — aucune procédure).
- Le mot « annuaire » est employé en langage courant, sans lien glossaire ; le jargon (« iPXE », « DHCP », « sync AD », « tick ») n'apparaît **qu'entre guillemets**, en citation d'un libellé d'écran.

### Scénario 12.8 — « En cas de problème » : symptômes → vérifications interface, zéro commande
**But** : la page d'aide reste dans l'interface, renvoie à l'exploitation quand ça la dépasse.
**Étapes / attendu** :
- Trois symptômes couverts : **poste qui ne démarre pas sur le réseau** (bannière « Service DHCP injoignable… » ; contrôle « iPXE » d'« État du système » ; ordre de démarrage matériel du poste ; orientation serveur → exploitation) ; **installation qui s'arrête** (badge « Réinstallation échouée » → « Relancer la réinstallation » ; carte réseau → pilotes réseau de « Gestion ISO Windows » ; source absente/en échec → « OS installables » ; poste protégé) ; **poste installé mais pas rattaché** (message « sync AD echouee » vu à la déclaration ; contrôle des informations de rattachement dans « État du système » ; identifiant technique absent sous le nom dans « Gestion du parc » ; renvoi exploitation en dernier ressort).
- **Aucune commande shell, aucun chemin serveur** dans toute la fiche.

### Scénario 12.9 — Lint, glossaire, captures et autonomie réseau
**But** : les règles de rédaction et l'autonomie sont tenues.
**Étapes / attendu** :
- `node .vitepress/lint-doc.mjs` vert : aucun mot interdit (« story »/« épic »/codes d'exigence/IPv4), aucun container natif `warning`/`danger`, aucune balise `<img>` brute. **Vérifié en LOCAL 2026-07-27** (lint OK, 58 fichiers ; build VitePress strict vert).
- Seul lien glossaire employé : `/glossaire#parc` (fiche « installer un poste neuf », première occurrence de « parcs »), ancre existante. « annuaire », « domaine », « salle » restent en langage courant **sans lien**.
- **Trois références de capture** posées (production séparée), toutes sous `/captures/admin/installer/…` en `.png` kebab-case avec alt non vide : `preparer-les-systemes/os-installables.png`, `reinstaller-un-poste/fenetre-de-reinstallation.png`, `verifier-la-mise-en-service/ligne-de-poste.png` — listées par la sortie informative du lint (fichiers absents = placeholder « Illustration à venir »). Les écrans du poste ne sont **pas** référencés en capture.
- `grep -RInE "https?://(fonts|cdn|unpkg|cdnjs|jsdelivr|googleapis)" userDoc/.vitepress/dist/` → vide.

### Limites connues Section 12 (différé VM)
- **Contrôle visuel réel non couvert en ssh-only** : rendu des fiches et des encarts en thème clair/sombre et en viewport mobile non observé dans un navigateur — même dette d'environnement que les sections précédentes.
- **Matrice curl serveur non rejouée** : les codes 200/inchangés ci-dessus sont attendus depuis le build local autoritaire (lint + build VitePress strict verts), non vérifiés sur le serveur.
- **Vérification des libellés à l'écran** : les libellés d'interface web sont confirmés depuis le code source ; les libellés des écrans du poste sont attestés par les gabarits `resources/views/ipxe/**` (un poste de test en démarrage réseau n'est pas exigible pour cette story de rédaction). À recaler visuellement lors de la levée de la dette VM.

### Checklist rapide Section 12
- [ ] 7 pages du domaine construites (6 fiches + index) ; page d'entrée = parcours en liste ordonnée (5 étapes + condition de réussite + lien), pas de mermaid
- [ ] Sidebar `/admin/` = un groupe additif « Installer et déployer un poste » (6 entrées) ; commentaire de convention conservé ; cinq groupes existants et `/poste/` inchangés
- [ ] Titre de la sous-section « Installation et déploiement d'un poste » de `/admin/` devenu un lien ; chemin « Serveur → Réglages » et reste de la page inchangés
- [ ] Partition trois plans tenue : déclaration à l'écran du poste (pas d'interface), ordre déclarer-puis-installer imposé, exploitation en prérequis sans procédure
- [ ] Réinstallation : 3 portes, double sécurité citée, protégés inviolables, ignorés + compte rendu, six libellés de suivi exacts, annuler/relancer/échec auto
- [ ] Deux droits distingués ; « Installer un poste » global honnête (non délégable par salle) ; préparation = admin serveur
- [ ] Sujets écartés (Linux géré, Windows par défaut, dépannage à distance, maintenance, invité, shell) : silence ; jargon uniquement en citation d'écran
- [ ] « En cas de problème » : 3 symptômes, vérifications interface, renvoi exploitation explicite, zéro commande
- [ ] Lint vert ; seul lien glossaire `#parc` ; 3 références de capture posées (alt non vide, kebab-case, .png) ; autonomie réseau vide
- [ ] **VM (différé)** : matrice curl serveur + contrôle visuel fiches clair/sombre/mobile + recalage des libellés

---

## Section 13 — Domaine admin « Réglages et supervision » (Story 53.8)

**Portée** : domaine « Réglages et supervision » du guide « J'administre SE5 », sous `userDoc/admin/reglages/` — page d'entrée + **sept** fiches (les réglages de l'établissement / capacités et portées / les adresses réseau des postes / le tableau de bord / un poste en règle ou en retard / l'état du système / en cas de problème). Sidebar `'/admin/'` enrichie d'un seul groupe (additif, en **dernière** position, après « Installer et déployer un poste ») ; lien de domaine posé sur `admin/index.md`. Rédaction adossée à la table de faits F1-F24, aucune écriture de code (rien hors `userDoc/` + ce runbook). **Points de vérité centraux** : la tuile « Active Directory » du tableau de bord est un **texte constant** (jamais un voyant qui passerait au rouge — renvoi vers « État du système ») ; il **n'existe pas de réglage de capacité par poste** (maille la plus fine = le groupe ; le plus spécifique gagne, le parc logique bat la salle) ; « Muet » = **probablement éteint, pas une panne** ; « En écart » = **auto-résolu** (la cible fait loi) ; « En retard » = **version rapportée au contact, pas un téléchargement ni une panne** ; la page Réglages est un **hall** dont plus de la moitié des cartes est signalée sans être documentée.

**Code / sources de référence** :
- `userDoc/admin/reglages/{index,reglages-de-l-etablissement,capacites-et-portees,reseau-dhcp,tableau-de-bord,poste-en-regle-ou-en-retard,etat-du-systeme,en-cas-de-probleme}.md` — les 8 nouvelles pages
- `userDoc/.vitepress/config.mjs` — groupe sidebar `'/admin/'` « Réglages et supervision » (additif, dernière position)
- `userDoc/admin/index.md` — sous-section « Réglages et supervision » dont le titre devient un lien vers `/admin/reglages/`
- Libellés confirmés depuis le code (table F1-F24) : `resources/views/pages/dashboard/index.blade.php` (tuiles « PostgreSQL », « MariaDB (legacy) », « Espace Disque » seuils 75 %/90 %, « Queue Workers », « Redémarrer les workers », « Actualiser » ; tuile « Active Directory » en texte statique) ; `.../dashboard/activity/index.blade.php` (page Activité, statut OK/Échec) ; `.../admin/settings/index.blade.php` (4 sections de cartes) ; `.../admin/settings/security/index.blade.php` (« Sécurité & session », délai 5-1440) ; `.../admin/settings/parc-defaults/_partials/registry-tab.blade.php` (« Défaut », « Éditer le défaut », « Gelé »/« Ouvert », badges amont) ; `.../parc/groups/_partials/capabilities-tab.blade.php` (« Valeur (parc) », « Retirer » = revenir au défaut) ; `app/Models/Capability.php` (badges de temporalité) ; `.../admin/settings/system-status/index.blade.php` (5 blocs, badges OK/Attention/Erreur, onglet « Logs ») ; `.../network/dhcp/index.blade.php` + `.../_partials/service-status-banner.blade.php` (3 onglets, « Service DHCP injoignable ») ; `app/Enums/AgentResourceStatus.php` + `.../conformity-badge.blade.php` (5 libellés de conformité) ; `.../admin/settings/agent/_partials/deployment-progress.blade.php` (« À jour »/« En retard »/« Jamais vus »)

### Scénario 13.1 — Les 8 pages du domaine existent et se rendent
**But** : le domaine est publié sous `/doc/admin/reglages/`.
**Étapes / attendu** :
- Le build produit `admin/reglages/{index,reglages-de-l-etablissement,capacites-et-portees,reseau-dhcp,tableau-de-bord,poste-en-regle-ou-en-retard,etat-du-systeme,en-cas-de-probleme}.html`.
- **Matrice curl (VM)** : les 8 pages en 200 ; `/doc/admin/` en 200 avec le lien du domaine ; `/doc/`, `/doc/poste/`, `/doc/glossaire.html` inchangés.
- La page d'entrée `index.md` annonce les **deux portes** (Tableau de bord ouvert à tous les connectés / Réglages sous droit d'administration serveur) et le **principe de portée**, puis liste les 7 fiches. **Aucun bloc mermaid.**

### Scénario 13.2 — Sidebar `/admin/` additive et sans lien mort ; `/poste/` intouchée
**But** : le seul ajout à la navigation admin est le groupe du domaine, en dernière position.
**Étapes / attendu** :
- La sidebar `/doc/admin/` porte, **après** « Installer et déployer un poste », le groupe « Réglages et supervision » avec ses 7 entrées + le lien de tête vers `/admin/reglages/`.
- Chaque entrée pointe vers une page réellement construite (build strict VitePress vert = preuve d'absence de lien mort, y compris les renvois internes et vers `/admin/parc/lire-l-etat-d-un-poste`, `/admin/fichiers/politique-de-fichiers`, `/admin/installer/preparer-les-systemes`, `/admin/applications/fonds-d-ecran`, `/admin/applications/catalogue-et-depot`).
- La sidebar `/doc/poste/` est bit à bit inchangée ; les six groupes admin existants ne sont ni réordonnés ni modifiés ; le commentaire de convention additive est conservé.

### Scénario 13.3 — Lien de domaine sur la page d'orientation
**But** : la mention texte du domaine devient un lien, sans autre retouche.
**Étapes / attendu** :
- Sur `/doc/admin/`, le titre de la sous-section « Réglages et supervision » est un lien vers `/admin/reglages/`.
- Le chemin « menu Pilotage → Tableau de bord, et menu Serveur → Réglages », le paragraphe et le reste de la page (autres sous-sections, schéma besoin→domaine, « Comment lire une fiche ») sont inchangés.

### Scénario 13.4 — La portée de chaque réglage (verdict central « pas de portée poste »)
**But** : chaque réglage porte sa portée, et l'absence de portée poste est énoncée honnêtement.
**Étapes / attendu** :
- « Sécurité & session » (`reglages-de-l-etablissement.md`) et le défaut de capacité (`capacites-et-portees.md`) portent « tout l'établissement » ; « Réseau DHCP » (`reseau-dhcp.md`) porte « tout l'établissement ».
- L'écart de capacité (`capacites-et-portees.md`) porte « ce groupe de postes » et **prime** sur le défaut ; la **précédence** est formulée en langage courant (« le plus précis l'emporte », « le parc logique l'emporte » sur la salle) **sans échelle ni rang technique**.
- La question « portée poste » reçoit une **réponse par la négative** : section « Y a-t-il un réglage par poste ? » → non, la maille la plus fine est le groupe ; contournement = placer le poste dans son propre groupe. **Aucune portée poste inventée.**
- Contrat amont : « Verrouillé » = imposé, non modifiable ; « Modifiable » = le réglage local prévaut — deux phrases, badges présents seulement en cas de rattachement.

### Scénario 13.5 — Tableau de bord : la tuile « Active Directory » n'est PAS un voyant
**But** : ne pas présenter comme un indicateur ce qui est un texte constant.
**Étapes / attendu** :
- `tableau-de-bord.md` décrit la tuile « Active Directory » comme affichant l'annuaire « Connecté » et précise qu'elle **n'est pas un voyant** qui changerait de couleur ; elle **oriente vers « État du système »** pour une vraie vérification. **Sans** le mot « statique », **sans** dénigrer le produit.
- Les tuiles réelles sont rendues avec leurs seuils : « Espace Disque » orange dès 75 % / rouge dès 90 % ; « Queue Workers » rouge à 0 + action « Redémarrer les workers » ; « MariaDB (legacy) » dont « Non configuré » est annoncé **normal** sur un serveur neuf.
- Aucun encart `droit-requis` sur cette fiche (page ouverte à toute personne connectée). Pour chaque tuile au rouge, une ligne « quoi faire » renvoie vers « En cas de problème ».

### Scénario 13.6 — Indicateurs : quoi faire ET quoi ne pas conclure
**But** : chaque état rouge est actionnable sans induire de fausse alerte ni de fausse tranquillité.
**Étapes / attendu** :
- Les **cinq libellés** sont repris mot pour mot (Conforme / En écart / Erreur / Muet / Jamais rapporté) avec lien vers `/admin/parc/lire-l-etat-d-un-poste`.
- **Muet** = « probablement éteint, sans certitude » → vérifier d'abord allumage/réseau (pas « en panne »). **En écart** = la cible fait loi, réapplication automatique → regarder « Depuis » avant d'agir. **Erreur** = détail + « Forcer la synchro » + relire avant d'escalader. **Jamais rapporté** = normal (pas encore allumé). **En retard** = version rapportée au contact serveur, pas un téléchargement → pas une panne, aucun délai de rattrapage promis.
- « Forcer la synchro » : droit « Contrôle à distance », effet au prochain contact (`delai-effet agent`), rafraîchissement automatique de l'affichage, poste en quarantaine non forçable.
- Panneau « Conformité agent » d'un groupe : compteurs + **seules exceptions** listées et cliquables (postes conformes jamais listés).

### Scénario 13.7 — Triage de la page Réglages et exclusions F24
**But** : la moitié « hall d'exploitation » est signalée sans être documentée, sans fuite d'interdits.
**Étapes / attendu** :
- `reglages-de-l-etablissement.md` trie les cartes en trois catégories : documenté ici (« État du système », « Réseau DHCP », « Sécurité & session », volet capacités) ; documenté ailleurs (liens Fichiers/Installation/Applications) ; relève de l'exploitation (une phrase générique, **sans procédure ni lien**).
- Les **profils applicatifs itinérants ne sont pas nommés** (direction non figée) ; le **compte technique de déploiement n'est pas nommé** ; la reprise depuis SE4 et la liaison à un pilotage central sont mentionnées d'une phrase.
- L'onglet « Logs » (`etat-du-systeme.md`) et le pilotage des versions / enrôlements (`poste-en-regle-ou-en-retard.md`) sont signalés d'une phrase, sans procédure.

### Scénario 13.8 — « En cas de problème » : deux volets, zéro commande
**But** : distinguer nettement ce que le référent corrige lui-même de ce qui relève du serveur.
**Étapes / attendu** :
- Volet **référent** (gestes interface) : poste Muet → vérifier allumage ; En écart/Erreur → détail + « Depuis » + « Forcer la synchro » ; « Queue Workers » à 0 → « Redémarrer les workers » + « Actualiser » ; déconnexions → « Sécurité & session » ; poste sans adresse → « Baux actifs » + réservation.
- Volet **serveur** (énoncé, jamais détaillé, renvoi à qui exploite le serveur) : « Espace Disque » au rouge ; bloc « État du système » en « Erreur » persistant ; « Service DHCP injoignable » ; journaux « Logs » ; « Queue Workers » toujours à 0 après redémarrage.
- Chaque volet renvoie vers la fiche du domaine concernée. **Aucune commande shell, aucun chemin serveur** dans toute la fiche.

### Scénario 13.9 — Lint, glossaire, citations d'écran et autonomie réseau
**But** : les règles de rédaction et la discipline de citation sont tenues.
**Étapes / attendu** :
- `node .vitepress/lint-doc.mjs` vert : aucun mot interdit (« story »/« épic »/codes d'exigence/IPv4), aucun container natif `warning`/`danger`, aucune balise `<img>` brute. **Vérifié en LOCAL 2026-07-27** (« Lint éditorial : OK, 66 fichiers » ; build VitePress strict vert).
- Le **jargon** (« PostgreSQL », « MariaDB (legacy) », « Queue Workers », « Redémarrer les workers », « controlHub », « Apache », « iPXE », « Réseau DHCP », « Service DHCP injoignable ») n'apparaît **qu'entre guillemets**, en citation d'un libellé d'écran ; la prose emploie « la base de données », « l'annuaire », « le service d'adressage réseau », « les traitements en arrière-plan », « le serveur web », « le démarrage réseau ». `grep -rnoE "\bworkers\b" userDoc/admin/reglages/` → uniquement dans « Redémarrer les workers ».
- Liens glossaire employés (première occurrence) : `/glossaire#capacite`, `/glossaire#groupe-de-postes`, `/glossaire#parc`, `/glossaire#socle-commun`, `/glossaire#agent` — toutes ancres existantes. « annuaire », « salle », « session » restent en langage courant **sans lien**.
- **Aucune référence de capture** posée dans ce domaine (fiches complètes sans image ; production éventuelle en étape séparée).
- `grep -RInE "https?://(fonts|cdn|unpkg|cdnjs|jsdelivr|googleapis)" userDoc/.vitepress/dist/` → vide.

### Limites connues Section 13 (différé VM)
- **Contrôle visuel réel non couvert en ssh-only** : rendu des fiches et des encarts en thème clair/sombre et en viewport mobile non observé dans un navigateur — même dette d'environnement que les sections précédentes.
- **Matrice curl serveur non rejouée** : les codes 200/inchangés ci-dessus sont attendus depuis le build local autoritaire (lint + build VitePress strict verts), non vérifiés sur le serveur.
- **Vérification des libellés à l'écran** : les libellés d'interface sont confirmés depuis le code source (table F1-F24, fichier:ligne) ; à recaler visuellement (tuiles du tableau de bord, badges de conformité) lors de la levée de la dette VM, en **lecture seule** — ne jamais muter un endpoint d'écriture (réglages, réservations) pour vérifier un libellé.

### Checklist rapide Section 13
- [ ] 8 pages du domaine construites (7 fiches + index) ; page d'entrée = deux portes + principe de portée, pas de mermaid
- [ ] Sidebar `/admin/` = un groupe additif « Réglages et supervision » en **dernière** position (7 entrées) ; commentaire de convention conservé ; six groupes existants et `/poste/` inchangés
- [ ] Titre de la sous-section « Réglages et supervision » de `/admin/` devenu un lien ; chemin et reste de la page inchangés
- [ ] Portée énoncée par réglage ; **pas de portée poste** dite par la négative ; précédence sans jargon (plus précis gagne, parc logique bat salle) ; contrat amont Verrouillé/Modifiable
- [ ] Tuile « Active Directory » jamais présentée comme un voyant → renvoi « État du système » ; seuils disque 75/90 % ; « Non configuré » normal
- [ ] Chaque indicateur rouge : quoi faire + quoi ne pas conclure (Muet ≠ panne, En écart auto-résolu, En retard ≠ panne) ; 5 libellés mot pour mot + lien parc
- [ ] Triage page Réglages : documenté/renvoyé/exploitation ; profils itinérants et compte de service **non nommés** ; Logs/enrôlements signalés une phrase
- [ ] « En cas de problème » : deux volets référent/serveur, renvois, **zéro commande**
- [ ] Lint vert ; jargon uniquement en citation d'écran ; liens glossaire valides ; aucune capture ; autonomie réseau vide
- [ ] **VM (différé)** : matrice curl serveur + contrôle visuel fiches clair/sombre/mobile + recalage des libellés en lecture seule

## Section 14 — Correspondance SE4 → SE5 : « Je viens de l'ancienne interface » (Story 53.9)

**Portée** : DERNIÈRE page de l'épic documentation utilisateur — une page unique de renvoi, `userDoc/admin/depuis-se4/index.md`, qui associe chaque tâche courante de l'ancienne interface (SE4) à son emplacement et son geste dans SE5, avec lien vers la fiche détaillée. Huit sections par domaine (comptes et groupes, droits d'administration, parcs et postes, applications et environnement des postes, fichiers/partages/quotas, imprimantes, serveur et supervision, modules sans équivalent). Sidebar `'/admin/'` enrichie d'un groupe additif final « Depuis SE4 » (après « Réglages et supervision ») ; `admin/index.md` reçoit un paragraphe additif avec lien. Rédaction adossée à la table de correspondances fermée et vérifiée des deux côtés du dev-lead (45 lignes), aucune écriture de code (rien hors `userDoc/` + ce runbook). **Points de vérité centraux** : la table est fermée (aucune équivalence inventée, aucun statut requalifié) ; les lignes « pas encore disponible » (C8, A9, S4, S5, M1-M3) sont énoncées sans date et sans lien ; la ligne imprimantes (I1) est un statut intermédiaire distinct — onglet **Imprimantes** de Gestion du parc réellement livré, mais sans fiche dans ce guide — jamais présentée comme « pas encore dans SE5 » ; les 9 notes de logique (C6, C9, C10, R1, R3, P7, A2, A8, F4) tiennent en une phrase côté administrateur, jamais un récit d'évolution produit.

**Code / sources de référence** :
- `userDoc/admin/depuis-se4/index.md` — la nouvelle page (8 sections, 45 lignes de correspondance)
- `userDoc/.vitepress/config.mjs` — groupe sidebar `'/admin/'` « Depuis SE4 » (additif, dernière position)
- `userDoc/admin/index.md` — paragraphe additif avec lien vers `/admin/depuis-se4/`, inséré après l'encart `droit-requis` qui clôt « Les domaines de l'administration »
- Libellé d'écran confirmé depuis le code : `resources/views/pages/parc/index.blade.php` l.656 (bouton « Ajouter une imprimante »), l.676 (onglet « Imprimantes »)
- Table de correspondances source : `_bmad-output/implementation-artifacts/53-9-correspondance-se4-vers-se5.md`

### Scénario 14.1 — La page existe et s'atteint
**But** : la page de correspondance est publiée et accrochée au guide.
**Étapes / attendu** :
- Le build produit `admin/depuis-se4/index.html` ; frontmatter `title`/`description` présents.
- Sidebar `/doc/admin/` porte, **après** « Réglages et supervision », un groupe additif « Depuis SE4 » avec une seule entrée « Je viens de l'ancienne interface » vers `/admin/depuis-se4/` ; les sept groupes de domaine existants et `/poste/` sont bit à bit inchangés.
- `/doc/admin/` porte un paragraphe additif (deux phrases + lien) après l'encart « Droit requis » qui clôt « Les domaines de l'administration », avant « Trouver le bon domaine à partir d'un besoin » ; le reste de la page est inchangé.

### Scénario 14.2 — Couverture des 45 lignes de correspondance, en 8 sections
**But** : chaque tâche SE4 attestée a sa ligne, dans la bonne section.
**Étapes / attendu** :
- 8 sections présentes dans cet ordre : Comptes et groupes (C1-C10), Droits d'administration (R1-R3), Parcs et postes (P1-P8), Applications et environnement des postes (A1-A9), Fichiers/partages/quotas (F1-F5), Imprimantes (I1), Serveur et supervision (S1-S6), Modules SE4 sans équivalent (M1-M3).
- Chaque ligne D nomme la tâche en langage métier (jamais un chemin `.php`), l'emplacement/geste SE5, et un lien vers la fiche de la colonne « Fiche à lier » de la table source — aucune autre cible liée.
- Aucune ligne ne réexplique un geste que la fiche liée couvre déjà (test de relecture : chaque cellule SE5 tient en une phrase courte).

### Scénario 14.3 — Statuts honnêtes : « pas encore » sans date, imprimantes distinctes
**But** : les deux règles d'honnêteté de la story sont tenues à la lettre.
**Étapes / attendu** :
- Les 7 lignes P (C8, A9, S4, S5, M1, M2, M3) portent la formulation unique « Pas encore disponible dans SE5. », sans lien, sans date, sans justification (« prévu », « à venir » absents).
- La ligne I1 (imprimantes) donne l'emplacement réel (onglet « Imprimantes » de Gestion du parc, bouton « Ajouter une imprimante », dépôt des pilotes), précise que ce guide n'a pas encore de fiche dédiée, et ne porte **aucun lien** ; elle n'est jamais formulée « pas encore dans SE5 ».
- `grep -n "pas encore" userDoc/admin/depuis-se4/index.md` → uniquement les 7 lignes P plus la mention imprimantes qui, elle, ne dit pas « pas encore dans SE5 » mais « pas encore de fiche ».

### Scénario 14.4 — Les 9 notes de logique, côté administrateur
**But** : chaque changement de logique tient en une phrase de geste, jamais de récit produit.
**Étapes / attendu** :
- Les 9 lignes concernées (C6, C9, C10, R1, R3, P7, A2, A8, F4) portent chacune UNE phrase de « ce qui change », formulée du point de vue de l'administrateur (le geste qu'il fait maintenant), jamais de l'implémentation ni de l'historique produit.
- A8 reprend la formulation prescrite : « Ce que vous régliez par des stratégies Windows se règle maintenant dans SE5 et s'applique par l'agent » (aucune mention de « GPO », « AD » ou « LDAP » comme termes techniques — seul « stratégies Windows » est employé).
- A2 énonce que le parc est l'unité d'affectation (reprise du principe déjà connu en SE4, généralisé aux réglages — sans dire que c'est entièrement nouveau).
- Aucune phrase ne commence par « SE5 a remplacé... » ni ne mentionne une décision abandonnée.

### Scénario 14.5 — Bascule côté poste assumée (C9, C10)
**But** : les deux seuls liens `/poste/` de la page sont corrects et justifiés.
**Étapes / attendu** :
- C9 renvoie vers `/poste/mon-compte/changer-mon-mot-de-passe` avec la phrase de logique (le mot de passe se change sur le poste, plus par une page web).
- C10 renvoie vers `/poste/fichiers/espace-personnel` avec la phrase de logique (les fichiers personnels se consultent depuis le poste).
- `grep -c "/poste/" userDoc/admin/depuis-se4/index.md` → exactement 2 occurrences de lien (hors mentions textuelles « sur le poste »).

### Scénario 14.6 — Lint, glossaire, gabarit et build
**But** : la page respecte la charte éditoriale et le build prouve tous les liens.
**Étapes / attendu** :
- `node .vitepress/lint-doc.mjs` vert : aucun mot interdit (« story »/« épic »/codes d'exigence/IPv4), aucun container natif `warning`/`danger`. **Vérifié en LOCAL 2026-07-27** (« Lint éditorial : OK, 67 fichiers » ; build VitePress strict vert, `build complete`).
- Aucun encart `droit-requis`/`delai-effet`/`vue-poste`/`attention` sur cette page (page de renvoi, pas fiche de geste).
- Liens glossaire à la première occurrence, seulement parmi les 9 ancres valides : `/glossaire#espace-personnel` (C10), `/glossaire#parc` (intro section Parcs et postes), `/glossaire#depot-applications` (A1), `/glossaire#capacite` (A8), `/glossaire#agent` (A8), `/glossaire#partage` (F1) — toutes ancres existantes dans `glossaire.md`.
- Aucun chemin de fichier `.php` ni nom de page technique SE4 recopié dans le texte publié ; tableaux markdown natifs (pas de HTML de tableau à la main).
- `npm run build` vert : les 36 cibles internes distinctes (34 pages du parcours admin + 2 fiches du parcours poste) résolvent — c'est la preuve autoritaire, aucun lien mort.

### Scénario 14.7 — Non-régression : diff limité, sept groupes existants et `/poste/` intouchés
**But** : la story n'a rien modifié en dehors du périmètre déclaré.
**Étapes / attendu** :
- `git diff --stat -- userDoc` : uniquement `.vitepress/config.mjs` (+6) et `admin/index.md` (+4), plus le nouveau fichier `admin/depuis-se4/index.md`.
- `git status` hors `userDoc/` et `docs/qa/` : vide (les suppressions préexistantes `docs/todo.md`/`todo/*.md`, sans rapport avec cette story, ni touchées ni restaurées).
- Aucune fiche de domaine 53.1-53.8 modifiée ; aucun fichier de l'outillage éditorial (`glossaire.md`, `CONTRIBUTING.md`, `.templates/`, `lint-doc.mjs`, `theme/custom.css`) modifié.

### Limites connues Section 14 (différé VM)
- **Matrice curl serveur non rejouée** : `/doc/admin/depuis-se4/` en 200, `/doc/admin/` en 200 avec le paragraphe additif, attendus depuis le build local autoritaire (lint + build VitePress strict verts), non vérifiés sur le serveur par ssh dans cette passe.
- **Contrôle visuel réel non couvert en ssh-only** : rendu des 8 sections et de leurs tableaux en thème clair/sombre et en viewport mobile non observé dans un navigateur — même dette d'environnement que les sections précédentes.
- **Libellé imprimantes confirmé par lecture de code, pas à l'écran** : « Imprimantes » (onglet) et « Ajouter une imprimante » (bouton) vérifiés dans `pages/parc/index.blade.php` l.656/676 ; recalage visuel à faire en lecture seule lors de la levée de la dette VM, sans muter d'endpoint d'écriture.

### Checklist rapide Section 14
- [ ] Page `admin/depuis-se4/index.md` construite ; frontmatter `title`/`description` ; 8 sections dans l'ordre attendu
- [ ] Sidebar `/admin/` = un groupe additif « Depuis SE4 » en **dernière** position (1 entrée) ; sept groupes existants et `/poste/` inchangés
- [ ] `admin/index.md` : paragraphe additif + lien après l'encart droit-requis ; reste de la page inchangé
- [ ] 45 lignes couvertes : 37 D avec lien vers la seule fiche de la colonne « Fiche à lier », 1 L (imprimantes, emplacement donné, aucun lien, jamais « pas encore »), 7 P (« Pas encore disponible dans SE5. », sans date, sans lien)
- [ ] 9 notes de logique en une phrase côté administrateur (A8 formulation prescrite ; A2 = parc unité d'affectation) ; aucun récit d'évolution produit
- [ ] C9/C10 = seuls liens `/poste/` de la page, avec phrase de logique
- [ ] Lint vert ; aucun encart normalisé sur la page ; liens glossaire valides (6 ancres utilisées) ; aucun chemin `.php` ; tableaux markdown natifs
- [ ] `npm run build` vert (preuve des 36 cibles internes) ; `git diff` limité à 2 fichiers modifiés + 1 nouveau, hors `userDoc/`+`docs/qa/` vide
- [ ] **VM (différé)** : matrice curl serveur + contrôle visuel clair/sombre/mobile + recalage à l'écran du libellé imprimantes en lecture seule
