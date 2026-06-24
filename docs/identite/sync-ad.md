# Import AD → SQL des identités (outil de migration)

> **Outil de bascule, transitoire.** Cet import amorce le miroir SQL des
> identités depuis l'annuaire AD au moment de **migrer un établissement**. Une
> fois l'établissement migré, SQL est la source de vérité et **l'import n'a plus
> vocation à tourner** (cf. [`metier.md`](metier.md), ADR-1). Cette fiche reste
> donc volontairement brève. Le modèle de données visé : `modele-identite.md`.
>
> Sens **AD → SQL uniquement** : l'import ne crée **jamais** de compte
> utilisateur en AD.

## 1. Lancer l'import

```bash
php artisan users:sync-from-ad [--scope=all|tree|memberOf] [--mode=delta|full] [--now] [--reset-delta-cursor]
```

- `--scope` — périmètre établissement : `all` (annuaire entier), `tree` (sous
  l'OU de l'établissement), `memberOf` (membres du groupe d'établissement).
- `--mode` — `full` (tout) ou `delta` (seulement les objets modifiés depuis le
  dernier passage).
- `--now` — exécute en synchrone ; sinon l'import part sur la queue `sync` (job
  `SyncUsersFromAdJob`).
- `--reset-delta-cursor` — repart d'un delta vierge.

## 2. Appariement (la règle qui compte)

Chaque objet AD est apparié à sa ligne SQL par **`ad_guid`** (l'`objectGUID`,
stable), puis à défaut par `login`, puis par `dn`. Conséquence : un renommage en
AD met à jour la **même** ligne, sans créer de doublon. Un `ad_guid` qui
porterait deux logins SQL distincts est signalé comme incohérence.

Le `login` est comparé de façon **insensible à la casse** (`sAMAccountName` l'est,
`=` PostgreSQL ne l'est pas).

En mode delta, un curseur (`whenchanged` AD) borne le passage aux objets
récemment modifiés ; il est persisté entre les runs.

## 3. Ce que l'import touche — et ne touche pas

- **Crée / met à jour** en SQL : utilisateurs et groupes (avec `ad_guid`, `dn`,
  rôle, rattachement établissement `school_code`/`school_name`).
- **Amorce** les permissions/rôles Spatie de façon **non destructive** (créés une
  fois, jamais réécrits — la configuration de droits existante est préservée).
- **Ne crée jamais** de compte utilisateur en AD (sens unique).
- **Ignore** les identités fédérées (`source = federated`) : elles ne viennent
  pas de l'AD (voir `identite-federee.md`).

## 4. Carte du code

| Rôle | Chemin |
|---|---|
| Commande | `app/Console/Commands/SyncUsersFromAdCommand.php` |
| Job d'orchestration (queue `sync`) | `app/Jobs/SyncUsersFromAdJob.php` |
| Cœur de l'import (fetch, appariement, upsert, delta) | `app/Services/UserSyncService.php` |
| Rattachement établissement (règles DN) | `app/Services/Ldap/EstablishmentMatcher.php` |
| Modèles LDAP lus | `app/LdapModels/LdapUser.php`, `app/LdapModels/SambaEduGroup.php` |
| Tests | `tests/Feature/Console/SyncUsersFromAdCommandTest.php`, `tests/Unit/Services/UserSyncService*Test.php` |

## 5. Renvois

- [`metier.md`](metier.md) — pourquoi l'import est transitoire (ADR-1) et la
  résolution par GUID (ADR-3).
- [`README.md`](README.md) — index du domaine.
