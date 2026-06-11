# Enrôlement du poste — porte 1 (installation iPXE)

> **Story 23.3** (Epic 23 — successeur GPO, agent desired-state).
> Ce document décrit la **naissance** du token agent sur la chaîne d'install
> iPXE Windows. Le cycle de vie du token (rotation, révocation, anti-clonage)
> est décrit dans [token-lifecycle.md](token-lifecycle.md) ; le contenu JSON
> échangé est figé par [contract-v1.md](contract-v1.md).

## 1. Vue d'ensemble

Deux portes d'entrée vers l'état « enrôlé » :

- **Porte 1 (ce document)** — postes installés par la chaîne iPXE : l'admin
  est déjà authentifié au menu iPXE (story 4.10), un **ticket d'enrôlement
  one-time** est émis côté serveur à la génération de l'`unattend.xml`, le
  poste l'échange contre son token au premier logon. Aucune action manuelle.
- **Porte 2 (Story 25.3, à venir)** — postes migrés/clonés sans ticket :
  approbation un-clic par l'admin. Réutilisera le même endpoint avec un
  autre mode ; aujourd'hui ces postes reçoivent un 403 indistinct.

## 2. Flux porte 1

```
iPXE (admin authentifié)                 serveur SE5                       poste Windows
        │                                     │                                  │
        │── GET /ipxe/windows/unattend.xml ──▶│                                  │
        │                                     │ openTicket(workstation)          │
        │                                     │  · révoque l'ancien token        │
        │                                     │    si poste déjà enrôlé (AC2)    │
        │                                     │  · hash SHA-256 en DB + TTL      │
        │◀── unattend.xml (ticket en clair) ──│                                  │
        │                                     │                                  │
        ·· install Windows (WinPE → reboot → specialize → OOBE) ··               │
        │                                     │                                  │
        │                                     │◀─ POST /api/v1/agent/enrollment ─│ FirstLogon
        │                                     │   {ticket, uuid, mac, hostname}  │ ordre 1
        │                                     │ redeem() : consomme le ticket,   │
        │                                     │ issueFor() → token (haché en DB) │
        │                                     │── 200 {success, token} ─────────▶│ → fichier token
        │                                     │                                  │ ordre 2 : icacls
```

1. **Émission** (`EnrollmentService::openTicket()`) — à la génération de
   l'unattend (`IpxeWindowsUnattendController`). Ticket = 64 hex
   (`bin2hex(random_bytes(32))`), seul le SHA-256 est persisté
   (`workstations.agent_enroll_ticket_hash` + `agent_enroll_ticket_expires_at`).
   TTL : `config('agent.enroll_ticket_ttl_minutes')` (env
   `AGENT_ENROLL_TICKET_TTL_MINUTES`, défaut **240 min** — couvre une install
   lente, plancher serveur 1 min). Un re-fetch WinPE écrase simplement le
   ticket précédent.
2. **Réinstallation = révocation immédiate (FR14).** Si le poste était déjà
   enrôlé, `TokenRotationService::revokeFor($ws, 'reinstall')` est appelé à
   l'émission du ticket — le clone éventuel de l'ancien token meurt **au
   début** de la réinstall, pas à la fin.
3. **Échange** (`POST /api/v1/agent/enrollment`, route `agent.v1.enrollment`)
   — résolution par **hash du ticket** exclusivement : le ticket EST
   l'identité ; uuid/mac sont spoofables sur le LAN et ne servent qu'au log
   de cohérence (`agent.enroll.identity_mismatch`, warning sans blocage) et
   au choix 409/403. Le ticket est consommé atomiquement (un seul redeem
   gagne, même concurrent), le token naît via
   `TokenRotationService::issueFor()` et le clair est renvoyé **une seule
   fois** (`{success: true, token}`, `Cache-Control: no-store`).
4. **Dépôt** — `FirstLogonCommands` de l'unattend, **ordres 1-2, avant le
   curl `etape=oobe`** historique (l'`action.cmd` récupéré peut rebooter le
   poste en ~5 s) :
   1. PowerShell : le dossier `C:\ProgramData\SambaEdu\Agent` est créé **et
      verrouillé d'abord** (`icacls /inheritance:r`, accès **SYSTEM +
      Administrators uniquement** via SID `*S-1-5-18`/`*S-1-5-32-544`,
      insensible à la locale) — le token n'existe jamais dans un dossier aux
      ACL héritées (Users-readable). Si le verrouillage échoue, on n'échange
      pas (ticket non consommé → porte 2). Puis POST de l'échange (URL
      absolue `http://<se4fs>/api/v1/agent/enrollment`) et écriture du token.
      Réseau indisponible ou erreur transitoire (5xx, 429) → retry 30×10 s ;
      un 4xx définitif → arrêt immédiat. **Un échec ne bloque jamais la
      suite de l'install** — le poste retombera sur la porte 2.
   2. `icacls` ceinture-et-bretelles : ré-application du verrouillage si le
      fichier token existe.

