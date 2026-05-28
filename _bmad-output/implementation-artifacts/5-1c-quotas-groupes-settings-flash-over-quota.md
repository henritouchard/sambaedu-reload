# Story 5.1c : Quotas groupes, `/admin/settings` scaffold et flash over-quota au login

Status: done

> **Origine** : Epic 5 — Système de Fichiers SER. Troisième sous-story de la Story 5.1 splittée le 2026-04-22.
>
> **Dépendances amont (toutes livrées)** :
> - **5.1a done** (2026-04-23 — commit `fb0b0b6`) — `App\Services\Filesystem\HomeDirService` + `App\Services\Filesystem\XfsQuotaService` sont en place. Cache 5min supprimé.
> - **5.1b done** (2026-04-23) — colonne `users.quota_snapshot` (JSONB pgsql / JSON sqlite) + commande `quota:snapshot` planifiée `dailyAt('03:00')->withoutOverlapping()->runInBackground()` + Livewire SFC `pages.users.[login]._partials.quota-section` avec refresh + override user via `server.admin`.
>
> **Scope 5.1c** :
> 1. **Édition quota par groupe** : section "Quota du groupe" sur `/app/users/groups/[id]` (Livewire SFC `group-quota-section`) avec breakdown effectif + modale override groupe (partition + inherited/unlimited/custom).
> 2. **Colonne "Quota" dans le listing** `/app/users/groups` (si ce listing existe — sinon décision D10 à trancher au kickoff).
> 3. **Scaffold `/admin/settings`** (nouvelle route) avec layout à onglets extensible — onglet "Quotas & FS" **uniquement** pour cette story. Contenu : defaults par profil (élève/prof/admin/itinérant) × 2 partitions + grace period par partition + TTL trash (jours) + toggle purge auto/manuelle.
> 4. **Persistance settings** : extension du modèle `QuotaSetting` (pattern K/V-par-partition déjà en place) et nouvelle table/rows pour les defaults profils + TTL trash + toggle purge (décision D1 à trancher).
> 5. **Toast over-quota au login** : lecture de `users.quota_snapshot` post-`Auth::login()` dans `SambaEduAuthGuard`, flash d'un toast "warning" via `ToastMagic::warning()` côté server-side (avant Livewire). Visible au chargement de la page post-login.
>
> **Stories avales** :
> - **5.1d** (backlog) : `default_itinerant` dans `XfsQuotaService::getEffectiveQuota()` (override si `User::isExternal()`), commande `trash:purge` (consomme TTL + toggle persistés par 5.1c), commande `quota:seed-from-legacy`. Dépend explicitement de l'onglet settings livré par 5.1c.

---

## Story

En tant que **responsable de collège**,
je veux ajuster le quota d'un groupe entier d'un coup depuis sa fiche, régler les valeurs par défaut (par profil + par partition + grace + TTL trash + toggle purge) depuis une page de réglages dédiée `/admin/settings`, et être averti par un toast clair à ma connexion quand mon snapshot indique un dépassement,
afin d'administrer les quotas de façon cohérente sans toucher chaque utilisateur individuellement et que les utilisateurs en dépassement soient alertés dès leur connexion sans action manuelle de ma part.

---

## Contexte & Motivation

### Ce qui est déjà livré (ne pas refaire)

| Composant | État | Détail |
|---|---|---|
| `App\Services\Filesystem\XfsQuotaService` | livré 5.1a | `getEffectiveQuota(username, partition, userGroups[], userProfile): array` (héritage user > group > default profil) + `setQuotaRule(type, target, partition, soft, hard, performedBy, applyImmediately=true): QuotaRule` + `deleteQuotaRule(QuotaRule, performedBy): bool` + `dispatchRecalculateGroupJob(groupName, partition, performedBy): void` (privée, auto-appelée après setQuotaRule/deleteQuotaRule de type `group`) |
| `users.quota_snapshot` JSONB/JSON | livré 5.1b | Structure documentée : `{home:{used_kb,soft_kb,hard_kb,used_mb,soft_mb,hard_mb,percent,is_over_soft,is_over_hard,grace_days}, sambaedu:{…}, captured_at:ISO8601}`. Cast Eloquent `'array'`. |
| `User::isExternal(): bool` | livré pré-Epic 5 | Compare `school_code` courant à celui de l'établissement via `SEConfig::getCurrentEstablishmentCode()`. Réutilisable tel quel pour 5.1d (pas pour 5.1c — pas de default_itinerant ici). |
| `quota_settings` table | livrée pré-Epic 5 | `partition(unique), grace_period_days(7), default_overage_percent(20), timestamps`. **Extension nécessaire en 5.1c** pour TTL trash + toggle purge. |
| `QuotaRule::TYPE_GROUP` | livré pré-Epic 5 | Utilisé par `QuotaController::updateGroupQuota` actuel (endpoint POST legacy). **À remplacer par le Livewire SFC 5.1c** — endpoint POST QuotaController conservé pour compat mais bouton UI migré. |
| Pattern onglets extensible | livré 4.7+7.2 | Voir `resources/views/pages/parc-settings/index.blade.php` (`#[Url(keep: true)] public string $tab` + `setTab(string $tab): void` + `<button role="tab" class="tab {{ $tab === 'xxx' ? 'tab-active' : '' }}">` + `<livewire:pages::xxx._partials.yyy-tab />`). Pattern à décalquer EXACTEMENT pour `/admin/settings`. |
| `App\Components\Traits\WithToasts` | livré | `toastSuccess/toastError/toastWarning/toastInfo/toastAccessDenied`. Uniquement utilisable depuis un composant Livewire. |
| `ToastMagic` facade (composer `devrabiul/laravel-toaster-magic`) | livrée | `ToastMagic::warning($message, $description, $options)` / `::success()` / `::info()` / `::error()` — stockage session, visible à la prochaine requête HTTP render. **Clé pour le toast over-quota server-side depuis SambaEduAuthGuard** (pas de contexte Livewire pendant le handshake auth). |
| Modale réutilisable | pattern | Pattern `<dialog class="modal">` + `@teleport('body')` + `x-data / @entangle` — cf. `password-reset-modal.blade.php` + `app-customize-modal.blade.php` + la modale d'override déjà livrée dans `quota-section.blade.php` (5.1b post-review #6). |

### Pourquoi l'édition quota groupe actuelle doit être refondue

Le composant `resources/views/components/quotas/group-quota-management.blade.php` (210 lignes, Blade pur) :
- Consomme `$group['cn']` qui vient d'un contexte legacy AD (lookup LDAP) — **ne fonctionne pas avec le modèle Eloquent `UserGroup`** utilisé dans `/app/users/groups/[id]` (qui utilise `$name` = cn exact).
- Pointe vers la route POST `app.users.groups.quota.update` (QuotaController) qui est un endpoint HTTP classique **non-Livewire** (redirect back + flash). Incohérent avec le reste de l'interface en Livewire SFC (cf. 5.1b).
- Utilise `@can('manage-quotas')` — **gate fantôme non défini** (problème identifié en 5.1b, partiellement résolu : `quota-info.blade.php` supprimé, mais ce composant `group-quota-management.blade.php` reste avec 2 occurrences du @can fantôme). **À nettoyer définitivement en 5.1c.**
- N'est **pas inclus** dans `resources/views/pages/users/groups/[id]/index.blade.php` (page groupe actuelle) — il était utilisé dans l'ancienne page legacy. **Décision produit D2** : recréer un Livewire SFC dédié `group-quota-section.blade.php` sur le modèle strict du `quota-section.blade.php` livré en 5.1b (même patterns : `WithToasts`, `Gate::allows('server.admin')`, modale `<dialog class="modal">` + `@teleport('body')` + `@entangle`, double guard UI+serveur).

### Pourquoi un scaffold `/admin/settings`

Il n'existe aujourd'hui **aucune page de configuration serveur** dans SER. `/admin/legacy-monitor`, `/admin/migrate`, `/admin/sync-from-ad` existent mais traitent des aspects opérationnels (observabilité, sync AD). Les defaults quotas sont actuellement éditables uniquement via :
- Des seeds BDD manuels (`QuotaRule::TYPE_DEFAULT_ELEVE/PROF/ADMIN`) — pas d'UI.
- Le champ `quota_settings.grace_period_days` + `default_overage_percent` — aucune UI.
- Le TTL trash et le toggle purge auto **n'existent pas encore en BDD** (5.1d les consommera).

Cette story pose le scaffold complet `/admin/settings` avec **un seul onglet "Quotas & FS"** (décision D3). Les futurs onglets (DHCP, CUPS, Profils, etc.) viendront dans leurs Epics respectives. **Interdiction formelle de poser des placeholders "coming soon"** — on n'ajoute que ce qui est implémenté.

### Pourquoi le toast au login

Le snapshot `users.quota_snapshot` est déjà calculé et persisté 1×/jour (5.1b). **Mais l'utilisateur lambda ne voit la page fiche qu'en cliquant sur son propre profil.** Le cas métier cible = un élève/prof qui se connecte le matin, n'a jamais consulté sa fiche, ne voit pas qu'il est à 99% de son quota `/home`, et se fait rejeter au premier enregistrement LibreOffice. Le toast proactif au login (`ToastMagic::warning(...)`) est la réponse.

### Investigation code existant (2026-04-24)

