# `GET /api/v1/agent/state` — l'état cible servi

> Story 23.5 — Epic 23 (agent desired-state). Complète `contract-v1.md` (le
> wire format, FIGÉ — il ne documente volontairement PAS la couche HTTP) et
> `state-providers.md` (la compilation côté serveur) : ce document décrit
> le **transport** — comment un agent (ou un mainteneur en curl/jq) tire
> l'enveloppe compilée, et le contrat HTTP qu'Epic 24 doit respecter.
> Le rapport remonte par `POST /api/v1/agent/report` — voir
> `report-endpoint.md` (Story 24.1).

## L'endpoint

| | |
|---|---|
| URL | `GET /api/v1/agent/state` (route `agent.v1.state`) |
| Auth | bearer token per-poste (canal 23.2/23.3) — l'identité de la requête EST le poste résolu par le token, jamais un paramètre |
| Middlewares | `auth.v1.secure-headers` + `throttle:60,1` + `agent.token` |
| Corps 200 | l'enveloppe `se5.desired-state/v1` **BRUTE** (`{schema, generated_at, ttl_seconds, machine, session, machine_user}`) — aucun wrapper SE5 `{success, …}` |
| Écritures | aucune côté controller — `agent_last_checkin_at` et la rotation sont gérés par le middleware `agent.token` |

Le throttle (60/min/IP) est posé **avant** `agent.token` : le lookup DB du
middleware est protégé du flood. Pas de `local.request` : l'auth est le
token (un poste en VPN admin peut tirer son état) ; les `secure-headers`
posent `Cache-Control: no-store` — sans incidence sur la revalidation
ci-dessous, qui ne repose pas sur un cache HTTP.

Diagnostic mainteneur (avant qu'aucun agent n'existe) :

```bash
curl -sS -H "Authorization: Bearer $TOKEN" \
  https://se4fs/api/v1/agent/state | jq .
```

## `?user=<login>` — le contexte de session

L'état est `f(poste, user)`. Le user de session est **optionnel** :

- **Absent ou vide** → compilation machine-only : les mailles user
  (`user`, groupes user) ne contribuent rien. C'est le check-in du service
  SYSTEM au boot (24.2).
- **Login connu** → les règles ciblées user / groupes user du contexte
  sortent. C'est le compagnon de session (24.3). Lookup **case-insensitive**
  (`JDOE` = `jdoe`, sémantique AD).
- **Login inconnu ou compte local** (admin local, compte hors SE5 — cas
  légitime du compagnon) → comportement machine-only + log
  `agent.state.unknown_user`, **jamais d'erreur** : une session locale doit
  recevoir un état.

Pas de cloisonnement par user dans la portée du token : le poste (SYSTEM)
est l'autorité sur QUI est dans SA session — demander l'état pour un user,
c'est lire SON état.

⚠️ `user=null` ne vide PAS mécaniquement les portées `session` /
`machine_user` : le scope est déclaré **par type** (ex. `wallpaper` =
`session`), pas par maille. Un wallpaper broadcast sort en portée `session`
même sans user — « vides » se lit « vides de toute contribution user ».
Le service SYSTEM ne traite que `machine` de toute façon.

## ETag / If-None-Match — la réponse conditionnelle

- Le header `ETag` de la réponse 200 porte `StateHasher::hashState()` du
  corps, sous la **forme quotée** RFC 7232 : `ETag: "6c0e8135…"`.
- L'agent stocke le header **verbatim** et le renvoie **verbatim** dans
  `If-None-Match` au prochain poll. Le hash est **opaque de bout en bout** :
  jamais de recalcul, jamais de déquotage côté agent.
- Correspondance → **304 sans corps**, ETag conservé sur la réponse. Le
  serveur économise le CORPS, pas le calcul : l'état est **recompilé à
  chaque hit** (pas de cache serveur — D1, optimisation différée à mesurer).
- `generated_at` est exclu du hash (contrat §4) : deux compilations du même
  état à des instants différents → même ETag. Seul un changement
  **sémantique** (nouvelle règle, règle modifiée) invalide l'ETag.

**Un ETag par couple (poste, user)** : deux contextes = deux états = deux
hashes. Le service machine et le compagnon de session tiennent chacun LEUR
`If-None-Match` — cache par contexte de requête, pas global (Epic 24).

### Rotation sur 304

Le header `X-Agent-New-Token` (rotation 23.2) survit au 304 : rotation due
+ état inchangé est un cas réel (réponse de rotation perdue puis
re-check-in). L'agent doit traiter ce header sur **toute** réponse du
canal, y compris 304 — invariant du recouvrement D5, testé.

### « Forcer la synchro » — bypass du 304 pendant une demande (Story 24.7)

