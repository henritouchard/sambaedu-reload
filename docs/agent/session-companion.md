# Compagnon de session — contrat du sous-système

> Vue **côté serveur** du compagnon de session : la séquence d'appels qu'un
> logon déclenche et la frontière de confiance que le sous-système
> matérialise (portée user). Complète [agent-skeleton.md](agent-skeleton.md)
> (boucle machine du service). Orthogonal au transport, défini par
> [state-endpoint.md](state-endpoint.md) (`?user=`, ETag par contexte), au
> wire format figé par [contract-v1.md](contract-v1.md), et à l'ACL du token
> décrite par [enrollment.md](enrollment.md) §3.
>
> **Implémentation : le binaire Go** (`agent/`). Les deux moitiés sont des
> sous-commandes du MÊME binaire signé : `agent.exe session-fetch` (SYSTEM)
> et `agent.exe companion` (user, résident).

## 1. Le nœud de design : le token est illisible côté user

Le token agent est sous ACL `SYSTEM + Administrators` **uniquement** et les
fichiers de l'agent restent hors de portée de l'élève. **Un processus aux
droits de la session ne peut donc ni porter le bearer ni appeler le
serveur.** Là où une GPO de logon s'exécutait dans le contexte du poste, le
« compagnon de session » est un sous-système en **deux moitiés**,
enregistrées par l'installeur comme tâches planifiées à déclencheur « At
log on » :

| Tâche | Compte | Rôle |
|---|---|---|
| `SambaEduAgent-SessionFetch` (`agent.exe session-fetch`) | SYSTEM | Seul acteur **réseau** : énumère les sessions interactives (WTS), appelle `GET /state?user=<login court>` pour chacune, écrit le cache per-user, synchronise les assets. |
| `SambaEduAgent-SessionCompanion` (`agent.exe companion`) | `BUILTIN\Users` (s'exécute dans la session du user qui ouvre, avec SES droits) | Seul acteur **session**, résident : lit SON cache en lecture seule, parse, partitionne les portées, traite `session` + `machine_user` (moteur §5 + handlers). Aucun réseau, aucun token. |

Un logon **provoque** l'appel `?user=` (observable côté serveur :
`agent.state.compiled` avec `user`), et le processus user reçoit les trois
portées et n'en traite que deux.

## 2. Séquence d'un logon

```
logon user (DOMAINE\jdoe)
   │                                    ← RIEN dans le chemin synchrone du
   ├─ la session s'ouvre normalement      logon : pas de script Winlogon/
   │                                      Userinit/GPO — les deux tâches
   │                                      partent EN PARALLÈLE
   │
   ├─ [SYSTEM] agent.exe session-fetch
   │    énumère les sessions (WTS, états Active/Disconnected,
   │      WTSUserName → login court ; LookupAccountName → SID)
   │    GET /state?user=jdoe             Authorization: Bearer <token>
   │                                     If-None-Match: <ETag DU contexte>
   │    200 → cache\sessions\<SID>\{state.json,etag.txt}
   │    304 → cache valide (rien à écrire)
   │    puis sync des assets wallpaper (SHA-256 vérifié)
   │
   └─ [user] agent.exe companion
        poll du cache frais (2 s, timeout 60 s — asynchrone à l'ouverture)
        sinon dernier cache existant ; sinon attente RÉSIDENTE
        ParseState → partition → moteur §5 → handlers session + machine_user
        applied-state per-user, drop per-SID, puis boucle résidente
        log %LOCALAPPDATA%\SambaEdu\Agent\companion.log
```

**Rafraîchissement mid-session** : à chaque itération du cycle du service
(60 min ± jitter), après la portée machine, le service ré-énumère les
sessions actives et rafraîchit chaque cache per-user — le **même code**
(in-process, `shared/sessionfetch.go`) que la tâche de logon. Le **processus
user** résident re-converge sur changement du cache (mtime) et
périodiquement (level-triggered).

## 3. Identité de session : résolue côté SYSTEM, jamais déclarée

