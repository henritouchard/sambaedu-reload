# Audit iso-legacy — Story 16.8 (Phase 2 Epic 16)

> Livrable T4/T5 de la Story 16.8 « Stabilisation Phase 1 ». Recensement
> exhaustif des occurrences `SE4FS` nu (Volet A) et des shims 1bis.18
> résiduels (Volet B). Plan de re-substitution / retrait actionnable pour
> 16.10 (HTTPS+JWT, re-substitution avant bascule) et 16.13 (cleanup shims
> définitif).

**Date d'exécution** : 2026-05-15
**Auteur** : claude-opus-4-7[1m] (Story 16.8, Phase T4/T5)
**HEAD host** : `0a4609c fix(story-16.8): tests Feature GPO`
**VM exécution Laravel** : `192.168.122.50` (`se4fs`, hôte du code applicatif)
**VM AD DC** : `192.168.122.60` (Samba AD DC `localdev.fr`, credentials `.env` : `Administrator/Azerty-1234`) — accessible via SMB depuis 192.168.122.50
**Statut** : livrable
**Statut tests Phase 1** : ✅ GREEN — 474 passed / 0 failed / 15 skipped / 3 risky

---

## 1. Méthodologie

### 1.1 Périmètres scannés

| Volet | Cible | Commande exacte |
|---|---|---|
| A — Code Laravel | `app/ resources/ routes/ config/` (host = sync inotify) | `grep -rnE 'SE4FS[^_-]\|http://SE4FS\|https://SE4FS\|\bSE4FS/\|//SE4FS\b' --include="*.php" --include="*.blade.php" --include="*.js" ...` |
| A — Code legacy | `legacy/` (host) | `grep -rnE 'SE4FS[^_-]\|http://SE4FS\|https://SE4FS\|\bSE4FS/' legacy/` |
| A — Placeholders templates | `app/ resources/ routes/ config/ legacy/` | `grep -rnE '###_SE4FS_NAME_###\|###_SE4FS_IP_###'` |
| A — Substitution dynamique | code source | `grep -rnE 'se4fs_name'` |
| A — SYSVOL DC | `\\192.168.122.60\sysvol\localdev.fr` (via SMB) | `smbclient //192.168.122.60/sysvol -U "Administrator%Azerty-1234" -Tc sysvol.tar localdev.fr` puis `grep -rnE "SE4FS[^_-]\|###_SE4FS_NAME_###\|###_SE4FS_IP_###"` sur le contenu extrait |
| A — Templates statiques | `/etc/sambaedu /usr/share/sambaedu` (VM) | `ssh /vm 'grep -rnE "SE4FS[^_-]" /etc/sambaedu /usr/share/sambaedu'` |
| B — Inventaire shims | `legacy/gpo_shim.inc.php legacy/modules/gpo/*.php` | `ls + wc -l` |
| B — Callsites Laravel | `app/ resources/` | `grep -rnE 'gpolistcontainers\|gpogetlink\|gposetlink\|gpodellink\|sysvol_put\|read_gpo_sysvol\|update_gpo_sysvol\|sysvol_acl_reset\|_shim_gpo_\|import_gpo\|export_gpo\|specialise_gpo\|sambatool\('` |
| B — Cartographie | croisement `audit-gpo-legacy.md` (Story 16.1) | lecture documentaire |

### 1.2 Limitations connues