L'admin peut **forcer une resynchronisation** d'un poste depuis l'UI parc
(FR11). Le mécanisme est du **pull pur**, pas un push : poser une demande
revient à écrire un timestamp `workstations.agent_sync_requested_at`. Tant
que cette demande est **pendante** :

- `GET /api/v1/agent/state` (tous contextes : machine ET `?user=`) répond
  **200 corps complet même si `If-None-Match` concorde** — le 304 est
  bypassé. RIEN d'autre ne change : MÊME `ETag`, enveloppe contrat BRUTE,
  aucun recalcul de hash. Le controller reste **zéro write** (la lecture du
  flag ne consomme PAS la demande — elle vit pour TOUS les fetchs du même
  cycle).
- Effet poste : le cache `state.json` est réécrit → `mtime` change → le
  compagnon rejoue les handlers ≤ ~60 s après le check-in. L'agent restocke
  le même `ETag` verbatim et reprend ses 304 au cycle suivant (un 200 « au
  lieu d'un 304 » est un chemin nominal de l'agent).
- La demande est **soldée** au premier `POST /api/v1/agent/report` suivant
  (`agent_sync_requested_at` remis à null) — voir `report-endpoint.md`.
  Le cycle agent étant GET(s) → … → report, tous les contextes ont bénéficié
  du bypass avant le solde.

`agent_sync_requested_at` a exactement **deux écrivains** : l'UI admin (pose
la demande) et `ReportController` (la solde). `StateController` ne l'écrit
jamais. Un log debug `agent.sync.state_forced` (channel `agent`) trace
chaque bypass.

**Latence honnête (D7)** : la demande est servie au **prochain contact**
(timer ≤ 60 min + jitter, ou boot/login immédiats) — c'est le modèle pull.
Le binaire Go 2.1.x **ignore `ttl_seconds` pour sa planification**
(`agent/shared/loop.go` : l'intervalle vient de `config.json`) : aucun
raccourcissement du poll par ttl dynamique n'est possible sans modifier
l'agent. **Évolution** : quand l'auto-update (25.x) portera un agent qui
honore `ttl_seconds`, un ttl dynamique pourra réduire cette latence — le
bypass 304 reste valable en attendant. Aucun push (WoL/reboot/WinRM) :
choix anti-couteau-suisse (NFR3).

## Codes de réponse

| Code | Sens | Corps |
|---|---|---|
| 200 | état compilé | enveloppe v1 brute + header `ETag` |
| 304 | `If-None-Match` à jour | vide (ETag conservé, `X-Agent-New-Token` possible) |
| 401 | bearer absent (`AGENT_TOKEN_MISSING`) ou invalide/révoqué (`AGENT_TOKEN_INVALID`) | `{error, message, code}` (format middleware 23.2) |
| 403 | poste en quarantaine (`AGENT_QUARANTINED`) | `{error, message, code}` |
| 429 | throttle 60/min/IP dépassé | réponse Laravel standard (hors contrat) |

## `config/agent.php` — clés du canal (gap 4 résolu)

| Clé | Défaut | Rôle |
|---|---|---|
| `ttl_seconds` | 3600 | cadence de poll conseillée, champ `ttl_seconds` de l'enveloppe (D7 : 60 min, aligné golden file). Indicatif — le serveur ne refuse pas un poll plus fréquent |
| `report_history` | `false` | flag D3 : historique de débogage des rapports (consommé en 24.1) |
| `report_events_retention_days` | 14 | rétention du journal des changements rapportés (« rétention courte » D3, purge 24.1) |
| `report_history_retention_days` | 30 | purge auto de l'historique de débogage |
| `token_rotation_days` | 30 | échéance de rotation du token (23.2, inchangé) |
| `enroll_ticket_ttl_minutes` | 240 | TTL du ticket d'enrôlement one-time (23.3, inchangé) |

Toutes les clés numériques portent un plancher `max(1, …)` évalué au
`config:cache` : une env mal renseignée (0, négatif, vide → `null`) ne
produit jamais de valeur dégénérée. La lecture du compilateur est durcie en
plus (`config('agent.ttl_seconds') ?? 3600`) : une clé **présente mais
null** ne produit plus `ttl_seconds: 0` (defer review 23.4).

## Ce que l'agent (Epic 24) doit retenir

1. Parser le corps 200 comme l'enveloppe contrat v1 — pas de wrapper à
   déballer.
2. Stocker l'`ETag` verbatim, le renvoyer verbatim, **par contexte**
   (machine-only ≠ avec user).
3. Traiter `X-Agent-New-Token` sur toute réponse, 304 compris.
4. `ttl_seconds` = cadence de poll conseillée, pas une expiration de bail.
5. 401 → re-enrôlement (porte 2, Story 25.3) ; 403 → quarantaine, check-ins
   légers uniquement.