Le fetch SYSTEM détermine qui est connecté par énumération **WTS**
(`WTSEnumerateSessions` états Active/Disconnected + `WTSUserName` =
**login court** sans domaine) ; SID résolu par `LookupAccountName`, liste
blanche `S-1-5-21-` + garde login non vide + dédoublonnage par SID. Le
processus user ne transmet **rien** — ni au serveur, ni au fetch : un élève
ne peut pas demander l'état d'un autre login (anti-usurpation **par
construction**, cohérent avec [state-endpoint.md](state-endpoint.md) : le
poste SYSTEM est l'autorité sur QUI est dans SA session). Jamais de `quser`
(sortie localisée, fragile).

Login inconnu ou **compte local** (admin local hors SE5 — cas légitime) :
le serveur répond **200 machine-only** + log `agent.state.unknown_user`,
jamais d'erreur — la session locale reçoit un état (broadcasts possibles)
et le compagnon le traite sans bruit.

## 4. Cache per-user et ETag par contexte

```
C:\ProgramData\SambaEdu\Agent\cache\
├── state.json, etag.txt          ← contexte MACHINE (service)
└── sessions\<SID>\
    ├── state.json                ← enveloppe v1 du contexte (poste, user)
    └── etag.txt                  ← ETag DU contexte, verbatim (guillemets inclus)
```

- **Un ETag par couple (poste, user)** ([state-endpoint.md](state-endpoint.md)) :
  réutiliser l'ETag machine pour un fetch `?user=` (ou l'inverse) casse la
  revalidation (faux 200/304) — d'où un `etag.txt` **par répertoire de
  session**, clé = **SID** (stable, jamais un nom localisé).
