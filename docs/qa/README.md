# Documentation QA manuelle

Ce dossier centralise les scénarios d'E2E manuels destinés à être joués sur
la VM SER après livraison d'une story.

## Conventions

Deux organisations coexistent par souci de transition incrémentale :

### `{epic}-{story}-e2e-manual.md` (legacy par story)

Format historique : un fichier par story, scénarios numérotés. Exemples :
`4-2-e2e-manual.md`, `7-1-e2e-manual.md`.

> **Note** : ce format est figé pour les stories existantes mais NE DOIT PLUS
> être créé pour les nouvelles stories — préférer le format par domaine
> ci-dessous (introduit en 5.1c).

### `domains/<domain>.md` (préféré, par domaine)

Format actuel : un fichier par domaine fonctionnel, append-only. Chaque story
ajoute une section nommée `## Story X.Y — <titre>` avec ses scénarios
numérotés. Permet de capitaliser des scénarios stables et de naviguer par
domaine plutôt que par story.

## Domaines couverts

| Domaine     | Fichier                          | Stories couvertes |
|-------------|----------------------------------|-------------------|
| Filesystem  | `domains/filesystem.md`          | 5.1c              |

## Pré-requis communs

Toutes les checklists supposent :

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Cache Spatie reset : `php artisan permission:cache-reset`
- User `admin` (ou équivalent SuperAdmin) pour les actions critiques
  (`server.admin`).

## Comment ajouter une checklist

1. Identifier le domaine principal de la story (Filesystem, Auth, Parc, etc.).
2. Si `docs/qa/domains/<domain>.md` existe : ouvrir et **append** une nouvelle
   section `## Story X.Y — <titre>` à la fin. Numéroter les scénarios à la
   suite (préserver les numéros existants — ils sont stables).
3. Sinon : créer le fichier en suivant la structure de `domains/filesystem.md`,
   et ajouter une ligne dans le tableau "Domaines couverts" ci-dessus.
4. **Ne jamais** créer un nouveau fichier `{epic}-{story}-e2e-manual.md` pour
   une story nouvelle.