## 3. ⚠️ Contrat avec l'Epic 24 — chemin du fichier token

```
C:\ProgramData\SambaEdu\Agent\token
```

Fichier texte sans newline final, contenu = token 64 hex. ACL : SYSTEM +
Administrators uniquement (décision 2026-06-11 : **ProgramData**, pas
`%PROGRAMFILES%` — Program Files est lisible par les Users standard, un
élève pourrait lire le token). L'agent (Epic 24) lit ce fichier ; le binaire
agent sera déposé par la Story 25.4. **Toute modification de ce chemin casse
le contrat** — à synchroniser avec l'Epic 24 et la purge sysprep (§5).

## 4. Codes de réponse de l'endpoint

Format d'erreur JSON SE5 : `{error, message, code}`. Middlewares :
`local.request` (LAN only) + `auth.v1.secure-headers` + `throttle:10,1` —
**pas** `agent.token` (le poste n'a pas encore de token).

| HTTP | `code` | Cas |
|---|---|---|
| 200 | — | Ticket valide : `{success: true, token}` (clair, une seule fois) |
| 409 | `AGENT_ENROLL_CONFLICT` | Ticket invalide **et** poste visé (uuid, à défaut mac) **déjà enrôlé** — son token reste intact, rien n'est écrasé silencieusement |
| 403 | `AGENT_ENROLL_NOT_ALLOWED` | Tout le reste : ticket absent/inconnu/expiré/déjà consommé, poste non enrôlé ou inconnu — **volontairement indistincts** (pas d'oracle sur l'état des tickets). C'est le futur point d'accueil de la porte 2 (25.3) |

Note collision : `POST /api/v1/agent/enroll` (`agent.v1.enroll`) appartient
au canal JWT **legacy-migration** (16.10/16.11) — intouché pendant la
transition, d'où l'URI `/enrollment`. À l'extinction du canal legacy
(Epic 27), `/enroll` se libérera sans impact (seule la chaîne d'install
appelle cet endpoint, jamais l'agent en routine).

## 5. Hygiène clonage (sysprep & nosysprep)

Le token est déposé au premier logon du master : une image capturée avec le
fichier ferait présenter **le token du master par N clones** →
`clone_detected` → quarantaine (mécanique 23.2). La purge
`C:\ProgramData\SambaEdu\Agent\` est donc exécutée sur **les trois chemins
de préparation de capture** (divergence de parité legacy assumée) :

- `sysprep.blade.php` — **avant** `sysprep.exe /generalize` ;
- `sysprep.blade.php`, bloc fallback `:nosysprep` (sysprep.exe KO) — avant
  le reboot de capture ;
- `nosysprep.blade.php` (clonage sans sysprep, `etape=sysprep&type=clonage`)
  — avant le reboot de capture.

Les clones déployés repassent par OOBE → sans ticket valide → porte 2
(25.3). En attendant la 25.3, un clone sans token est simplement
**non-enrôlé** (pas de quarantaine de masse).

## 6. Risques assumés (iso-legacy)

- **Résidu Panther** : le ticket reste en clair dans
  `C:\Windows\Panther\unattend.xml` après install — il est **single-use et
  déjà consommé** au moment où il y traîne (inerte). L'unattend y laisse
  déjà les mots de passe AutoLogon (risque legacy préexistant, non aggravé).
- **Transport HTTP en clair sur le LAN** : iso chaîne d'install existante
  (curl oobe, action.cmd). Le ticket est one-time + TTL court ; le token,
  lui, ne transite qu'une fois dans la réponse `no-store`.

## 7. Logging

Channel **`agent`** (cf. token-lifecycle.md §8) — jamais de ticket/token en
clair ni de hash :

| Action | Niveau | Moment |
|---|---|---|
| `agent.enroll.ticket_opened` | info | Émission du ticket (génération unattend) |
| `agent.enroll.reinstall_revoked` | info | Révocation de l'ancien token à la réinstall (AC2) |
| `agent.enroll.enrolled` | info | Échange réussi, token né |
| `agent.enroll.identity_mismatch` | warning | uuid/mac/hostname reçus ≠ fiche (sans blocage) |
| `agent.enroll.rejected` | warning | Échec d'échange (avec raison interne + choix conflit) |

## 8. Renvois

- [token-lifecycle.md](token-lifecycle.md) — cycle de vie du token né ici.
- [contract-v1.md](contract-v1.md) — contenu JSON v1 (le ticket/token sont
  du transport, jamais dans le corps v1).
- **Story 23.5** — `GET /api/v1/agent/state` derrière `agent.token`.
- **Story 25.3** — porte 2 : approbation un-clic, levée de quarantaine.
- **Story 25.4 / Epic 24** — dépôt du binaire agent (ici : token seul).