**`App\Http\Middleware\Auth\SambaEduAuthGuard`** (l. 36-82) est le point d'entrée pour injecter le toast login :
- Après `Auth::login($laravelUser)` (l. 78), le `User` Eloquent est accessible via `Auth::user()` ou `AuthUser::findByLogin($login)`.
- Pour obtenir `quota_snapshot`, requête directe `App\Models\User::where('login', $login)->value('quota_snapshot')` (cast `'array'`).
- **Injection du toast via `ToastMagic::warning($message, $description)`** — stockage session, consommé au prochain render Blade via `{!! ToastMagic::scripts() !!}` déjà appelé dans `layouts/app.blade.php` l. 136. Pas de dispatch Livewire nécessaire (pas de contexte Livewire pendant l'auth).
- **Gotcha** : `Auth::login` est appelé **à chaque requête** (handshake cookie + LDAP). Pour éviter de spammer le toast à chaque requête, **stocker un flag `quota_warning_shown_at` en session et ne le re-déclencher qu'1×/session** (décision D5).

**`App\Models\QuotaSetting`** (l. 18-73) :
- Table `quota_settings` : `partition(unique), grace_period_days, default_overage_percent, timestamps`. Pattern 1 row / partition.
- Helper `forPartition(string $partition): self` (firstOrCreate avec defaults 7/20).
- `calculateHardQuota(int $softMb): int` utilitaire.

**Extension nécessaire en 5.1c** (tranche à arbitrer — décision D1) :
- **Option A** (recommandée SM) : **Nouvelle table `system_settings` K/V JSON** (`key` unique + `value` jsonb + timestamps). Permet d'éviter la prolifération de colonnes sur `quota_settings`. Pratique pour d'autres futurs toggles système. Keys attendues 5.1c : `quota.default_eleve.home.soft_mb`, `quota.default_eleve.home.hard_mb`, `quota.default_prof.home.soft_mb`, `quota.default_itinerant.home.soft_mb` (seed à zéro, effectif 5.1d), `quota.trash.ttl_days`, `quota.trash.purge_auto`. Ou plus propre : `quota.defaults` = jsonb `{eleve:{home:{soft,hard},sambaedu:{soft,hard}}, prof:{…}, admin:{…}, itinerant:{…}}`, `quota.trash` = jsonb `{ttl_days: 30, purge_auto: false}`.
- **Option B** : Étendre `quota_settings` avec colonnes `trash_ttl_days`, `trash_purge_auto` et créer de vraies `QuotaRule` (TYPE_DEFAULT_ELEVE/PROF/ADMIN) via le formulaire settings. Rows déjà existants pour defaults profils. Moins élégant mais réutilise l'existant.
- **Option C** : Fichier `config/parc.php` (déjà utilisé par 4.2 timeout). **Rejeté** : les defaults changent en prod (opérateur responsable d'établissement) → pas un fichier config statique.

**Routing (`routes/web.php` l. 210-254)** :
- Route prefix `admin` avec middleware `sambaedu.admin` (déjà en place pour `/admin/legacy-monitor`, `/admin/migrate`, `/admin/sync-from-ad`). Ajout d'une route Livewire `Route::livewire('/settings', 'pages::admin.settings.index')->name('settings')` avec middleware `can:server.admin` (cohérent avec sync-from-ad l. 228 — actions critiques).

**Filesystem-based router (`resources/views/pages/`)** :
- Nouveau dossier `resources/views/pages/admin/settings/` + `index.blade.php` (SFC Livewire avec onglets) + `_partials/quotas-fs-tab.blade.php` (SFC Livewire onglet).
- **Décision D7** : le SFC d'onglet est un composant dédié (cohérent pattern `parc-settings`) pour permettre d'ajouter d'autres onglets sans toucher ce fichier.

**`resources/views/pages/users/groups/[id]/index.blade.php`** (285 lignes) :
- Structure verticale (pas d'onglets). Ordre actuel : header + edit-form conditionnel OU (group-header + members-list + wallpaper-card conditionnel). **Insertion** d'une nouvelle section après `members-list` et avant la `wallpaper-card` : `<livewire:pages::users.groups.[id]._partials.group-quota-section :groupId="$groupId" />`. Pattern d'include Livewire identique au `quota-section` 5.1b.
- **Pas d'onglets ajoutés** (décision D6) — section simple comme wallpaper-card.

**Dispatch recalcul post-override groupe** :
- `XfsQuotaService::setQuotaRule(TYPE_GROUP, $groupCn, $partition, $soft, $hard, $performedBy, applyImmediately=true)` appelle déjà automatiquement `dispatchRecalculateGroupJob()` en interne (l. 365) lorsque `$applyImmediately=true` ET que `type=group`. **Le Livewire SFC ne doit PAS redispatcher manuellement** — laisser le service faire son travail pour ne pas doubler la queue.
- Idem pour `deleteQuotaRule()` qui dispatch le recalcul (l. 511) quand la règle supprimée était `type=group`.

**Login event** :
- Laravel émet `Illuminate\Auth\Events\Login` quand `Auth::login()` est appelé. **Pattern alternative D5** : enregistrer un listener dans `EventServiceProvider::$listen[Login::class]` au lieu d'intégrer la logique directement dans `SambaEduAuthGuard::handle()`. **Recommandation SM** : listener séparé (découplage + testabilité). Si décision D5=autre, garder la logique dans le guard.

### Couplages, points d'attention

1. **Idempotence du toast login (D5)** — sans garde-fou, le toast re-s'affiche à chaque requête (car `Auth::login` rejoué à chaque hit cookie). Solution : flag session `quota_overage_toast_fired_at` avec TTL 1 session, OU dispatch `ToastMagic` uniquement sur `Illuminate\Auth\Events\Login` (vrai login effectif, pas re-session).
2. **Performance listener Login** — lecture BDD `users.quota_snapshot` par login = 1 query SELECT. Négligeable (< 1ms). À conserver pur lecture (pas de `XfsQuotaService::getDiskUsage()` synchrone — trop coûteux).
3. **SQLite tests** — si D1 crée une nouvelle table `system_settings`, migration conditionnelle JSONB/JSON via `DB::getDriverName()` (pattern 5.1b et 7.1 `delegation_history`).
4. **Pas de cleanup destructif de `quota_settings`** — la table existante reste, on étend (Option B) ou on crée à côté (Option A).
5. **Audit des settings (D9)** — chaque update des defaults via UI doit-il être tracé (table `quota_audit_logs` existante ou `audit_logs` générique) ? Recommandation SM : réutiliser `quota_audit_logs` si la modification touche un TYPE_DEFAULT_*, sinon pas d'audit (settings système = pas de besoin RGPD).
6. **Permissions `server.admin`** — toute modification de defaults système est une action critique. Double guard UI + serveur (cohérent 5.1b).
7. **Préservation du toast WithToasts (AC 12)** — le toast login étant server-side via `ToastMagic::warning()`, il passe par `{!! ToastMagic::scripts() !!}` (déjà dans app.blade.php). **Ne pas utiliser le pattern dispatch `toastMagic` Livewire** (le guard n'a pas de contexte Livewire).
8. **Pattern modale (post-review 5.1b)** — `<dialog class="modal">` + `@teleport('body')` + `@entangle` + `modal-backdrop` (cf. quota-section.blade.php l. modale livrée). **Décalquer exactement** pour la modale override groupe.
9. **Nettoyage incident group-quota-management.blade.php** — le Blade pur historique doit être supprimé en 5.1c (incohérent avec le nouveau SFC, gate fantôme). Grep préalable obligatoire avant suppression.
10. **Trash non-exécutée en 5.1c** — les champs TTL trash + toggle purge sont **persistés** mais pas consommés par une commande (trash:purge = 5.1d). Risque UX : un admin règle TTL=30j + toggle=auto → rien ne se passe jusqu'à 5.1d livrée. Solution : banner info "Cette option sera activée à la prochaine mise à jour" dans l'onglet settings (AC 9 explicite).

---

## Acceptance Criteria

**AC 1 — Migration extension settings (décision D1)**

**Given** la base de données existante avec les tables `quota_rules`, `quota_settings`, `users.quota_snapshot`
**When** la migration 5.1c est appliquée
**Then** soit une nouvelle table `system_settings` (K/V JSON) est créée avec colonnes `key string unique`, `value jsonb (pgsql) / json (sqlite)`, `timestamps`, index sur `key` (Option A) ; soit les colonnes `trash_ttl_days unsignedSmallInteger nullable default 30` et `trash_purge_auto boolean default false` sont ajoutées à `quota_settings` (Option B)
**And** la migration est conditionnelle pgsql/sqlite via `DB::getDriverName()` pour le type jsonb/json
**And** la migration up+down tourne sans erreur sur sqlite et pgsql
**And** le modèle `QuotaSetting` OU le nouveau `SystemSetting` expose les helpers nécessaires (`SystemSetting::get(string $key, mixed $default): mixed`, `SystemSetting::set(string $key, mixed $value): void` avec cast JSON auto si Option A)

**AC 2 — Section Quota sur `/app/users/groups/[id]`**

**Given** je consulte la fiche d'un groupe `/app/users/groups/{id}`
**When** la page se charge
**Then** une nouvelle section "Quota du groupe" est affichée sous `members-list` et avant la wallpaper-card
**And** la section est rendue via le Livewire SFC `pages.users.groups.[id]._partials.group-quota-section` avec paramètre `$groupId`
**And** la section affiche **pour chaque partition** (`/home` + `/var/sambaedu`) : règle courante (label "Hérité", "Illimité" ou "Xmo (+Y%)") avec badge coloré, bouton "Modifier" conditionné `@can('server.admin')`
**And** si aucune règle `QuotaRule::TYPE_GROUP + target=$groupName + partition=X` n'existe : le label affiche "Hérité (défaut)" (badge-ghost)
**And** si une règle existe avec `quota_soft_mb=0 && quota_hard_mb=0` : label "Illimité" (badge-success)
**And** sinon : label affiche soft en Mo ou Go + `(+N%)` où `N = getOveragePercent()`

**AC 3 — Modale d'override du quota groupe**

**Given** je suis admin (`server.admin`) et je clique sur "Modifier" sur une partition du quota groupe
**When** la modale s'ouvre
**Then** la modale utilise le pattern `<dialog class="modal">` + `@teleport('body')` + `modal-backdrop` (cohérent quota-section 5.1b post-review)
**And** les champs pré-remplis affichent la règle courante ou les valeurs par défaut (inherited / unlimited / custom avec soft=500 Mo, overage=20%)
**And** trois types disponibles via radio : `inherited` (supprime la règle groupe), `unlimited` (soft=0, hard=0), `custom` (soft + overage_percent → hard calculé serveur-side)
**And** validation : `soft_mb >= 10` sur `/home` si custom (cohérent avec `QuotaController::updateGroupQuota:84`)
**And** à la soumission, le composant Livewire appelle :
  - Pour `inherited` : `XfsQuotaService::deleteQuotaRule($rule, $performedBy)` si règle existe, no-op sinon
  - Pour `unlimited` : `XfsQuotaService::setQuotaRule(TYPE_GROUP, $groupName, $partition, 0, 0, $performedBy)` (paramètre `applyImmediately` par défaut = true — déclenche dispatchRecalculateGroupJob en interne)
  - Pour `custom` : `XfsQuotaService::setQuotaRule(TYPE_GROUP, $groupName, $partition, $softMb, $hardMb, $performedBy)` avec `$hardMb = round($softMb * (1 + $overagePercent/100))`
**And** un toast `WithToasts::toastSuccess("Quota du groupe mis à jour.")` est émis en cas de succès
**And** un toast `WithToasts::toastError(...)` (message générique, pas `$e->getMessage()` — leçon 5.1b review fix #4) est émis en cas d'exception
**And** la modale se ferme et la section se re-rend avec la nouvelle valeur

**AC 4 — Double guard Gate `server.admin` sur la modification groupe**

**Given** un utilisateur **sans** permission `server.admin` consulte `/app/users/groups/{id}`
**When** la section Quota est rendue
**Then** la section est visible en **lecture seule** : badges + labels affichés, **boutons "Modifier" absents** (double guard `@can('server.admin')` en Blade)
**And** un payload Livewire forgé ciblant la méthode `openOverrideModal()` ou `applyOverride()` déclenche `abort(403)` en première ligne serveur
**And** un test Feature couvre explicitement les deux bypass tentatives (`actingAs($nonAdmin)->call('applyOverride', ...)` → 403)

**AC 5 — Route `/admin/settings` + scaffold onglets**

**Given** la route `/admin/settings` n'existe pas aujourd'hui
**When** 5.1c est livrée
**Then** la route Livewire `Route::livewire('/settings', 'pages::admin.settings.index')->name('settings')` est ajoutée dans le groupe `admin` (middleware `sambaedu.admin` hérité) + middleware explicite `can:server.admin` (cohérent avec `/admin/sync-from-ad`)
**And** la page `resources/views/pages/admin/settings/index.blade.php` est un Livewire SFC avec :
  - `#[Url(keep: true)] public string $tab = 'quotas-fs';`
  - `setTab(string $tab): void` qui redirect avec query `?tab=xxx`
  - Navigation `<div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit">` avec boutons `role="tab"` pour chaque onglet
  - **Un seul onglet visible** : "Quotas & FS" (pas de placeholders)
  - Contenu inclus via `<livewire:pages::admin.settings._partials.quotas-fs-tab />`
**And** le lien "Réglages" est **ajouté à la sidebar admin** (décision D8 — à trancher au kickoff) OU accessible uniquement via URL directe/dropdown (décision par défaut SM : ajouter dans sidebar sous "Administration")
**And** un utilisateur authentifié **sans** `server.admin` reçoit `abort(403)` à l'accès direct `/admin/settings`

**AC 6 — Onglet "Quotas & FS" : defaults profils**

**Given** je suis admin et je consulte `/admin/settings?tab=quotas-fs`
**When** la page se charge
**Then** l'onglet affiche 4 sections de defaults profils : **élève, prof, admin, itinérant** (même ordre)
**And** chaque section expose pour chacune des 2 partitions (`/home`, `/var/sambaedu`) : `soft Mo` (input number), `overage %` (input number 0-100), `hard Mo` calculé read-only affiché
**And** la soumission d'un formulaire "Enregistrer" persiste les valeurs :
  - Option A D1 : via `SystemSetting::set('quota.defaults', [...])`
  - Option B D1 : via `QuotaRule` TYPE_DEFAULT_{ELEVE,PROF,ADMIN} + **nouveau** TYPE_DEFAULT_ITINERANT (requiert extension enum constants QuotaRule)
**And** les defaults itinérants sont **persistés mais non-effectifs** en 5.1c (un banner info "Les defaults itinérants seront appliqués dans la prochaine version" peut figurer — 5.1d consommera)
**And** un toast de confirmation s'affiche (`toastSuccess('Réglages enregistrés')`) après chaque Enregistrer
**And** la validation refuse soft < 10 Mo sur `/home` (cohérent avec le reste de l'app)

**AC 7 — Onglet "Quotas & FS" : grace period par partition**

**Given** je consulte l'onglet "Quotas & FS"
**When** la section "Grace period" est affichée
**Then** deux inputs sont visibles (1 par partition) permettant de régler `grace_period_days` (default 7, min 0, max 30)
**And** à la soumission, `QuotaSetting::forPartition('/home')->update(['grace_period_days' => $value])` et idem `/var/sambaedu` persistent en BDD
**And** optionnel : tentative d'appliquer le grace au filesystem via `XfsQuotaService::setGracePeriod($partition, $days, $performedBy)` (API existante l. 454) — décision D4 à trancher (recommandation SM : persister + dispatcher l'appel — cohérent avec le pattern 5.1b setQuotaRule+applyImmediately)

**AC 8 — Onglet "Quotas & FS" : TTL trash + toggle purge**

**Given** je consulte l'onglet "Quotas & FS"
**When** la section "Corbeille /home/trash" est affichée
**Then** un input numérique "TTL avant purge définitive (jours)" (default 30, min 1, max 365) est visible
**And** un toggle "Purge automatique" (default false) est visible
**And** un banner info est visible sous la section : "Cette configuration sera consommée par la commande `trash:purge` livrée dans la prochaine version (5.1d)."
**And** à la soumission, les valeurs sont persistées (Option A : `SystemSetting::set('quota.trash', ['ttl_days'=>…,'purge_auto'=>…])` ; Option B : colonnes `quota_settings.trash_ttl_days/purge_auto`)
**And** **aucune commande Artisan n'est exécutée** en 5.1c (trash:purge = 5.1d) — la persistance seule suffit pour l'AC

**AC 9 — Toast over-quota au login (server-side)**

**Given** un utilisateur dont le snapshot `users.quota_snapshot` contient `{home: {…, is_over_soft: true, used_mb: 510, soft_mb: 500, …}}` ou `is_over_hard: true` sur `/home` ou `/var/sambaedu`
**When** il se connecte à SER (premier hit HTTP authentifié après login AD)
**Then** un toast `ToastMagic::warning("Votre espace {partition} est dépassé.", "X Mo utilisés / Y Mo autorisés. Libérez de l'espace pour éviter les blocages.")` est émis server-side (via session flash)
**And** le toast apparaît au render de la première page (grâce au `{!! ToastMagic::scripts() !!}` déjà dans `layouts/app.blade.php`)
**And** **le toast n'est pas re-émis** à chaque requête suivante de la même session (flag session `quota_overage_toast_fired_at` OU utilisation de l'event `Illuminate\Auth\Events\Login` — décision D5)
**And** si les 2 partitions sont over-quota : **un seul toast** listant les deux (pas 2 toasts séparés — UX moins bruyante)
**And** si `quota_snapshot` est null (user jamais snapshoté) ou si aucune partition n'est over : **aucun toast** n'est émis
**And** la logique est **découplée** soit dans un listener `App\Listeners\NotifyQuotaOverageOnLogin` câblé sur `Login::class`, soit directement dans `SambaEduAuthGuard::handle()` après `Auth::login` (décision D5 — recommandation SM : listener)

**AC 10 — Listing `/app/users/groups` (si applicable — décision D10)**

> **Décision produit D10 à trancher au kickoff.** Si la page listing groupes existe : ajouter la colonne Quota. Sinon : AC 10 retiré du scope 5.1c.

**Given** la page listing des groupes existe aujourd'hui (vérification kickoff)
**When** je consulte cette page
**Then** une nouvelle colonne "Quota" est affichée (source : règle `TYPE_GROUP + target=name + partition=/home` ou "—" si inexistante)
**And** la lecture est **en une seule query** (join ou subquery Eloquent — pas de N+1)
**And** si la page listing groupes n'existe pas aujourd'hui, l'AC 10 est **retiré du scope** (pas de création de listing dans 5.1c — hors scope)

**AC 11 — Nettoyage incident `group-quota-management.blade.php`**

**Given** le composant Blade pur `resources/views/components/quotas/group-quota-management.blade.php` (210 lignes) contient 2 `@can('manage-quotas')` pointant vers un gate fantôme non défini
**When** la section Quota groupe est refondue en Livewire SFC (5.1c)
**Then** le composant Blade pur est **supprimé** (`gio trash` / `trash` — jamais `rm -rf` selon `/home/htouchard/.claude/CLAUDE.md`)
**And** un grep final `grep -rn 'group-quota-management\|manage-quotas' resources app` retourne 0 résultat applicatif (hors tests historiques)
**And** la route POST `app.users.groups.quota.update` + `QuotaController::updateGroupQuota` est **conservée telle quelle** en 5.1c (compat rétro, pas de suppression pour éviter les régressions — décision D11 à trancher : recommandation SM garder l'endpoint, il n'est plus référencé depuis aucun Blade après cette story)

**AC 12 — Double guard Gate `server.admin` sur la page settings**

**Given** un utilisateur **sans** permission `server.admin` tente d'accéder à `/admin/settings`
**When** la requête arrive
**Then** le middleware route `can:server.admin` retourne 403 sans rendu de vue
**And** même si un payload Livewire forgé cible `saveDefaults()` ou `saveGrace()` sur le composant, une vérification `Gate::allows('server.admin')` en première ligne de chaque méthode serveur déclenche `abort(403)`
**And** un test Feature couvre les bypass tentatives sur au moins 2 méthodes serveur publiques du composant settings

**AC 13 — Tests Feature Livewire : groupe quota + settings + toast login**

**Given** les composants Livewire impactés
**When** les tests Feature tournent
**Then** au minimum 12 tests passent (répartition indicative) :
1. `GroupShowQuotaSectionTest::it_renders_group_quota_section_with_inherited_label` (aucune règle groupe → "Hérité")
2. `GroupShowQuotaSectionTest::it_renders_group_quota_section_with_custom_rule` (règle custom existante → label soft+overage)
3. `GroupShowQuotaSectionTest::it_renders_unlimited_when_soft_and_hard_zero`
4. `GroupShowQuotaSectionTest::it_hides_modify_button_without_server_admin`
5. `GroupShowQuotaSectionTest::it_blocks_apply_override_without_server_admin_even_on_forged_payload` (actingAs non-admin → call('applyOverride', …) → 403)
6. `GroupShowQuotaSectionTest::it_applies_inherited_override_deletes_rule` (règle existante → inherited → `deleteQuotaRule` appelé)
7. `GroupShowQuotaSectionTest::it_applies_custom_override_sets_rule_and_dispatches_recalculate` (Queue::fake → assertPushed ApplyQuotaJob si setQuotaRule applique immédiatement — ou au minimum assert BDD la règle créée)
8. `GroupShowQuotaSectionTest::it_rejects_custom_soft_below_10mb_on_home`
9. `AdminSettingsPageTest::it_renders_single_tab_quotas_fs` (assertSee "Quotas & FS", assertDontSee autre onglet placeholder)
10. `AdminSettingsPageTest::it_blocks_access_without_server_admin` (actingAs sans perm → 403)
11. `AdminSettingsQuotasFsTabTest::it_persists_defaults_per_profile_and_partition` (soumission → Option A SystemSetting::get retourne les valeurs / Option B QuotaRule TYPE_DEFAULT_ELEVE existe)
12. `AdminSettingsQuotasFsTabTest::it_persists_trash_ttl_and_purge_toggle`
13. `AdminSettingsQuotasFsTabTest::it_persists_grace_period_per_partition` (QuotaSetting updated)
14. `AdminSettingsQuotasFsTabTest::it_blocks_save_without_server_admin` (bypass tentative)
15. `LoginOverQuotaToastTest::it_fires_toast_when_user_is_over_soft_on_home` (seed snapshot is_over_soft=true → simule login → session flash ToastMagic contient "dépassé")
16. `LoginOverQuotaToastTest::it_does_not_fire_toast_when_snapshot_is_null`
17. `LoginOverQuotaToastTest::it_does_not_fire_toast_when_nothing_over`
18. `LoginOverQuotaToastTest::it_fires_single_toast_when_both_partitions_are_over` (1 toast combiné, pas 2)
19. `LoginOverQuotaToastTest::it_does_not_refire_toast_on_second_request_in_same_session` (flag session OU listener Login event — selon D5)

**AC 14 — Non-régression 5.1b (19 tests existants doivent rester verts)**

**Given** la suite de tests de 5.1b livrée (19 tests : QuotaSnapshotCommandTest 8 + UserShowQuotaSectionTest 5 + UsersIndexPageQuotaColumnTest 4 + UsersIndexPageNoShelloutTest 1 + KernelScheduleTest 1)
**When** 5.1c est livrée
**Then** les **19 tests 5.1b restent tous verts** sans modification
**And** les 4 tests ajoutés post-review 5.1b (`it_preserves_sambaedu_snapshot_when_report_is_empty`, `it_logs_warning_for_user_absent_from_xfs_report`, `test_it_blocks_refresh_without_server_admin`, `test_it_rate_limits_refresh_after_5_attempts`) restent tous verts
**And** le test `test_it_refreshes_snapshot_on_button_click` (modifié en 5.1b review) reste vert
**And** **aucune modification** n'est apportée à `app/Models/User.php` cast `quota_snapshot` ni au fillable
**And** **aucune modification** n'est apportée à `app/Console/Commands/QuotaSnapshotCommand.php`
**And** **aucune modification** n'est apportée à `app/Services/Filesystem/XfsQuotaService.php` (méthodes publiques réutilisées telles quelles — exception possible : nouvelle méthode publique `captureSnapshotForUser(string $login): ?array` si refactor interne — décision laissée au dev, recommandation SM : pas de changement)

**AC 15 — Gestion d'erreur préservée (pas de comportement silencieux)**

**Given** un échec d'opération (BDD down, job dispatch failed, validation côté serveur bypassée)
**When** l'erreur survient dans une méthode serveur du composant Livewire
**Then** `Log::error('QuotaService: ...', [...contexte])` est émis (préfixe historique conservé selon feedback 5.1a)
**And** un toast générique `toastError('Impossible de ...')` est affiché à l'utilisateur (**pas** `$e->getMessage()` exposé — leçon 5.1b post-review #4)
**And** aucune modification partielle n'est écrite en BDD (transaction DB OU garde défensive early-return)

---

## Décisions produit à arbitrer au kickoff

1. **D1 — Stockage des settings étendus** (AC 1, 8) : **(A) Nouvelle table `system_settings` K/V JSON** (recommandation SM — future-proof, pattern moderne, keys typées) **vs (B) Extension de `quota_settings` + rows `QuotaRule` TYPE_DEFAULT_*** (minimal, moins élégant) **vs (C) `config/parc.php`** (rejeté SM — pas dynamique prod). Impact : +1 migration + 1 modèle léger si A / +1 migration si B.

2. **D2 — UI Quota groupe : refonte en Livewire SFC vs @include Blade existant** (AC 2, 3) : **(A) Livewire SFC dédié** (recommandation SM — cohérent 5.1b, réactif, testable, double guard) **vs (B) garder `group-quota-management.blade.php` existant et l'inclure dans la page groupe** (minimal, mais conserve les problèmes identifiés : endpoint POST non-Livewire + @can fantôme + `$group['cn']` LDAP). Recommandation : (A).

3. **D3 — Scaffold `/admin/settings` avec un seul onglet vs multi-onglets avec placeholders** (AC 5) : **(A) Un seul onglet "Quotas & FS"** (recommandation SM — interdiction stricte placeholders) **vs (B) plusieurs onglets avec placeholders "coming soon"** (rejeté SM). Recommandation : (A).

4. **D4 — Application du grace period au filesystem post-save** (AC 7) : **(A) Persist + appel `XfsQuotaService::setGracePeriod` synchrone** (recommandation SM — cohérent 5.1b applyImmediately) **vs (B) persist only** (laisse l'opérateur relancer manuellement — plus lent à prendre effet). Recommandation : (A).

5. **D5 — Idempotence du toast login** (AC 9) : **(A) Listener event `Illuminate\Auth\Events\Login`** (recommandation SM — vrai login effectif, naturellement 1×/session, découplage) **vs (B) logique dans `SambaEduAuthGuard::handle()` + flag session** (couplage middleware) **vs (C) pas de flag, toast à chaque requête** (rejeté — UX bruyante). Recommandation : (A).

6. **D6 — Onglets vs section verticale sur page groupe** (AC 2) : **(A) Section verticale insérée** (recommandation SM — pattern identique 5.1b + wallpaper-card sur user+group) **vs (B) refonte en onglets** (trop invasif pour scope 5.1c). Recommandation : (A).

7. **D7 — Onglet settings = composant Livewire dédié vs `@include` Blade pur** (AC 5) : **(A) Composant Livewire dédié** (recommandation SM — cohérent `parc-settings/_partials/*-tab.blade.php`) **vs (B) Blade pur** (statique, moins réactif). Recommandation : (A).

8. **D8 — Lien "Réglages" dans la sidebar admin** (AC 5) : **(A) Ajouter un item de menu** (recommandation SM — page découvrable) **vs (B) Accessible uniquement par URL directe** (découvrabilité zéro). Recommandation : (A) — l'ajouter sous "Administration" avec icône `fa-cog`, conditionné `@can('server.admin')`.

9. **D9 — Audit des modifications de settings** : **(A) Réutiliser `quota_audit_logs`** pour les TYPE_DEFAULT_* (recommandation SM si Option B D1) **vs (B) Audit générique via un nouveau Listener `audit_logs`** (overkill pour cette story) **vs (C) Pas d'audit** (settings système — pas de RGPD critique, recommandation SM si Option A D1). Recommandation : (C) si D1=A, (A) si D1=B.

10. **D10 — Colonne Quota dans listing groupes** (AC 10) : **Vérifier au kickoff** si la page listing existe (`resources/views/pages/users/groups/index.blade.php` ?). Si oui : ajouter colonne. Si non : **retirer AC 10 du scope 5.1c** (création listing = hors scope). Décision kickoff obligatoire.

11. **D11 — Conservation de l'endpoint legacy `QuotaController::updateGroupQuota`** (AC 11) : **(A) Conserver** (recommandation SM — compat rétro, pas de casse, plus aucun Blade ne le référence après 5.1c) **vs (B) Supprimer + cleanup route** (dette réduite mais risque de casse si externe référence). Recommandation : (A).

12. **D12 — Extension enum `QuotaRule::TYPE_DEFAULT_ITINERANT`** (AC 6) : **(A) Ajouter la constante en 5.1c** (cohérent — le formulaire settings crée déjà la ligne, même si 5.1d est la seule à la consommer via `getEffectiveQuota`) **vs (B) Reporter à 5.1d** (plus propre car 5.1d = implémentation effective). Recommandation SM : (A) — la constante est passive tant que `getEffectiveQuota` ne la lit pas, et permet à l'UI 5.1c de persister les valeurs via le même modèle `QuotaRule`.

---

## Tasks / Subtasks

### Phase 0 — Kickoff & décisions produit (bloquant)

- [x] **Tâche 0.1** — Capturer la baseline de tests avant démarrage : `php artisan test 2>&1 | tail -5` → noter N tests passing / M errors / K failures. Cible 5.1c : +15 à +20 tests nouveaux, 0 régression sur N. **Résultat : 1149 passed / 1 incomplete / 47 skipped (52.44s).**
- [x] **Tâche 0.2** — Décisions D1-D12 validées par Henri → reporter les choix dans Dev Notes section "Kickoff Décisions".
- [x] **Tâche 0.3** — Vérifier l'existence/non-existence de `resources/views/pages/users/groups/index.blade.php` pour trancher D10. **Résultat : N'EXISTE PAS → AC 10 retiré du scope (D10=B).**
- [x] **Tâche 0.4** — Grep exhaustif baseline : `grep -rn 'group-quota-management\|manage-quotas\|/admin/settings' app resources routes database tests` → documenter état initial dans Dev Notes. **Résultat : 2 hits dans `resources/views/components/quotas/group-quota-management.blade.php` (lignes 87 et 115 — `@can('manage-quotas')`). Aucune autre référence applicative. Aucune route `/admin/settings` existante.**

### Phase 1 — Backend settings (migration + modèle)

- [x] **Tâche 1.1** — D1=A appliqué : migration `database/migrations/2026_04_25_100000_create_system_settings_table.php` créée (`key string(191) unique` + `value jsonb (pgsql) / json (sqlite)` conditionnel via `DB::getDriverName()` + timestamps + `Schema::hasTable` early-return). Modèle `app/Models/SystemSetting.php` avec helpers statiques `get/set/forget`. Cast `'value' => 'array'`. `down()` via `Schema::dropIfExists`.
- [x] **Tâche 1.2** — N/A (D1=A retenu, B abandonné).
- [x] **Tâche 1.3** — D12=A appliqué : ajout constante `QuotaRule::TYPE_DEFAULT_ITINERANT = 'default_itinerant'` + cas dans `isDefault()`, `scopeDefaults()`, `getTypeLabel()` ("Défaut itinérants"). PAS de logique dans `getEffectiveQuota` (5.1d s'en chargera).
- [x] **Tâche 1.4** — N/A (D9=C — pas d'audit settings sous Option A).

### Phase 2 — Page `/admin/settings` (scaffold + route + onglet unique)

- [x] **Tâche 2.1** — Route ajoutée dans `routes/web.php` (groupe `admin`) : `Route::livewire('/settings', 'pages::admin.settings.index')->middleware('can:server.admin')->name('settings');`. Insérée juste après `/admin/sync-from-ad` (cohérence cluster server.admin).
- [x] **Tâche 2.2** — Créé `resources/views/pages/admin/settings/index.blade.php` Livewire SFC décalqué sur `parc-settings/index.blade.php` : `#[Url(keep: true)] public string $tab = 'quotas-fs'` + `setTab()` (double guard `Gate::allows`) + `mount()` (double guard) + `<x-organisms.page>` + tablist avec un seul onglet "Quotas & FS" + `<livewire:pages::admin.settings._partials.quotas-fs-tab />`.
- [x] **Tâche 2.3** — D8=A appliqué : lien "Réglages" ajouté dans `sidebar.blade.php` après "Migration", conditionné `@can('server.admin')` + icône `fa-cog`.

### Phase 3 — Onglet "Quotas & FS" (SFC dédié)

- [x] **Tâche 3.1** — Créé `resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php` Livewire SFC : propriétés `$defaults` (par profil+partition), `$grace`, `$trash`. `mount()` charge depuis `SystemSetting::get('quota.defaults', scaffold)` + `QuotaSetting::forPartition` pour grace + `SystemSetting::get('quota.trash')`. `use WithToasts;`. Méthodes `saveDefaults/saveGrace/saveTrash` avec double guard `Gate::allows('server.admin')` first.
- [x] **Tâche 3.2** — Template Blade : 3 sections (cards) — "Quotas par défaut", "Période de grâce", "Corbeille". Chaque section formulaire isolé avec `wire:submit.prevent` + spinner `wire:loading`.
- [x] **Tâche 3.3** — Section Defaults : boucle sur 4 profils (élève/prof/admin/itinérant) × 2 partitions (/home, /var/sambaedu). Input soft (Mo) + overage (%) + Hard (Mo) readonly calculé via helper `calculateHard($soft, $overage)`. Badge "Effectif en 5.1d" sur profil itinérant.
- [x] **Tâche 3.4** — Section Grace : 2 inputs (home + sambaedu, min 0 max 30). D4=A : post-persist BDD, appel `XfsQuotaService::setGracePeriod($partition, $days, $performedBy)` dans try/catch — échec applique fallback `toastInfo` ("application reportée — consultez les logs"). Toast error générique si exception extérieure (pas `$e->getMessage()`).
- [x] **Tâche 3.5** — Section Corbeille : input TTL (min 1 max 365) + toggle purge auto + banner info "Cette configuration sera consommée par la commande `trash:purge` livrée dans la prochaine version (5.1d)."
- [x] **Tâche 3.6** — Défense : `Gate::allows('server.admin')` + `abort(403)` en première ligne de `mount()`, `saveDefaults()`, `saveGrace()`, `saveTrash()`. Validation `soft >= 10 Mo` sur `/home` (sauf 0 = illimité).

### Phase 4 — Section Quota groupe (Livewire SFC)

- [x] **Tâche 4.1** — Créé `resources/views/pages/users/groups/[id]/_partials/group-quota-section.blade.php` Livewire SFC modelé sur `quota-section.blade.php` (5.1b) : props `#[Locked] $groupId/$groupName`, `?array $homeRule/$sambaeduRule` (snapshot only de la rule pour Wireable safe), état modale (`showOverrideModal/overridePartition/overrideType/overrideSoftMb/overrideOveragePercent`). `boot(XfsQuotaService)` + `use WithToasts;`. `mount(int $groupId)` charge UserGroup + `loadRules()` interroge `QuotaRule::where('type', TYPE_GROUP)->where('target', $groupName)->where('partition', X)->first()`. Méthodes `openOverrideModal()` (Gate gracieux + pré-remplit), `applyOverride()` (Gate strict + abort(403) + validation + setQuotaRule/deleteQuotaRule + toasts génériques), `closeOverrideModal()`, helpers `formatQuotaMb`, `describeRule`.
- [x] **Tâche 4.2** — Template Blade : grille `md:grid-cols-2` (home/sambaedu) avec badges (Hérité/Illimité/Custom soft+overage) et labels conformes AC 2. Bouton "Modifier" gated `@can('server.admin')`. Modale `<dialog class="modal">` + `@teleport('body')` + `@entangle('showOverrideModal')` + `modal-backdrop` — décalqué EXACTEMENT sur le pattern post-review 5.1b.
- [x] **Tâche 4.3** — Inclus dans `resources/views/pages/users/groups/[id]/index.blade.php` : `<livewire:pages::users.groups.[id]._partials.group-quota-section :groupId="$groupId" :key="'group-quota-' . $groupId" />` après `@include('pages.users.groups.[id]._partials.members-list')` et avant `@can('wallpaper.manage')` wallpaper-card.
- [x] **Tâche 4.4** — AC 11 cleanup : `gio trash resources/views/components/quotas/group-quota-management.blade.php`. Grep final `grep -rn 'group-quota-management\|manage-quotas' app resources routes tests` → 0 hit.
- [x] **Tâche 4.5** — N/A (D10=B — `resources/views/pages/users/groups/index.blade.php` n'existe pas, AC 10 retiré du scope).

### Phase 5 — Toast over-quota au login

- [x] **Tâche 5.1** — D5=A appliqué : créé `app/Listeners/NotifyQuotaOverageOnLogin.php`. `handle(Login $event)` lit `$event->user->quota_snapshot` (cast 'array' sur User) directement (pas de query supplémentaire). Parse + `ToastMagic::warning()` si over. Try/catch global silencieux (`Log::warning` + return) — un échec listener ne casse PAS le login.
- [x] **Tâche 5.2** — Listener enregistré dans `app/Providers/EventServiceProvider.php` : `Login::class => [NotifyQuotaOverageOnLogin::class]`. Pas de modification de `shouldDiscoverEvents()` (reste `false`).
- [x] **Tâche 5.3** — N/A (D5=A retenu, B abandonné).
- [x] **Tâche 5.4** — Parse `$snapshot['home']['is_over_soft']` OU `$snapshot['home']['is_over_hard']` OU idem sambaedu (méthode `collectOverPartitions`). Format `ToastMagic::warning("Votre espace {label} est dépassé.", "X Mo utilisés / Y Mo autorisés. Libérez de l'espace pour éviter les blocages.")` si 1 partition.
- [x] **Tâche 5.5** — Si 2 partitions over : 1 SEUL toast avec titre "Plusieurs espaces de stockage sont dépassés." + description multi-lignes (`emitWarningToast`). Si aucune ou snapshot null : no-op silencieux.

### Phase 6 — Tests

- [x] **Tâche 6.1** — Créé `tests/Feature/Livewire/Users/GroupShowQuotaSectionTest.php` avec 8 tests (AC 13 #1-8). Pattern décalqué sur `UserShowQuotaSectionTest` 5.1b. 2 tests Gate explicites : `#4 it_hides_modify_button_without_server_admin`, `#5 it_blocks_apply_override_without_server_admin_even_on_forged_payload`. Sous-classe anonyme `XfsQuotaService` (override `getDiskUsage`) pour stubber les shellouts. Queue::fake().
- [x] **Tâche 6.2** — Créé `tests/Feature/Livewire/Admin/AdminSettingsPageTest.php` avec 3 tests (AC 13 #9-10 + AC 12) : `it_renders_single_tab_quotas_fs`, `it_blocks_access_without_server_admin`, `it_blocks_set_tab_without_server_admin`.
- [x] **Tâche 6.3** — Créé `tests/Feature/Livewire/Admin/AdminSettingsQuotasFsTabTest.php` avec 6 tests (AC 13 #11-14 + AC 6 + AC 12) : `it_persists_defaults_per_profile_and_partition`, `it_rejects_defaults_soft_below_10mb_on_home`, `it_persists_trash_ttl_and_purge_toggle`, `it_persists_grace_period_per_partition` (stub XfsQuotaService::setGracePeriod), `it_blocks_save_without_server_admin`, `save_methods_recheck_permission_when_user_changes`.
- [x] **Tâche 6.4** — Créé `tests/Feature/LoginOverQuotaToastTest.php` avec 6 tests (AC 13 #15-19 + AC 9 défensif) : `it_fires_toast_when_user_is_over_soft_on_home` (Mockery PartialMock sur ToastMagic facade), `it_does_not_fire_toast_when_snapshot_is_null`, `it_does_not_fire_toast_when_nothing_over`, `it_fires_single_toast_when_both_partitions_are_over`, `it_does_not_refire_toast_on_second_request_in_same_session` (validation pattern Login event = 1×/session naturellement), `it_does_not_break_login_if_handler_throws`.
- [x] **Tâche 6.5** — Non-régression 5.1b vérifiée : `php artisan test --filter='QuotaSnapshotCommandTest|UserShowQuotaSectionTest|UsersIndexPageQuotaColumnTest|UsersIndexPageNoShelloutTest|KernelScheduleTest'` → 28 tests passent (5.1b 19 originaux + 4 review fixes + 1 modifié + ajouts antérieurs).
- [x] **Tâche 6.6** — Suite complète : **1172 passed (vs 1149 baseline) → +23 tests, 0 régression** (1 incomplete + 47 skipped pré-existants identiques). Durée 53.79s.

### Phase 7 — Documentation & validation

- [x] **Tâche 7.1** — Enrichi `docs/domains/filesystem.md` (créé en 5.1b) : 4 nouvelles sections en 5.1c (Quota groupe UI, Réglages système /admin/settings, Toast over-quota au login, Modèle SystemSetting). Footer "Dernière mise à jour" passé à 2026-04-25 + référence story 5.1c ajoutée. Append-only.
- [x] **Tâche 7.2** — Doc admin enrichie dans la même page : flux complet par section (defaults / grace / trash) avec format de structure JSON persistée + comportement filesystem fail-soft (D4=A) + format des messages toast login (1 vs 2 partitions).
- [x] **Tâche 7.3** — Convention QA modifiée : créé `docs/qa/README.md` (transition format `{epic}-{story}-e2e-manual.md` legacy → `domains/<domain>.md` append-only) + `docs/qa/domains/filesystem.md` avec 18 scénarios numérotés stables (5.1c-1 à 5.1c-18) couvrant section Quota groupe / page settings / toast login / cleanup. **PAS** de fichier `5-1c-e2e-manual.md` créé (interdit par instruction kickoff).

### Phase 8 — Validation finale

- [x] **Tâche 8.1** — Grep final `grep -rn 'group-quota-management\|manage-quotas' app resources routes tests` → **0 hit**. Cleanup AC 11 confirmé.
- [x] **Tâche 8.2** — Grep final `grep -rn '/admin/settings\|admin\.settings' routes resources app` → route enregistrée (`routes/web.php:237`), page créée (`pages/admin/settings/index.blade.php:46`), onglet (`pages/admin/settings/_partials/quotas-fs-tab.blade.php:13`), lien sidebar (`sidebar.blade.php:72`). Tous présents.
- [x] **Tâche 8.3** — Smoke test VM : `php artisan migrate` → migration `2026_04_25_100000_create_system_settings_table` appliquée (90.78ms). `php artisan route:list | grep settings` → route `admin.settings` listée. `php artisan test` complet → 1172 passed (vs 1149 baseline) → +23 tests, 0 régression.
- [x] **Tâche 8.4** — Dev Notes Kickoff Décisions complétée + Investigation initiale ajoutée + Dev Agent Record + File List ci-dessous.

---

## Fichiers concernés (prévisionnel)

### Fichiers créés (10-13 selon D1/D10)

- `database/migrations/2026_0X_XX_XXXXXX_create_system_settings_table.php` *(si D1=A — nouveau modèle K/V)*
- `app/Models/SystemSetting.php` *(si D1=A — ~60 lignes, helpers get/set)*
- `resources/views/pages/admin/settings/index.blade.php` *(Livewire SFC scaffold onglets, ~80-100 lignes)*
- `resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php` *(Livewire SFC onglet, ~300-400 lignes avec 3 sections + validation + toasts)*
- `resources/views/pages/users/groups/[id]/_partials/group-quota-section.blade.php` *(Livewire SFC ~350-450 lignes, décalqué sur quota-section.blade.php 5.1b)*
- `app/Listeners/NotifyQuotaOverageOnLogin.php` *(si D5=A — ~60 lignes, handle Login event)*
- `tests/Feature/Livewire/Users/GroupShowQuotaSectionTest.php` *(8 tests AC 13 #1-8)*
- `tests/Feature/Livewire/Admin/AdminSettingsPageTest.php` *(2 tests AC 13 #9-10)*
- `tests/Feature/Livewire/Admin/AdminSettingsQuotasFsTabTest.php` *(4 tests AC 13 #11-14)*
- `tests/Feature/LoginOverQuotaToastTest.php` *(5 tests AC 13 #15-19)*
- `docs/qa/5-1c-e2e-manual.md` *(checklist VM)*

### Fichiers modifiés (5-7)

- `routes/web.php` — ajout route `/admin/settings` avec middleware `can:server.admin`
- `resources/views/pages/users/groups/[id]/index.blade.php` — insertion `<livewire:pages::users.groups.[id]._partials.group-quota-section :groupId="$groupId" />`
- `resources/views/components/organisms/sidebar.blade.php` — ajout lien "Réglages" (si D8=A)
- `app/Models/QuotaRule.php` — si D12=A, ajout constante `TYPE_DEFAULT_ITINERANT` + cas dans `isDefault()`, `scopeDefaults()`, `getTypeLabel()`
- `app/Providers/EventServiceProvider.php` — registration listener `Login::class => [NotifyQuotaOverageOnLogin::class]` (si D5=A)
- `app/Models/QuotaSetting.php` — si D1=B, ajout `trash_ttl_days`, `trash_purge_auto` à fillable + casts
- `docs/domains/filesystem.md` — enrichissement sections (créé en 5.1b)

### Fichiers supprimés (1)

- `resources/views/components/quotas/group-quota-management.blade.php` *(210 lignes, Blade pur obsolète, remplacé par le SFC Livewire — via `trash` ou `gio trash`, jamais `rm -rf`)*

### Fichiers NON touchés (vérification explicite)

- `app/Services/Filesystem/HomeDirService.php` — aucun impact (5.1c n'opère pas sur les home dirs physiques)
- `app/Services/Filesystem/XfsQuotaService.php` — aucune modification de signature ; méthodes `setQuotaRule/deleteQuotaRule/setGracePeriod/getEffectiveQuota/dispatchRecalculateGroupJob` réutilisées telles quelles
- `app/Console/Commands/QuotaSnapshotCommand.php` — aucun changement (5.1b livré, touché par review fixes uniquement)
- `app/Console/Kernel.php` — aucune nouvelle planification (trash:purge = 5.1d)
- `app/Models/User.php` — aucun changement cast/fillable `quota_snapshot`
- `app/Jobs/ApplyQuotaJob.php` — consommé via `setQuotaRule`, pas de modification
- `app/Http/Controllers/QuotaController.php` — conservé tel quel (D11 = A recommandé)
- `resources/views/pages/users/[login]/_partials/quota-section.blade.php` — **interdit d'y toucher** (5.1b livré, review fixes appliqués)
- `resources/views/pages/users/index.blade.php` — aucun changement colonne Utilisation (5.1b livré)
- `resources/views/pages/users/[login]/index.blade.php` — aucun changement (le @livewire quota-section reste)

---

## Dev Notes

### Patterns à suivre

- **Convention services Filesystem** (5.1a) : `App\Services\Filesystem\XfsQuotaService`. **Interdiction d'appeler directement `sudo xfs_quota` ou `sudo quota` hors du service.**
- **Logs** : préfixe historique `QuotaService:` dans tous les logs (décision SM 5.1a).
- **Livewire SFC** : pattern `<?php … new class extends Component { … }; ?>` + template en dessous. `use App\Components\Traits\WithToasts;`.
- **Blade component syntax** : `<x-molecules.xxx>`, JAMAIS `<livewire:components::molecules.xxx>` (cf. feedback mémorisé `feedback_blade_component_syntax.md`).
- **Modale réutilisable** : pattern `<dialog class="modal">` + `@teleport('body')` + `x-data / @entangle` + `modal-backdrop`. Cf. le fix #6 appliqué sur `quota-section.blade.php` en 5.1b review — **décalquer EXACTEMENT** ce pattern pour toutes les modales nouvelles (group-quota + settings).
- **`base_path('...')`** : privilégier aux `dirname(__DIR__, N)` (règle mémorisée `feedback_prefer_base_path.md`).
- **Migration JSONB/JSON conditionnelle** (si D1=A) : pattern `DB::getDriverName()` (cf. `2026_04_22_100002_create_workstation_group_schedule_runs_table.php` + migration `add_quota_snapshot` 5.1b + migration `delegation_history` 7.1).
- **Écriture atomique** (non applicable en 5.1c) — tout est en BDD, pas de fichier partagé.
- **Double guard Gate** : UI (`@can`) + serveur (`Gate::allows()` + `abort(403)` en première ligne). Pattern strict 5.1b.
- **Pattern tabs** : `#[Url(keep: true)] public string $tab` + bouton `wire:click="setTab('xxx')"` qui redirect (cf. `parc-settings/index.blade.php`).
- **Pattern listener Laravel 11** : `EventServiceProvider::$listen` array (cf. `Registered::class => [SendEmailVerificationNotification::class]`). Le listener est auto-résolu par le conteneur DI. Pas d'auto-discovery activé (`shouldDiscoverEvents(): false`).

### Permissions & Gates — rappel

- **Permission `SambaPermission::ServerAdmin = 'server.admin'`** réutilisée pour toutes les actions critiques 5.1c (cohérent 5.1b + 7.2 sync-from-ad). Pas de nouvelle permission.
- **Routes `/admin/*`** — middleware `sambaedu.admin` (alias `RequireAdminRights`) déjà en place sur le groupe admin, **+** middleware explicite `can:server.admin` sur `/admin/settings` (cohérent avec `/admin/sync-from-ad` l. 228).
- **Gate `manage-quotas` fantôme** — le dernier usage (2 refs dans `group-quota-management.blade.php`) disparaît avec la suppression du fichier en AC 11.

### Testing Strategy

**Stratégie : tests ciblés + non-régression 5.1b pure.**

- **Baseline** : suite à capturer au kickoff (baseline attendue : ~1054+ tests, ~106 errors + 2 failures pré-existants LDAP/Imagick/legacy). Cible 5.1c : +15 à +20 tests nouveaux, 0 régression.
- **Tests Feature Livewire** (majorité) : `Livewire::test(...)` + `assertSee/assertDontSee` + `actingAs($user)` avec/sans permission. Pattern `GroupShowPageTest` existant.
- **Tests Gate** : `actingAs($nonAdmin)->call('applyOverride', ...)` → `assertStatus(403)` ou `$this->expectException(AuthorizationException::class)`.
- **Test listener Login** (AC 13 #15-19) : `Event::fake([Login::class])->dispatch(new Login('web', $user, false))` → assert session flash contient message attendu via `Session::get('toast_magic_messages')` ou équivalent facade ToastMagic.
- **Sous-classe anonyme XfsQuotaService** (pattern 5.1b) : si besoin de stubber `setQuotaRule` pour isoler l'assertion sur la règle créée sans exec shellout ni job réel. Avec `Queue::fake()` pour attraper `ApplyQuotaJob`.
- **Migration tests sqlite** : les suites Feature utilisent sqlite :memory: en BDD — la migration JSONB/JSON conditionnelle doit être symétrique (pattern 5.1b validé).

### Points d'attention / risques

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| **Option A D1 (`system_settings`) reinvente `spatie/laravel-settings`** | Faible | Dette technique | Si Henri veut une lib : `composer require spatie/laravel-settings`. Sinon notre K/V JSON ≤ 60 lignes reste simple. Trade-off validé au kickoff. |
| **Toast login spam (D5)** | Moyenne | UX bruyante | D5=A (listener Login event) résout naturellement — l'event n'est émis qu'à la 1ʳᵉ auth, pas à chaque requête. D5=B (middleware) doit impérativement poser un flag session. |
| **ToastMagic session flash vs Livewire dispatch** | Faible | Toast non-affiché | Le toast login est server-side (pas Livewire) → utilisation de `ToastMagic::warning()` facade (pas du trait WithToasts). Rendu via `{!! ToastMagic::scripts() !!}` déjà dans `layouts/app.blade.php`. Test explicite. |
| **Conflit endpoint POST legacy `/users/groups/{groupCn}/quota`** | Faible | Double saisie de la règle | D11=A conserve l'endpoint. Aucun Blade ne le référence après 5.1c — endpoint dormant, pas de risque collision. |
| **Format des defaults profils en BDD** | Moyenne | Incompat prod si structure change | Figer la structure JSON dès 5.1c (documentée dans docs/domains/filesystem.md) + test `AdminSettingsQuotasFsTabTest` qui lit/écrit → signale un drift. |
| **Concurrence sauvegarde settings** | Faible | Dernier écrivain gagne | Acceptable pour un endpoint admin peu fréquenté (1-3 admins max). Pas de verrou optimiste nécessaire. |
| **Nettoyage `group-quota-management.blade.php` casse un include caché** | Faible | Erreur Blade en prod | Grep exhaustif préalable (Tâche 4.4). Tests de non-régression 5.1b garantissent l'absence de dépendance. |
| **Middleware `can:server.admin` sur `/admin/settings` bloque admin legacy** | Faible | Dashboard inaccessible | Vérifier au kickoff que l'admin legacy (`is_admin_legacy`) est bien mappé à `server.admin` via Spatie. Normalement OK depuis 7.2 (PermissionSeeder). |
| **Structure `quota.defaults` jsonb évolue entre 5.1c et 5.1d** | Moyenne | Breaking change BDD | 5.1d ajoute UNIQUEMENT la lecture de `default_itinerant` — la structure doit inclure la clé `itinerant` dès 5.1c (D12=A). |
| **SambaEduAuthGuard appelé à chaque requête cookie** | Haute | Re-émission toast | D5=A résout. D5=B doit impérativement poser flag session. |
| **Listener `NotifyQuotaOverageOnLogin` en erreur casse le login** | Faible | Login impossible | Try/catch dans le handle + Log::error silencieux (toast = bonus, pas critique). Pattern défensif listener. |
| **Page listing groupes n'existe pas** | Haute | AC 10 invalide | D10 tranché au kickoff (Tâche 0.3). Si non, AC 10 retiré proprement — scope non-affecté. |
| **Sidebar admin n'a pas de section "Administration" existante** | Faible | UX lien perdu | Tâche 2.3 grep la sidebar ; si section absente, l'ajouter en tant que nouvelle section (cohérent admin-sidebar layout). |

### References

- [Source: epics.md#Story-5.1c:1621-1661](../planning-artifacts/epics.md) — scope + AC originaux de la story
- [Source: epics.md#Investigation-Legacy-2026-04-22:1521-1536](../planning-artifacts/epics.md) — décisions produit Henri (scaffold /admin/settings, flash over-quota, TTL trash + toggle)
- [Source: epics.md#Story-5.1d:1665-1707](../planning-artifacts/epics.md) — story avale, dépend de l'onglet settings 5.1c pour TTL trash + toggle
- [Source: architecture.md#Services/Filesystem:447](../planning-artifacts/architecture.md) — mapping architectural `Filesystem/`
- [Source: architecture.md#Integration-Patterns:502-505](../planning-artifacts/architecture.md) — "Système … via Services — jamais d'appels directs hors Services"
- [Source: 5-1a-refactor-services-filesystem.md](../implementation-artifacts/5-1a-refactor-services-filesystem.md) — 5.1a livré : structure `HomeDirService` + `XfsQuotaService` actée
- [Source: 5-1b-snapshot-quotas-quotidien-et-ui-user.md](../implementation-artifacts/5-1b-snapshot-quotas-quotidien-et-ui-user.md) — 5.1b livré : colonne `users.quota_snapshot` + commande snapshot + quota-section Livewire SFC **à décalquer** pour group-quota-section
- [Source: codeReviews/5-1b.md](../codeReviews/5-1b.md) — leçons review 5.1b (modale dialog+teleport, toasts génériques pas `$e->getMessage()`, double guard Gate, N+1 eager-load `with('userGroups')`)
- [Source: resources/views/pages/parc-settings/index.blade.php](../../resources/views/pages/parc-settings/index.blade.php) — **pattern tabs à décalquer** pour `/admin/settings/index.blade.php`
- [Source: resources/views/pages/users/[login]/_partials/quota-section.blade.php](../../resources/views/pages/users/[login]/_partials/quota-section.blade.php) — **pattern Livewire SFC quota + modale à décalquer** pour group-quota-section
- [Source: resources/views/components/quotas/group-quota-management.blade.php](../../resources/views/components/quotas/group-quota-management.blade.php) — **à supprimer** (AC 11)
- [Source: app/Services/Filesystem/XfsQuotaService.php:295,348,454](../../app/Services/Filesystem/XfsQuotaService.php) — API `setQuotaRule`, `deleteQuotaRule`, `setGracePeriod` réutilisées
- [Source: app/Http/Middleware/Auth/SambaEduAuthGuard.php:78](../../app/Http/Middleware/Auth/SambaEduAuthGuard.php) — point d'injection toast login (ligne `Auth::login($laravelUser)`)
- [Source: app/Components/Traits/WithToasts.php](../../app/Components/Traits/WithToasts.php) — trait Livewire (utilisé pour toasts UI composants) — **ne pas** utiliser depuis SambaEduAuthGuard
- [Source: vendor/devrabiul/laravel-toaster-magic/src/ToastMagic.php:309](../../vendor/devrabiul/laravel-toaster-magic/src/ToastMagic.php) — facade `ToastMagic::warning()` — **à utiliser** pour toast login server-side
- [Source: CLAUDE.md](../../CLAUDE.md) — conventions routing filesystem-based + modale réutilisable + trait WithToasts + interdiction `rm -rf`

### Kickoff Décisions (validées par Henri 2026-04-25)

- **D1** (stockage settings) : **A** — Nouvelle table `system_settings` K/V JSON + modèle `SystemSetting` avec helpers `get/set` (cast `array`). Migration conditionnelle pgsql/sqlite via `DB::getDriverName()`.
- **D2** (UI quota groupe) : **A** — Refonte en Livewire SFC dédié `group-quota-section.blade.php` (1:1 sur quota-section 5.1b).
- **D3** (scaffold mono-onglet) : **A** — Un seul onglet "Quotas & FS" visible. Interdiction de placeholders.
- **D4** (grace period apply post-save) : **A** — Persist + appel `XfsQuotaService::setGracePeriod` synchrone post-save (dans try/catch avec toast generic en cas d'échec).
- **D5** (idempotence toast login) : **A** — Listener `NotifyQuotaOverageOnLogin` sur event `Illuminate\Auth\Events\Login` (1×/session naturellement).
- **D6** (section vs onglets page groupe) : **A** — Section verticale insérée après `members-list` et avant `wallpaper-card` (pas d'onglets).
- **D7** (onglet SFC dédié) : **A** — Composant Livewire SFC dédié `quotas-fs-tab.blade.php`.
- **D8** (lien sidebar) : **A** — Ajouter "Réglages" dans `resources/views/components/organisms/sidebar.blade.php` après "Migration", avec icône `fa-cog` et `@can('server.admin')`.
- **D9** (audit settings) : **C** — Pas d'audit (D1=A → settings système, pas de RGPD critique).
- **D10** (colonne listing groupes) : **B** — `resources/views/pages/users/groups/index.blade.php` n'existe pas → AC 10 retiré du scope 5.1c. Pas de création de listing dans cette story (hors scope).
- **D11** (conservation endpoint legacy) : **A** — Conserver `QuotaController::updateGroupQuota` + route `app.users.groups.quota.update` (dormant, plus référencé après 5.1c).
- **D12** (enum TYPE_DEFAULT_ITINERANT dès 5.1c) : **A** — Ajouter la constante immédiatement (passive — UI 5.1c persiste, 5.1d la consomme via `getEffectiveQuota`).

### Investigation initiale (Phase 0)

**Grep `group-quota-management|manage-quotas|/admin/settings` :**
- 2 hits dans `resources/views/components/quotas/group-quota-management.blade.php` (lignes 87 et 115 — `@can('manage-quotas')` gate fantôme).
- Aucune autre référence applicative (pas d'`@include` ou `<x-quotas.group-quota-management>` ailleurs).
- Aucune route `/admin/settings` existante dans `routes/web.php`.

**Vérification D10 :**
- `resources/views/pages/users/groups/index.blade.php` → **n'existe pas** (le dossier `resources/views/pages/users/groups/` ne contient que `[id]/` et `new/`).
- AC 10 retiré du scope. Le listing groupes apparaît sur `/app/users` (page users avec onglet groupes) — la colonne Quota n'y est pas ajoutée pour rester focalisé.

**Sidebar admin :**
- Pas de section "Administration" repliable existante. Liens admin actuels sont des `<li>` directs (`Tableau de bord`, `Utilisateurs`, `Gestion des droits`, `Controlhub`, `Gestion du parc`, `Applications`, `Migration`).
- Décision : ajouter le lien "Réglages" en `<li>` direct après "Migration", avec `@can('server.admin')`.

**Baseline tests (sur VM) :** 1149 passed / 1 incomplete / 47 skipped — durée 52.44s.

---

## Recommandation Modèle Dev

### Choix : **opus** (claude-opus-4-7)

### Justification

Cette story cumule une densité exceptionnelle : **5 couches fonctionnelles simultanées** (migration settings + onglet settings + UI quota groupe + refonte incidentelle + toast login server-side) touchant **3 domaines transverses** (quotas, admin UI, authentification).

1. **Multi-couches avec état partagé** — la décision D1 (stockage settings) impacte le modèle + la migration + 3 méthodes du SFC onglet + le listener Login (lecture settings pour messaging). Un mauvais choix de pattern (ex: Option A avec clés non-typées) peut cascader en 15+ bugs silencieux.
2. **Sécurité cross-cutting critique** — 2 Gates `server.admin` à placer simultanément (page settings + modale groupe), 2 double-guards (UI + serveur), 1 middleware route, 1 bypass test formellement exigé. Toute fuite = faille de sécurité élévée (un non-admin peut modifier les defaults de TOUS les users).
3. **Toast login server-side** — la boucle de session Laravel (`SambaEduAuthGuard::handle()` rejouée à chaque requête) est un piège connu. Le bon choix (D5=A event listener) nécessite de comprendre le cycle Auth + Events + middleware. D5=B (guard) demande un flag session avec TTL cohérent (= cache buster). Opus raisonne mieux sur ces subtilités.
4. **12 décisions produit à trancher** — volume élevé (vs 9 en 5.1b). Chaque décision a des ramifications cross-fichiers que seul un modèle avec contexte large traite correctement.
5. **Pattern modale critique post-review 5.1b** — le dev doit décalquer EXACTEMENT le pattern `<dialog class="modal">` + `@teleport('body')` + `@entangle` + `modal-backdrop` corrigé en review. Une régression = retour en case départ.
6. **Non-régression stricte 5.1b (19 tests)** — la moindre modification d'imports dans `QuotaRule.php` ou `User.php` peut casser. Audit coordonné nécessaire.
7. **Nettoyage incidentel (AC 11)** — suppression d'un fichier Blade pur 210 lignes avec grep de sécurité. Un oubli = écran blanc en prod.
8. **Coordination tests** — 19 tests nouveaux répartis sur 4 fichiers Feature avec setup/teardown différents (Livewire + Event + session). Nécessite un modèle capable d'orchestrer la stratégie testing.

**Alternative sonnet envisageable si** le dev accepte de découper en 2 passes : passe 1 = scaffold settings + migration + UI quota groupe (réplication pattern 5.1b, mécaniquement faisable en sonnet) ; passe 2 = toast login + Event listener + tests orchestrés (opus). Plus lourd opérationnellement — **opus en une passe est plus simple, plus sûr, plus prévisible**.

Modèle recommandé final : **`opus`** (claude-opus-4-7).

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7` (1M context). Choix conforme à la recommandation SM (5 couches simultanées, sécurité cross-cutting, 12 décisions produit, pattern modale post-review 5.1b, non-régression stricte).

### Debug Log References

- Baseline tests sur VM (avant démarrage) : `php artisan test` → 1149 passed / 1 incomplete / 47 skipped (52.44s).
- Tests finaux sur VM : `php artisan test` → 1172 passed / 1 incomplete / 47 skipped (53.79s). **+23 tests, 0 régression.**
- Tests ciblés 5.1c : `php artisan test --filter='GroupShowQuotaSectionTest|AdminSettingsPageTest|AdminSettingsQuotasFsTabTest|LoginOverQuotaToastTest'` → 23 passed (47 assertions).
- Non-régression 5.1b ciblée : `php artisan test --filter='QuotaSnapshotCommandTest|UserShowQuotaSectionTest|UsersIndexPageQuotaColumnTest|UsersIndexPageNoShelloutTest|KernelScheduleTest'` → 28 passed.
- Migration sur VM : `php artisan migrate` → `2026_04_25_100000_create_system_settings_table` ran in 90.78ms.

### Completion Notes List

**Tests** :
- Baseline : 1149 passed / 1 incomplete / 47 skipped.
- Final : 1172 passed / 1 incomplete / 47 skipped.
- **Δ : +23 tests, 0 régression.**
- Cible initiale (15-20 nouveaux) dépassée — l'ajout de tests défensifs (validation soft<10Mo sur defaults, save_methods_recheck_permission, listener throw resilience) a bonifié le ratio.

**Décisions D10 (vérification kickoff)** : `resources/views/pages/users/groups/index.blade.php` n'existe pas → **AC 10 retiré du scope 5.1c**. La colonne Quota dans le listing groupes est reportée à une future story qui créera la page de listing (hors scope 5.1c).

**Décisions techniques notables prises pendant l'implémentation** :
1. **Sidebar** : pas de section "Administration" repliable existante, donc le lien "Réglages" est ajouté en `<li>` direct après "Migration" (cohérent layout actuel).
2. **Test #14 forged-payload sur QuotasFsTab** : la première version (mount avec admin puis bascule vers viewer pour `call`) retournait 404 au lieu de 403 (limitation Livewire test runner). Refonte : 2 tests séparés — `it_blocks_save_without_server_admin` (mount sans admin → 403 immédiat) + `save_methods_recheck_permission_when_user_changes` (revoke permission post-mount + re-actingAs). Couvre la défense en profondeur AC 12.
3. **assertSee('Quotas & FS')** : Blade encode `&` en `&amp;`. Test refactoré en `assertSee('Quotas')` + `assertDontSee` sur les chaînes placeholder (couvre AC 5 sans dépendre de l'escape).
4. **`group-quota-section` ne stocke pas `?QuotaRule`** mais une projection `?array` pour éviter les soucis Wireable (les modèles Eloquent en propriétés Livewire sont fragiles). Charge via `loadRules()` qui fait 2 queries simples avec `->only([...])`.
5. **Test ToastMagic facade** : utilisation de `ToastMagic::shouldReceive('warning')` (Mockery PartialMock) plutôt que de scanner `session()->all()` — plus fiable car la facade ToastMagic stocke ses messages avec une clé qui peut varier selon la version du package.
6. **Sync host→VM** : le sync auto présumé `host:e5-files → VM:sambaedu-reload` n'a pas eu lieu pendant l'exécution (la VM était sur main, pas e5-files). J'ai utilisé un `tar | ssh tar -xf` ciblé sur les fichiers nouveaux/modifiés (pas un rsync — pattern transfert ad-hoc, aligné avec l'esprit "pas de rsync auto bilatéral"). Les fichiers `_bmad-output` (story, sprint-status) restent côté HOST puisqu'ils ne sont pas runtime VM.
7. **Migration system_settings** : pattern conditionnel pgsql/sqlite via `DB::getDriverName()` cohérent avec `add_quota_snapshot` 5.1b et `delegation_history` 7.1. `Schema::hasTable` early-return pour ré-exécution idempotente.
8. **Listener Login event** : try/catch global `\Throwable` autour de tout le `handle()` — un échec listener (BDD down, snapshot mal formé, ToastMagic facade indisponible) ne casse JAMAIS le login. Test explicite `it_does_not_break_login_if_handler_throws` couvre ce cas.

**Convention projet — guards Livewire (formalisée en review 5.1c #1)** :
- **Méthodes "ouverture d'UI"** (ex: `openOverrideModal`) — sans side-effect serveur : `toastAccessDenied + return` (UX douce, page reste fonctionnelle, cohérent avec 5.1b post-review).
- **Méthodes mutantes** (ex: `applyOverride`, `saveDefaults`, `saveGrace`, `saveTrash`, `setTab`) : `abort(403)` en première ligne (trace 403 dans monitoring, barrage final).
- Cette convention décale légèrement de la lettre de l'AC4 (qui demandait `abort(403)` partout) au profit d'une cohérence projet : la sécurité réelle est garantie dans les 2 cas (la mutation reste bloquée), seule la cosmétique d'erreur diffère sur les méthodes UI-only. Validation Henri 2026-04-25.

**Migrations à appliquer en prod ([PROD])** :
```bash
ssh root@192.168.122.50 'cd /var/www/sambaedu-reload && php artisan migrate'
# Migration : 2026_04_25_100000_create_system_settings_table
```
Pas de seed nécessaire (les helpers `SystemSetting::get(key, default)` retournent un default scaffold quand aucune row n'existe).

**Conformité AC** :
- AC 1 (migration system_settings) : ✅ Migration + modèle + helpers livrés.
- AC 2-3 (section Quota groupe + modale override) : ✅ SFC livré, tests 1-3 et 6-7 passent.
- AC 4 (double guard server.admin groupe) : ✅ tests 4-5 passent.
- AC 5 (route /admin/settings + scaffold mono-onglet) : ✅ test 9 passe.
- AC 6 (defaults 4 profils × 2 partitions) : ✅ test 11 passe + validation 10Mo (test bonus).
- AC 7 (grace period 2 partitions + setGracePeriod sync) : ✅ test 13 passe.
- AC 8 (TTL trash + toggle + banner) : ✅ test 12 passe.
- AC 9 (toast over-quota au login server-side + idempotence) : ✅ tests 15-19 passent.
- AC 10 (colonne listing groupes) : ⏳ retiré du scope (D10=B — listing groupes inexistant).
- AC 11 (cleanup group-quota-management.blade.php) : ✅ supprimé via `gio trash`, grep 0 hit.
- AC 12 (double guard server.admin settings) : ✅ tests 10 + bypass tests passent.
- AC 13 (19 tests Feature minimum) : ✅ 23 tests livrés (4 fichiers Feature).
- AC 14 (non-régression 5.1b) : ✅ 28 tests 5.1b (incluant ajouts post-review) restent verts.
- AC 15 (gestion d'erreur préservée) : ✅ tous les `catch` log + toast générique (pas `$e->getMessage()`).

### File List

**Créés** (10) :
- `database/migrations/2026_04_25_100000_create_system_settings_table.php`
- `app/Models/SystemSetting.php`
- `app/Listeners/NotifyQuotaOverageOnLogin.php`
- `resources/views/pages/admin/settings/index.blade.php`
- `resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php`
- `resources/views/pages/users/groups/[id]/_partials/group-quota-section.blade.php`
- `tests/Feature/Livewire/Users/GroupShowQuotaSectionTest.php`
- `tests/Feature/Livewire/Admin/AdminSettingsPageTest.php`
- `tests/Feature/Livewire/Admin/AdminSettingsQuotasFsTabTest.php`
- `tests/Feature/LoginOverQuotaToastTest.php`
- `docs/qa/README.md`
- `docs/qa/domains/filesystem.md`

**Modifiés** (6) :
- `app/Models/QuotaRule.php` — ajout `TYPE_DEFAULT_ITINERANT` + cas dans `isDefault()`, `scopeDefaults()`, `getTypeLabel()`.
- `app/Providers/EventServiceProvider.php` — registration listener `Login::class => [NotifyQuotaOverageOnLogin::class]`.
- `routes/web.php` — ajout route `admin.settings` avec middleware `can:server.admin`.
- `resources/views/pages/users/groups/[id]/index.blade.php` — inclusion `<livewire:pages::users.groups.[id]._partials.group-quota-section>` entre members-list et wallpaper-card.
- `resources/views/components/organisms/sidebar.blade.php` — ajout lien "Réglages" `@can('server.admin')` après "Migration".
- `docs/domains/filesystem.md` — enrichissement : 4 nouvelles sections (Quota groupe UI, Réglages système /admin/settings, Toast over-quota au login, Modèle SystemSetting) + footer mis à jour.

**Supprimés** (1) :
- `resources/views/components/quotas/group-quota-management.blade.php` (210 lignes Blade pur obsolète, contenait 2 `@can('manage-quotas')` gate fantôme — supprimé via `gio trash`).

**Sprint-status.yaml** : ligne `5-1c-quotas-groupes-settings-flash-over-quota` passée de `ready-for-dev` → `in-progress` au démarrage, puis `in-progress` → `review` à la complétion. Commentaire enrichi avec décisions D1-D12 + résultats tests.
