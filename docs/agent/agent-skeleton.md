# Agent core Windows — contrat de boucle (Story 24.2, réécrit en Go par 24.5)

> Vue **côté serveur** de ce que fait l'agent (`agent/` top-level, **binaire
> Go** `agent.exe` en service Windows SYSTEM depuis la story 24.5 — le spike
> PowerShell 24.2 est retiré, ses décisions de design sont conservées). Le
> wire format reste défini par `docs/agent/contract-v1.md` (FIGÉ) ;
> l'enrôlement et le chemin du token par `docs/agent/enrollment.md`
> (FIGÉ 23.3). Ce document décrit la **séquence d'appels** attendue et les
> invariants que l'agent honore — c'est le contrat que vérifient les tests
> serveur `tests/Feature/Api/V1/Agent/AgentSkeletonE2eTest.php` (inchangés
> par la bascule Go : la boucle est identique vue du serveur).

## 1. La boucle (boot + timer 60 min ± 10 %)

```
lire token (C:\ProgramData\SambaEdu\Agent\token — relu à CHAQUE cycle)
   │
   ▼
GET /api/v1/agent/state          Authorization: Bearer <token>
                                 If-None-Match: <ETag du cache, verbatim>
   │ 200 → persister cache\state.json + cache\etag.txt (ETag verbatim)
   │ 304 → cache local valide (rien à persister)
   ▼
POST /api/v1/agent/report        items: []  (core 24.5 : handlers Go → 24.6)
   │ 200 {success: true, counts: {…0…}}
   ▼
attendre interval (3600 s + jitter ±10 %) puis recommencer
```

Points fermes :

- **Le rapport part aussi sur 304** : état inchangé en cache = on rapporte
  quand même (la fraîcheur `agent_last_checkin_at` + le rapport sont le
  signal de vie du poste).
- **Pas de `?user=`** : le service SYSTEM est portée machine uniquement. Le
  compagnon de session (24.3) ajoutera `GET /state?user=<login>`.
- **ETag opaque verbatim** : stocké et renvoyé avec les guillemets RFC 7232
  (ex. `"6c0e8135…"`). Le serveur (`StateController::isNotModified()`) gère
  guillemets/`W/`/listes — mais l'agent ne déquote jamais.

## 2. Identité déclarée dans le rapport

```json
"workstation": { "hostname": "SALLE101-PC03", "uuid": "<UUID SMBIOS>" }
```