- **SYSVOL dev ≠ SYSVOL prod** : le Samba AD DC `192.168.122.60` (domaine `localdev.fr`) est actif et accessible via SMB, mais **vide de contenu** : 14 GPO recensées dans `Policies/` (dont les 2 GPO Windows standard `{31B2F340-...}` Default Domain Policy et `{6AC1786C-...}` Default Domain Controllers + 12 GPO custom), mais toutes ne contiennent **que des `GPT.INI`** (22 octets, en-tête metadata). Aucun script `.cmd`/`.bat`/`.ps1`, aucune policy `.pol`, aucun fichier `Drives.xml` peuplé. L'audit SYSVOL retourne **0 occurrence** sur cet environnement, ce qui est cohérent avec son rôle (DC propre dev/test, GPO non spécialisées). **À refaire sur un serveur de prod** (où les GPO `se4_*` sont peuplées avec scripts et placeholders substitués) avant 16.10.
- **iPXE/preseed/unattend déployés** : non vérifiés sur le parc — l'audit ne couvre que les **sources** dans le dépôt, pas les artefacts générés et résidant sur les postes Windows / images iPXE actuelles.
- **Fichiers fantômes inotify** : selon `[[project_inotify_no_delete_sync]]`, des fichiers supprimés sur le host peuvent persister sur la VM. Non vérifié sur ce périmètre (les fichiers VM `/usr/share/sambaedu` / `/etc/sambaedu` sont gérés hors sync inotify).

---

## 2. Volet A — `SE4FS` nu

### 2.1 Tableau exhaustif par famille

> **Critique = bloquant 16.10** (HTTPS+JWT) si non corrigé : URLs qui résoudraient
> vers le **central historique** (`172.19.254.4`) alors que les endpoints v1 sont
> sur le **serveur local** (`se4fs-<UAI>`).

