# QA Manuel — Infrastructure e2e (Playwright)

**Domaine** : socle des tests end-to-end Playwright sur Postgres préseedé —
harnais hôte, instance e2e dédiée de la VM, template DB recréable, garde-fous
destructifs.

**Stories couvertes** : 21.1 (socle Playwright + environnement e2e), 21.2 (fake
AD/Samba en e2e — Section 4). _Seed de référence (21.3) et parcours fonctionnels
(21.4-21.7) à ajouter en sections dédiées quand livrés._

**Code de référence** :
- `playwright.config.ts` — config hôte (baseURL=E2E_BASE_URL, workers:1, globalSetup)
- `tests/e2e/global-setup.ts` — reset SSH avant suite (DP-1)
- `tests/e2e/smoke.spec.ts` — smoke `/authentication/login` (AC1)
- `app/Console/Commands/E2eResetCommand.php` — `e2e:reset` (DROP + CREATE … TEMPLATE)
- `app/Console/Commands/E2eBuildTemplateCommand.php` — `e2e:build-template`
- `app/Console/Commands/Concerns/GuardsE2eDatabase.php` — garde-fou structurel D-2
- `app/Console/Commands/Concerns/UsesMaintenanceConnection.php` — connexion `postgres` à la volée
- `.env.e2e.example` — clés de l'instance e2e (le `.env.e2e` réel vit sur la VM)
- `docs/qa/e2e-setup.md` — runbook de provisioning (Henri)

> **Provisioning** : voir `docs/qa/e2e-setup.md`. Ce runbook-ci valide le socle
> **une fois provisionné**.

---

## Pré-requis communs

- Instance e2e provisionnée sur la VM (cf. `docs/qa/e2e-setup.md`) : DB
  `sambaedu_e2e` + template `sambaedu_e2e_template`, `.env.e2e` (`APP_ENV=e2e`,
  chown `www-admin`), vhost/port dédié.
- Hôte : `npx playwright install` exécuté, `E2E_BASE_URL` + `E2E_SSH_*` exportés.
- ⚠️ Branche `playwrite` **non syncée** inotify → le code testé est celui
  **déployé** sur la VM (déploiement explicite requis pour tester `playwrite`).

---

## Section 1 — Garde-fou structurel destructif (Story 21.1, D-2 / AC5-6-9)

> **Le cœur sécurité de l'epic.** Ces scénarios prouvent qu'aucun chemin ne peut
> dropper une base hors e2e. Le test host automatisé
> `tests/Feature/E2e/E2eResetGuardTest.php` couvre déjà le chemin de refus sans
> Postgres ; ces scénarios manuels confirment sur la VM réelle.

### Scénario 1.1 — Refus hors env e2e

1. Sur la VM, dans un shell où `APP_ENV` n'est **pas** `e2e` (instance de dev) :
   `php artisan e2e:reset`.
2. **Attendu** : message `GARDE-FOU e2e … APP_ENV="…" (attendu "e2e")`, exit ≠ 0,
   **aucune base droppée** (vérifier `\l` Postgres inchangé).

### Scénario 1.2 — Refus sur base non suffixée `_e2e`

1. Forcer temporairement `DB_DATABASE=sambaedu` avec `APP_ENV=e2e`, lancer
   `php artisan e2e:reset`.
2. **Attendu** : message `GARDE-FOU e2e … ne porte pas le suffixe "_e2e"`,
   exit ≠ 0, base `sambaedu` (dev) **intacte**.

### Scénario 1.3 — Détection du config cache

1. `php artisan config:cache` avec une config pointant la prod, puis
   `APP_ENV=e2e php artisan e2e:reset`.
2. **Attendu** : le garde-fou lit `bootstrap/cache/config.php` (qui prime) et
   refuse, avec le hint « Config caché détecté … `php artisan config:clear` ».
3. Nettoyer : `php artisan config:clear`.

---

## Section 2 — Construction de template & reset (Story 21.1, AC3-4)

### Scénario 2.1 — Build de la template

1. `APP_ENV=e2e php artisan e2e:build-template`.
2. **Attendu** : `sambaedu_e2e_template` (re)créée, `migrate:fresh` + `db:seed`
   exécutés dessus, exit 0. Vérifier les tables seedées présentes dans la template.

