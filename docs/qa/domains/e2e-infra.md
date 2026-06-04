# QA Manuel — Infrastructure e2e (Playwright)

**Domaine** : socle des tests end-to-end Playwright sur Postgres préseedé —
harnais hôte, instance e2e dédiée de la VM, template DB recréable, garde-fous
destructifs.

**Stories couvertes** : 21.1 (socle Playwright + environnement e2e). _Fake AD
(21.2), seed de référence (21.3) et parcours fonctionnels (21.4-21.7) à ajouter
en sections dédiées quand livrés._

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

## Post-correctifs & non-régressions

| Incident | Origine | Scénario de couverture |
|---|---|---|
| Premier `e2e:reset` après `e2e:build-template` pouvait échouer (« source database is being accessed by other users » — sessions résiduelles sur la template, non terminées avant `CREATE … TEMPLATE`) | Review 21-1 (P-3/N-1, corrigé pré-merge) | 2.4 |

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