| # | Famille | Représentant (fichier:ligne) | Contexte | Classification | Critique 16.10 ? | Plan re-substitution |
|---|---|---|---|---|---|---|
| F1 | Logs / strings / noms internes | `app/Services/ControlHub/ControlHubService.php:37` `Log::info('SE4FS Handshake ...')` | message log | commentaire/doc — string interne | **Non** | N/A — pas une URL appelée |
| F1 | Logs / strings | `app/Http/Controllers/Api/v1/ControlHub/StatsControllerOld.php:138-405` `Log::info('SE4FS Stats request')` × N | logs | commentaire/doc | **Non** | N/A |
| F1 | Titres de pages Livewire | `resources/views/pages/users/[login]/index.blade.php:22` `#[Title('Profil utilisateur - Instance SE4FS')]` × 5 vues | branding UI | commentaire/doc | **Non** | N/A — texte affiché à l'utilisateur |
| F1 | Noms de classes / méthodes | `app/Models/ControlHubConnection.php:87/141` `getSE4FSToken`/`validateSE4FSToken`, `app/Models/SE4FSApiToken.php` | API interne | actif natif | **Non** | N/A — naming interne |
| F1 | Commentaires de code | `app/Ldap/AdMachineManager.php:80` `// Iso-legacy : serveurs SE4FS/SE4AD = no-op idempotent.` | doc | commentaire/doc | **Non** | N/A |
| F1 | Config branding | `app/Services/StatsService.php:279/424` `config('se4fs.establishment.name', 'SE4FS Instance')` | nom d'instance défaut | actif natif (string) | **Non** | N/A — string interne |
| F2 | Variable Windows `%SE4FS%` (chemin SMB) | `app/Gpo/Services/ApplicationScriptsAssembler.php:498/527/530` `\\\\%SE4FS%\\users\\...` | script .cmd généré côté serveur, exécuté côté poste | actif natif | **Non — sous condition** | Dépend de la valeur effective de `%SE4FS%` côté poste, positionnée par `wpkg.cmd` à `se4fs-<UAI>` (cf. Tech Spec §5.0). Si poste à jour → OK. Si vieux poste → fallback `###_SE4FS_NAME_###` substitué côté serveur lors de la génération (lignes 305-333). **Couvert** par la logique de fallback. |
| F2 | `%SE4FS%` (env Windows) | `/usr/share/sambaedu/applications/{rclone,thunderbird,vscode,firefox,conda}/logon*.windows` | scripts utilisateur | template/script statique | **Non — sous condition** | Idem F2. Risque résiduel sur postes non re-déployés. À couvrir par 16.11 (auto-bootstrap migration postes). |
| F2 | `%SE4FS%` (env Windows) | `/usr/share/sambaedu/applications/firewall/startup.windows:28` `netsh advfirewall ... name="Allow from SE4FS"` | string label firewall | template/script statique | **Non** | N/A — string label, pas URL |
| F2 | `%SE4FS%` (env Windows) | `/usr/share/sambaedu/applications/reseau/networkInfo.ps1:64` `$Uri = "http://${env:SE4FS}/logs.php"` | endpoint logs | template/script statique | **Non — sous condition** | URL construite via env var. **Devra passer en HTTPS** en 16.10. Cohérent avec F2 — la valeur `%SE4FS%` est par poste, pas hardcodée. |
| F3 | Placeholder `###_SE4FS_NAME_###` (templates GPO) | `/usr/share/sambaedu/gpo/sambaedu-gpo/se4_applications/Machine/Scripts/{Shutdown,Startup}/*.cmd:2-4` | scripts GPO SambaEdu standard | template/script statique | **Non — sous condition** | Substitué côté serveur par `specialise_gpo` (legacy) ou `WpkgGpoSynchronizer` (natif Laravel 16.6) lors de l'import dans SYSVOL → `se4fs-<UAI>`. **À vérifier en prod que la dernière substitution a bien été déclenchée**. |
| F3 | Placeholder | `legacy/modules/ipxe/Win10/action.php:7`, `legacy/modules/gpo/network_out.php:5`, `legacy/modules/gpo/applications.php:5` | commentaires curl example dans doc legacy | commentaire/doc | **Non** | N/A — pas du code actif |
| F4 | URL dynamique `$config['se4fs_name']` | `legacy/modules/ipxe/Win10/action.php:90/105/113/131/169` `curl -F ... "http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php"` | curl runtime côté serveur (rendu inline du script poste) | actif legacy via shim | **Non** | Génération dynamique vers serveur local. Devra passer en `https://` en 16.10. |
| F4 | URL dynamique | `legacy/modules/ipxe/Win10/install.bat.php:47/56`, `repair.bat.php:62`, `unattend.xml.php:37` | scripts iPXE installation Win10 | actif legacy via shim | **Non — sous condition** | Idem F4. Re-déploiement images iPXE requis après 16.10 (HTTPS). |
| F4 | URL dynamique | `legacy/modules/display/config.php:36` `http://" . $config['se4fs_name'] . "/display/"` | doc UI legacy | actif legacy via shim | **Non** | Doc UI, sera retirée avec module display (Phase 3 ?) |
| F5 | Config Laravel | `config/sambaedu.php:170` `'se4fs_name' => env('SE4FS_NAME', '')`, `config/app-customizations.php:59` `'se4fs_name' => env('SE4FS_NAME', 'se4fs')` | source de vérité de la résolution | actif natif | **Non** | N/A — point de paramétrage attendu |
| F6 | IP hardcodée | `/usr/share/sambaedu/scripts/LTSP_buster_SE4.sh:16` `IP_SE4FS="172.16.1.11"` | script bootstrap LTSP (postes Linux) | template/script statique | **Hors-scope 16.10** | IP fixe pour LTSP boot. Si LTSP utilisé en prod, à revoir séparément (story dédiée). Pas dans le périmètre GPO/HTTPS. |

### 2.2 SYSVOL DC dev (T4.4)

```
# Téléchargement intégral SYSVOL via SMB depuis le DC dev
ssh /vm 'cd /tmp/sysvol-audit && smbclient //192.168.122.60/sysvol \
  -U "Administrator%Azerty-1234" -Tc sysvol.tar localdev.fr'
→ 14 fichiers (uniquement GPT.INI ; aucun script, aucune policy peuplée)

# Grep des fichiers téléchargés
grep -rnE "SE4FS[^_-]|###_SE4FS_NAME_###|###_SE4FS_IP_###" /tmp/sysvol-audit/localdev.fr
→ 0 occurrence (SYSVOL dev non spécialisée)
```

