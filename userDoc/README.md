# userDoc — Documentation publique SE5

Site de documentation statique, publié **sans authentification**, sous
`/doc` — porte à deux parcours : « J'administre SE5 » (`/admin/`) et
« J'utilise mon poste » (`/poste/`).

Généré par [VitePress](https://vitepress.dev/) 1.x. Ce dossier est
**strictement isolé** de l'application Laravel : `package.json`,
`package-lock.json` et la chaîne de build qui suivent n'ont **aucun** lien
avec `package.json` / `vite.config.js` / `resources/{css,js}` à la racine du
dépôt — ne jamais les modifier depuis ce dossier, ni l'inverse.

## Build — commande unique

```bash
cd userDoc
npm install
npm run build
```

Le site est généré dans `userDoc/.vitepress/dist` (`outDir` par défaut de
VitePress). Ce dossier **n'est pas** celui qui est servi par Apache.

## Qui publie ?

`scripts/update.sh` (fonction `ensure_user_doc`) rejoue le build ci-dessus à
chaque mise à jour du serveur, puis **miroir-purge** `.vitepress/dist` vers
`userDoc/dist/` — c'est ce dernier dossier que l'alias Apache `/doc` sert
(`scripts/setupApache.sh`). Le miroir reconstruit intégralement la sortie
publiée (fichiers orphelins purgés) : aucune commande manuelle n'est
nécessaire côté serveur pour publier une évolution de la documentation.

Un échec de build de la documentation (registre npm injoignable, lien
interne mort, etc.) est **fail-soft** : il ne fait jamais échouer la mise à
jour de l'application, et le site précédemment publié reste servi intact.

## Date de fraîcheur

Chaque page affiche sa date de dernière mise à jour, dérivée par défaut de
l'historique git au build (`lastUpdated: true`). **Aucune date n'est saisie
à la main** dans le frontmatter des sources.

Repli : si les sources sont déployées hors d'un dépôt git (cas observé sur
la VM de développement, dont la copie `/var/www/sambaedu-reload` n'est pas
un checkout git), `userDoc/.vitepress/config.mjs` retombe sur le mtime du
fichier source (`transformPageData`). Sur un déploiement avec checkout git
réel, la date git prime — le repli ne s'active que si git n'a rien produit.

## Fantômes de source côté VM

La synchronisation inotify vers la VM (branche `main`) propage les créations
et modifications, **mais pas les suppressions**. Si une page Markdown est
supprimée ou renommée côté hôte, l'ancien fichier reste présent dans les
sources de la VM et continue d'être compilé au prochain build.

Le miroir de sortie (`ensure_user_doc` → `userDoc/dist/`) neutralise les
pages fantômes **publiées** (une page absente des sources ne reste jamais
servie), mais ne peut rien contre un fantôme de **source** encore présent
sur la VM : celui-ci doit être retiré à la main, par ssh, avec `trash` ou
`mv` — **jamais `rm -rf`**.

## Développement local

```bash
cd userDoc
npm install
npm run dev
```

Le serveur de développement sert le site à la racine (`/`), pas sous
`/doc/` — c'est normal, `base: '/doc/'` ne s'applique qu'au build publié.
