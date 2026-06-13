# Distribution des releases agent (Story 25.1 — D6, FR24)

Moitié **serveur** de la distribution canari : publier une version du binaire
agent, la cibler par **ring**, la servir aux postes authentifiés. L'auto-update
côté agent (download, vérification hash + signature, swap binaire) = Story 25.2.

## Tables (D6)

| Table | Rôle |
|---|---|
| `agent_releases` | Une ligne par version publiée : `version` (unique), `hash` (SHA-256 **vérifié** contre le fichier réel à la création), `filename` (unique — la donnée stable ; l'`url` du manifest est calculée à la réponse, jamais stockée), `is_stable` (au plus une ligne à true — invariant transactionnel du service). |
| `agent_release_rings` | Ciblage : **un ring = UN WorkstationGroup existant** (salle physique OU parc logique — D6 × D1, le pivot 4.11 ne distingue pas), `workstation_group_id` UNIQUE → `agent_release_id`. L'`updated_at` est la donnée de **récence**. FK cascade des deux côtés : la release ou le groupe supprimé emporte le ring (le poste retombe sur la stable). |

Le canal agent n'écrit **que** dans `agent_*` : le ciblage LIT les
WorkstationGroups, n'y écrit jamais. Aucune dépendance AD (critère Keycloak,
NFR7).

## Commandes artisan (outillage pré-UI — l'UI rings/releases = 25.5)

```bash
# 1. Déposer le binaire signé (build 24.5/24.6) dans le répertoire des
#    releases — dépôt direct sur le serveur, hors git/inotify :
install -o www-admin -g www-admin agent/build/dist/sambaedu-agent-2.1.2.exe \
    storage/agent/releases/

# 2. Publier — le --hash (sha256sum du pipeline de build) est OBLIGATOIRE,
#    contre-vérifié sur le fichier réel. Toute incohérence (fichier absent,
#    hash divergent, version dupliquée, formats invalides, filename
#    ≠ sambaedu-agent-<version>.exe) = REFUS, aucune ligne écrite, exit ≠ 0 :
php artisan agent:release:create 2.1.2 sambaedu-agent-2.1.2.exe \
    --hash=$(sha256sum storage/agent/releases/sambaedu-agent-2.1.2.exe | cut -d' ' -f1)
#    [--stable] marque la release stable (au plus une — swap transactionnel).

# 3. Cibler un ring (canari) — le ring est un WorkstationGroup existant,
#    lookup par `name` :
php artisan agent:release:target 2.1.2 salle_lab

# 4. Promouvoir stable (défaut des postes sans ring — c'est aussi le
#    rollback du pointeur) :
php artisan agent:release:promote 2.1.2
```

## Endpoints (canal agent — bearer 23.2, chaîne iso state/report)