**Action requise avant 16.10** : refaire l'audit SYSVOL sur un **serveur de prod déployé** où les GPO `se4_applications`, `se4_lecteurs_reseau`, `se4_impression`, `se4_wpkg` etc. sont effectivement peuplées avec scripts et policies. Les commandes ci-dessus restent valides (à adapter avec l'IP et les credentials du DC cible) — vérifier l'absence de `###_SE4FS_NAME_###` non substitué (= GPO publiée avec une version antérieure du serveur de génération). Si présence : re-publier la GPO via `WpkgGpoSynchronizer::publish` (16.6) ou shim `import_gpo`.

### 2.3 Conclusions Volet A

- **0 occurrence critique** identifiée dans le code source du dépôt (host + VM dev). Aucun appel hardcodé `http://SE4FS/...` ou `https://SE4FS/...`.
- **5 familles de substitution dynamique** identifiées, toutes paramétrées par `$config['se4fs_name']` (legacy) / `config('sambaedu.se4fs_name')` (Laravel) / `%SE4FS%` env var Windows / `###_SE4FS_NAME_###` placeholder → résolution vers `se4fs-<UAI>` (serveur local) par construction.
- **Risque résiduel** : postes Windows non re-déployés où `%SE4FS%` env var n'a pas été repositionnée par `wpkg.cmd` à `se4fs-<UAI>`. **Mitigation** = 16.11 (auto-bootstrap migration postes).
- **Action court terme** (avant 16.10) : confirmer sur un serveur prod que les GPO SYSVOL ne contiennent pas de `###_SE4FS_NAME_###` non substitué (= GPO publiée avec une version antérieure du serveur de génération). Si présence : re-publier la GPO via 16.6 (`WpkgGpoSynchronizer::publish`) ou shim `import_gpo`.
- **Action moyen terme** (16.10) : substituer `http://` → `https://` pour toutes les URLs construites via `$config['se4fs_name']` (5 fichiers iPXE legacy + 1 fichier display) lors de la mise en place HTTPS+JWT.

---

## 3. Volet B — Shims 1bis.18 résiduels

### 3.1 Inventaire fichiers shim

| Fichier shim | Lignes | Description |
|---|---|---|
| `legacy/gpo_shim.inc.php` | 518 | Shim principal — bridge Kerberos (`KRB5CCNAME`) + wrapper `_shim_gpo_exec` + fallbacks `_shim_*` des 8 fonctions GPO si les includes legacy ne sont pas chargés (cf. story 1bis.18g) |
| `legacy/modules/gpo/applications.php` | 51 | Endpoint legacy `/gpo/applications.php` (curl POST clients pour récupérer scripts pré/post-app) |
| `legacy/modules/gpo/associations_out.php` | 173 | Endpoint legacy `/gpo/associations_out.php` (associations app↔extension Wine) |
| `legacy/modules/gpo/gestion_gpo.php` | 69 | Page UI legacy de gestion GPO (`/gpo/gestion_gpo.php`) |
| `legacy/modules/gpo/gpo-export.php` | 88 | Export GPO (zip téléchargé) |
| `legacy/modules/gpo/gpo-maj.php` | 193 | Mise à jour GPO (import zip) |
| `legacy/modules/gpo/network_out.php` | 54 | Endpoint legacy `/gpo/network_out.php` (script bash 802.1x) |
| `legacy/modules/gpo/veyon_out.php` | 141 | Endpoint legacy `/gpo/veyon_out.php` (config Veyon) |
| `legacy/modules/gpo/wine.php` | 79 | Page UI legacy gestion Wine |
| **Total** | **1366** | |

### 3.2 Callsites Laravel natif → fonctions shim

