# Code Review — Epic 39 : alignement de la couture controlHub↔SE5 (review finale consolidée)

Date : 2026-07-06
Périmètre : état FINAL sur `main` (commit `4db61e7`) des 4 stories intégrées — 39.1 canal ① (ingestion contrat), 39.2 canal ③ (émetteur conformité), 39.3 canal ⑤ (JWT fédéré), 39.4 canal ④ (pull binaires amont).
Méthode : 4 reviews adversariales par canal + 1 review transversale de couture + rejeu des tests sur l'hôte + vérification manuelle des findings porteurs. Les findings déjà traités dans les reviews unitaires (`39-1.md`..`39-4.md`) ne sont pas re-listés (corrections vérifiées présentes en fin de doc).

## Verdict

**Ne pas clôturer `done` en l'état.** Le socle par story est sain (corrections unitaires bien présentes, crypto du canal ⑤ correcte), mais la review **transversale** révèle des défauts de couture non couverts par le découpage story-par-story, dont deux qui invalident des invariants produit (rupture 32.1 ; PRD §5.3 « panne ≠ rupture ») et une régression de test réelle sur `main`. Le « 178/178 verts » de l'ultradev était scopé epic-39 et a raté une casse hors périmètre.

## Synthèse — findings nouveaux (post-review unitaire)