### Scénario 2.2 — Reset rapide depuis la template

1. `APP_ENV=e2e php artisan e2e:reset`.
2. **Attendu** : `sambaedu_e2e` droppée puis recréée `TEMPLATE sambaedu_e2e_template`
   en ~centisecondes (pas de re-migration), exit 0. Données = état de la template.

### Scénario 2.3 — Reset avec session active sur la cible

1. Ouvrir une connexion `psql sambaedu_e2e` (laisser ouverte), puis
   `APP_ENV=e2e php artisan e2e:reset`.
2. **Attendu** : `pg_terminate_backend` coupe la session, le DROP réussit (pas de
   « database is being accessed by other users »), reset OK.

### Scénario 2.4 — Reset avec session active sur la TEMPLATE (post-correctif review 21-1)

1. Ouvrir une connexion `psql sambaedu_e2e_template` (laisser ouverte) — simule
   une session résiduelle juste après un `e2e:build-template` ou un psql de
   debug oublié. Puis `APP_ENV=e2e php artisan e2e:reset`.
2. **Attendu** : les sessions sur la **template** sont aussi terminées avant le
   `CREATE … TEMPLATE` (pas de « source database is being accessed by other
   users »), reset OK. Enchaîner directement `e2e:build-template` puis
   `e2e:reset` doit également passer du premier coup.

---

## Section 3 — Harnais Playwright & smoke (Story 21.1, AC1-7)

### Scénario 3.1 — Smoke /login

1. Hôte : `export E2E_BASE_URL=<url instance e2e>` puis `npm run test:e2e`.
2. **Attendu** : `globalSetup` lance le reset via SSH (log
   `[e2e:global-setup] Reset DB e2e via SSH …`), puis le smoke ouvre
   `/authentication/login` et asserte `#login` + `#password` visibles → 1 test vert.

### Scénario 3.2 — Échec SSH explicite

1. Hôte : pointer `E2E_SSH_KEY` sur une clé invalide, `npm run test:e2e`.
2. **Attendu** : `globalSetup` échoue avec un message actionnable (clé/instance/
   branche), la suite ne tourne pas sur un état non resetté.

### Scénario 3.3 — Saut de reset (debug local)

1. Hôte : `export E2E_RESET_DISABLED=1`, `npm run test:e2e`.
2. **Attendu** : warning `E2E_RESET_DISABLED actif — reset DB e2e SAUTÉ`, la
   suite tourne sur l'état courant (aucun SSH déclenché).

---

## Section 4 — Fake AD/Samba (Story 21.2, AC1-9)

> **Cœur sécurité n°2 de l'epic** : en `APP_ENV=e2e`, TOUTE interaction AD/Samba
> (écritures ET authentification) passe par un fake. Rien n'atteint
> `samba-ad-dc`, et le client AD réel ne peut PAS être instancié. Les
> environnements non-e2e (dev/prod/`testing`) sont STRICTEMENT inchangés.

**Code de référence (21.2)** :
- `app/Contracts/Ad/AdCredentialValidator.php` — interface bind auth (canal B).
- `app/Contracts/Ad/AdDirectory.php` — interface résolution d'identité (canal A).
- `app/Services/Auth/RealAdCredentialValidator.php` — bind LDAP réel (défaut partout).
- `app/Ldap/Real/RealAdDirectory.php` — résolution réelle `LdapUser::findByLogin` (défaut).
- `app/Ldap/Fakes/FakeE2eAdCredentialValidator.php` — validation mdp seedé (e2e).
- `app/Ldap/Fakes/FakeAdDirectory.php` — résolution depuis Postgres (e2e).
- `app/Ldap/Fakes/FakeSambaToolRunner.php` — capture `samba-tool` sans exécuter (e2e, canal C).
- `app/Ldap/Fakes/ThrowingLdapConnection.php` — connexion LdapRecord PIÉGÉE (garde-fou AC3).
- `app/Ldap/Fakes/FakeAdRecorder.php` — GUID factices stables + capture journal.
- `app/Models/E2e/AdWriteLog.php` + migration `…_create_e2e_ad_writes_table.php` — journal (table `e2e_ad_writes`, e2e-only).
- `app/Http/Controllers/E2e/AdWriteLogController.php` + route `GET /e2e/ad-writes` (déclarée si `APP_ENV=e2e`).
- Bindings : `AppServiceProvider::register()` (réels par défaut + swap fakes gated e2e) ;
  `LdapRecordServiceProvider::boot()` (connexion piégée en e2e).
