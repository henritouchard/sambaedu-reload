---
name: legacy-update
description: Synchronise les évolutions du legacy sambaedu/ vers le refactoring sambaedu-reload/. Récupère les derniers commits du legacy, analyse les changements fonctionnels et propose les adaptations à reporter dans le nouveau code.
---

# legacy-update

Outil d'aide à la synchronisation entre le code legacy (`sambaedu/`) et la réécriture (`sambaedu-reload/`).

## Principe

`sambaedu/` continue d'évoluer en parallèle du refactoring `sambaedu-reload/`. Cette skill permet, après un `git pull` sur le legacy, de ne pas perdre de vue les évolutions fonctionnelles qui doivent être reportées dans la nouvelle base.

## Étapes

### 1. Récupérer les diffs depuis le dernier pull

Dans `sambaedu/` :

- Lire `ORIG_HEAD` si présent (pointe sur l'état avant le dernier pull/merge) ; sinon demander à l'utilisateur un ref de référence (tag, commit, date).
- `git log --oneline ORIG_HEAD..HEAD` pour lister les commits pullés.
- `git diff --stat ORIG_HEAD..HEAD` pour voir l'ampleur.
- Pour chaque commit intéressant : `git show <sha>` ou `git diff` ciblé.

Si `ORIG_HEAD` est absent ou trop ancien, proposer à l'utilisateur de donner un point de départ (dernier SHA déjà analysé, date, etc.).

### 2. Analyser chaque changement

Pour chaque commit / groupe de changements, classifier :

- **Fonctionnel** : nouveau comportement, correction de bug, évolution métier → candidat au report.
- **Cosmétique / version bump / typo** : probablement à ignorer (mais le mentionner).
- **Déjà couvert dans le refactoring** : vérifier en explorant `sambaedu-reload/` (routes, controllers, livewire components, services) si la fonctionnalité touchée existe déjà sous une forme refactorée.
- **Sans équivalent** : la zone n'a pas encore été migrée → noter le fichier legacy pour la migration future, sans action immédiate.

Pour faire le mapping legacy → reload :
- Identifier les fichiers PHP touchés dans `sambaedu/` (ex. `sambaedu/users/*.php`).
- Chercher dans `sambaedu-reload/laravel/` les routes/controllers/views/livewire équivalents (par nom de page, libellé, table SQL, endpoint).
- Respecter l'arborescence du refactoring (cf. `CLAUDE.md`) : routes dans `resources/views/pages/`, livewire SFC, trait `WithToasts` pour les notifs, modale réutilisable.

### 3. Restituer les conclusions

Produire un rapport concis structuré par commit (ou par thème si plusieurs commits touchent la même feature) :

```
## <sha court> — <sujet>
- Nature : fonctionnel | cosmétique | bug fix | …
- Fichiers legacy : …
- Équivalent reload : <chemin> | non migré
- Action proposée : à reporter | ignorer | à noter pour migration future
- Détail du changement : 2-3 lignes max
```

Terminer par un **plan d'action** : la liste ordonnée des modifications à faire dans `sambaedu-reload/`, avec pour chacune les fichiers cibles et une description courte.

### 4. Passage à l'action

Ne pas modifier `sambaedu-reload/` sans validation explicite. Présenter d'abord le rapport, puis attendre le feu vert pour :
- soit implémenter directement les reports,
- soit ouvrir des todos / issues,
- soit créer un plan détaillé pour les changements lourds.

### 5. Propager le legacy sur la VM

Le code tourne sur la VM `se4fs` (accessible via l'alias `se4ssh` = `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`).

**Contexte important** :
- `sambaedu-reload/` (le refactor Laravel) est déjà synchronisé entre host et VM via le mount partagé — rien à pousser côté reload.
- `sambaedu/` (le legacy PHP pur) **n'est PAS monté** : le host embarque une version plus récente (issue des pulls upstream traités à l'étape 1), la VM embarque la version installée au déploiement. Sans sync, les tests qui `require` des fichiers legacy via le bootstrap tombent sur l'ancien code de la VM (ex. `get_sid_from_name` existe sur le host mais pas sur la VM où c'est `get_sid`).

**But de l'étape** : pousser les fichiers legacy modifiés (ceux identifiés à l'étape 1) du host vers la VM.

**Note** : les alias shell ne sont pas résolus en SSH non-interactif. Dans le Bash tool, utiliser la forme complète : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "<cmd>"`.

**Étape a — identifier les fichiers legacy à pousser**

Sur le host dans `sambaedu/`, lister les fichiers modifiés entre l'ancien ref (avant le pull traité à l'étape 1) et `HEAD` :

```bash
git -C sambaedu diff --name-only ORIG_HEAD..HEAD
```

**Étape b — rsync host → VM**

Pousser ces fichiers vers `/var/www/sambaedu/` sur la VM :

```bash
git -C sambaedu diff --name-only ORIG_HEAD..HEAD | \
  rsync -avz --files-from=- -e "ssh -i ~/.ssh/id_se4fs_vm" sambaedu/ root@192.168.122.50:/var/www/sambaedu/
```

Pour une resync complète (si l'écart est large), faire un rsync global avec exclusions :

```bash
rsync -avz --delete \
  --exclude '.git' --exclude 'node_modules' --exclude 'vendor' --exclude '*.log' \
  -e "ssh -i ~/.ssh/id_se4fs_vm" \
  sambaedu/ root@192.168.122.50:/var/www/sambaedu/
```

Confirmer **avant** d'utiliser `--delete` : cela supprimera côté VM tout fichier absent du host.

**Étape c — clear caches Laravel côté reload** (nécessaire si le legacy nouvellement poussé est consommé par du code reload avec du caching) :

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "cd /var/www/sambaedu-reload && php artisan optimize:clear"
```

**Étape d — validation** : smoke test URL legacy (catchall) ou tests PHPUnit ciblant les fonctions concernées :

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "cd /var/www/sambaedu-reload && php artisan test --filter=<TestClass>"
```

Filtrer les warnings PHPUnit bruyants avec `| grep -vE 'WARN.*Metadata found'` pour garder la sortie lisible.

**Vérification parité legacy** : si un test échoue sur un `function_exists()`, comparer côté VM la liste réelle des fonctions :

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "grep -E '^function ' /var/www/sambaedu/includes/<file>.php | head -30"
```

Si un écart subsiste après rsync, c'est probablement que l'ORIG_HEAD de référence est trop récent (fichier manquant dans le diff) — relancer le rsync global de l'étape b.

## Garde-fous

- Ne jamais commit/push dans `sambaedu/` (c'est un repo upstream).
- Respecter les conventions du refactoring (Livewire SFC, filesystem routing, composants réutilisables).
- Si un changement touche des fichiers PHP legacy sans équivalent dans le reload, le signaler mais ne pas improviser la structure de la migration.
- Ne jamais lancer les commandes de l'étape 5 sans l'accord explicite de l'utilisateur (la VM est un environnement partagé).