| # | Finding | Canal | Sévérité | Vérifié |
|---|---------|-------|----------|---------|
| E1 | Rupture 32.1 **ressuscitée** : après `sever-link`, le POST suivant du contrat crée un nouveau contrat `active` → verrous ré-imposés sans handshake | ①/32.1 | 🟠 (décision produit) | ✅ code lu |
| E2 | Régression sur `main` : `controlhub:report-compliance` planifié casse le garde-fou `no_scheduled_command_decays_the_contract` (339/340) | ③ | 🟠 | ✅ test rejoué |
| E3 | SSRF : `artifact.url` du contrat ingéré fetché sans allowlist hôte/schéma, redirections Guzzle par défaut | ④ | 🔴/🟠 (selon posture de confiance amont) | ✅ surface confirmée |
| E4 | `pull_status ∈ {error,pending}` **jamais lu** par le canal ③ + retry mort → panne de pull **permanente et invisible des deux côtés** | ③↔④ | 🟠 | ✅ code lu |
| E5 | Watermark de cadence sur store `apc` (défaut) → inerte entre process CLI → émission **chaque minute** au lieu de 15 min | ③ | 🟠 (si `CACHE_DRIVER=apc` en prod) | ✅ store confirmé |
| E6 | `UpstreamLockResolver` singleton + catalogue mémoïsé `??=` sans invalidation → rapports **périmés** pour la vie du daemon `queue:work` | ③ | 🟠 | ✅ binding+memo lus |
| E7 | `locked → applied` inconditionnel : items de type sans adaptateur (`wallpapers`/`shortcuts`) ou label sur zéro parc rapportés `applied` alors qu'imposés nulle part | ③ | 🟠 | ✅ code lu |
| E8 | Détection d'override ignore `capabilities.is_active` → `overridden` rapporté pour une capacité désactivée (faux positif symétrique de E7) | ③ | 🟠 | ✅ code lu |
| E9 | Course « deux contrats `active` » (2 POST concurrents, PG READ COMMITTED) + `active()` sans `ORDER BY` → sever ne coupe qu'un des deux, rapport flippe | ①/③/32.1 | 🟠 | ✅ code lu |
| E10 | Deux sources de vérité pour le credential entrant : `validateSE4FSToken()` (DB, handshake) **jamais appelé** ; middleware valide la config live → rotation `.env` sans re-handshake casse ①+32.1 | ①/⑤ | 🟠 | ✅ code lu |
| E11 | `pull_error` fuit l'URL signée via le message d'exception Guzzle (`ConnectException` suffixe `for <URI>`) → remontée canal ③, contredit NFR-A3 | ④ | 🟠 | ✅ code lu |
| E12 | Précédence locale marque `downloaded` même quand le binaire local a un checksum ≠ checksum imposé → ③ rapporte un binaire satisfait qui ne correspond pas | ④ | 🟠 | ✅ code lu |
| E13 | Garde 39.1#1 ne bloque que le corps 0-clé ; un payload **partiel** (`{"items":[...]}` seul) prune labels+imposed_groups+catalog_apps en 200 | ① | 🟡 | ✅ code lu |
| E14 | Borne de taille (39.4#3) appliquée **après** téléchargement complet → disk-fill résiduel (jusqu'à 60 s × bande passante) ; `Content-Length` non vérifié | ④ | 🟡 | ✅ code lu |
| E15 | Wallpaper non-JPEG (PNG/GIF/BMP/WEBP accepté) matérialisé `<sha>.jpg` sans ré-encodage → invariant bibliothèque rompu, MIME menteur pour l'aval | ④ | 🟡 | ✅ code lu |
| E16 | Pas de bornage de longueur : `delivery_mode`/`artifact_*`/`executable_*` en varchar(255) → PG 22001 (500) invisible en sqlite ; `artifact_checksum` sans validation hex/64 | ④ | 🟡 | ✅ code lu |
| E17 | `(string) $value` sur array JSON → 500 non mappé (au lieu de 422) ; idem `target_label` array | ① | 🟡 | ✅ code lu |
| E18 | Course prune/pull : asset matérialisé pour un item déjà supprimé ; `@unlink` du perdant sur collision unique `agent_tools` supprime le fichier du gagnant | ④ | 🟡 | ✅ code lu |
| E19 | Fenêtre de rapport hybride : `received_at` et items lus hors même transaction → état-intégral corrèle nouveaux items / ancien horodatage | ③ | 🟡 | ✅ code lu |
| E20 | `link_state => 'active'` codé en dur dans l'enveloppe ③ ; après rupture le canal se tait → l'amont ne distingue jamais « rompu » de « en panne » | ③/32.1 | 🟡 | ✅ code lu |
| E21 | `capabilitiesForRegistryKey()` non déterministe (`items()->get()` sans `ORDER BY`) + override = existence d'assignment sans comparaison de valeur | ③ | 🟡 | ✅ code lu |
| E22 | `name`/`email` du JWT contournent le garde `is_scalar` de `stringClaim()` → `(string) []` = `"Array"` écrit en base | ⑤ | 🟡 | ✅ code lu |
| E23 | Clé publique IdP (DB) consommée sans pinning/contrôle d'intégrité — item F8 promis « à adresser dans la story de vérification JWT » (39.3) **non fermé** | ⑤ | 🟡 (résidu à ratifier) | ✅ commentaire lu |
| E24 | `config('controlHub.artifact_max_bytes')` : clé absente de `config/controlHub.php` **et** de l'env → borne non configurable (seul défaut codé) | ④ | 🟡 | ✅ config lue |
| E25 | `.env.example` ne porte aucune var `CONTROLHUB_*` (kill-switch ③ `CONTROLHUB_COMPLIANCE_ENABLED`/`_INTERVAL` + `SE4FS_INSTANCE_ID`) → invisible du provisioning | ③/⑤ | 🟡 | ✅ fichier lu |
| E26 | Retry job ③ indiscriminé sur 4xx permanentes (401/404/422) → ~96–1440 `failed_jobs`/jour, aucun circuit-breaker | ③ | 🟡 | ✅ code lu |
| E27 | Règle de précédence locale dupliquée en deux `match` identiques (dispatch 39.1 / service 39.4) → divergence silencieuse si l'un évolue | ①↔④ | 🟡 | ✅ code lu |
| E28 | Runbook Section 23 (39.4) numéroté `22.1`–`22.5` → collision avec Section 22 (39.2) | doc | 🟡 | ✅ doc lue |

## Findings porteurs — détail

### E1 — 🟠 Rupture 32.1 ressuscitée par le canal ①
`ControlHubContractIngestionService::resolveActiveContract()` ne cherche qu'un contrat `WHERE link_state='active'` ; un contrat `severed` ne matche pas → création d'un **nouveau** contrat `active` (ligne 256), verrous ré-imposés. Le docstring l'assume (« la rupture relève d'Epic 32, hors scope »), mais depuis que 39.1 rend l'ingestion atteignable par HTTP, le POST périodique de l'amont **annule silencieusement** toute rupture initiée côté SE5 — sans handshake, par-dessus l'état figé matérialisé par la rupture. Le middleware `ControlHubAuth` ne s'invalide pas non plus au sever. **Frontalement contraire au mécanisme 32.1 (rupture = figer l'état effectif) et au PRD §5.3.** Décision produit requise : l'ingestion doit-elle no-op/rejeter tant que `severed`, ou la rupture doit-elle propager (désactiver connexion + révoquer clé) ?

### E2 — 🟠 Régression de test sur `main`
`vendor/bin/phpunit tests/Feature/ControlHub/ tests/Unit/Auth/Federated/FederatedJwtVerifierTest.php tests/Feature/Auth/Federated/` → **339 passed, 1 failed**. `UpstreamUnavailabilityTest::no_scheduled_command_decays_the_contract_on_staleness:493` verrouille l'allowlist « seul `controlhub:heartbeat` planifié » (garde PRD §5.3). La 39.2 a planifié `controlhub:report-compliance` (`app/Console/Kernel.php:26`) sans confronter ce garde. La commande est read-only sur le contrat (transport sortant, comme heartbeat) → fix légitime = étendre l'allowlist avec justification tracée. Mais la casse est réelle sur `main`.

### E3 — 🔴/🟠 SSRF sur le pull d'artefact
`ArtifactPullService` (ligne 112) : `Http::timeout(60)->sink($tmp)->get($url)` où `$url = artifact.url` du contrat ingéré, **aucune** validation de schéma/hôte, pas d'ancrage sur l'URL du controlHub connu, redirections Guzzle par défaut (downgrade https→http). Un amont compromis (ou détenteur de `SE4FS_INSTANCE_API_KEY`) fait faire au worker SE5 un GET vers `http://169.254.169.254/…` ou `http://127.0.0.1:port/…` ; le code HTTP est persisté dans `pull_error` puis remonté au canal ③ → oracle de scan réseau interne. Sévérité modulée par le modèle de confiance (l'URL vient de l'amont authentifié), mais défense en profondeur attendue : allowlist d'hôte + `https` forcé + redirections désactivées.

### E4 — 🟠 Le canal ③ ment sur le canal ④ (+ retry mort)
`resolveStatus()` ne lit **jamais** `pull_status` (commentaire « pas de signal fiable au grain par item » devenu faux le jour où 39.4 a livré ce signal). Un wallpaper `locked` avec artifact, sha256 mismatch → `pull_status=error`, aucune matérialisation, mais rapport `status=applied`. Combiné : `dispatchArtifactPulls()` n'est appelé que sur `mutated=true`, et une ré-émission identique (seule l'URL signée change, hors colonnes) est un no-op → l'item reste `error` à vie. Résultat : **panne de pull permanente ET invisible des deux côtés du contrat.** Corriger conjointement (③ doit consommer `error`/`pending` ; prévoir une réconciliation périodique des items sans asset, indépendante de la mutation).