- `config/e2e.php` — `fake_ad_password` / `fake_ad_passwords` (alimentés par `.env.e2e` / seed 21.3).

### Architecture (3 canaux doublés)

| Canal | Surface réelle | Doublure e2e | Garde-fou |
|---|---|---|---|
| A — LdapRecord | `LdapUser::findByLogin` (résolution auth + revérif guard) ; `AdSyncService` (create/delete/rename/move WorkstationGroup + move machine — symétrie complète post-review P-3) | `FakeAdDirectory` (Postgres) ; capture service-level dans `AdSyncService` | Connexion `default` = `ThrowingLdapConnection` |
| B — `ldap_bind` brut | `AuthenticationService::attemptBind` | `FakeE2eAdCredentialValidator` (compare mdp seedé) | — (le bind réel n'est jamais bindé en e2e) |
| C — `samba-tool` | `SambaToolRunner` (AdUserManager/AdMachineManager…) | `FakeSambaToolRunner` (capture + exit 0) | aucun process lancé |

**GPO/WPKG/iPXE/Power/Print** : NON doublés fonctionnellement (hors parcours
21.4-21.7). Ils restent couverts par le seul garde-fou anti-AD-réel : s'ils
tapent LdapRecord, la connexion piégée lève ; s'ils passent par `SambaToolRunner`,
le fake capture sans exécuter.

### ⚠️ Limite actée — le garde-fou AC3 est SDK-only (review 21-2 P-2/N-2, décision henri 2026-06-05)

La connexion piégée (`ThrowingLdapConnection`) neutralise le **canal LdapRecord
SDK uniquement**. Les appels **php-ldap bruts hors `AuthenticationService`** ne
sont PAS neutralisés en e2e et tenteraient un vrai connect/bind s'ils étaient
traversés :

- `AdUserManager::validatePassword()` — chemin Veyon via `ReadUserManager`
  (l'iPXE, lui, passe par `validateAdCredentials` → couvert par le fake) ;
- `ImportExportService` ;
- `Doctor\Checks\Ad\LdapBindCheck` (commande doctor).

**Aucun de ces chemins n'est sur les parcours 21.4-21.7.** Si une story future
exerce l'un d'eux en e2e, le router via `AdCredentialValidator` (pattern 21.2)
avant d'écrire le test.

### Journal des écritures (`e2e_ad_writes`)

- Table Postgres créée UNIQUEMENT en e2e (migration no-op hors e2e). Reset
  gratuit avec la template (21.1).
- Chaque ligne : `action_type` (ex. `ad.user.create`, `machine.move`),
  `target`, `fake_guid` (déterministe, stable), `payload` (mdp masqué), `channel`.
- **Inspection Playwright** : `GET /e2e/ad-writes` (JSON `{count, writes}`),
  filtres `?action_type=…&target=…`. Route déclarée seulement si `APP_ENV=e2e`.
- **Inspection host** : `App\Models\E2e\AdWriteLog::all()`.

### Scénario 4.1 — Aucune écriture AD réelle pendant un parcours

1. Sur l'instance e2e, déclencher une écriture (création user/machine, move de
   salle) via un parcours (ou un tinker équivalent).
2. **Attendu** : `GET /e2e/ad-writes` renvoie l'entrée capturée (action_type +
   target + `fake_guid` non nul). Vérifier côté DC qu'AUCUN objet réel n'a été
   créé/déplacé (`samba-tool user list` / `computer list` inchangés).

### Scénario 4.2 — Garde-fou anti-AD-réel (AC3)