| Service Laravel natif | Ligne | Fonction shim appelée | Statut | Story qui remplace |
|---|---|---|---|---|
| `app/Gpo/Services/WpkgGpoSynchronizer.php` | 710-720 | `import_gpo($config, 'se4_wpkg', 'se4_wpkg.zip', $update, $force)` (via binding `legacy.import_gpo` ou `function_exists`) | **encore utilisé (actif)** | Story 16.6 enveloppe — pas de remplacement direct du shim `import_gpo` (encore appelé pour publier `se4_wpkg`). Sera retiré ou natif en 16.13/Phase 3 |
| `app/Services/RoamingProfileService.php` | 102, 122, 182, 184, 229, 235 | `read_gpo_sysvol`, `update_gpo_sysvol` (via `$this->requireFunction(...)` puis appel direct) | **encore utilisé (actif)** | Story 1bis.18f (déjà passée) garde l'usage shim pour roaming profiles. Pas de portage natif planifié — service stable. Retrait dans 16.13 implique réécriture native (= story Phase 3+) |
| `app/Gpo/Services/GpoService.php` | 327 | (commentaire docblock `gpodellink`) | mort-code | N/A (commentaire) |

### 3.3 Cartographie page legacy → story native qui remplace

> Croisement avec `audit-gpo-legacy.md` (Story 16.1, §6.A) :

| Fichier legacy | Story native | Statut |
|---|---|---|
| `gestion_gpo.php` (listing GPO) | Story 16.2 (`/app/gpo`) | ✅ Portage natif livré (status `review`) |
| `wine.php` (UI Wine) | Story 16.3c (`/app/gpo/wine`) | ✅ Portage natif livré (status `review`) |
| `network_out.php` | Story 16.3b — `NetworkScriptGenerator` + endpoint `NetworkOutController` | ✅ Portage natif livré (status `review`) |
| `veyon_out.php` | Story 16.3b — `VeyonConfigGenerator` + endpoint `VeyonOutController` | ✅ Portage natif livré (status `review`) |
| `associations_out.php` | Story 16.3c (associations Wine — module commun) | ✅ Portage natif livré (status `review`) |
| `applications.php` | Story 16.7 — `ApplicationScriptsGenerator` + endpoint `ApplicationsController` | ✅ Portage natif livré (status `review`) |
| `gpo-export.php` | (non porté) | ❌ Pas de story Phase 1 — usage UI marginal, à statuer Phase 3 |
| `gpo-maj.php` | Story 16.6 (`WpkgGpoSynchronizer::publish`) couvre `se4_wpkg` ; **autres GPO non couvertes** | 🟡 Partiel — 16.6 ne porte que `se4_wpkg` ; autres GPO (`se4_applications`, `se4_lecteurs_reseau`, `se4_impression`, …) restent via shim |

### 3.4 Plan retrait shims (alimente 16.13)

| Shim | Plan retrait | Bloqué sur |
|---|---|---|
| `legacy/gpo_shim.inc.php` (fonctions GPO `_shim_*`) | **À conserver Phase 2** | Bridge Kerberos `KRB5CCNAME` toujours requis tant que `RoamingProfileService` + `WpkgGpoSynchronizer` appellent les fonctions GPO. Retirable uniquement après portage natif de ces 2 services (Phase 3+). |
| `legacy/modules/gpo/gestion_gpo.php` | **Retirable maintenant** | Story 16.2 livrée. Catchall legacy redirige déjà `/gpo/gestion_gpo.php` → `/app/gpo`. Risque résiduel = aucun (skip-tests via Stash + portage natif). |
| `legacy/modules/gpo/wine.php` | **Retirable maintenant** | Story 16.3c livrée. Redirection en place. |
| `legacy/modules/gpo/network_out.php` | **Retirable maintenant** | Story 16.3b livrée. Endpoint natif `/gpo/network_out.php` servi par `NetworkOutController` (post-call legacy ou directement, à confirmer). |
| `legacy/modules/gpo/veyon_out.php` | **Retirable maintenant** | Story 16.3b livrée. |
| `legacy/modules/gpo/associations_out.php` | **Retirable maintenant** | Story 16.3c livrée. |
| `legacy/modules/gpo/applications.php` | **Retirable maintenant** | Story 16.7 livrée. |
| `legacy/modules/gpo/gpo-export.php` | **Non retirable** | Aucun portage natif planifié (Phase 3+). |
| `legacy/modules/gpo/gpo-maj.php` | **Non retirable** | Couverture native partielle (16.6 = `se4_wpkg` seulement). Autres GPO encore via shim. |