| Endpoint | Réponse |
|---|---|
| `GET /api/v1/agent/release` (`agent.v1.release`) | **Manifest** wrapper SE5 `{success, version, hash, url}` — `url` **absolue** vers le download. Golden : `tests/Fixtures/Agent/release-manifest.v1.json` (évolution : champ AJOUTÉ = mineur ; champ retiré/renommé = breaking, interdit sans nouvelle version de contrat). |
| `GET /api/v1/agent/releases/{filename}` (`agent.v1.release.download`) | **Binaire** (`BinaryFileResponse`, pas de wrapper SE5). Pattern strict `sambaedu-agent-<version>.exe` AVANT tout accès ; seul un filename présent dans `agent_releases` est servi (lookup DB d'abord, disque ensuite, realpath confiné). |

**Codes** : 200 (servi) · 401 `AGENT_TOKEN_MISSING`/`AGENT_TOKEN_INVALID` ·
403 `AGENT_QUARANTINED` · 404 — manifest : `{error: "no_release"}` (aucune
release applicable — l'agent 25.2 traite 404 = « rien à faire ») ; download :
`{error: "not_found"}` **indistinct** (malformé / inconnu DB / fichier absent —
aucun oracle de présence) · 429 (throttle 60/min). La rotation
`X-Agent-New-Token` (D5) survit aux deux réponses.

## Règle de résolution du manifest

1. **Rings** des WorkstationGroups du poste authentifié (pivot global 4.11,
   identité = le token, jamais un identifiant en entrée) ;
2. plusieurs rings → la ligne ring **la plus récemment modifiée**
   (`updated_at`) gagne + warning `agent.release.ring_conflict` **seulement
   si les rings pointent des releases distinctes** (ambiguïté réelle, FR4 —
   salle + parc alignés sur la même version ne warne pas). Couvre le canari
   (ciblage lab posé APRÈS le parc) et le rollback (re-ciblage posé APRÈS —
   `agent:release:target` rafraîchit toujours `updated_at`, même pour la
   même version) ;
3. aucun ring → version **stable** (`is_stable = true`) — jamais une canari
   par accident ;
4. ni ring ni stable → **404 `no_release`** (jamais un 200 vide ambigu).

## Répartition de l'intégrité (décision n° 8)

| Garantie | Où | Quoi |
|---|---|---|
| Intégrité à la **création** | Serveur (`ReleaseCreationService`) | SHA-256 du fichier réel = hash déclaré par le build, sinon refus. Impossible de publier un artefact incohérent. |
| Intégrité au **transport** | Agent (25.2) | SHA-256 du corps téléchargé = `hash` du manifest, avant écriture (convention 24.4). |
| Authenticité à l'**exécution** | Agent (25.2) + build | Vérification de la signature Authenticode avant swap du binaire ; `agent/build/build.sh` refuse déjà de produire du non-signé (sauf `ALLOW_UNSIGNED=1`) et vérifie sa propre signature. Le serveur ne re-vérifie PAS Authenticode (osslsigncode n'est pas une dépendance runtime du serveur). |

## Convention de dépôt des binaires

- Répertoire : `config('agent.releases_path')` — défaut
  `storage/agent/releases/` (clé `AGENT_RELEASES_PATH`). **Non versionné**
  (convention storage), **hors inotify** : dépôt direct sur le serveur
  (scp/cp/install), jamais committé.
- **chown www-admin** (uid 599 — PHP-FPM) obligatoire : un fichier illisible
  fait échouer `hash_file()` à la création et tomber le serving en 404
  silencieux.
- Le fichier doit rester en place après publication : le hash en DB ne
  protège pas d'une substitution disque côté serveur (root requis) — c'est
  la vérification hash + signature côté agent (25.2) qui couvre le poste.

## Observabilité (AC5)

Channel `agent`, actions `agent.release.*` : `created` (info) / `rejected`
(warning + raison machine) / `promoted` (info) / `targeted` (info) /
`ring_conflict` (warning — uniquement si releases distinctes) /
`manifest_served` (**debug** — un par check-in, volumétrie NFR4) /
`no_release` (debug) / `download_served` (**debug** — un téléchargement
n'est qu'un préalable sans garantie : la trace de déploiement qui fait foi
est la **version rapportée par l'agent au check-in**, contrat 25.2 — version
dans chaque rapport, échec d'update rapporté au serveur) /
`download_not_found` (info — l'anomalie, elle, reste visible) /
`releases_path_missing` (warning — `releases_path` absent/illisible :
distingue côté ops « config cassée, parc entier en 404 » de « release
inconnue » ; la réponse client reste le 404 indistinct). Toujours
`workstation_id` quand applicable ; jamais de token ni de payload binaire.

**Note contrat 25.2 (versions exotiques)** : `version` admet `+`/`~` ;
dans l'`url` du manifest, le filename est alors **percent-encodé**
(`2.1.2+r1` → `sambaedu-agent-2.1.2%2Br1.exe`). Le serveur décode à la
réception (vérifié) ; l'agent doit décoder avant toute comparaison littérale
filename ↔ version. Sans objet pour le semver simple produit par le build.

## Endpoints d'amorçage LAN (Story 25.4 — non authentifiés)

Les deux chemins d'installation de l'agent (GPO-dispatcher figée pour les
postes migrés, unattend iPXE pour les postes neufs) tournent **avant** que
l'agent ait un token. Ils ne peuvent donc pas passer par les endpoints
ci-dessus (derrière `agent.token`). Trois endpoints **non authentifiés**,
chaîne middleware iso `/v1/agent/enrollment` (`local.request` LAN-only +
`auth.v1.secure-headers` + `throttle:60,1`), **hors** du groupe `agent.token`
(`app/Http/Controllers/Api/V1/Agent/BootstrapController.php`) :

| Méthode | URI | Route | Réponse |
|---|---|---|---|
| GET | `/api/v1/agent/stable` | `agent.v1.stable` | Manifest stable `{success, version, hash, url}` (URL **absolue**, FIXE) ; 404 `no_release` si aucune stable |
| GET | `/api/v1/agent/stable/download` | `agent.v1.stable.download` | Binaire **stable** (octet-stream). URL FIXE : la résolution du filename est interne (jamais un input client). Confinement realpath iso 25.1, 404 indistinct |
| GET | `/api/v1/agent/ca` | `agent.v1.ca` | Racine CA en PEM (`text/plain`) ; **503** si la PKI n'est pas initialisée (`php artisan auth:ca:init`), jamais 500 |

**Résolution forcée stable** : l'appelant n'a pas de token, donc aucun ring à
résoudre — `ReleaseManifestService::stableManifest()` sert **toujours** la
`is_stable` (jamais une canari). Une canari publiée ne fuit jamais par ces
endpoints.

**Frontière `agent_*` + zéro AD (NFR7)** : lecture seule `agent_releases` + le
`.crt` PKI sur disque ; **aucune** écriture, **aucun** appel
LdapRecord/Kerberos/samba-tool. Le périmètre de confiance est le réseau
(`local.request`).

**Intégrité au premier dépôt** : aucun re-signage / re-vérif Authenticode
côté serveur (décision 25.1). La confiance repose sur (a) le canal LAN/VPN, (b)
le hash optionnellement vérifié par le script via `/stable`, (c) la signature
Authenticode validée **ensuite** à chaque auto-update par l'agent (25.2). La
racine CA est le **prérequis de confiance** déployée par **les deux** chemins.

**Logs (channel `agent`)** : `agent.release.stable_served` (manifest + binaire),
`agent.ca.served`, `agent.ca.unavailable` (503), `agent.release.stable_not_found`
(404 indistinct). Jamais de token/hash en clair.