- ACL posée par le fetch SYSTEM à la création du répertoire :
  `/inheritance:r`, `*S-1-5-18:(OI)(CI)F`, `*S-1-5-32-544:(OI)(CI)F`,
  `<SID>:(OI)(CI)R` — le user **lit** son état, n'écrit rien, ne lit pas
  celui d'un autre SID. Les fichiers héritent (pas d'icacls par fichier :
  un ré-ACL SYSTEM+Admins retirerait le R du user). Les parents (`cache\`,
  `sessions\`) restent SYSTEM+Admins : le user n'énumère pas l'arborescence
  mais ouvre son fichier par chemin complet (bypass traverse checking,
  défaut Windows pour Users).
- Écriture atomique tmp + `Move-Item`, le tmp naissant dans le répertoire
  cible pour hériter de la bonne ACL.
- Pas de purge automatique (volume borné par les users du poste).

## 5. Partition des portées — jamais de recouvrement

| Acteur | Portées traitées |
|---|---|
| Service SYSTEM | `machine` **seulement** |
| Compagnon (processus user) | `session` + `machine_user` **seulement** |

⚠️ `session`/`machine_user` ne sont **pas** « vides sans user » : le scope
est déclaré **par type** (ex. `wallpaper` = scope `session`), pas par
maille — un wallpaper broadcast sort en portée `session` même dans une
enveloppe machine-only. La partition ci-dessus est donc la SEULE règle :
chaque item est traité par exactement un acteur.

Le compagnon **converge réellement** (moteur §5 + handlers
wallpaper/shortcuts/printers/drives). **Il ne poste rien** : le rapport v1
n'a pas de dimension user (contrat §6) — il dépose ses résultats dans SON
drop per-SID, que le service collecte, valide strictement et rapporte (cf.
[handlers-wallpaper-overlay.md](handlers-wallpaper-overlay.md) §6).

**Exception `overlay`.** L'écriture de `overlay.json` est faite par le
**SERVICE SYSTEM au logon** (abonnement session-change `WTS_SESSION_LOGON`,
`overlay_logon_windows.go`), pour l'infalsifiabilité. Le fichier reste au
chemin per-user `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` mais il est
**possédé par SYSTEM avec ACL `<SID>:R`** — l'élève **lit**, ne **falsifie
jamais** la donnée affichée. `overlay` est donc le seul item de portée
`session` traité côté **SYSTEM** (wallpaper/shortcuts/printers/drives
restent au compagnon). Le compagnon conserve en plus un **watchdog
Rainmeter** (relance du rendu, droits user).

## 6. Frontière de confiance — qui détient quoi

| | token | réseau | cache machine | cache\sessions\<SID> | écritures |
|---|---|---|---|---|---|
| Service + SessionFetch (SYSTEM) | lecture/écriture | oui | R/W | R/W (tous) | ProgramData |
| Compagnon (droits user) | **illisible** (ACL) | **jamais** | illisible | **R sur LE SIEN** | `%LOCALAPPDATA%\SambaEdu\Agent\` uniquement |

- Jamais de copie du token vers un emplacement lisible user, jamais en
  argument/env d'un processus user (lisible via `Win32_Process.CommandLine`).
- Tentative d'écriture user sous `C:\ProgramData\SambaEdu\Agent\` ou
  `C:\Program Files\SambaEdu\Agent\` → Access Denied — à l'exception cadrée
  du drop `reports\sessions\<SON SID>\` (ACL `<SID>:M`).
- Le binaire exécuté par le user vit sous `C:\Program Files\SambaEdu\Agent\
  agent.exe` (Users = read+execute par défaut), **jamais** sous
  ProgramData ; il est signé Authenticode (pipeline `build-agent.sh`).
- Aucun appel AD/Kerberos/LDAP dans tout le sous-système — le compagnon ne
  construit jamais le client HTTP (aucun code réseau dans
  `shared/companion.go` ni `windows/companion_windows.go`).

## 7. Résilience

- **Rotation `X-Agent-New-Token`** : traitée sur toute réponse du fetch de
  session, 304 compris (même couche HTTP `shared/client.go` que la portée
  machine — jamais un second client).
- **Course deux-acteurs** : le service (cycle) et le fetch (logon)
  partagent le même fichier token. Si l'un rotate pendant qu'un appel de
  l'autre est en vol, ce dernier prend un 401 avec une grâce mémoire
  **vide**. Durcissement (`shared/client.go`, profite aussi au service) —
  ordre sur 401 :
  (a) réessai fenêtre de grâce avec l'ancien token mémoire si présent ;
  (b) **relecture du token sur disque** — s'il diffère des tokens essayés,
  UN réessai ; (c) sinon irrécupérable → arrêt + log, jamais de
  re-enrôlement automatique.
- **Quarantaine (403)** : aucun fetch de session, aucun traitement d'état —
  les check-ins légers restent le `GET /state` machine du service. (La
  tâche at-logon, processus neuf, peut tenter UN fetch qui prend le 403 et
  s'arrête là.)
- **Serveur injoignable au logon** : la session s'ouvre normalement, le
  compagnon vit sur le dernier cache (sinon sortie silencieuse) ; le fetch
  raté est rattrapé au prochain cycle du service — pas de retry agressif,
  pas de backoff propre au fetch.

## 8. Login jamais bloquant

**Rien dans le chemin synchrone du logon** : pas de script
Winlogon/Userinit, pas de GPO logon script, pas d'attente réseau bloquante.
Les tâches à déclencheur logon s'exécutent en parallèle de l'ouverture ;
l'attente bornée du compagnon (poll du cache) est asynchrone à l'ouverture.
Procédure de mesure (3 logons serveur joignable vs 3 débranché, événements
Winlogon / création d'`explorer.exe`) : `docs/qa/domains/agent.md` §3.

## 9. Ce que le serveur observe (vérification lab)

Après un logon d'un user du domaine sur un poste enrôlé :

- channel `agent` : `agent.state.compiled` avec `user=<login>` (ou
  `agent.state.not_modified` avec `user`) — la preuve que le logon a
  **provoqué** le check-in de session ;
- login local/inconnu : `agent.state.unknown_user` (info, jamais d'erreur) ;
- `workstations.agent_last_checkin_at` avance (middleware, comme tout appel) ;
- **aucun** rapport supplémentaire (le compagnon ne rapporte rien).

## 10. Résolution du SID — un seul sous-système de sécurité

Les deux côtés résolvent le SID via le **même sous-système de sécurité
Win32 (LSA)** : le fetch résout `LookupAccountName(DOMAIN\login)`
(`windows.LookupSID`), le compagnon lit le SID de **son token de processus**
(`OpenCurrentProcessToken` → `GetTokenUser`) — pour un même compte loggé,
LSA retourne le même SID des deux côtés (le token du processus du user EST
l'objet que LSA a construit pour ce compte), ce qui garantit que le
compagnon trouve le cache à `cache\sessions\<SID>\`.

Limite résiduelle : un compte AzureAD (`S-1-12-1-*`) est hors liste blanche
`S-1-5-21-` côté fetch — il n'a donc NI cache NI drop, et son compagnon
reste résident sans converger (silencieux, cohérent : parc AD on-prem).

Code : `agent/shared/sessionfetch.go` (fetch, un seul code logon + cycle),
`agent/shared/companion.go` (processus user — ni réseau ni token),
`agent/windows/sessions_windows.go` (WTS + SID), `agent/shared/client.go`
(durcissement 401 deux-acteurs), sous-commandes dans
`agent/windows/main_windows.go`.
