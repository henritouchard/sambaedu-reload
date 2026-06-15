# Story 26.2 : Mode nomade — modèle 100 % local assumé (clôture de FR29)

Status: review

<!-- Note: cette story a été RECADRÉE deux fois pendant le dev-cycle (voir Change Log). Le titre de fichier conserve « offline-resync » par stabilité de clé sprint-status, mais le contenu livré est le modèle LOCAL (pas d'offline/resync). -->

## Story

En tant qu'**exploitant du parc**,
je veux **un modèle de poste nomade clair et assumé**,
afin que **la nature « nomade » soit documentée comme un poste 100 % local servi par l'agent, sans machinerie de redirection/synchronisation qui ne correspond pas à la réalité d'exploitation**.

## Contexte & double recadrage (lire en premier)

Cette story a évolué pendant le dev-cycle, après investigation du legacy et arbitrages d'Henri :

1. **Recadrage 1 — nomade = local, `clean_profiles` hors sujet.** Un poste nomade est un environnement **100 % local** (mémoire `port_perdir`). Les mécanismes legacy `clean_profiles`/`del-roam` agissent sur le store des profils **itinérants** `/home/profiles` (par user, aveugles au poste) → ils ne touchent pas un nomade. L'AC d'origine « désactiver clean_profiles pour les nomades » était **sans objet** → retirée. (La réimplémentation native du nettoyage de profils = **Story 26.3**, spin-off enregistré.)

2. **Recadrage 2 — FR29 (offline/resync) écartée, modèle local assumé.** L'epic (FR29) recommandait « serveur = source de vérité + cache offline + resync » via **Folder Redirection + Offline Files (CSC)**. Henri a tranché (2026-06-13) : **ce modèle ne correspond pas à l'exploitation réelle.** En vrai, un poste nomade :
   - **n'a pas de profil utilisateur réseau** ;
   - **stocke ses documents en local, jamais supprimés** (le poste *est* la source de vérité de ses données) ;
   - reçoit sa config (logiciels WPKG, raccourcis, wallpaper) par **l'agent desired-state** (Epics 23-25), comme tout poste. **C'est tout.**

   → **Aucune redirection de dossiers, aucun fichier hors-connexion, aucune synchronisation serveur** n'est mise en place. Le runbook offline rédigé en cours de cycle a été **jeté** (hors-sujet). **Conséquence explicitement acceptée par Henri** : perte des données locales si le portable est perdu/volé/en panne (pas de copie serveur). Un éventuel filet de sauvegarde serait une **décision séparée**, hors 26.2.

26.2 devient donc une **story de décision/documentation** : elle **clôt FR29** par un choix délibéré (ne pas construire la machinerie CSC) et **documente le modèle nomade**, pour que personne ne réintroduise plus tard une redirection/sync en croyant qu'elle est requise.

## Acceptance Criteria

1. **Le modèle nomade est documenté** dans `docs/agent/state-providers.md` (section « Environnement de poste » → sous-section « Mode nomade ») : poste 100 % local, pas de profil réseau, documents locaux jamais supprimés, configuration servie par l'agent desired-state. **C'est le seul comportement attendu pour un nomade.**

2. **FR29 est explicitement close** : la doc indique que le modèle « serveur source de vérité + Folder Redirection + Offline Files + resync » a été **écarté** (et pourquoi : ne correspond pas à l'exploitation, machinerie lourde sans bénéfice recherché). **Conséquence acceptée** (perte de données si perte du poste) documentée. Aucun artefact de redirection/offline n'est livré (le runbook éventuellement esquissé est retiré).

3. **`clean_profiles`/`del-roam` documentés comme hors sujet** pour les nomades (pas de profil réseau → rien à désactiver), avec renvoi vers la **Story 26.3** (réimplémentation native du nettoyage de profils).

4. **AUCUN code, AUCUN retrofit legacy.** Story de pure documentation : aucun fichier `app/`, `routes/`, `resources/`, agent Go, golden file, migration ou template GPO. `RoamingProfileService`, `ApplicationScriptsGenerator`, `ShortcutCompilerService`, le pansement Bug C (`4e5a152`), la GPO `redirections`/`ExcludeProfileDirs` restent **intouchés**.

5. **Discipline NFR7 / NFR12.** La nature du poste reste une donnée du domaine lue via `WorkstationEnvironmentResolver` (Postgres-only, NFR7) ; l'identifiant `nomade` reste la valeur figée de l'enum (NFR12). 26.2 ne fait que **référencer** ce socle 26.1 dans la doc.

## Tasks / Subtasks

- [x] **T1 — Documenter le modèle nomade local + clore FR29** (AC: #1, #2, #3, #5)
  - [x] Réécrire la sous-section « Mode nomade (Story 26.2) » de `docs/agent/state-providers.md` : modèle 100 % local (pas de profil réseau, docs locaux jamais supprimés, config par l'agent), clôture explicite de FR29 (modèle CSC écarté + pourquoi + conséquence acceptée), note `clean_profiles`/`del-roam` hors sujet → 26.3.
  - [x] Retirer l'artefact offline rédigé en cours de cycle (`docs/runbooks/gpo-nomade-offline-files.md` → corbeille) et toute trace QA/README associée (section `gpo.md` 26.2, ligne README) — la 26.2 ne touche plus le domaine GPO.

- [x] **T2 — Tracer la décision** (AC: #2, #4)
  - [x] Consigner la décision et le double recadrage dans cette story (Contexte + Change Log + Dev Agent Record) et dans `sprint-status.yaml`.
  - [x] (Recommandé, à valider Henri) reporter la clôture de FR29 dans l'epic `epics-agent-desired-state.md` (correct-course) pour que la planification reflète le modèle local — voir « Question pour Henri ».

## Dev Notes

### Nature de la story

**Pure décision/documentation. Zéro code, zéro runbook, zéro template.** Le livrable est une mise à jour doc qui acte le modèle nomade local et clôt FR29. Aucun mécanisme technique n'est introduit (pas de redirection, pas de CSC, pas de sync).

### Pourquoi pas de mécanisme offline

- **Réalité d'exploitation (Henri)** : nomade = PC personnel + itinérant, **un seul utilisateur**, **pas de profil réseau**, **docs locaux jamais supprimés**, config par l'agent. Le besoin « accès offline » est trivialement satisfait : les données **sont** locales en permanence.
- **FR29 (offline/resync via CSC) écartée** : machinerie Windows lourde (Folder Redirection, Offline Files, conflits Sync Center) pour un bénéfice non recherché. Le risque « données prisonnières du poste » de l'epic est **réinterprété** : les données vivent sur le poste **par conception**, et leur perte en cas de perte du poste est un **risque assumé** (un filet de sauvegarde serait une décision distincte).
- **L'agent fait déjà le reste** : logiciels WPKG, raccourcis, wallpaper convergent via le canal desired-state (Epics 23-25) — indépendant de la nature nomade.

### Hors-scope (NE PAS faire)

- **Toute redirection de dossiers / Offline Files / synchronisation serveur** pour les nomades (écarté par décision — AC2).
- **Réimplémentation native du nettoyage de profils** (pastille tableau user + purge orphelins, calcul journalier) = **Story 26.3**.
- **Redirection des profils navigateur** selon `WorkstationEnvironment` = **Story 27.4**.
- **Handlers / Bug C** = Epic 27.
- **Tout retrofit legacy** (`RoamingProfileService`, `ApplicationScriptsGenerator`, `ShortcutCompilerService`, GPO `redirections`).
- **Filet de sauvegarde des données nomades vers le serveur** : non décidé ici (serait une story dédiée si le risque de perte devenait inacceptable).

### Dépendances

| Story | Rôle pour 26.2 | Statut | Bloquant ? |
|-------|----------------|--------|------------|
| 26.1 — Enum + resolver + colonne + UI | Fournit l'enum/`nomade` et le resolver (référencés en doc). | `review` | Non (doc only) |
| 26.3 — Nettoyage natif des profils (spin-off) | 26.2 y renvoie pour le nettoyage de profils. | backlog | Non |
| Epics 23-25 — agent desired-state | C'est l'agent qui sert la config des nomades (wpkg/raccourcis/wallpaper). | done/review | Non |

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 26.2 / FR29] — AC d'origine (offline/resync via CSC) — **écartée** par décision (voir Contexte). Correct-course de l'epic recommandé.
- [Source: docs/agent/state-providers.md#Environnement de poste — Mode nomade (Story 26.2)] — doc livrée.
- [Source: _bmad-output/implementation-artifacts/26-1-enum-workstation-environment.md] — socle enum/resolver.
- [Source: sambaedu/includes/partages.inc.php:186 / sambaedu/gpo/del_roam.php / sambaedu/annu/ldap_cleaner.php] — `clean_profiles`/`del-roam` (per-user, hors sujet nomade) — contexte 26.3.

## Question pour Henri

1. **Correct-course de l'epic** : veux-tu que je reporte la clôture de FR29 (modèle local assumé, CSC écarté) directement dans `epics-agent-desired-state.md` (Story 26.2 + FR29), pour que la planification ne décrive plus le modèle serveur/offline ? (Recommandé pour éviter qu'un futur lecteur reconstruise la machinerie CSC.)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (orchestrateur dev-cycle — rédaction directe, story de pure décision/documentation, conforme `feedback_devcycle_doc_stories_direct`).

### Debug Log References

Investigation legacy (recadrage 1) : `sambaedu/includes/partages.inc.php:186`, `sambaedu/gpo/del_roam.php`, `sambaedu/annu/ldap_cleaner.php:184-313`, `app/Services/RoamingProfileService.php`, `resources/views/pages/admin/settings/_partials/profils-itinerants-tab.blade.php`. Aucun test (zéro code).

### Completion Notes List

- **Double recadrage** : (1) clean_profiles hors sujet (nomade = local), (2) FR29 écartée — modèle local assumé, le poste est la source de vérité de ses données, perte si perte du poste = risque accepté (Henri, 2026-06-13).
- **Runbook offline jeté** : `docs/runbooks/gpo-nomade-offline-files.md` mis à la corbeille (`gio trash`) ; section QA `gpo.md` 26.2 retirée ; ligne `gpo` de `qa/README.md` revertée. 26.2 ne touche plus le domaine GPO.
- **Livrable final = documentation seule** : sous-section « Mode nomade (Story 26.2) » de `state-providers.md` réécrite (modèle local + clôture FR29 + note clean_profiles → 26.3).
- **Spin-off 26.3** enregistré (backlog) : nettoyage natif des profils (pastille tableau user + bouton orphelins, calcul journalier).
- **Review intermédiaire archivée** : la review (sonnet + 2e avis opus) portait sur le runbook offline désormais supprimé → findings obsolètes, voir `_bmad-output/codeReviews/26-2.md` (statut superseded). Le seul livrable restant (doc state-providers) est trivial et factuel.
- **Discipline** : NFR7/NFR12 respectés ; zéro code ; zéro retrofit legacy ; frontière agent_*/golden files intouchée.

### File List

- `docs/agent/state-providers.md` (modifié) — sous-section « Mode nomade (Story 26.2) » : modèle local + clôture FR29 + note clean_profiles → 26.3.
- `_bmad-output/implementation-artifacts/26-2-mode-nomade-offline-resync.md` (cette story — réécrite, modèle local).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modifié) — 26-2 recadrée (modèle local), 26-3 enregistrée, epic-26 mise à jour.
- `_bmad-output/codeReviews/26-2.md` (modifié) — marqué superseded (review du runbook supprimé).
- `docs/runbooks/gpo-nomade-offline-files.md` (SUPPRIMÉ — corbeille).
- `docs/qa/domains/gpo.md` (revert — section 26.2 retirée).
- `docs/qa/README.md` (revert — ligne gpo).

## Change Log

| Date | Changement |
|------|-----------|
| 2026-06-13 | Création (SM/opus) — scope initial : offline/resync + garde clean_profiles per-poste (modèle epic FR29). |
| 2026-06-13 | Recadrage 1 (investigation legacy + Henri) : nomade = local → garde clean_profiles sans objet, retirée ; spin-off 26.3. |
| 2026-06-13 | Dev direct (orchestrateur) : runbook offline + appends doc/QA livrés ; review sonnet + 2e avis opus (corrections appliquées). |
| 2026-06-13 | Recadrage 2 (Henri) : FR29 écartée — modèle 100 % local assumé. Runbook offline jeté, QA/README revertés, story réduite à décision/documentation. Review du runbook → superseded. |
