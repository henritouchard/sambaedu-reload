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
