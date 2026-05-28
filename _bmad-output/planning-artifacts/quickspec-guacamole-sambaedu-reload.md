# Quick Spec — Support Guacamole dans sambaedu-reload

> **Statut** : draft section-by-section · **Auteur** : John (PM) · **Date** : 2026-05-07
> **Périmètre** : runtime utilisateur "accès domicile" uniquement (hors provisioning, hors dépannage admin)
> **Document complémentaire** : [handoff-guacamole-controlhub.md](./handoff-guacamole-controlhub.md)

---

## 1. Contexte & objectifs

### Pourquoi cette spec

Le projet **sambaedu-reload** refactorise en Laravel le legacy PHP `sambaedu/`. Dans ce périmètre, l'accès distant aux postes via **Apache Guacamole** doit être porté pour ne pas régresser sur la continuité pédagogique (élèves/enseignants accédant à leur poste de l'établissement depuis le domicile).

La présente spec couvre **uniquement le scénario "accès domicile"** : un utilisateur authentifié sur sambaedu-reload sélectionne une machine de son établissement et lance une session Guacamole (RDP/VNC/SSH) dans son navigateur. Il ne s'agit ni d'une refonte UX ni d'une révision de l'architecture Guacamole.

### Décisions structurantes actées en amont

1. **Le fork `sambaedu-guacamole` (basé sur Apache Guacamole 1.6.0) est intouché.** sambaedu-reload doit s'y intégrer en client, pas le repenser. Référence : https://gitlab.sambaedu.org/sambaedu/sambaedu-guacamole.

2. **Séparation des responsabilités legacy central vs local.** Le legacy partage une base de code unique mais avec des points d'appel distincts. Cartographie validée :
   - **sambaedu-reload** (= remplaçant local) → runtime utilisateur "accès domicile"
   - **controlHub** (= remplaçant central) → provisioning Guacamole (Tomcat, HAProxy, conf UAI) + dépannage admin sur `remote_admin_machine`
   - **Briques techniques** (crypto token, URL multi-tenant, connection-builder) → **dupliquées** dans chaque codebase, pas de package partagé. La cohérence inter-codebases (notamment de `guac_priv_key` et du format de token) est un risque connu, géré par contrat de format documenté.

3. **Pas de refonte produit.** Les features avancées de Guacamole 1.6 (session recording, MFA, OpenID Connect en remplacement du token signé custom, mode observation VNC) sont hors-scope de ce portage. Elles peuvent faire l'objet de specs ultérieures si le besoin produit émerge.

### Objectifs

- **O1** — Reproduire fidèlement le comportement legacy de `display_remote_list()` + `parcs2/rdp.php` dans Laravel.
- **O2** — Encapsuler la logique Guacamole dans des services Laravel testables (extraction de `remote.inc.php`).
- **O3** — Préserver la compatibilité du token signé avec le serveur Guacamole existant (pas de coupure d'auth).
- **O4** — Documenter le contrat partagé avec controlHub (format token, conventions URL multi-tenant) pour éviter la divergence.

### Non-objectifs (explicites)

- Refonte UX de la page d'accès distant.
- Migration vers OpenID Connect / SAML / MFA.
- Provisioning serveur Guacamole, Tomcat, HAProxy, fichier conf par UAI.
- Dépannage admin depuis controlHub sur `remote_admin_machine`.
- Session recording, audit, ou conformité RGPD spécifique au scénario domicile.
- Suppression de la version legacy (cohabitation possible pendant la migration).

### Définition du succès

Un utilisateur authentifié sur sambaedu-reload peut, depuis son domicile, ouvrir une session Guacamole sur une machine de son parc, **sans différence fonctionnelle observable** par rapport au legacy. Les services Laravel sont couverts par tests unitaires et un test d'intégration token round-trip contre le fork sambaedu-guacamole.

---

## 2. Périmètre

### IN — Couvert par cette spec (sambaedu-reload)

- **Runtime "accès domicile"** : un utilisateur authentifié sur sambaedu-reload sélectionne une machine de son parc et lance une session Guacamole (RDP / VNC / SSH / Master) dans son navigateur.
- **Portage de l'UI `parcs2`** uniquement (l'UI `parcs/rdp.php` est considérée legacy dépréciée, non portée).
- **Génération côté Laravel du token signé** Guacamole (équivalent `encrypt_json_token` + `get_guacamole_auth_token`), compatible avec le fork `sambaedu-guacamole`.
- **Résolution multi-tenant des URLs** Guacamole par établissement (équivalent `guacamole_url()` : substitution `/etab/guacamole/`).
- **Connection builder** : construction des payloads de connexion par protocole (RDP, VNC, SSH, Master) avec paramètres adaptés (clé privée PEM pour SSH, imprimante virtuelle "Guacamole", `vnc_password`, etc.).
- **Cache des tokens** côté Laravel (remplaçant du cache APCu legacy 2h, indexé par hash `machines + user + type`).
- **Lecture de la configuration** (`guacamole_url`, `guac_priv_key`, `guacamole_schema`, `vnc_password`, `reverse_proxy`, `etab_ou`) depuis la source de vérité Laravel (à arbitrer en §5 : `config()`, table dédiée, ou shim legacy LDAP).
- **Intégration UI** dans la nav sambaedu-reload (équivalent du lien "Se connecter à un ordinateur" géré par `user.interface.inc.php:2016-2026`).
- **Gestion d'erreurs utilisateur** via le trait `WithToasts` (Guacamole down, token expiré, machine injoignable).