### 3.5 Conclusions Volet B

- **9 fichiers shim** + 1 fichier principal (`gpo_shim.inc.php`) = **10 unités à gérer**.
- **6 fichiers retirables maintenant** (16.13) : `gestion_gpo.php`, `wine.php`, `network_out.php`, `veyon_out.php`, `associations_out.php`, `applications.php`.
- **2 fichiers non retirables** (Phase 3+) : `gpo-export.php` (pas de portage), `gpo-maj.php` (couverture partielle).
- **1 shim fondation** (`gpo_shim.inc.php`) : à conserver en Phase 2/3 tant que `RoamingProfileService` + `WpkgGpoSynchronizer` consomment les fonctions GPO ; retrait conditionnel à un portage natif complet (Phase 3+).
- **2 services natifs Laravel** restent dépendants de fonctions shim (`RoamingProfileService` → `read_gpo_sysvol`/`update_gpo_sysvol`, `WpkgGpoSynchronizer` → `import_gpo`). **Hors-scope 16.13**.

---

## 4. Conclusions & recommandations

### 4.1 Récapitulatif chiffré

- **Volet A** : 50+ occurrences brutes recensées, **0 occurrence critique** (= bloquant 16.10). Toutes les URLs sont construites dynamiquement via `$config['se4fs_name']` ou `%SE4FS%` env var ou placeholder `###_SE4FS_NAME_###` substitué côté serveur. SYSVOL DC dev (192.168.122.60) accessible mais non spécialisée (14 GPO avec seulement leurs `GPT.INI` — audit prod à refaire sur DC déployé avec scripts peuplés).
- **Volet B** : 10 fichiers shim recensés, **6 retirables immédiatement** par 16.13 (pages legacy couvertes par portage natif Phase 1), **3 conditionnels** (1 shim fondation + 2 modules sans portage), **2 services natifs** dépendants à porter Phase 3+.

### 4.2 Recommandations court terme (avant 16.10)

1. ✅ **GO 16.10/16.9** : aucun blocage iso-legacy identifié dans le code source. La bascule HTTPS+JWT peut commencer.
2. ⚠️ **Audit SYSVOL prod** : avant déploiement 16.10 en production, refaire l'audit SYSVOL sur un serveur de prod déployé (le DC dev `192.168.122.60` est accessible mais ses GPO ne sont pas peuplées — c'est un env propre de dev). Recherche placeholders `###_SE4FS_NAME_###` non substitués → re-publier la GPO concernée via 16.6.
3. ⚠️ **Migration `%SE4FS%` postes Windows** : 16.11 doit garantir que tous les postes Windows ont leur env var `%SE4FS%` repositionnée à `se4fs-<UAI>` (= nom du serveur local). Sinon les postes non migrés appelleront le central historique qui ne portera pas les endpoints v1 HTTPS+JWT.

### 4.3 Recommandations moyen terme (16.10 / 16.13)

1. **16.10 — substitution `http://` → `https://`** : dans les 5 fichiers iPXE legacy (`legacy/modules/ipxe/Win10/{action,install.bat,repair.bat,unattend.xml}.php`) + `legacy/modules/display/config.php`, modifier les URLs construites via `$config['se4fs_name']`. **Risque mineur** : ces fichiers sont rendus côté serveur (templates PHP), pas appelés directement par les postes — la modification s'applique au prochain `import_gpo` / re-publication iPXE.