## Corrections unitaires vérifiées présentes (rappel, non re-listées)
- 39.1#1 garde enveloppe (`ContractIngestionController:58`) ✅ — **partielle** (cf. E13).
- 39.2#1 `tries` fonctionnel (job relève sur `http_error`) ✅ ; #2 test `no_token` ✅ ; #3 `detail` ordonné ✅ (mais **non testé**) ; #4 catalogue mémoïsé ✅ (revers : E6).
- 39.3#1 test précédence config-pleine ✅ ; #4 `hasFederatedIdp` strict ✅ ; #5 commentaire config ✅. Crypto RS256 correcte (pas d'alg-confusion, signature avant claims, pas d'hybride DB/config).
- 39.4#1 checksum lowercase bout-en-bout ✅ ; #2 `pulledFileIsSafeImage` ✅ ; #3 `sink`+borne ✅ (revers : E14) ; #4 isolation par item ✅ (**non testée**) ; #5 docblock `AgentTool` ✅.

## Trous de couverture de test (transversaux)
Aucun test : borne de taille / timeout du pull ; contenu de `pull_error` sur exception réseau (aurait attrapé E11) ; `pull_status` après précédence agent_tool ; payload partiel 1-clé (E13) ; isolation d'erreur `dispatchArtifactPulls` (39.4#4) ; déterminisme du `detail` d'override (39.2#3) ; watermark/cadence `intervalElapsed()` ; toggle `compliance.enabled` ; items `locked`/label sans adaptateur (E7).

## Corrections appliquées directement (2026-07-06, review epic)

| # | Fix | Fichier | Statut |
|---|-----|---------|--------|
| E2 | Allowlist du garde-fou étendue à `report-compliance` (read-only sur contrat, confronté PRD §5.3) | `tests/Feature/ControlHub/UpstreamUnavailabilityTest.php` | ✅ 340/340 verts |
| E11 | `pull_error` ne persiste plus `$e->getMessage()` (fuite URL signée) → catégorie stable ; détail complet en log serveur | `app/Services/ControlHub/ArtifactPullService.php` | ✅ |
| E22 | `name`/`email` routés via `stringClaim()` (garde `is_scalar`) | `app/Auth/Federated/Jwt/FederatedJwtVerifier.php` | ✅ |
| E24 | Clé `artifact_max_bytes` configurable via `CONTROLHUB_ARTIFACT_MAX_BYTES` | `config/controlHub.php` | ✅ |
| E25 | Vars `CONTROLHUB_COMPLIANCE_*` + `CONTROLHUB_ARTIFACT_MAX_BYTES` ajoutées | `.env.example` | ✅ |

> Déploiement VM : les changements `config/controlHub.php` exigent `config:cache` + `chown www-admin` après sync inotify pour être visibles.

### E10 — credential entrant normalisé (SE5 livré, CH en attente) — story de suivi 39-5

Côté SE5 : `ControlHubAuth` passé en **dual-accept** — validation prioritaire du `se4fs_api_token` négocié au handshake (`ControlHubConnection::current()->validateSE4FSToken()`, primitif jusqu'ici mort), repli legacy sur `instance_api_key` le temps de la bascule. Couverture ajoutée (`ContractIngestionEndpointTest`, 11/11) : token de handshake accepté même avec `instance_api_key` de config différente ; token inconnu rejeté (403) en présence d'une connexion active. **Non-cassant** : le CH continue via le repli tant qu'il n'a pas migré.

Reste bilatéral (bloqué côté CH) — prompt BMAD à feed à irundoo :

```
Story (bug de couture SE5↔ControlHub — finding E10 de l'Epic 39, correction côté ControlHub)

CONTEXTE
Quand le ControlHub POSTe vers les endpoints d'ingestion entrante de SE5
(injection de contrat / capabilities, middleware ControlHubAuth côté SE5), il
s'authentifie aujourd'hui avec la clé d'identité statique de l'instance SE5
(SE4FS_INSTANCE_API_KEY / instance_api_key), c'est-à-dire un secret symétrique
connu des deux côtés et figé en config.

C'est le finding E10 : l'auth entrante n'utilise PAS le credential négocié au
handshake. Or, au handshake, le ControlHub FRAPPE lui-même un token par instance
(`se4fs_api_token`) qu'il renvoie à SE5, lequel le stocke par connexion. C'est ce
token qui doit faire autorité pour authentifier les requêtes sortantes du CH vers
SE5, pas la clé d'identité de SE5.

CE QUE SE5 A DÉJÀ FAIT
Le middleware d'ingestion SE5 est passé en DUAL-ACCEPT : il valide d'abord le
`se4fs_api_token` négocié (via `ControlHubConnection::validateSE4FSToken()`),
et retombe sur `instance_api_key` en repli legacy le temps de la bascule. SE5
n'exige donc pas de coordination de timing : le CH peut migrer quand il veut sans
rompre l'ingestion.

CHANGEMENT DEMANDÉ (ControlHub)
Pour tout appel HTTP SORTANT du ControlHub vers les endpoints d'ingestion de SE5,
présenter le token frappé au handshake pour CETTE instance :

    Authorization: Bearer <se4fs_api_token de l'instance>

et NON plus la clé instance_api_key de SE5.

- Récupérer le `se4fs_api_token` là où le CH persiste la connexion/handshake par
  instance SE5 (le token qu'il a lui-même généré et renvoyé dans la réponse de
  handshake).
- Le poser dans l'en-tête Authorization Bearer des requêtes vers SE5.
- Ne pas réinventer de secret : réutiliser strictement le token du handshake déjà
  en base côté CH.
- Format attendu inchangé côté SE5 : ^[a-zA-Z0-9_-]{16,}$, en Bearer.

CRITÈRES D'ACCEPTATION
1. Les POST d'ingestion CH→SE5 partent avec le se4fs_api_token du handshake en
   Bearer, résolu par instance.
2. Si aucune connexion/handshake valide n'existe pour l'instance cible, l'appel
   échoue proprement (log + retry/handshake), sans retomber sur une clé statique.
3. Rotation : si un nouveau handshake refrappe le token, les appels suivants
   utilisent le nouveau token (pas de valeur mise en cache figée).
4. Test d'intégration : un POST d'ingestion signé avec le se4fs_api_token du
   dernier handshake est accepté par SE5 (200/2xx) ; un POST signé avec un token
   révoqué/périmé est rejeté (403).

HORS SCOPE / À NE PAS FAIRE
- Ne pas supprimer encore le support de instance_api_key côté contrat : SE5 le
  garde en repli. Sa suppression sera une story de clôture ULTÉRIEURE, une fois
  la bascule CH confirmée en pré-prod et la couture ratifiée amont.
- Ne pas toucher au canal SORTANT de SE5 vers le CH (là, instance_api_key reste
  la clé d'identité de SE5 et c'est correct).

POINT À RATIFIER
Confirmer que le token d'authentification des appels entrants CH→SE5 est bien le
`se4fs_api_token` frappé par le CH au handshake (et pas un autre secret). C'est le
contrat que SE5 valide désormais en priorité.
```

## Actions restantes avant clôture
1. **Trancher E1** (produit) : sémantique de l'ingestion face à un lien rompu — puis coder le garde + test.
2. **Corriger E2** : étendre l'allowlist du garde-fou avec justification (report-compliance = transport read-only).
3. **Corriger E3/E11** : durcir le pull (allowlist hôte, https, no-redirect, ne jamais persister l'URL dans `pull_error`).
4. **Corriger E4** : câbler la consommation `pull_status` par ③ + réconciliation périodique des pulls orphelins.
5. **Trancher E5** (rollout) : fixer le store du watermark (`Cache::store('file')`) ou confirmer `CACHE_DRIVER` prod ; idem `withoutOverlapping` repose sur le même store.
6. **Corriger E6/E7/E8** : invalidation du catalogue mémoïsé côté worker + honnêteté du mapping `applied`/`overridden` (types sans adaptateur, `is_active`).
7. **Compléter provisioning** : `.env.example` (E25) + clé `artifact_max_bytes` (E24) + `SE4FS_INSTANCE_ID` figé (déjà tracé 39.3#2).
