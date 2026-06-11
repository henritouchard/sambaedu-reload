# Compagnon de session — contrat du sous-système (Story 24.3)

> Vue **côté serveur** du compagnon de session (portée user, FR17/NFR1).
> Complète `agent-skeleton.md` (la boucle machine du service, 24.2). Le wire
> format reste défini par `docs/agent/contract-v1.md` (FIGÉ), le transport
> par `state-endpoint.md` (`?user=`, ETag par contexte — câblés serveur en
> 23.5 : **aucun code serveur n'a changé en 24.3**), l'ACL du token par
> `enrollment.md` §3 (FIGÉ 23.3). Ce document décrit la **séquence
> d'appels** déclenchée par un logon et la frontière de confiance que le
> sous-système matérialise — c'est le contrat que vérifient les tests
> serveur `tests/Feature/Api/V1/Agent/SessionCompanionE2eTest.php`.

## 1. Le nœud de design : le token est illisible côté user

Le token agent est sous ACL `SYSTEM + Administrators` **uniquement**
(contrat figé 23.3) et NFR5 exige que les fichiers de l'agent restent hors
de portée de l'élève. **Un processus aux droits de la session ne peut donc
ni porter le bearer ni appeler le serveur.** Le « compagnon de session »
est en conséquence un sous-système en **deux moitiés**, enregistrées par
l'installeur comme tâches planifiées à déclencheur « At log on » :

| Tâche | Compte | Rôle |
|---|---|---|
| `SambaEduAgent-SessionFetch` | SYSTEM | Seul acteur **réseau** : énumère les sessions interactives (CIM), appelle `GET /state?user=<login court>` pour chacune, écrit le cache per-user. |
| `SambaEduAgent-SessionCompanion` | `BUILTIN\Users` (s'exécute dans la session du user qui ouvre, avec SES droits) | Seul acteur **session** : lit SON cache en lecture seule, parse, partitionne les portées, traite `session` + `machine_user`. Aucun réseau, aucun token. |

L'AC epic « le compagnon appelle `GET /state` avec le user de la session »
est honoré **au niveau du sous-système** : un logon **provoque** l'appel
`?user=` (observable côté serveur : `agent.state.compiled` avec `user`), et
le processus user reçoit les trois portées et n'en traite que deux.

## 2. Séquence d'un logon

```
logon user (DOMAINE\jdoe)
   │                                    ← RIEN dans le chemin synchrone du
   ├─ la session s'ouvre normalement      logon : pas de script Winlogon/
   │                                      Userinit/GPO — les deux tâches
   │                                      partent EN PARALLÈLE (NFR1)
   │
   ├─ [SYSTEM] SessionStateFetch.ps1
   │    énumère les sessions (CIM Win32_LogonSession 2/10/11
   │      + Win32_LoggedOnUser → login court + SID)
   │    GET /state?user=jdoe             Authorization: Bearer <token>
   │                                     If-None-Match: <ETag DU contexte>
   │    200 → cache\sessions\<SID>\{state.json,etag.txt}
   │    304 → cache valide (rien à écrire)
   │
   └─ [user] SessionCompanion.ps1
        poll du cache frais (2 s, timeout 60 s — asynchrone à l'ouverture)
        sinon dernier cache existant ; sinon sortie silencieuse
        Parse-State → partition → traite session + machine_user
        (24.3 : no-op journalisé — les handlers arrivent en 24.4)
        log %LOCALAPPDATA%\SambaEdu\Agent\companion.log
```

**Rafraîchissement mid-session** : à chaque itération du cycle du service
(60 min ± jitter), après la portée machine, le service ré-énumère les
sessions actives et rafraîchit chaque cache per-user — le **même code**
(`Invoke-SessionStateFetch`) que la tâche de logon. Fraîcheur laxe NFR3
(logon + timer, pas de push). La réexécution périodique du **processus
user** mid-session arrive avec les handlers (24.4).

## 3. Identité de session : résolue côté SYSTEM, jamais déclarée

Le fetch SYSTEM détermine qui est connecté par énumération CIM
(`Win32_LogonSession` LogonType 2/10/11 + `Win32_LoggedOnUser` →
`Win32_Account.Name` = **login court** sans domaine, + SID). Le processus
user ne transmet **rien** — ni au serveur, ni au fetch : un élève ne peut
pas demander l'état d'un autre login (anti-usurpation **par construction**,
cohérent avec `state-endpoint.md` : le poste SYSTEM est l'autorité sur QUI
est dans SA session). Jamais de `quser` (sortie localisée, fragile).

Login inconnu ou **compte local** (admin local hors SE5 — cas légitime) :
le serveur répond **200 machine-only** + log `agent.state.unknown_user`,
jamais d'erreur — la session locale reçoit un état (broadcasts possibles)
et le compagnon le traite sans bruit.

## 4. Cache per-user et ETag par contexte

```
C:\ProgramData\SambaEdu\Agent\cache\
├── state.json, etag.txt          ← contexte MACHINE (service 24.2)
└── sessions\<SID>\
    ├── state.json                ← enveloppe v1 du contexte (poste, user)
    └── etag.txt                  ← ETag DU contexte, verbatim (guillemets inclus)
```

- **Un ETag par couple (poste, user)** (`state-endpoint.md`) : réutiliser
  l'ETag machine pour un fetch `?user=` (ou l'inverse) casse la
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
- Écriture atomique tmp + `Move-Item` (iso 24.2), le tmp naissant dans le
  répertoire cible pour hériter de la bonne ACL.
- Pas de purge automatique en 24.3 (volume borné par les users du poste).

## 5. Partition des portées — jamais de recouvrement

| Acteur | Portées traitées |
|---|---|
| Service SYSTEM (24.2) | `machine` **seulement** |
| Compagnon (processus user) | `session` + `machine_user` **seulement** |

⚠️ `session`/`machine_user` ne sont **pas** « vides sans user » : le scope
est déclaré **par type** (ex. `wallpaper` = scope `session`), pas par
maille — un wallpaper broadcast sort en portée `session` même dans une
enveloppe machine-only. La partition ci-dessus est donc la SEULE règle :
chaque item est traité par exactement un acteur.

En 24.3 le compagnon **parse, partitionne et journalise** (no-op par item :
type, scope, mode) — il n'applique encore rien (iso-approche squelette
24.2). **Il ne rapporte rien non plus** : le rapport v1 n'a pas de
dimension user (contrat §6 FIGÉ) et le service continue ses rapports
`items: []` — la remontée des résultats session est un design de 24.4.