> **MAJ 2026-05-16 (Story 16.10 dev claude-opus-4-7[1m])** : la substitution
> `http://` → `https://` dans les 6 fichiers iPXE/display ci-dessus est
> **REPORTÉE à 16.13 / Phase 3** (décision Henri 2026-05-16). Motivation :
> ces fichiers sont des **templates legacy rendus côté serveur lors de
> `import_gpo`** ; modifier `http` → `https` en 16.10 alors que (a) les
> postes en mode legacy continuent d'appeler `http://` les endpoints
> `*_out.php` (D8 dual-mode), et (b) l'auto-bootstrap 16.11 n'est pas
> encore livré, casserait la phase de transition. La substitution
> effective sera faite en 16.13 (cleanup shims définitif) **après** que
> tous les postes du parc auront migré v1 via 16.11. **Action 16.10 = aucune
> modification de ces 6 fichiers** ; cette note documente le report.
2. **16.13 — retrait shims** : retirer **6 fichiers** legacy GPO sans risque (cf. tableau §3.4) → ~530 lignes de legacy supprimées.
3. **Phase 3+ — portage natif `RoamingProfileService` + `WpkgGpoSynchronizer`** : nécessaire pour retirer `gpo_shim.inc.php` complètement. **Hors-scope Phase 2**.

### 4.4 Reste à statuer (questions ouvertes)

- `gpo-export.php` (88 lignes) : doit-on le porter natif en Phase 3 ou l'abandonner ? Décision Henri requise.
- `gpo-maj.php` : couverture native pour les GPO autres que `se4_wpkg` ? Plan story dédié ? Décision Henri requise.
- LTSP `IP_SE4FS="172.16.1.11"` hardcodée : LTSP encore actif en prod ? Si oui, story dédiée pour paramétrer.

---

## 5. Annexe — commandes shell d'audit (reproductibles)

### 5.1 Volet A

```bash
# Code Laravel
grep -rnE 'SE4FS[^_-]|http://SE4FS|https://SE4FS|\bSE4FS/|//SE4FS\b' \
  --include="*.php" --include="*.blade.php" --include="*.js" \
  app/ resources/ routes/ config/

# Code legacy
grep -rnE 'SE4FS[^_-]|http://SE4FS|https://SE4FS|\bSE4FS/' legacy/

# Placeholders
grep -rnE '###_SE4FS_NAME_###|###_SE4FS_IP_###' \
  --include="*.php" --include="*.blade.php" --include="*.js" \
  app/ resources/ routes/ config/ legacy/

# Substitution dynamique
grep -rnE "se4fs_name" --include="*.php" app/ resources/ legacy/ config/

# SYSVOL via SMB (DC = autre VM/serveur, credentials .env)
ssh /vm '
  mkdir -p /tmp/sysvol-audit && cd /tmp/sysvol-audit &&
  smbclient //192.168.122.60/sysvol -U "Administrator%Azerty-1234" \
    -Tc sysvol.tar localdev.fr &&
  tar xf sysvol.tar &&
  grep -rnE "SE4FS[^_-]|###_SE4FS_NAME_###|###_SE4FS_IP_###" .
'

# Templates statiques
ssh /vm 'grep -rnE "SE4FS[^_-]" /etc/sambaedu /usr/share/sambaedu'
```

### 5.2 Volet B

```bash
# Inventaire shim
ls legacy/gpo_shim.inc.php legacy/modules/gpo/*.php
wc -l legacy/gpo_shim.inc.php legacy/modules/gpo/*.php

# Callsites Laravel
grep -rnE "gpolistcontainers|gpogetlink|gposetlink|gpodellink|\
sysvol_put|read_gpo_sysvol|update_gpo_sysvol|sysvol_acl_reset|\
_shim_gpo_|import_gpo|export_gpo|specialise_gpo|sambatool\(" \
  --include="*.php" app/ resources/
```

---

## 6. Références

- `_bmad-output/planning-artifacts/audit-gpo-legacy.md` (Story 16.1, 2026-05-11) — cartographie de référence (19 fichiers UI + 4 includes legacy).
- `_bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md` §5.0 (topologie), §6.1 (séquencement stories), §7 (risques), §8.1 (critère bascule).
- `_bmad-output/implementation-artifacts/16-8-stabilisation-phase1-tests-audit-legacy.md` — story 16.8 (livrable).
- `_bmad-output/implementation-artifacts/16-6-hook-gpo-invocation-wpkgjs-cote-client.md` — `WpkgGpoSynchronizer` (callsite `import_gpo`).
- `CLAUDE.md` projet — sync inotify VM, contraintes hôtage SSH `/vm`.
