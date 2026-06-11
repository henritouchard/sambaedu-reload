# Cycle de vie du token agent — transport du canal desired-state

> **Story 23.2** (Epic 23 — successeur GPO, agent desired-state).
> Ce document décrit la **couche transport** du canal agent : authentification,
> rotation, révocation, anti-clonage. Le **contenu** échangé (état désiré,
> rapports) est figé par [contract-v1.md](contract-v1.md) — les deux documents
> sont orthogonaux : le token est du transport, il ne transite jamais dans le
> corps JSON v1.

## 1. Principes

- **Un token par poste, portée minimale (FR12).** Chaque poste détient un
  bearer token opaque qui ne lui permet que de lire **son** état et écrire
  **ses** rapports. Le poste résolu par le token est la **seule identité** de
  la requête : les controllers du canal n'acceptent **jamais** d'identifiant
  de poste en entrée (règle d'enforcement — toute PR qui ajoute un paramètre
  `workstation_id`/`uuid` à un endpoint agent est à rejeter).
- **Zéro dépendance AD (NFR7, critère Keycloak).** Tout le flux (émission,
  vérification, rotation, révocation) est SQL-only : colonnes `agent_*` de
  `workstations`. Aucun appel LDAP/Kerberos/samba-tool.
- **Jamais de clair persisté ni loggé.** Le token (64 hex,
  `bin2hex(random_bytes(32))`) n'existe en clair que dans la réponse qui le
  transmet au poste ; seul son SHA-256 hex est stocké
  (iso-pattern `WorkstationRefreshToken` du canal auth-v1).

## 2. Colonnes (`workstations`)

| Colonne | Rôle |
|---|---|
| `agent_token_hash` (varchar 64, unique) | SHA-256 du token courant |
| `agent_previous_token_hash` (varchar 64, index) | SHA-256 de l'ancien token pendant la fenêtre de grâce |
| `agent_token_rotated_at` | Dernière émission/rotation — base de l'échéance |
| `agent_last_checkin_at` | Dernier appel authentifié (mis à jour même en quarantaine) |
| `agent_quarantined_at` | Quarantaine anti-clonage (non null = 403) |

Le token vit **sur la ligne du poste** : la suppression du poste révoque par
construction (FR14).

## 3. Naissance, présentation, fin de vie

- **Naissance** : `TokenRotationService::issueFor()` à l'enrôlement —
  porte 1 = installation iPXE (**Story 23.3**, qui appelle ce service) ;
  porte 2 = postes migrés avec approbation un-clic (**Story 25.3**).
  `issueFor()` repart d'un état propre : grâce effacée, quarantaine levée
  (un ré-enrôlement légitime réhabilite le poste).
- **Présentation** : header `Authorization: Bearer <token>` sur toute route
  derrière l'alias middleware **`agent.token`**
  (`App\Http\Middleware\AuthenticateAgentToken`).
- **Fin de vie** : révocation par **événement**, pas par calendrier (FR13/14) :
  bouton « Révoquer le token agent » (page détail machine), réinstallation
  (23.3 → `revokeFor()` puis ré-émission), suppression du poste. Il n'existe
  **aucune expiration calendaire sèche** : un poste éteint 6 mois (vacances)
  se ré-authentifie et se rotate — il ne meurt pas.

## 4. Rotation glissante (D5) et fenêtre de grâce

Échéance : `config('agent.token_rotation_days')` (env
`AGENT_TOKEN_ROTATION_DAYS`, défaut **30 jours**, plancher serveur **1 jour**
— une valeur 0/négative déclencherait une rotation à chaque check-in du parc).
Un `rotated_at` dans le futur (snapshot DB restauré) est traité comme
incohérent → rotation immédiate qui repose une date saine.

Au premier check-in passé l'échéance :

1. le serveur génère un nouveau token ; l'ancien hash glisse en
   `agent_previous_token_hash` ;
2. le nouveau token est renvoyé dans le **header de réponse
   `X-Agent-New-Token`** (le corps reste le JSON v1 figé du contrat — un
   token dans le corps violerait le schéma) ;
3. **l'ancien token reste valide jusqu'au premier usage du nouveau**
   (fenêtre de grâce) ;
4. au premier appel authentifié avec le nouveau token, la grâce se ferme
   (`agent_previous_token_hash` effacé, log `agent.token.rotation_confirmed`).

**Réponse perdue.** Si le poste n'a jamais reçu le nouveau token (réponse
perdue), il re-check-in avec l'ancien : le serveur **ré-émet un nouveau
token** (on ne stocke que des hash — impossible de renvoyer le même clair)
et `previous` reste l'ancien token, le seul que le poste détient réellement.
Conséquence : **jamais de lock-out**, quel que soit le nombre de réponses
perdues.

**Côté agent (Epic 24)** : à chaque réponse, si `X-Agent-New-Token` est
présent, persister le nouveau token **avant** de l'utiliser, puis l'employer
dès l'appel suivant (ce qui confirme la rotation côté serveur).