### OUT — Hors-scope, traité ailleurs

| Sujet | Où c'est traité | Référence |
|---|---|---|
| Provisioning Tomcat + `guacamole.properties` | controlHub | Epic C10-1 |
| Provisioning HAProxy backend par établissement | controlHub | Epic C10-2 |
| Génération + déploiement de la conf par UAI | controlHub | Epic C10-3 |
| Dépannage admin sur `remote_admin_machine` | controlHub | Epic C10-4 |
| Orchestration `activate_etab` (création d'établissement) | controlHub | Epic C10-6 |
| Refonte du fork `sambaedu-guacamole` | Hors-scope total | — |
| Migration vers OpenID Connect / SAML / MFA | Spec produit ultérieure si besoin | — |
| Session recording, audit RGPD du dépannage | Spec produit ultérieure (côté controlHub) | — |
| Mode observation VNC sans prise de main | Spec produit ultérieure (côté controlHub) | — |
| UI `parcs/rdp.php` (legacy) | Non porté — déprécié | — |

### Hypothèses prises (à valider à l'implémentation)

- L'authentification utilisateur sambaedu-reload est déjà résolue (cf. backlog story 1-4 *Interface AuthGuard* + `SambaEduAuthGuard`). Cette spec consomme l'utilisateur authentifié, ne le ré-authentifie pas.
- La fonction `create_remote_connexion()` (écriture LDAP `ou=rdp` avec `guacConfigGroup`) est considérée hors-scope tant que sa nature (central vs local) n'est pas confirmée — voir §7 *Risques & points ouverts*.
- Le composant modal réutilisable du projet et le pattern Livewire SFC sont disponibles (cf. `CLAUDE.md` projet).
- Les fonctions PHP legacy peuvent être consultées via le shim si besoin de référence runtime, mais la cible est une **réimplémentation native Laravel**, pas un wrapper du legacy.

## 3. Architecture cible Laravel

### Vue d'ensemble

```
┌────────────────────────────────────────────────────────────────────┐
│  UI (Blade page + Livewire SFC)                                    │
│  /app/parcs/{salle}/rdp ── RemoteList (Livewire)                  │
└────────────────┬───────────────────────────────────────────────────┘
                 │ launch(machine, protocol)
                 ▼
┌────────────────────────────────────────────────────────────────────┐
│  GuacamoleSessionLauncher (orchestrateur applicatif)               │
│   1. resolve URL via GuacamoleUrlResolver                          │
│   2. build payload via GuacamoleConnectionBuilder                  │
│   3. encrypt + sign via GuacamoleAuthService                       │
│   4. POST /api/tokens via GuacamoleClient → authToken              │
│   5. retourne URL finale "{guac_url}#/?token={authToken}"          │
└──┬──────────────────┬───────────────┬───────────────┬──────────────┘
   │                  │               │               │
   ▼                  ▼               ▼               ▼
GuacamoleAuth     Guacamole       Guacamole       Guacamole
Service           Connection      Url             Client
(token crypto)    Builder         Resolver        (Guzzle/Http)
                  (payloads       (multi-         POST /api/tokens
                   RDP/VNC/SSH)    tenant)        GET /api/session
                                                  /data/json/...
```

### Services à créer

#### `App\Services\Guacamole\GuacamoleAuthService`
Responsable de la **crypto du token signé** consommé par le fork sambaedu-guacamole.

**Méthodes** :
- `encryptJsonToken(array $payload): string` — AES-128-CBC + HMAC-SHA256, format compatible avec l'extension JSON-auth Guacamole. Pendant de `encrypt_json_token()` legacy.
- `requestAuthToken(string $guacUrl, string $signedPayload): string` — POST `{guacUrl}/api/tokens` (form-data `data=signed_payload`), retourne `authToken`. Pendant de `get_guacamole_auth_token()`.
- `checkAuthToken(string $guacUrl, string $authToken): bool` — GET `{guacUrl}/api/session/data/json/connections?token={authToken}` pour validation (HTTP 200 = valide). Pendant de `check_guacamole_auth_token()`.

**Dépendances** : `GuacamoleClient` (HTTP), `GuacamoleConfigRepository` (clé `guac_priv_key`).

#### `App\Services\Guacamole\GuacamoleConnectionBuilder`
Construit les **payloads de connexion par protocole**, équivalent `create_remote_json_connection()`.

**Méthodes** :
- `buildRdp(Machine $m, User $u, ?string $password = null): array`
- `buildVnc(Machine $m, User $u, ?string $password = null): array`
- `buildSsh(Machine $m, User $u, ?string $password = null): array`
- `buildMaster(Machine $m, User $u, ?string $password = null): array` (Veyon)
- `buildBatch(Collection $machines, User $u, string $type, ?string $password): array` (équivalent `guacamole_urls()` pour une salle)

**Paramètres injectés** : imprimante virtuelle `Guacamole`, clé privée PEM pour SSH (lue depuis config), `vnc_password`, encodages, timeouts.

#### `App\Services\Guacamole\GuacamoleUrlResolver`
Résout l'**URL Guacamole pour un établissement donné**, équivalent `guacamole_url()`.

**Méthodes** :
- `resolve(string $etab = ''): string` — substitue `/etab/guacamole/` selon contexte établissement courant vs étranger. Logique alignée sur `remote.inc.php:450-457`.

**Note** : la logique de substitution est subtile (cf. correction du bug `b023546` — URL corrompue `//guacamole/fichiers` quand `etab` vide). À couvrir explicitement par tests.

#### `App\Services\Guacamole\GuacamoleConfigRepository`
**Source de vérité** des paramètres de configuration Guacamole.

**Méthodes** :
- `getUrl(): string` — `guacamole_url`
- `getPrivateKey(): string` — `guac_priv_key`
- `getSchema(): string` — `guacamole_schema`
- `getVncPassword(): ?string` — `vnc_password`
- `getReverseProxy(): ?string` — `reverse_proxy`
- `getEtabOu(): ?string` — `etab_ou` (UAI courant)

**Source des données** : à arbitrer en §5 (config Laravel `.env` + `config/guacamole.php`, ou table dédiée, ou shim legacy lisant la config LDAP).

#### `App\Services\Guacamole\GuacamoleClient`
**Wrapper HTTP** mince autour de `Http::` Laravel (ex-`GuzzleHttp\Client` legacy). Centralise base URI, timeouts, gestion d'erreurs réseau et logging.

#### `App\Services\Guacamole\GuacamoleSessionLauncher`
**Orchestrateur applicatif** appelé par le Livewire SFC. Encapsule la séquence : config → URL → builder → auth → URL finale. Pendant de `create_remote_token()` (cas local, pas l'usage central `remote_admin_machine`).

**Méthode principale** :
- `launch(Machine $m, string $type, User $u, ?string $password = null): GuacamoleLaunchResult`
- Le résultat contient l'URL signée prête à ouvrir + métadonnées (token TTL, cache hit/miss).

### Composants UI

| Composant | Fichier | Rôle |
|---|---|---|
| Page Blade | `resources/views/pages/app/parcs/[salle]/rdp/index.blade.php` | Route filesystem-based |
| Livewire SFC | `resources/views/pages/app/parcs/[salle]/rdp/_partials/RemoteList.blade.php` | Liste machines + boutons lancer (équivalent `display_remote_list`) |
| Modal mot de passe | composant modal réutilisable du projet | Saisie SSO si SSO password absent (cf. `parcs.inc.php:683-684`) |
| Toasts | trait `WithToasts` (`laravel/app/Components/Traits/WithToasts.php`) | Erreurs token / Guacamole down / machine offline |

### Cache

- **Driver** : cache Laravel par défaut du projet (à confirmer : Redis, file, ou autre).
- **Clé** : hash déterministe de `machines_signature + user_id + type` (équivalent `md5(serialize(...))` legacy).
- **TTL** : 2h, comme legacy. Documenté constante `GuacamoleAuthService::TOKEN_TTL_SECONDS`.
- **Périmètre** : seuls les **tokens** sont cachés (URLs finales). Les payloads bruts ne le sont pas.

### Principes de découpage

- **Pas de logique HTTP dans `GuacamoleAuthService`** — le réseau passe par `GuacamoleClient`. Permet de tester la crypto en pur unitaire.
- **Pas d'I/O dans `GuacamoleConnectionBuilder`** — uniquement de la construction d'arrays. Test unitaire trivial.
- **`GuacamoleSessionLauncher` est le seul point public** appelé par l'UI. Les autres services sont injectés et restent internes.
- **Pas d'héritage du legacy** — réimplémentation native, contrats fonctionnels documentés en §4.

## 4. Mapping legacy → Laravel

### Fonctions PHP portées dans sambaedu-reload

| Fonction PHP legacy | Source legacy | Service Laravel | Méthode | Notes |
|---|---|---|---|---|
| `encrypt_json_token($config, $json)` | `remote.inc.php` | `GuacamoleAuthService` | `encryptJsonToken(array): string` | Crypto AES-128-CBC + HMAC-SHA256. Format **immuable** (compat fork). |
| `get_guacamole_auth_token($config, $etab, $data)` | `remote.inc.php:1054` | `GuacamoleAuthService` | `requestAuthToken(string $url, string $payload): string` | POST `/api/tokens`, form-data `data=...`. |
| `check_guacamole_auth_token($config, $etab, $token)` | `remote.inc.php:1087` | `GuacamoleAuthService` | `checkAuthToken(string $url, string $token): bool` | GET validation. |
| `guacamole_url($config, $etab = "")` | `remote.inc.php:450` | `GuacamoleUrlResolver` | `resolve(?string $etab = null): string` | Logique de substitution `/etab/guacamole/`. **Test bug `b023546` à reproduire** (etab vide). |
| `create_remote_json_connection(...)` | `remote.inc.php` | `GuacamoleConnectionBuilder` | `buildRdp / buildVnc / buildSsh / buildMaster` | Une méthode par protocole. Imprimante virtuelle `Guacamole` injectée. |
| `create_remote_token($config, $machine, $type, $user, $password, $timeout)` | `remote.inc.php:888` | `GuacamoleSessionLauncher` | `launch(Machine, string, User, ?string): GuacamoleLaunchResult` | **Cas local uniquement** (le cas central `remote_admin_machine` part dans controlHub). |
| `guacamole_urls($config, &$machines, $user, $password, $type, $timeout)` | `remote.inc.php:1123` | `GuacamoleSessionLauncher` | `launchBatch(Collection, User, string, ?string): Collection<GuacamoleLaunchResult>` | Pour une salle entière. Cache batch. |
| `display_remote_list($config, $login)` | `remote.inc.php:472` | Livewire SFC `RemoteList` | (composant) | Décomposé : data layer = `GuacamoleSessionLauncher`, UI = Livewire. |
| Lecture `$config['guacamole_url']` | partout | `GuacamoleConfigRepository` | `getUrl(): string` | Cf. §5 source de config. |
| Lecture `$config['guac_priv_key']` | partout | `GuacamoleConfigRepository` | `getPrivateKey(): string` | |
| Lecture `$config['guacamole_schema']` | partout | `GuacamoleConfigRepository` | `getSchema(): string` | |
| Lecture `$config['vnc_password']` | builder | `GuacamoleConfigRepository` | `getVncPassword(): ?string` | |
| Cache APCu tokens (TTL 7200) | `remote.inc.php:1123+` | Cache Laravel | clé `guacamole.token.{hash}` | TTL 7200s. |

### Fonctions PHP NON portées dans sambaedu-reload

| Fonction PHP legacy | Source | Destination | Référence |
|---|---|---|---|
| `configure_guacamole_server($config)` | `remote.inc.php:911` | controlHub | C10-1 |
| `create_guacamole_conf($config, $etab)` | `remote.inc.php:1023` | controlHub | C10-3 |
| `create_haproxy_guacamole_backend($config, $uai, $ip)` | `sites.inc.php:564` | controlHub | C10-2 |
| `activate_etab($config, $uai)` | `sites.inc.php:724` | controlHub | C10-6 |
| `create_remote_token($config, …)` (cas `remote_admin_machine`) | `central/php/includes/annu_ui.inc.php:23` | controlHub | C10-4 |

### Entry points legacy → routes Laravel

| Entry point legacy | Route Laravel cible |
|---|---|
| `parcs2/rdp.php` | `/app/parcs/{salle}/rdp` (filesystem-based, cf. CLAUDE.md projet) |
| `parcs/rdp.php` | **NON porté** (UI legacy dépréciée) |
| Lien menu "Se connecter à un ordinateur" (`user.interface.inc.php:2016`) | Élément de nav sambaedu-reload pointant vers `/app/parcs/{salle}/rdp` |
| Action admin `guacamole_update` (`config_action.php:197`) | **NON porté** (controlHub C10-1) |

### Schéma LDAP — `ou=rdp` / `guacConfigGroup`

Le legacy crée des groupes LDAP `ou=rdp` avec objectClass `guacConfigGroup` (`create_remote_connexion()` dans `remote.inc.php`). Hors-scope de cette spec :
- À confirmer si appelé côté local ou central (cf. §7 *Risques & points ouverts*).
- Si central uniquement → controlHub.
- Si utilisé côté local → à intégrer dans une story dédiée de cette spec.

### Constante `SE_COMPUTER_CONTROL = 0x200`
Définie dans `ldap.inc.php:2964` — droit utilisateur "Accès distant Guacamole". À mapper sur le système de droits de sambaedu-reload (Spatie Permission, cf. epic 1bis et story 1-4 *Interface AuthGuard*). À traiter dans la story de portage UI / nav, pas dans la crypto/token.

## 5. Détails techniques

### 5.1 Crypto du token signé (compatibilité fork sambaedu-guacamole)

Le fork sambaedu-guacamole utilise l'extension JSON-auth standard d'Apache Guacamole, qui attend un payload chiffré et signé selon un format **immuable**. Toute divergence casse l'auth.

**Algorithme exact** (à reproduire à l'identique) :

```
1. Clé : guac_priv_key stockée en hex, 32 caractères → 16 octets binaires (AES-128)
   Si vide à la première utilisation : générer bin2hex(openssl_random_pseudo_bytes(16))
   et persister dans la config.

2. IV : CONSTANT NUL (16 octets de zéros).
   ⚠️ Choix imposé par le fork — réutilisation IV avec même clé = pattern leak,
   à documenter comme dette crypto connue (cf. §7).

3. HMAC : hash_hmac('sha256', $json_payload, $key_binary, raw=true) → 32 octets bruts.

4. signed = HMAC_binary || JSON_payload   (concaténation)

5. ciphertext = AES-128-CBC.encrypt(signed, key=key_binary, iv=NULL_IV)
   en mode RAW (pas de PKCS#7 implicite côté output base64).

6. result = base64_encode(ciphertext)   → c'est la valeur `data` envoyée à /api/tokens.
```

**Référence legacy** : `remote.inc.php:693-745`.

**Test de non-régression critique** : un round-trip (chiffrement Laravel → POST `/api/tokens` du fork → réception authToken HTTP 200) avec un payload de référence figé.

### 5.2 Format du payload JSON Guacamole

Structure attendue par l'extension JSON-auth :

```json
{
  "username": "henri",
  "expires": 1714400000000,           // timestamp ms (now + timeout)
  "connections": {
    "rdp sur PC-001": {                // clé = nom de connexion affiché
      "protocol": "rdp",
      "parameters": { ... }
    }
  }
}
```

**Le nom de la connexion** (clé du dict `connections`) est de la forme `{type} sur {cn}` — convention legacy à respecter pour cohérence UX (l'utilisateur voit ce nom dans Guacamole).

### 5.3 Builder de connexion par protocole

Paramètres exacts par protocole (à reproduire fidèlement) :

#### RDP
```
hostname            = $machine->cn
port                = 3389
username            = $user
password            = $password
domain              = config('samba_domain')
server-layout       = "fr-fr-azerty"
ignore-cert         = true
enable-font-smoothing = true
resize-method       = "display-update"
enable-printing     = true
printer-name        = "Guacamole"
```

#### VNC
```
hostname = $machine->cn
port     = 5900
password = config('vnc_password')
```

#### SSH
```
hostname    = $machine->cn
port        = 22
username    = "root"
private-key = file_get_contents('/etc/sambaedu/id_rsa.pem')
```

⚠️ **Lifecycle de la clé SSH** (`remote.inc.php:777-780`) : si `/etc/sambaedu/id_rsa.pem` n'existe pas, le legacy la génère depuis `/etc/sambaedu/id_rsa` via `cp` + `sudo ssh-keygen -p -N "" -m PEM`. À reproduire ou pré-requis ops (provisioning hors Laravel) — voir §7.

#### Master (Veyon Master)
Identique à RDP **plus** : `remote-app = "||Veyon Master"`.

#### Veyon (poste)
Identique à RDP **plus** : `remote-app = "||Veyon Poste"`.

### 5.4 Auto-switch du type de connexion

Le builder legacy ne respecte **pas aveuglément** le `$type` demandé — il applique des règles de bascule (`remote.inc.php:764-768`) :

```
si machine.cn matche /se4[fs|ad]/   → forcer SSH
sinon si config.vnc_password défini
       ET machine non "open"
       ET un user est connecté ET ce user != "wpkg"
                                    → forcer VNC
sinon                                → garder le type demandé
```

⚠️ **Note** : la regex legacy `se4[fs|ad]` est syntaxiquement bizarre (charclass équivalente à `se4[fsad|]`) — elle matche par chance les serveurs `se4fs` et `se4ad`. **À normaliser** dans Laravel : `^se4(fs|ad)` ou liste explicite. Comportement fonctionnel : forcer SSH pour les serveurs d'infra (admin shell distant).

⚠️ **Conséquence d'archi** : `GuacamoleConnectionBuilder` n'est donc pas un service "pur" — il consulte l'état de la machine. Soit on extrait la décision dans un service `ConnectionTypeResolver` séparé, soit on accepte que le builder lit la machine. Décision de design à arbitrer en review.

### 5.5 Multi-tenant URL — logique exacte

Référence : `remote.inc.php:450-459`. Substitution selon que la machine cible est dans l'établissement courant ou un autre.

```
input  : config.guacamole_url + (config.etab_ou) + etab_demandé
output : URL adaptée
```

Règle :
```
si (etab_ou défini ET etab_demandé != etab_ou)
   OU (etab_demandé non vide ET URL ne contient pas déjà etab_demandé)
→ URL = preg_replace("#/guacamole/#", "/{etab}/guacamole/", URL)
sinon
→ URL = config.guacamole_url tel quel
```

**Bug `b023546` à reproduire en test** : avant le fix, `etab_demandé` vide pouvait entrer dans la branche de substitution → générait `//guacamole/{path}` (double slash). La condition `! empty($etab)` doit être présente.

### 5.6 Source de configuration — arbitrage

Il faut décider **où** Laravel lit `guacamole_url`, `guac_priv_key`, `guacamole_schema`, `vnc_password`, `reverse_proxy`, `etab_ou`, `samba_domain`.

| Option | Pour | Contre |
|---|---|---|
| **A — `.env` + `config/guacamole.php`** | Standard Laravel, simple, testable, déploiement Ansible-friendly | Modification = redéploiement |
| **B — Table dédiée + Eloquent** | Modifiable à chaud via UI admin, audit trail | Complexité, risque de divergence avec le legacy qui lit en LDAP |
| **C — Shim legacy LDAP (cohabitation)** | Compatibilité parfaite avec legacy pendant la migration | Couplage fort au legacy ; bloque la sortie du shim |

**Recommandation** : **Option A** pour `guacamole_url`, `guac_priv_key`, `guacamole_schema`, `samba_domain`, `reverse_proxy` (config statique côté serveur). **Lecture du contexte établissement** (`etab_ou`) depuis la session/utilisateur Laravel (déjà résolu par AuthGuard). Le `vnc_password` est semi-statique → `.env`.

Si `guac_priv_key` doit rester synchrone avec le serveur Guacamole : la stocker côté `.env` et rappeler dans le runbook controlHub que les rotations doivent être propagées.

### 5.7 Cache des tokens

| Aspect | Choix |
|---|---|
| Driver | Cache Laravel par défaut du projet (héritage du choix global, à confirmer) |
| Clé | `guacamole.token.{md5(serialize(machines.cn) + user + type)}` — équivalent legacy |
| TTL | 7200 secondes (2h) — constante `GuacamoleAuthService::TOKEN_TTL_SECONDS` |
| Invalidation | Aucune programmée. Le token est self-contained, on laisse expirer. |
| Multi-utilisateur | Une clé par tuple `(machines, user, type)` — pas de partage croisé |

**Note** : le cache stocke le `authToken` retourné par Guacamole, pas le payload chiffré. Réutilisation directe pour ouvrir la session navigateur.

### 5.8 Imprimante virtuelle "Guacamole"

Le param `printer-name = "Guacamole"` injecté dans les payloads RDP active une imprimante PDF côté Guacamole (côté serveur Guacamole, pas côté Laravel). Aucun impact côté sambaedu-reload — juste reproduire le paramètre.

### 5.9 Options proxy HTTP

Le legacy applique `curl_proxy_options($config, $opt)` avant chaque appel Guzzle vers Guacamole. À porter en intercepteur sur le `Http::` Laravel ou en option par défaut du `GuacamoleClient`. Surface : `proxy_uri`, `proxy_user`, `proxy_password` (à confirmer dans le legacy).

## 6. Routes & UX

### Routes (filesystem-based)

Convention projet (cf. `CLAUDE.md`) : un dossier dans `resources/views/pages/` = une route, `index.blade.php` = page racine.

> **À confirmer pendant l'implémentation** : le pattern exact de routes parc déjà en place dans sambaedu-reload (le backlog mentionne *"pages /parc/*"* — voir epic 4 *Gestion des Machines*). La spec part du principe que la route s'aligne sur le pattern existant.

| Route | Fichier | Rôle |
|---|---|---|
| `GET /parc/{group}/rdp` (à valider) | `resources/views/pages/parc/[group]/rdp/index.blade.php` | Page liste des machines + boutons "Lancer" |
| (interne) | `resources/views/pages/parc/[group]/rdp/_partials/RemoteList.blade.php` | Livewire SFC liste machines + actions |
| (interne) | `resources/views/pages/parc/[group]/rdp/_partials/PasswordPrompt.blade.php` | Modal SSO password (réutilise le composant modale du projet) |

### Composant Livewire `RemoteList`

**État** :
- `Collection<Machine> $machines` — liste des machines du groupe (déjà résolue par WorkstationService existant)
- `string $type = 'rdp'` — type de connexion par défaut (RDP/VNC/SSH/Master)
- `?string $password = null` — password SSO si l'utilisateur a dû le ressaisir
- `array $launchUrls = []` — URLs Guacamole signées, indexées par cn de machine

**Actions** :
- `mount(Group $group)` : charge les machines.
- `requestLaunch(string $cn)` : déclenche le lancement pour une machine. Si SSO password absent (cf. legacy `parcs.inc.php:683-684`), ouvre la modal `PasswordPrompt`. Sinon enchaîne `launch`.
- `launch(string $cn)` : appelle `GuacamoleSessionLauncher::launch()`, stocke l'URL, dispatch un événement front pour ouvrir l'onglet (équivalent `openOnce()` legacy).
- `launchBatch(string $type)` : pour un mode "ouvrir toute la salle" (équivalent `guacamole_urls()`). Cas d'usage admin/prof — à confirmer si présent dans le scénario "domicile".
- `setType(string $type)` : change le protocole demandé.

**Évènements émis** :
- `guacamole.opening` (avec URL) → JS écoute et `window.open(url, 'guacamole')` (équivalent `openOnce`).
- `guacamole.error` (avec message) → toast via `WithToasts`.

### Flux UX nominal "accès domicile"

```
1. Utilisateur authentifié arrive sur /parc/{group}/rdp
2. Liste des machines de son groupe affichée (machines + état online/offline si dispo)
3. Clic sur "Lancer RDP" pour une machine
4. Si pas de mot de passe SSO en session → modal demande le mdp (Livewire + composant modal réutilisable)
5. Soumission → GuacamoleSessionLauncher.launch() → token Guacamole signé
6. Évènement front → ouverture nouvel onglet vers Guacamole
7. Session RDP s'ouvre dans le navigateur
```

### Cas d'erreur & gestion via `WithToasts`

| Cas | Message toast (FR) | Niveau |
|---|---|---|
| `guacamole_url` non configurée | "L'accès distant n'est pas configuré sur ce serveur." | error |
| Guacamole serveur injoignable (timeout / 5xx) | "Le service d'accès distant est indisponible. Merci de réessayer dans quelques minutes." | error |
| Token refusé par Guacamole | "Échec de l'authentification au service distant. Veuillez réessayer." | error |
| Mot de passe SSO incorrect (auto-bascule à l'ouverture RDP) | "Mot de passe refusé par le poste — vérifiez votre saisie." | warning |
| Machine offline (info pré-affichée si statut connu) | "Cette machine semble éteinte." (label sur le bouton, pas un toast) | info |

### Intégration dans la navigation

- Si `config('guacamole.url')` est définie → afficher le lien "Se connecter à un ordinateur" dans la nav utilisateur (équivalent `user.interface.inc.php:2016-2026`).
- Le lien pointe vers `/parc/{group_par_défaut}/rdp` ou un sélecteur de groupe si plusieurs.
- Visibilité conditionnée par le droit `SE_COMPUTER_CONTROL = 0x200` — à mapper sur Spatie Permission lors de l'implémentation (gate `accessRemoteDesktop` ou similaire).

### Hors-périmètre UI

- Pas de page de configuration Guacamole côté utilisateur (provisioning = controlHub).
- Pas de page d'historique des sessions (audit = controlHub si besoin).
- Pas de gestion de file d'attente / sessions concurrentes (cf. note legacy `display_remote_list:464-469` : sujet ouvert non tranché — non porté dans cette spec).

## 7. Risques & points ouverts

### Risques techniques

| # | Risque | Sévérité | Mitigation |
|---|---|---|---|
| R1 | **IV nul dans AES-128-CBC** (§5.1) — réutilisation IV avec même clé permet un pattern leak (analyse statistique de payloads chiffrés). | Moyen | Imposé par le fork sambaedu-guacamole, ne peut être changé unilatéralement. À tracer comme dette crypto, à remonter à l'équipe sambaedu-guacamole pour évolution future. |
| R2 | **Divergence du format token entre sambaedu-reload et controlHub** (briques dupliquées) — un changement crypto d'un côté casse l'auth de l'autre. | Élevé | Documenter le format dans `handoff-guacamole-controlhub.md` §3, ajouter un test de compatibilité round-trip à exécuter sur les deux codebases. Inscrire la `guac_priv_key` comme paramètre de coordination ops. |
| R3 | **Cache batch `guacamole_urls` invalidé incorrectement** — le legacy met en cache un token contenant N connexions ; ajouter une machine au groupe sans invalider laisse l'utilisateur sur l'ancien token. | Moyen | À reproduire au cas où le scénario domicile expose une liste batch (probablement non — on lance machine par machine). Sinon : invalidation explicite à toute mutation de groupe. |
| R4 | **Auto-switch RDP→VNC/SSH dans le builder** (§5.4) couple le builder à l'état de la machine. | Faible | Décision de design : extraire en `ConnectionTypeResolver` ou accepter le couplage. À trancher en code review. |
| R5 | **Regex `se4[fs|ad]`** mal formée (§5.4). | Faible | Réécriture en `^se4(fs|ad)` ou liste explicite — à faire au moment du portage, pas à reproduire à l'identique. |
| R6 | **Sessions concurrentes** sur une même machine — comportement non spécifié dans le legacy (cf. TODO `display_remote_list:464-469`). | Faible | Hors-scope de cette spec. À traiter dans une story ultérieure si retour utilisateur. |
| R7 | **Guacamole serveur indisponible** — flux UX non testé sur cas dégradé. | Moyen | Toasts d'erreur explicites (§6). Test manuel à inclure dans le plan de tests (§8). |
| R8 | **Cycle de vie de `/etc/sambaedu/id_rsa.pem`** (§5.3) — le legacy auto-génère via `sudo ssh-keygen`. Porter ce comportement = exposer du sudo dans Laravel. | Moyen | **Recommandation** : externaliser ce pré-requis vers le provisioning (Ansible/AWX) côté controlHub, sambaedu-reload présume le fichier présent et lève une erreur explicite sinon. |

### Points ouverts à trancher

| # | Point ouvert | Impact | Quand trancher |
|---|---|---|---|
| O1 | **`create_remote_connexion()` (LDAP `ou=rdp` + `guacConfigGroup`)** — central ou local ? | Si local → manque dans le scope, story à ajouter. Si central → handoff à compléter. | Avant code review de la première story d'impl |
| O2 | **Source de configuration** — option A (.env) vs B (table) vs C (shim LDAP) (§5.6) | Guide la première story | Avant kickoff de la première story |
| O3 | **Convention exacte de routing** `/parc/{group}/rdp` ou variante | Naming des fichiers | Première story |
| O4 | **Veyon Master / Veyon poste** dans le scope domicile ? | Si OUI → tests à ajouter ; si NON → builder n'expose pas ces protocoles dans la story domicile | Avant la story builder |
| O5 | **`launchBatch` (équivalent `guacamole_urls`)** dans le scope domicile ? Le scénario "élève à la maison" n'a pas vraiment besoin d'ouvrir 30 machines en batch ; mais l'enseignant peut. | Présence du bouton "ouvrir tout le parc" dans l'UI | Découpage en stories |
| O6 | **Niveau de logging** structurés (start/end appel Guacamole, latences, erreurs) | Observabilité runtime ; cf. epic 1bis-1 *Error Logger* | Story builder |
| O7 | **Comportement multi-onglets** (utilisateur lance 2 sessions sur la même machine) | UX | Tests manuels |
| O8 | **Mapping `SE_COMPUTER_CONTROL = 0x200` → permission Spatie** | Quel nom de permission ? Existante ou à créer ? | Story UI/nav |
| O9 | **Options proxy HTTP** (`curl_proxy_options`) — quelle config existe côté Laravel ? | Si proxy ENT en place → à brancher | Story client HTTP |

### Décisions différées (notées dans cette spec, reportées à l'implémentation)

- **Driver de cache** Laravel pour les tokens : hérité du choix global du projet.
- **Granularité des tests d'intégration** contre Guacamole : à arbitrer avec QA selon disponibilité d'un Guacamole de test.
- **Mécanisme de feature flag** pour cohabitation legacy/Laravel pendant la migration : à harmoniser avec la stratégie globale `LEGACY_BLOCK_MIGRATED_ROUTES` (epic 1-2).

### Hors-scope explicite (rappel)

Les sujets ci-dessous ne sont **pas des risques** parce qu'ils sont assumés hors-scope, mais doivent rester dans la conscience du lecteur :

- Refonte UX, MFA, OpenID Connect, session recording, audit RGPD, mode observation VNC.
- Provisioning Tomcat/HAProxy/conf UAI (controlHub C10-1/2/3).
- Dépannage admin (controlHub C10-4).

## 8. Tests

### 8.1 Tests unitaires (Pest / PHPUnit)

| Cible | Cas couverts |
|---|---|
| `GuacamoleAuthService::encryptJsonToken` | • Round-trip avec payload de référence figé (JSON déterministe) → output base64 attendu.<br>• Génération de `guac_priv_key` si absente, persistance.<br>• Erreur explicite si clé non hex valide.<br>• Erreur explicite si clé != 16 octets après `hex2bin`. |
| `GuacamoleConnectionBuilder` | • `buildRdp` produit les params attendus (snapshot test).<br>• `buildVnc` injecte `vnc_password` depuis config.<br>• `buildSsh` lit la clé PEM, lève une erreur si fichier absent.<br>• `buildMaster` ajoute `remote-app=||Veyon Master`.<br>• Auto-switch SSH si `cn` matche `se4(fs\|ad)`.<br>• Auto-switch VNC si `vnc_password` + machine occupée par utilisateur ≠ wpkg.<br>• Pas de switch sinon. |
| `GuacamoleUrlResolver::resolve` | • `etab` vide ET `etab_ou` vide → URL inchangée.<br>• `etab` == `etab_ou` → URL inchangée.<br>• `etab` ≠ `etab_ou` → URL avec préfixe inséré.<br>• **Non-régression bug `b023546`** : `etab` vide ne doit JAMAIS produire `//guacamole/`. |
| `GuacamoleConfigRepository` | • Lecture des 6 paramètres documentés en §3.<br>• Comportement quand `guac_priv_key` absente (auto-génération vs erreur, selon arbitrage). |

### 8.2 Tests d'intégration

| Cible | Cas |
|---|---|
| **Round-trip token vs fork sambaedu-guacamole** (CRITIQUE) | Lancer un Guacamole `sambaedu-guacamole` (Docker ou VM dédiée), envoyer un payload chiffré par `GuacamoleAuthService`, vérifier HTTP 200 sur `/api/tokens` et présence d'un `authToken` valide dans la réponse. **Non-négociable** : si ce test ne passe pas, la spec n'est pas livrée. |
| `GuacamoleClient` HTTP | Mock du serveur Guacamole avec un fake server (pas Mockery sur `Http::` — simuler les réponses du fork). |
| `GuacamoleSessionLauncher` end-to-end | Avec `Http::fake()` Laravel : appel `launch()` sur une machine factice → vérifier URL retournée (forme `{guac_url}#/?token={token}`), cache hit/miss. |

### 8.3 Tests d'erreur

| Scénario | Comportement attendu |
|---|---|
| `guacamole_url` config absente | `launch()` lève `GuacamoleNotConfiguredException` ; UI affiche toast error correspondant. |
| Guacamole serveur timeout | `launch()` lève `GuacamoleUnavailableException` ; UI affiche toast retry. |
| Guacamole 5xx | Idem timeout. |
| Token rejeté par Guacamole (HTTP 403 sur `/api/tokens`) | Exception loggée, UI affiche toast generic auth error. |
| Clé SSH `/etc/sambaedu/id_rsa.pem` manquante (build SSH) | Exception levée explicitement (pas de génération auto en Laravel — déléguée au provisioning). |
| Clé `guac_priv_key` non hex / mauvaise longueur | Exception au boot ou au premier appel selon stratégie de validation. |

### 8.4 Tests manuels UI

À exécuter sur la VM `/vm` après déploiement :

- [ ] Connexion utilisateur standard → page `/parc/{group}/rdp` accessible.
- [ ] Liste des machines correspond aux machines du groupe.
- [ ] Clic "Lancer RDP" → ouverture nouvel onglet → session Guacamole RDP fonctionnelle.
- [ ] Clic "Lancer VNC" sur une machine occupée → bascule auto vers VNC (auto-switch §5.4).
- [ ] Clic "Lancer SSH" sur un serveur `se4fs-XXX` → session SSH (admin uniquement).
- [ ] Saisie mot de passe SSO via modale si pas en session → session ouvre correctement.
- [ ] Mauvais mot de passe → toast warning + utilisateur reste sur la liste.
- [ ] `guacamole.url` vidée dans config → lien nav disparaît, route renvoie 404 ou message d'indisponibilité.
- [ ] Couper Guacamole (stop tomcat sur `/vm`) → toast error explicite, pas de stack trace exposée.

### 8.5 Tests de non-régression vs legacy

Comparaison directe pendant la cohabitation legacy/Laravel :

- [ ] Token généré par Laravel pour une machine donnée → ouvre la même session que le token legacy pour la même machine et le même utilisateur.
- [ ] URL multi-tenant identique entre `guacamole_url($config, $etab)` legacy et `GuacamoleUrlResolver::resolve($etab)` Laravel pour les 4 cas du tableau §5.5.
- [ ] Format JSON des connexions strictement identique (snapshot diff par protocole) — toute divergence sur `printer-name`, `server-layout`, etc. doit être tracée.

### 8.6 Tests de coordination avec controlHub (E2E inter-codebases)

À programmer avec l'équipe controlHub pour valider la cohérence des briques dupliquées :

- [ ] Token généré par controlHub pour `remote_admin_machine` → accepté par le même Guacamole que les tokens sambaedu-reload.
- [ ] Rotation de `guac_priv_key` côté provisioning controlHub → invalide les anciens tokens des deux côtés ; les nouveaux tokens des deux côtés fonctionnent.
