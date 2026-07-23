# QA Manuel — Documentation

**Domaine** : site de documentation publique SE5 — build VitePress isolé (`userDoc/`), publication Apache verrouillée sous `/doc`, intégration fail-soft à `scripts/update.sh`, socle deux parcours (« J'administre SE5 » / « J'utilise mon poste »).

**Stories couvertes** : 52.1 (socle : build isolé, alias Apache, `ensure_user_doc()` fail-soft, porte à deux parcours). _Stories 52.2 → 52.8 (gabarit/encarts/glossaire, contenu des fiches, recherche, captures, lien d'aide depuis l'app) à ajouter en sections suivantes quand livrées._

**Code de référence** :
- `userDoc/package.json`, `userDoc/package-lock.json` — chaîne npm ISOLÉE de l'application (jamais de dépendance croisée)
- `userDoc/.vitepress/config.mjs` — `base: '/doc/'`, `lastUpdated` + repli mtime (`transformPageData`), sidebars keyées par chemin, `srcExclude` (README.md exclu du build public)
- `userDoc/.vitepress/theme/{index.js,custom.css}` — `theme-without-fonts` + IBM Plex embarquée
- `userDoc/{index,admin/index,poste/index}.md` — porte d'accueil + pages d'orientation des deux parcours
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