## 6. Frontière de confiance (NFR5) — qui détient quoi

| | token | réseau | cache machine | cache\sessions\<SID> | écritures |
|---|---|---|---|---|---|
| Service + SessionFetch (SYSTEM) | lecture/écriture | oui | R/W | R/W (tous) | ProgramData |
| Compagnon (droits user) | **illisible** (ACL 23.3) | **jamais** | illisible | **R sur LE SIEN** | `%LOCALAPPDATA%\SambaEdu\Agent\` uniquement |

- Jamais de copie du token vers un emplacement lisible user, jamais en
  argument/env d'un processus user (lisible via `Win32_Process.CommandLine`).
- Tentative d'écriture user sous `C:\ProgramData\SambaEdu\Agent\` ou
  `C:\Program Files\SambaEdu\Agent\` → Access Denied (scénario QA §3).
- Les scripts exécutés par le user vivent sous `C:\Program Files\SambaEdu\Agent\`
  (Users = read+execute par défaut), **jamais** sous ProgramData ; ils sont
  signés (bundle `Build-Agent.ps1`).
- Aucun appel AD/Kerberos/LDAP dans tout le sous-système (NFR7).

## 7. Résilience (D5, FR22)

- **Rotation `X-Agent-New-Token`** : traitée sur toute réponse du fetch de
  session, 304 compris (même `Update-TokenIfRotated` que 24.2).
- **Course deux-acteurs** : le service (cycle) et le fetch (logon)
  partagent le même fichier token. Si l'un rotate pendant qu'un appel de
  l'autre est en vol, ce dernier prend un 401 avec un `PreviousToken`
  mémoire **null**. Durcissement (implémenté UNE fois dans
  `Invoke-AgentHttpWithGrace`, profite aussi au service) — ordre sur 401 :
  (a) réessai fenêtre de grâce avec `PreviousToken` si présent ;
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

## 8. Login jamais bloquant (NFR1) — le KPI du brief

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
  **provoqué** le check-in de session. NB : ce log est un comportement
  **hérité de 23.5** (`state-endpoint.md` — aucun code serveur modifié en
  24.3), pas une garantie nouvelle de cette story ;
- login local/inconnu : `agent.state.unknown_user` (info, jamais d'erreur) ;
- `workstations.agent_last_checkin_at` avance (middleware, comme tout appel) ;
- **aucun** rapport supplémentaire (le compagnon ne rapporte rien en 24.3).

## 10. Ce que 24.4 branchera dessus

- Les **handlers session** (wallpaper user, overlay…) remplacent le no-op
  journalisé du compagnon, item par item.
- La **remontée des résultats session dans le rapport** — à résoudre SANS
  toucher le contrat v1 (pas de dimension user au §6).
- La **réexécution périodique** du processus compagnon mid-session.
- Pour l'agent définitif (binaire Go/.NET, prérequis Epic 25) : l'IPC
  named-pipe service ⇄ session est la voie naturelle, écartée pour le MVP
  PowerShell (cf. `agent/README.md`). À traiter au portage (review 24.3 #6) :
  le SID du chemin de cache vient de deux sources différentes —
  `Win32_Account.SID` (fetch CIM) vs `WindowsIdentity::GetCurrent().User`
  (compagnon). Identiques sur un parc AD classique, mais une divergence
  (AzureAD `S-1-12-1-*`, profils mandatoires) ferait sortir le compagnon
  silencieusement (cache jamais trouvé) — l'IPC supprime ce double lookup.

Code : `agent/windows/SessionStateFetch.ps1` (entrée tâche SYSTEM),
`agent/windows/SessionCompanion.ps1` (processus user, ContractV1 seul),
fonctions partagées dans `agent/windows/SambaEduAgent.ps1`
(`Get-InteractiveSessions`, `Save-SessionStateCache`,
`Invoke-SessionStateFetch`, durcissement 401).