- **`hostname` = nom COURT** (`os.Hostname()` Go = computer name, fallback
  `COMPUTERNAME` — ex-`$env:COMPUTERNAME` du spike PS), **jamais le FQDN** —
  résolution du defer review 24.1 #8. Le `ReportController` compare (insensible
  à la casse) ce champ à `workstations.name` (nom court d'enrôlement) : un FQDN
  (`salle101-pc03.sambaedu.lan`) déclenche le warning
  `agent.report.identity_mismatch` **à chaque rapport** (le rapport reste
  accepté — l'identité réelle est le token — mais le channel `agent` est
  spammé). Tout futur agent DOIT envoyer le nom court.
- **`uuid` = UUID SMBIOS** (`Get-CimInstance Win32_ComputerSystemProduct` —
  shell-out PowerShell depuis le binaire Go, échappatoire admise par
  l'addendum architecture, même source que le spike),
  envoyé tel quel — comparaison insensible à la casse côté serveur. Certains
  firmwares exposent un UUID **placeholder** (vide, `FFFFFFFF-…`, `00000000-…`) :
  l'agent l'envoie quand même (champ déclaratif) mais logue un `WARNING` local —
  côté serveur, des warnings `agent.report.identity_mismatch` sont alors
  attendus sur ce poste (le rapport reste accepté, l'identité réelle est le
  token).
- **`X-Agent-Mac` n'est PAS envoyé** (décision 24.2 maintenue en Go) : le
  middleware 23.2 **quarantaine** sur mismatch MAC, et un poste multi-NIC
  (Wi-Fi, dock) présenterait facilement une autre MAC que celle d'enrôlement
  (PXE) → faux positif. L'anti-clonage MAC est donc **inerte** tant que ce
  header n'est pas câblé avec une sélection de NIC fiable.
- L'identité qui fait foi est **le token** (décision 24.1 n° 1) : le bloc
  `workstation` est déclaratif (debug), jamais utilisé pour résoudre le poste.

## 3. Format du rapport squelette

```json
{
  "schema": "se5.desired-state/v1",
  "generated_at": "2026-06-11T08:05:00Z",
  "agent_version": "2.0.0",
  "workstation": { "hostname": "SALLE101-PC03", "uuid": "f1d2c3b4-…" },
  "items": []
}
```

`items: []` est **valide** (validé 24.1, règle `present` pas `required`) :
réponse `200 {success: true, counts: {compliant: 0, drift: 0,
drifted_allowed: 0, error: 0}}`, aucune ligne `agent_resource_states` écrite,
`agent_last_checkin_at` stampé par le middleware. Les items réels arrivent
avec les handlers Go (24.6). `agent_version` = `2.0.0` (lignée Go — les
rapports `1.x` étaient le spike PS).

## 4. Codes HTTP que l'agent gère

| Code | Signification | Réaction de l'agent |
|---|---|---|
| 200 | OK | `GET /state` : persister cache + ETag ; `POST /report` : boucle fermée. |
| 304 | État inchangé | Réutiliser le cache, **poursuivre vers le rapport**. |
| 401 | `AGENT_TOKEN_MISSING` / `AGENT_TOKEN_INVALID` | Si une rotation vient d'avoir lieu : **un** réessai avec l'ancien token (fenêtre de grâce D5). Sinon : **arrêt + log local** — JAMAIS de re-enrôlement automatique (intervention admin). |
| 403 | `AGENT_QUARANTINED` | **Check-ins légers** : `GET /state` continue à cadence normale (le serveur surveille la présence pour lever la quarantaine), plus aucun `POST /report` ni traitement d'état. Pas de boucle agressive. |
| 429 | Throttle (`throttle:60,1`) | Backoff (le timer 60 min n'atteint jamais ce seuil en nominal). |
| 5xx / timeout / réseau | Serveur injoignable | L'agent vit sur son **dernier cache**, retries en backoff exponentiel **30 s → 60 s → 120 s → … plafonné à 3600 s**, log local du délai. |

### Rotation du token (invariant D5)

Header `X-Agent-New-Token` possible sur **toute** réponse du canal (200 du
state, **304** du state, 200 du report, même un 422) : l'agent écrit le
nouveau token sur disque (écriture atomique, ACL SYSTEM + Administrators,
64 hex sans newline) et conserve l'ancien en mémoire ; le prochain check-in
avec le nouveau token ferme la fenêtre de grâce côté serveur.

## 5. Fichiers locaux du poste (récapitulatif)

| Fichier | Rôle |
|---|---|
| `C:\ProgramData\SambaEdu\Agent\token` | Token bearer (CONTRAT FIGÉ 23.3 — `enrollment.md` §3). |
| `C:\ProgramData\SambaEdu\Agent\config.json` | `server_url` + `interval_seconds` (défaut 3600) — posé par `agent.exe install`. |
| `C:\ProgramData\SambaEdu\Agent\cache\state.json` | Dernière enveloppe état (brute). |
| `C:\ProgramData\SambaEdu\Agent\cache\etag.txt` | Dernier ETag, verbatim. |
| `C:\ProgramData\SambaEdu\Agent\applied-state.json` | Dernier-appliqué par item (créé/préservé vide `{}` — consommé par les handlers 24.6, mode `default`). |
| `C:\ProgramData\SambaEdu\Agent\logs\agent.log` | Log local `[ISO 8601] [LEVEL] message`, rotation quotidienne, 7 j. |

Tous sous ACL `SYSTEM` + `Administrators` uniquement (icacls `*S-1-5-18` /
`*S-1-5-32-544` — convention 23.3).

## 6. Ce que le serveur observe (vérification AC8)

Après le premier cycle d'un poste enrôlé :

- `workstations.agent_last_checkin_at` mis à jour (stampé par le middleware
  `agent.token` sur chaque appel authentifié) ;
- channel `agent` : `agent.state.compiled` (ou `agent.state.not_modified`) +
  `agent.report.received` avec `counts` à zéro ;
- aucune ligne `agent_resource_states` tant que `items: []` (normal — les
  lignes apparaîtront avec les handlers Go 24.6).

Code agent : `agent/` (top-level, hors Laravel — module Go `sambaedu/agent` :
`shared/` cœur OS-agnostique testé sur l'hôte, `windows/` service SYSTEM,
`build/build.sh` binaire statique signé Authenticode) — décision techno
(gate Go résolu) et contrats locaux dans `agent/README.md`.
