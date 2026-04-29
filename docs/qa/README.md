# QA Manuel — Index pré-prod

Checklist par domaine métier. À dérouler avant une mise en production importante. Chaque fichier dans `domains/` est un runbook stable qui s'enrichit au fil des stories (pas de réécriture, append only).

## Domaines couverts

- [ ] [rights-management](domains/rights-management.md) — Droits applicatifs, rôles Spatie, permissions, délégations périmétrées, scoping classe, profils CRUD _(Stories 7.1, 7.2)_
- [ ] [filesystem](domains/filesystem.md) — Quotas, snapshot, trash, home dirs _(Stories 5.1c, 5.1d)_

## À créer au fil des prochaines stories

- [ ] `users.md` — création / modification / suppression / bulk reset MDP / itinérant
- [ ] `parc.md` — actions machine / batch groupes / schedules / wallpapers / AppProfiles
- [ ] `ad-sync.md` — `/admin/sync-from-ad`, rapatriement profils LDAP
- [ ] `bootstrap-update.md` — `scripts/update.sh`, migrations, seed auto, cache reset
- [ ] `legacy-shims.md` — routes catchall legacy, modules GPO / iPXE / DHCP / BBB / imprimantes
- [ ] `auth.md` — login LDAP, sessions, `AuthUser` ↔ `User`, CAS / OAuth2

## Convention

Chaque domaine = un fichier Markdown dans `domains/`. Structure interne :

1. **Pré-requis communs** (VM, utilisateurs test, seed…)
2. **Sections par sous-domaine** (numérotation stable : `## Section 1 — …`)
3. **Scénarios numérotés** (`### Scénario 1.1 — …`) — **l'ID est stable**, ne pas renuméroter après ajout de nouveaux scénarios
4. **Post-correctifs & non-régressions** en fin de section (pourquoi ce scénario existe, quel incident il couvre)
5. **Checklist rapide** à la toute fin, cases à cocher pour le relecteur

Les corrections post-review ajoutent des scénarios dédiés (append), pas de réécriture des existants.

> **Format legacy** : les fichiers `{epic}-{story}-e2e-manual.md` (ex : `7-1-e2e-manual.md`) sont figés et ne servent plus de référence — ne pas en créer de nouveaux.

## Comment ajouter une checklist

1. Identifier le domaine principal de la story (Filesystem, Auth, Parc, etc.).
2. Si `docs/qa/domains/<domain>.md` existe : ouvrir et **append** une nouvelle section `## Story X.Y — <titre>` à la fin. Numéroter les scénarios à la suite (préserver les numéros existants — ils sont stables).
3. Sinon : créer le fichier en suivant la structure de `domains/rights-management.md`, et ajouter une ligne dans "Domaines couverts" ci-dessus.

## Pré-requis communs

Toutes les checklists supposent :

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Cache Spatie reset : `php artisan permission:cache-reset`
- User `admin` (ou équivalent SuperAdmin) pour les actions critiques (`server.admin`).

## Utilisation

Avant une mise en prod : ouvrir ce `README.md`, cocher les domaines impactés par le déploiement, puis dérouler chaque runbook domaine par domaine. Les scénarios doivent passer tous verts avant merge `main` → tag prod.