## 5. Anti-clonage (FR15)

L'agent envoie systématiquement deux headers d'identité :

| Header | Comparé à | Divergence ⇒ |
|---|---|---|
| `X-Agent-Mac` | `workstations.mac` — les **deux** formes passent par `MacAddressNormalizer::normalize()` (forme canonique `aa:bb:cc:dd:ee:ff`, séparateurs `:`/`-`/espaces tolérés) | **Quarantaine immédiate** + log error `agent.token.clone_detected` + 403 |
| `X-Agent-Hostname` | `workstations.name` (comparaison insensible à la casse) | Log warning `agent.token.hostname_mismatch`, **sans** quarantaine |

Justification de l'asymétrie : la MAC est l'ancre fiable (l'UUID SMBIOS s'est
montré vide/peu fiable côté iPXE) ; un hostname peut légitimement diverger le
temps qu'un renommage UI/AD se propage au poste. Headers absents → pas de
détection (tolérance transitoire, l'agent Epic 24 les enverra toujours).
Format MAC non reconnu par le normalizer → pas de détection non plus (un
mismatch de séparateur ou un header corrompu ne quarantaine jamais un poste
légitime — review 23.2).

**Quarantaine** : le poste quarantainé poursuit des check-ins légers
(`agent_last_checkin_at` mis à jour — il reste visible dans le parc) mais
reçoit 403 `AGENT_QUARANTINED` sur tout endpoint. Levée : ré-enrôlement
(réinstallation) ou révocation + ré-émission ; outillage d'approbation
un-clic → Story 25.3.

## 6. Codes de réponse

Format d'erreur JSON SE5 : `{error, message, code}`.

| HTTP | `code` | Cas |
|---|---|---|
| 401 | `AGENT_TOKEN_MISSING` | Header `Authorization: Bearer` absent ou malformé |
| 401 | `AGENT_TOKEN_INVALID` | Token inconnu **ou révoqué** (indistincts volontairement — pas d'oracle : révoqué = hash effacé = introuvable) |
| 403 | `AGENT_QUARANTINED` | Poste en quarantaine, ou MAC divergente détectée sur cette requête |

Le 403 « non approuvé » (porte 2, postes migrés en attente d'approbation)
relève de la **Story 25.3** — il n'existe pas encore.

## 7. Ordre des vérifications du middleware

1. Bearer absent/malformé → 401 `AGENT_TOKEN_MISSING` ;
2. lookup `sha256(bearer)` sur `agent_token_hash` **ou**
   `agent_previous_token_hash` ; introuvable → 401 `AGENT_TOKEN_INVALID` ;
3. anti-clonage (§5) — avant tout traitement ;
4. quarantaine → maj check-in puis 403 `AGENT_QUARANTINED` ;
5. rotation (§4) — **sérialisée sous verrou ligne** (transaction +
   `SELECT … FOR UPDATE`, review 23.2 : deux check-ins simultanés du même
   poste ne peuvent pas s'écraser mutuellement la rotation) : auth via
   previous → ré-émission ; auth via courant avec grâce ouverte →
   confirmation ; échéance dépassée → rotation ;
6. maj `agent_last_checkin_at`, injection `agent.workstation` dans les
   attributs de la requête, suite du pipeline. Le header `X-Agent-New-Token`
   est posé sur la réponse si une rotation a eu lieu.

## 8. Logging

Channel dédié **`agent`** (`storage/logs/agent/agent.log`, daily, env
`AGENT_LOG_LEVEL`/`AGENT_LOG_DAYS`). Actions namespacées, contexte
`workstation_id`, **jamais** de token clair ni de hash :

| Action | Niveau | Moment |
|---|---|---|
| `agent.token.issued` | debug | Émission (enrôlement/ré-enrôlement) |
| `agent.token.rotated` | debug | Rotation (échéance ou ré-émission) |
| `agent.token.rotation_confirmed` | debug | Premier usage du nouveau token |
| `agent.token.revoked` | info | Révocation (avec `reason`) |
| `agent.token.clone_detected` | **error** | MAC divergente → quarantaine |
| `agent.token.hostname_mismatch` | warning | Hostname seul divergent |

## 9. Renvois

- [contract-v1.md](contract-v1.md) — contenu JSON v1 figé (état & rapport).
- **Story 23.3** — naissance du token (porte 1, `POST /enroll` iPXE) ; note :
  le préfixe `/api/v1/agent/*` et les noms `agent.v1.*` sont occupés par le
  canal JWT legacy-migration (`routes/api.php`), collision à résoudre là-bas.
- **Story 25.3** — porte 2 (postes migrés), 403 « non approuvé », levée de
  quarantaine outillée.
- **Epic 24** — agent côté poste : présentation des headers, gestion
  401/403, persistance de `X-Agent-New-Token`.
