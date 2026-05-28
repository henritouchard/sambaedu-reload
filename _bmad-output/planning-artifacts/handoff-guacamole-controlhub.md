# Handoff Guacamole → controlHub

> **Statut** : draft · **Auteur** : John (PM) · **Date** : 2026-05-07
> **Document parent** : [quickspec-guacamole-sambaedu-reload.md](./quickspec-guacamole-sambaedu-reload.md)
> **Audience** : équipe controlHub (ou équipe sambaedu-reload selon répartition)

Ce document liste ce qui, dans le legacy `sambaedu/`, doit être porté côté **controlHub** plutôt que dans sambaedu-reload, et les points d'attention associés.

---

## 1. Fonctions à porter dans controlHub

Ces fonctions PHP du legacy `sambaedu/` sont **central-only** (cf. cartographie des callers — toutes les invocations sont dans `central/` ou dérivent d'`activate_etab`). Elles ne sont donc pas portées dans sambaedu-reload mais doivent l'être côté controlHub.

### 1.1 Provisioning Tomcat + `guacamole.properties`

**Source legacy** : `configure_guacamole_server($config)` — `sambaedu/includes/remote.inc.php:911-1011`

**Ce que ça fait** :
- Lit `/etc/tomcat9/server.xml` (ou `/etc/tomcat10/server.xml`) via `sudo cat`.
- Manipule le XML avec `DOMDocument` :
  - Ajoute / met à jour / supprime un `<Valve className="org.apache.catalina.valves.RemoteIpValve" internalProxies="..." remoteIpHeader="x-forwarded-for" ...>` selon `$config['reverse_proxy']`.
- Lit `/etc/guacamole/guacamole.properties` (via `sudo cp` + `sudo chown www-admin /tmp/...`).
- Garantit la cohérence des params LDAP : `se4ad_name`, `ldap_port`, `people_rdn`, `ldap_base_dn`, `admin_rdn`, `ldap_admin_name`, `ldap_admin_passwd`.
- Garantit `json-secret-key = $config['guac_priv_key']`.
- Garantit `extension-priority = ldap`.
- Si modifications : `sudo cp` retour, `sudo chown tomcat:tomcat`, `sudo systemctl restart tomcat9.service`.

**Déclenchement** : `config/config_action.php:195-199` — case `guacamole_update`, `reverse_proxy`, `guac_priv_key` (fall-through PHP volontaire).

**Mapping epic** : C10-1.

### 1.2 Provisioning HAProxy backend par établissement

**Source legacy** : `create_haproxy_guacamole_backend($config, $uai, $ip)` — `sambaedu/includes/sites.inc.php:564-685`

**Ce que ça fait** : 5 POST + 1 PUT en transaction sur **HAProxy Data Plane API v2** :
1. `POST /v2/services/haproxy/configuration/backends` → backend `guacamole_{uai}`, mode http, healthcheck OPTIONS.
2. `POST /v2/services/haproxy/configuration/servers` → `{ip}:8080`, check enabled, inter 20000.
3. `POST /v2/services/haproxy/configuration/http_request_rules` → `replace-path` `^/{uai}/(.*)` → `/\1`.
4. `POST /v2/services/haproxy/configuration/acls` (sur frontend `central_front`) → ACL `acl_guacamole_{uai}` avec `path_beg /{uai}/guacamole/`.
5. `POST /v2/services/haproxy/configuration/backend_switching_rules` → `if acl_{name}` → backend.
6. `PUT /v2/services/haproxy/transactions/{id}` → commit.

Idempotent par check préalable `if (! in_array($name, $backends))`.

**Mapping epic** : C10-2.

### 1.3 Génération de la conf par UAI

**Source legacy** : `create_guacamole_conf($config, $etab)` — `sambaedu/includes/remote.inc.php:1023-1045`

**Ce que ça fait** :
- Compute `$reverse_proxy` (via DNS reverse de `se4fs-{etab}` puis substitution gateway), `$remote_admin_machine`, `$guacamole_url` (substitution `/guacamole` → `/{etab}/guacamole`).
- Si `/etc/sambaedu/guacamole/{uai}.conf` n'existe **pas** : écrit le fichier avec ce contenu :
  ```
  reverse_proxy = "..."
  remote_admin_machine = "..."
  guacamole_url = "..."
  guacamole_schema = "..."
  guac_priv_key = "..."
  ```
- Idempotent par `file_exists`.

**À CLARIFIER avec les ops** : ce fichier est généré sur le central mais utilisé sur les serveurs des sites distants. Le commentaire legacy dit *"le résultat est enregistré dans un fichier pour déploiement ultérieur sur les sites"*. Mécanisme de déploiement non identifié dans le code analysé — possiblement AWX/Ansible (présent dans `activate_etab` via `create_awx_host`), `push_ssh_commands` (`sites.inc.php:695-713`), ou rsync externe.

**Mapping epic** : C10-3.

### 1.4 Dépannage admin via `remote_admin_machine`

**Source legacy** : `create_remote_token($config, search_machine($config, $config['remote_admin_machine'] . etab_suffix($etab)), "master", $config['login'])` — appelée depuis `sambaedu/central/php/includes/annu_ui.inc.php:23`

**Ce que ça fait** :
- Identique au runtime user "domicile" (cf. quickspec §4) **mais** :
  - Cible la machine `remote_admin_machine` de l'établissement (poste de rebond admin), pas la machine de l'utilisateur.
  - Type forcé à `"master"` (Veyon Master).
  - Identité : `$config['login']` admin courant.
- Réutilise toutes les briques crypto et builder (token signé, URL multi-tenant, payload Veyon Master).

**Conséquence** : controlHub doit re-implémenter ou réutiliser :
- `encrypt_json_token` → crypto AES-128 + HMAC, **format identique à sambaedu-reload** (cf. §3 Contrat).
- `get_guacamole_auth_token` → POST `/api/tokens`.
- `guacamole_url` → résolution multi-tenant.
- `create_remote_json_connection` → builder de payloads (au moins le mode `master`).

**Mapping epic** : C10-4.

### 1.5 Orchestration `activate_etab`

**Source legacy** : `activate_etab($config, $uai, $force)` — `sambaedu/includes/sites.inc.php:724-754`

**Ce que ça fait** : workflow complet de création d'un établissement, qui inclut deux étapes Guacamole (lignes 745-747) :
1. Création AD + DHCP + AWX hosts (hors-scope Guacamole).
2. `create_haproxy_backend()` (backend HTTP principal).
3. **`create_haproxy_guacamole_backend()`** (cf. 1.2).
4. **`create_guacamole_conf()`** (cf. 1.3).
5. `create_nc_api_user()` (Nextcloud).

**Conséquence** : la création/réactivation d'un établissement côté controlHub doit déclencher les étapes 3 et 4 dans le bon ordre (HAProxy avant la conf, ou en parallèle si idempotents — vérifier).

**Mapping epic** : C10-6.

---

## 2. Points d'attention techniques

### 2.1 Manipulations système (`sudo`, fichiers, `systemctl`)

`configure_guacamole_server()` exécute :
- `sudo cat /etc/tomcat*/server.xml`
- `sudo test -f /etc/guacamole/guacamole.properties`
- `sudo cp ... /tmp/...`
- `sudo chown www-admin /tmp/...`
- `sudo cp /tmp/server.xml /etc/tomcat*/server.xml`
- `sudo chown tomcat:tomcat ...`
- `sudo mv /tmp/guacamole.properties /etc/guacamole/...`
- `sudo systemctl restart tomcat*.service`

**Question d'archi** : controlHub fait-il directement ce genre d'opérations système, ou délègue-t-il à AWX/Ansible (qui est déjà dans la stack — `create_awx_host` est utilisé) ?

**Recommandation** : déléguer à un playbook AWX. controlHub appelle l'API AWX, le playbook fait la manipulation Tomcat. Avantages :
- Pas de `sudo` exposé côté Laravel.
- Idempotence native d'Ansible.
- Audit centralisé via AWX.
- Réutilisable pour rebuild propre.

À arbitrer dans la story C10-1.

### 2.2 Manipulation XML de `server.xml`

Le legacy utilise `DOMDocument` + XPath. À porter en Laravel : utiliser le même `DOMDocument` PHP standard (pas besoin de lib externe). XPath ciblé :
```
/Server/Service/Engine/Host/Valve[@internalProxies]
/Server/Service/Engine/Host
```

Attention : Tomcat 9 vs 10, le legacy a la condition `if (file_exists("/etc/tomcat10/server.xml") && false)` (le `&& false` désactive Tomcat 10) — **dette technique** à clarifier au portage.

### 2.3 HAProxy Data Plane API v2

Pas de surprise : Guzzle ou `Http::` Laravel. Auth basic. Pattern transaction (POST `/transactions` → opérations → PUT `/transactions/{id}`). Endpoints concernés :
- `/v2/services/haproxy/configuration/backends`
- `/v2/services/haproxy/configuration/servers`
- `/v2/services/haproxy/configuration/http_request_rules`
- `/v2/services/haproxy/configuration/acls`
- `/v2/services/haproxy/configuration/backend_switching_rules`
- `/v2/services/haproxy/transactions/{id}`

### 2.4 Déploiement de la conf UAI vers les sites

À investiguer (cf. 1.3). Hypothèses ouvertes :
- AWX playbook qui copie `/etc/sambaedu/guacamole/{uai}.conf` du central vers `se4fs-{uai}`.
- `push_ssh_commands` legacy (`sites.inc.php:695-713`) avec un `scp`.
- Tâche périodique de sync.

À clarifier avec les ops avant le portage de C10-3.

### 2.5 Réutilisation des briques runtime

Les fonctions `encrypt_json_token`, `get_guacamole_auth_token`, `guacamole_url`, `create_remote_json_connection` sont aussi nécessaires côté controlHub (cf. 1.4). Stratégie validée par Henri : **duplication** — pas de package Composer partagé.

→ controlHub re-implémente ces fonctions à l'identique. Le contrat de format est documenté en §3.

---

## 3. Contrat partagé avec sambaedu-reload

Pour que sambaedu-reload et controlHub puissent générer des tokens **interopérables avec le même fork sambaedu-guacamole**, ils doivent respecter strictement le même contrat technique. Toute divergence casse l'auth d'un côté ou de l'autre.

### 3.1 Crypto du token signé (verrou strict)

| Élément | Valeur |
|---|---|
| Algorithme symétrique | AES-128-CBC |
| Mode | RAW (pas d'encodage PKCS#7 sur la sortie base64) |
| Taille de clé | 16 octets binaires (32 caractères hex) |
| IV | **Constant nul** (16 octets de zéros) — `00000000000000000000000000000000` en hex |
| HMAC | HMAC-SHA256, output binaire (32 octets), pas de hex |
| Layout du clair | `HMAC_binary || JSON_payload_utf8` (concaténation directe) |
| Encodage final | `base64(ciphertext_raw)` |

**Référence implémentation** : `remote.inc.php:693-745`.

### 3.2 Source partagée de `guac_priv_key`

- La clé est stockée dans la **config sambaedu** (legacy : LDAP). Pour la migration : à arbitrer. Recommandation : single source of truth (par ex. `.env` du central, propagé via Ansible vers les SE et synchronisé avec sambaedu-reload).
- **Toute rotation doit être propagée simultanément** côté `guacamole.properties` du serveur Guacamole, côté sambaedu-reload, et côté controlHub. Inscrire un runbook ops.
- À l'initialisation (cf. `encrypt_json_token:697-699`) : si la clé est vide, le legacy génère `bin2hex(openssl_random_pseudo_bytes(16))`. Ce comportement doit rester **central-only** (une seule génération de référence) pour éviter la divergence.

### 3.3 Format du payload JSON

```json
{
  "username": "<user>",
  "expires": <timestamp_ms>,
  "connections": {
    "<connection_name>": {
      "protocol": "rdp|vnc|ssh",
      "parameters": { ... }
    }
  }
}
```

**Convention de nommage des connexions** : `"<type> sur <cn>"` (legacy : `'rdp sur ' . $machine['cn']`, etc.). À respecter pour cohérence UX.

**TTL `expires`** : 7200 s par défaut (2h), ms epoch. Doit être cohérent entre les deux codebases si tokens partagés (improbable, mais documenté).

### 3.4 Multi-tenant URL

Logique de substitution identique des deux côtés (cf. quickspec §5.5) :
```
si (etab_ou défini ET etab_demandé != etab_ou)
   OU (etab_demandé non vide ET URL ne contient pas déjà etab_demandé)
→ URL = preg_replace("#/guacamole/#", "/{etab}/guacamole/", URL)
sinon → URL inchangée
```

**Bug `b023546`** corrigé par condition `! empty($etab)` — à reproduire dans les deux codebases.

### 3.5 Test de compatibilité bilatéral

Recommandation : ajouter un test d'intégration qui s'exécute **dans chaque codebase** :
1. Génération d'un token avec un payload de référence figé (committé dans le repo).
2. Vérification que le résultat base64 final matche un fingerprint stocké également dans les deux repos.
3. Si l'un des fingerprints diverge → CI rouge sur les deux codebases.

→ détecte immédiatement toute divergence d'implémentation crypto, format JSON ou résolution URL.

### 3.6 Versionning du contrat

Tout changement futur du contrat (ex: rotation algo, ajout de claim, changement de format JSON) doit être :
- documenté dans **ce fichier** (qui devient le single source of truth du contrat),
- coordonné entre les équipes sambaedu-reload, controlHub et sambaedu-guacamole (le fork upstream),
- accompagné d'une migration côté config (ex: `guac_priv_key_v2` pour permettre cohabitation pendant rollout).
