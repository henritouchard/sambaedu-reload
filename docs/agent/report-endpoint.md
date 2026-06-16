# `POST /api/v1/agent/report` — l'ingestion des rapports

> Story 24.1 — Epic 24 (agent desired-state). Complète `contract-v1.md`
> (le wire format du rapport, FIGÉ — §6 + golden
> `tests/Fixtures/Agent/report.v1.json`) et `state-endpoint.md` (l'aller) :
> ce document décrit le **retour** — comment l'agent rapporte la conformité
> par item, et ce que le serveur en stocke (D3/FR9).

## L'endpoint

| | |
|---|---|
| URL | `POST /api/v1/agent/report` (route `agent.v1.report`) |
| Auth | bearer token per-poste (canal 23.2/23.3) — **l'identité de la requête EST le poste résolu par le token**, jamais le payload |
| Middlewares | `auth.v1.secure-headers` + `throttle:60,1` + `agent.token` (chaîne iso `GET /state`) |
| Corps requête | le rapport `se5.desired-state/v1` (§6 du contrat, golden `report.v1.json`) |
| Corps 200 | ACK serveur→agent au format SE5 : `{"success": true, "counts": {"compliant": n, "drift": n, "error": n}}` — l'agent n'a besoin que du 2xx (Story 27.8 : `drifted_allowed` retiré, 3 statuts) |
| Écritures | tables `agent_*` uniquement (`ReportIngestService`) — `agent_last_checkin_at` et la rotation restent gérés par le middleware `agent.token` |

Contrairement à `GET /state`, l'ACK est un wrapper SE5 (pas l'enveloppe
brute) : aucun hash/ETag n'est calculé sur ce corps — seul le state sert le
contrat brut.

Diagnostic mainteneur (poste enrôlé, avant qu'aucun agent n'existe) :

```bash
curl -sS -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  --data @tests/Fixtures/Agent/report.v1.json \
  https://se4fs/api/v1/agent/report | jq .
```

## Identité = le token, jamais le payload (décision n° 1)

Le bloc `workstation {hostname, uuid}` du rapport est **déclaratif** (aide
au debug agent) : il est validé en forme mais **jamais utilisé pour
résoudre le poste**. Une divergence entre l'identité déclarée et le poste
authentifié produit un log `warning` (`agent.report.identity_mismatch`,
contexte borné) et l'ingestion **poursuit** — l'anti-clonage MAC est le
travail du middleware 23.2, pas du report.

## Stockage D3 — trois tables, volume borné

| Table | Rôle | Borne |
|---|---|---|
| `agent_resource_states` | état COURANT par (poste, type), upsert | UNIQUE `(workstation_id, type)` : « conforme = 1 ligne », jamais N rapports = N lignes |
| `agent_report_events` | journal des SEULS changements, append-only | rétention `report_events_retention_days` (14 j) |
| `agent_report_history` | payloads bruts complets, append-only, **debug** | flag `AGENT_REPORT_HISTORY` (défaut **off**) + rétention `report_history_retention_days` (30 j) ; table au retrait prévu (D3) |

`reported_at` de la ligne d'état est rafraîchi à **chaque** rapport, même
identique (fraîcheur du dernier rapport = donnée UI 24.5) — « identique »
n'inhibe que la création d'ÉVÉNEMENT.

### Règle de création d'événement (décision n° 2)

Pour chaque item, le serveur compare `(status, hash)` entrant à la ligne
d'état existante — **égalité de chaînes opaques**, jamais de recalcul :

- **ligne absente** (premier rapport du type) : événement seulement si
  `status ≠ compliant` (un premier « tout va bien » n'est pas un
  changement) ;
- **`(status, hash)` identiques** : aucun événement (rapport identique) ;
- **différents** : événement, **sauf** transition `compliant → compliant`
  (la cible a bougé et l'agent a convergé silencieusement — le hash de la
  ligne d'état est mis à jour, c'est suffisant).

L'événement porte `previous_status` (null si ligne absente), `status`,
`hash`, `detail`.

L'ingestion est **transactionnelle** : état + événements + history d'un
rapport sont atomiques. Un rapport **malformé est rejeté en 422 AVANT toute
écriture** — et l'ingestion **ne hashe jamais le payload agent** (les
hashes du rapport sont les chaînes opaques `StateHasher` reçues de
`GET /state`, stockées telles quelles) : un body forgé (UTF-8 invalide,
NAN/INF) produit un 4xx, jamais un 500 (defer review 23.1, résolu ici).

### Solde d'une demande « forcer la synchro » (Story 24.7)

Après l'ingestion, le controller **solde** une éventuelle demande de
resynchronisation pendante (`workstations.agent_sync_requested_at`, posée par
l'UI parc — voir `state-endpoint.md` § « Forcer la synchro ») :
`agent_sync_requested_at` est remis à **null**, un log
`agent.sync.fulfilled` (channel `agent`) est émis. **No-op** si aucune
demande n'était pendante (le cas nominal).

C'est l'invariant des **deux écrivains** de cette colonne : l'UI admin la
pose, `ReportController` la solde. Le cycle agent étant GET(s) → … → report,
tous les contextes du cycle ont déjà bénéficié du bypass 304 (200 forcé)
**avant** ce solde. L'écriture de la colonne est indépendante de la
transaction d'ingestion D3 (colonne `agent_*`, idempotente). Une demande sur
un poste en **quarantaine** ne sera jamais soldée (403 au report) — l'UI
désactive le bouton pour ces postes.

## Purge — `agent:reports:prune`

Commande artisan planifiée daily 02:35 (`app/Console/Kernel.php`) :

- `agent_report_events` au-delà de `config('agent.report_events_retention_days')` ;
- `agent_report_history` au-delà de `config('agent.report_history_retention_days')` —
  **inconditionnelle au flag** (vide si off, nettoie les résidus d'une
  phase de debug terminée).

## Codes de réponse

| Code | Cas | Corps |
|---|---|---|
| 200 | rapport ingéré (y compris `items: []` — agent sans rien à rapporter) | `{success, counts}` |
| 401 | bearer absent (`AGENT_TOKEN_MISSING`) ou invalide (`AGENT_TOKEN_INVALID`) | `{error, message, code}` (middleware 23.2) |
| 403 | poste en quarantaine (`AGENT_QUARANTINED`) — **aucune** écriture de rapport | `{error, message, code}` |
| 422 | rapport malformé : `schema` inconnu, `items` absent/mal typé, `status` hors enum, `hash` non hex-64, `detail` absent/vide sur `error`, `type` hors liste publiée (§7) ou dupliqué — **rien n'est écrit** | `{message, errors}` (ValidationException Laravel) |
| 429 | throttle 60/min/IP dépassé | réponse Laravel standard (hors contrat) |

Champs **inconnus ignorés** (règle d'évolution §9 : champ ajouté = version
mineure — le serveur tolère l'inconnu).

## Ce que l'agent (Epic 24) doit retenir

1. POSTer le rapport au schéma exact `se5.desired-state/v1` — un schéma
   inconnu est refusé (miroir de « l'agent refuse un major inconnu »).
2. **Renvoyer le hash reçu de `GET /state` tel quel** — opaque, jamais
   recalculé ni tronqué (hex-64 strict).
3. `detail` est **obligatoire et non vide** quand `status = error`
   (≤ 2000 caractères) ; optionnel sinon.
4. Un seul item par `type` et par rapport (les doublons → 422).
5. Traiter `X-Agent-New-Token` sur **toute** réponse du report, 200 compris
   (invariant D5 — la rotation survit au POST).
6. `items: []` est un rapport valide : le check-in est stampé même sans
   contenu.