1. Forcer un chemin qui résout la connexion LdapRecord par défaut hors fake
   (ex. `LdapUser::findByLogin()` direct via tinker sur l'instance e2e).
2. **Attendu** : exception `GARDE-FOU e2e : accès au client AD RÉEL interdit…`.
   Le vrai `samba-ad-dc` reste inatteignable. (Couvert host par
   `tests/Unit/E2e/FakeAdGuardTest.php`.)

### Scénario 4.3 — Auth d'un user seedé sans bind LDAP (AC4)

1. Pré-requis : un user seedé en Postgres + `E2E_FAKE_AD_PASSWORD` dans
   `.env.e2e` (données = 21.3). Se connecter via `/authentication/login` avec ce
   couple login/mdp.
2. **Attendu** : login réussi SANS bind LDAP réel (le DC n'a vu aucune
   connexion). L'utilisateur reste authentifié de requête en requête (la
   revérif par requête du guard passe par `FakeAdDirectory`, pas le vrai LDAP).

### Scénario 4.4 — GUID factice stable (AC2)

1. Écrire puis relire le même objet (ex. créer une machine, puis la déplacer).
2. **Attendu** : le `fake_guid` du journal est IDENTIQUE entre les deux entrées
   pour la même cible (résolution par GUID stable préservée).

### Scénario 4.5 — Non-régression hors e2e (AC5)

1. Sur une instance NON-e2e (dev), vérifier que le binding résout
   `RealAdCredentialValidator` / `RealAdDirectory` et que `SambaToolRunner` n'est
   PAS le fake. La route `/e2e/ad-writes` n'existe pas (404 catch-all legacy).
2. **Attendu** : aucun fake actif, flux AD/auth réel inchangé. (Couvert host par
   `tests/Feature/E2e/FakeAdCaptureTest::hors_e2e_les_bindings_ad_reels_sont_en_place`.)

---

## Post-correctifs & non-régressions

| Incident | Origine | Scénario de couverture |
|---|---|---|
| Premier `e2e:reset` après `e2e:build-template` pouvait échouer (« source database is being accessed by other users » — sessions résiduelles sur la template, non terminées avant `CREATE … TEMPLATE`) | Review 21-1 (P-3/N-1, corrigé pré-merge) | 2.4 |
| `ThrowingLdapConnection::connect(): static` = fatal PHP au chargement de classe (parent ldaprecord v3.8.6 retourne `void`, invariant) — cassait le boot e2e ET la suite PHPUnit host | Review 21-2 (P-1/N-1, corrigé pré-merge) | 4.2 (+ `FakeAdGuardTest` host) |
| `AdSyncService` delete/rename/moveWorkstationGroup non doublés → 500 garde-fou sur parcours salles | Review 21-2 (P-3, corrigé pré-merge, décision henri) | 4.1 |

- **`AppProfileAdSyncService` non doublé** (review 21-2 P-5, fichier hors story) :
  `new Group()` LdapRecord direct + observer actif en e2e → tout parcours
  touchant un `AppProfile` lèvera le garde-fou. À doubler si le parcours 21.6
  l'exerce (association AppProfile ↔ salle).

- **Aucun DROP sur dev/prod** : Section 1 est l'invariant absolu. Tout
  changement des commandes e2e doit re-jouer 1.1/1.2/1.3 + le test host
  `E2eResetGuardTest`.
- **`phpunit.xml` inchangé (AC8)** : le canal e2e est totalement séparé du canal
  PHPUnit/SQLite. Vérifier qu'aucune story e2e ne modifie `phpunit.xml`.

---

## Checklist rapide

- [ ] 1.1 reset refuse hors e2e (aucune base droppée)
- [ ] 1.2 reset refuse sur base non `_e2e`
- [ ] 1.3 config cache détecté → refus + hint
- [ ] 2.1 build-template OK (migrate+seed sur template)
- [ ] 2.2 reset depuis template ~centisecondes
- [ ] 2.4 reset OK avec session ouverte sur la template (et juste après build-template)
- [ ] 2.3 reset coupe les sessions actives avant DROP
- [ ] 3.1 smoke `/authentication/login` vert
- [ ] 3.2 échec SSH explicite et bloquant
- [ ] 3.3 E2E_RESET_DISABLED saute le reset
- [ ] 4.1 aucune écriture AD réelle (journal capture, DC inchangé)
- [ ] 4.2 garde-fou anti-AD-réel lève (LdapRecord piégée)
- [ ] 4.3 auth user seedé sans bind LDAP + session persistante
- [ ] 4.4 GUID factice stable entre write/relecture
- [ ] 4.5 non-régression hors e2e (bindings réels, route absente)
