---
type: spike
title: Affichage d'info sur le fond d'écran — outils open source (cuire vs overlay)
date: 2026-06-09
author: henri
status: étude (comparatif outils) — verdict de direction posé, gate Phase 2 client
relates_to:
  - project_wallpaper_library_native_overlay_direction (mémoire)
  - spike-windows-anchor-2026-06-08.md (même prérequis « agent client fiable »)
---

# Spike — Afficher de l'info sur le fond d'écran (Windows + Linux)

## 1. La question

> **Avec quel(s) outil(s) open source afficher sur le fond d'écran le nom/prénom
> de l'utilisateur et un message d'avertissement conditionnel, en rafraîchissant
> ces infos par poll — côté Windows ET Linux ?**

Besoin terrain confirmé (gestion de parc) : l'identité affichée est **nécessaire**,
pas du cosmétique. Le « message si info à transmettre » est **conditionnel et
réactif** — c'est lui qui oriente le choix.

**Contrainte ajoutée (2026-06-09)** : le poste — **neuf à l'installation OU déjà
installé/migré** — doit **installer automatiquement** le composant nécessaire
(Rainmeter / Conky). Pas d'action manuelle par poste. Voir §6bis.

## 2. L'existant (rappel, pour situer le delta)

Aujourd'hui l'info est **cuite côté serveur** dans le JPEG par `WallpaperComposer`
(Imagick) à chaque logon : bandeau identité + badges + cartouches d'alerte
(`app/Services/Wallpaper/WallpaperComposer.php`), servi par
`WallpaperController` (legacy `wallpaper_out.php` + API V1 JWT). Côté poste,
`wallpaper/logon.windows` télécharge le JPEG et le pose via `SetWallpaper.ps1` ;
`logon.linux` via `gsettings`/`dconf`. Griefs : texte **pixellisé** (« moche »),
re-cuisson à chaque changement, asset couplé par nom de fichier (fantômes).

La refonte (mémoire `project_wallpaper_library_native_overlay_direction`) sépare
**l'asset** (fond statique, bibliothèque `storage/`) de **l'info** (overlay).
Ce spike valide le « comment » de la partie info.

## 3. Deux familles d'outils — c'est la vraie ligne de partage

| | **Cuire dans le bitmap** | **Overlay vivant** |
|---|---|---|
| Windows | BGInfo, **PowerBGInfo** | **Rainmeter** |
| Linux | script ImageMagick + `feh`/`nitrogen` | **Conky** |
| Principe | re-génère l'image de fond et la repose | calque transparent **au-dessus** d'un fond statique |
| Poll | seulement en **relançant** l'outil (tâche planifiée / RMM) | **natif** (WebParser/JsonParser ; Lua `execi`) |
| Rendu texte | bitmap, **pixellisé** (= grief actuel) | **vectoriel net** |
| Alerte conditionnelle | re-cuisson complète à chaque MAJ | apparaît/masque en live |
| Déploiement | **un script** (léger, pas d'agent résident) | **un composant** + skin + autostart |
| Reproduit l'archi actuelle ? | oui (déporte le « cuire » serveur→client) | non (nouveau modèle) |

> Insight : « cuire côté client » (PowerBGInfo) ≠ refonte — c'est le **même modèle
> qu'aujourd'hui**, juste déplacé du serveur vers le poste. L'overlay est le
> changement de modèle que la refonte vise.

## 4. Fiche par outil

### BGInfo (Sysinternals) — **écarté**
Gratuit (usage commercial OK, déploiement illimité) **mais NON open source** :
EULA Sysinternals, sources retirées du catalogue, modification/redistribution
interdites. Contrainte « open source » non remplie → exclu malgré sa robustesse.

### PowerBGInfo (EvotecIT) — **candidat « cuire », MIT**
- Licence **MIT**, **sans installation** (module PowerShell pur), flexible.
- Built-ins (host, user, OS, CPU, RAM, disk) **+ valeurs custom** depuis
  PowerShell / WMI / registre / **AD / API / RMM** → **peut interroger l'endpoint
  JSON SE5** (`WallpaperController::apiV1`).
- **Cuit un bitmap** (point-in-time), force le refresh wallpaper pour contrer le
  cache Windows. **Pas d'overlay vivant** : poll = re-exécution via **tâche
  planifiée** (réutilise exactement le « refresh périodique » du spike
  GPO-dispatcher).
- Déploiement type : robocopy depuis NETLOGON + tâche planifiée.
- Limite : Windows-only (PowerShell), texte pixellisé, alerte fraîche seulement
  à l'intervalle de la tâche.

### Rainmeter — **candidat « overlay » Windows, GPLv2**
- **Open source GPLv2**, overlay pur (aucun hook kernel) → **léger au runtime**.
- **WebParser** (PCRE) + plugin **JsonParser.dll** : poll d'un endpoint JSON
  natif ; cadence réglable (`UpdateRate` × `UpdateDivider`).
- Texte vectoriel, alerte conditionnelle triviale (mesure → visibilité).
- « Lourd ? » → **non au runtime** ; le coût est **opérationnel** : composant à
  packager + skin `.ini` + autostart + version à maintenir par poste.

### Conky — **candidat « overlay » Linux, GPLv3**
- **Open source GPLv3**, « le Rainmeter de Linux » : overlay léger sur X
  (Wayland avec réserves).
- Config Lua : **poll HTTP/JSON** via `${execi N curl … | jq}` ou Lua natif.
- **Symétrie forte avec Rainmeter** (même modèle overlay+poll) → cohérent avec le
  thème « convergence OS » du projet (cf. spike GPO-dispatcher : deux
  déclencheurs bêtes vers la même API).

## 5. Lecture pour la décision

**L'overlay (Rainmeter + Conky) répond mieux au besoin exprimé** :
1. le **message d'avertissement conditionnel** y est natif et réactif (pas de
   re-cuisson) ;
2. le **poll** est natif des deux côtés ;
3. **rendu net** (résout le grief « moche ») ;
4. **symétrie Windows/Conky** propre (deux outils, même modèle, tous deux GPL,
   tous deux poll) — aligne wallpaper sur la philosophie « un tuyau, deux outils » ;
5. **fond = fichier statique** → supprime la composition Imagick serveur (objectif
   refonte).

**Mais l'overlay porte le même prérequis bloquant que le successeur GPO** : un
**agent/canal client fiable** pour déployer+autostarter le composant et le skin
(gate Phase 2). Tant qu'il n'existe pas, l'overlay n'est pas livrable.

**PowerBGInfo = pont de transition, pas la cible.** Il permet, **sans agent et
sans Imagick serveur**, de poser l'identité côté client en interrogeant déjà
l'endpoint JSON SE5 (réutilise la tâche « refresh » du dispatcher). Mais c'est le
modèle « cuire » que la refonte veut quitter (pixellisé, alerte non temps réel).
À considérer **uniquement si** on veut décommissionner l'Imagick serveur **avant**
d'avoir l'agent overlay.

**Cas sensible — « prise en main à distance » (veyon/consentement) :** garder la
reco mémoire = **pré-cuire une variante de fond dédiée** (signal fort, enjeu
consentement) **en plus** d'un éventuel overlay réactif. Ne pas confier ce signal
au seul overlay.

## 6. Direction proposée (à valider)

- **Cible** : info en **overlay** — **Rainmeter** (Windows) + **Conky** (Linux),
  tous deux poll de l'endpoint **JSON** SE5 ; fond d'écran = **fichier statique**
  servi (fin de la composition Imagick).
- **Prérequis / gate** : agent/canal client fiable (même gate que le PoC
  GPO-dispatcher — `spike-windows-anchor-2026-06-08.md`).
- **Pré-requis serveur déjà en route (Phase 1, sans impact poste)** : variante
  **JSON** de l'API context (`WallpaperController::apiV1`) qui expose
  nom/prénom + flag(s) d'alerte → c'est ce que l'overlay consommera.
- **Transition optionnelle** : PowerBGInfo (MIT) si on veut retirer l'Imagick
  serveur avant l'agent overlay.
- **Hors-overlay maintenu** : variante de fond pré-cuite pour l'alerte
  remote-control (consentement).

## 6bis. Auto-déploiement (la contrainte — et le « vrai poids » de l'overlay)

> Insight : cette contrainte **EST** le coût opérationnel de l'overlay signalé au
> §3 (« composant + skin + autostart à packager »). La bonne nouvelle : les
> **canaux existent déjà** (winget côté Win, apt/se4XP côté Linux), et le script
> `startup/windows` **agrège déjà winget** (cf. `spike-windows-anchor`, collapse
> startup). On ne crée pas de canal — on **déclare un paquet de plus** + une
> **auto-réparation idempotente**.

**Principe = install-if-absent au `startup` (auto-réparation), pas WinPE-only.**
Un poste **migré/déjà installé** n'a jamais reçu le robocopy WinPE
(cf. mémoire `project_migrated_poste_missing_client_helpers` : `%PROGRAMFILES%\SambaEdu`
vide) → l'install **ne peut pas** dépendre de WinPE. Elle doit être portée par le
**script `startup` (SYSTEM)** qui vérifie la présence et installe si absent — ce
qui couvre d'un coup les deux cas demandés (neuf **et** déjà installé). C'est le
même pattern d'auto-réparation que la tâche `refresh` du GPO-dispatcher.

**Découpage par contexte de privilège** (aligné sur la matrice de déclencheurs du
spike GPO-dispatcher) :

| Étape | Event | Contexte | Action |
|---|---|---|---|
| Installer le **binaire** | `startup` (boot machine) | SYSTEM | install-if-absent, machine-wide, idempotent |
| Déposer **skin + config** + lancer | `logon` (ouverture session) | user | Rainmeter charge le skin / Conky démarré pour la session |
| Maintenir à jour | `refresh` périodique | SYSTEM | re-vérifie présence/version (auto-réparation) |

> **Pas de réinstallation par session.** L'install est sur `startup` (boot machine),
> **pas** sur `logon` (chaque session), et elle est **gardée par un test de présence**
> (exe/clé registre/`winget list`) → no-op si déjà là. Le coût récurrent par session =
> **lancer un process déjà installé + lire un `.ini`**, pas relancer l'installeur.
> L'installeur ne tourne qu'au 1er boot manquant ou en auto-réparation (`refresh`).
> Seul point d'attention : un test de présence **fiable** (sinon faux négatif →
> réinstall inutile).

### Windows — Rainmeter

⚠️ **Piège winget en SYSTEM** : `winget` exécuté en SYSTEM **force le
machine-scope** et n'installe que les paquets au manifeste machine-scope ; or
l'installeur Rainmeter est **per-user (NSIS)** → `winget install Rainmeter.Rainmeter`
sous SYSTEM **peu fiable**.

→ **Chemin robuste = installeur NSIS silencieux** (l'installeur expose ses propres
flags, indépendants de winget) :
```
Rainmeter-x.y.z.exe /S /AUTOSTARTUP=1 /DESKTOPSHORTCUT=0
```
SE5 **héberge l'installeur** (cohérent avec les assets `/os` déjà servis) ; le
script `startup` le `curl` + exécute en SYSTEM (admin) si Rainmeter absent.
`/AUTOSTARTUP=1` pose le démarrage per-user ; pas de raccourci bureau.
`winget` reste une **option** quand le canal WPKG/winget sera dégaté
(`WPKG_WINGET_ENABLED`, cf. mémoire), mais pas le chemin de référence pour le
SYSTEM-startup.

### Linux — Conky

`conky-all` est dans Debian → **apt** depuis le miroir SE5 **se4XP**
(cf. mémoire prérequis install Linux), soit en **dépendance du paquet Debian
sambaedu** (installé une fois), soit install-if-absent dans `startup.linux`.
Autostart per-user via `~/.config/autostart/conky.desktop` + dépôt du `.conkyrc`/
skin par `logon.linux` (déjà le script qui pose le wallpaper Linux).

### Choix de canal : impératif vs déclaratif
- **Court terme** : auto-réparation **impérative** via `applications/startup`
  (existe, winget déjà câblé, pas de gate). Pragmatique, livrable avec l'agent.
- **Cible** : déclarer Rainmeter/Conky comme **paquet géré** (WPKG/winget côté
  Win, apt côté Linux) une fois le canal WPKG dégaté → « le poste doit avoir X »
  exprimé déclarativement, pas re-scripté. Aligne sur la distinction
  `applications` (impératif) vs `wpkg` (déclaratif) du projet.

## 6ter. Contrat JSON overlay (v1 — design Phase 1, serveur)

> Livrable **maintenant**, zéro impact poste : l'overlay (Rainmeter/Conky) et le
> futur agent client consommeront ce JSON. Ancré sur le code réel
> (`WallpaperContext`, dérivations de `WallpaperComposer`).

### Endpoint
`GET /api/v1/workstation-config/overlay` — **même auth que `apiV1`** (JWT
`workstation_uuid`, middleware `auth.v1.workstation`, résolveur
`WorkstationConfigContextResolver`). Renvoie le payload ci-dessous (pas une
image). Paramètres `user`/`userprofile`/`os` comme `apiV1`.

### Architecture : service `Overlay` (facade) + deux sources de signaux
> Décision 2026-06-09 : un service **`Overlay`** encapsule la production du
> payload de poll ET la gestion des signaux. La facade rend la source de chaque
> alerte invisible au client (et permet de changer d'outil overlay sans toucher
> au serveur).

```
Overlay (facade)
├── pollPayload(OverlayContext): OverlayPayload   // lu par GET /overlay
│     ├── identity        (depuis le contexte)
│     ├── alerts[] source=derived   (recalculés chaque poll : veyon/session/quota)
│     └── alerts[] source=posted    (signaux stockés, actifs pour ce poste/user)
└── postSignal(target, OverlaySignal): void        // appelé par les producteurs
      → persiste dans le store de signaux ; récupéré au(x) prochain(s) poll
```

- **`identity`** : qui/où (toujours présent).
- **signaux `derived`** : **dérivés système** (veyon, multi-session, quota) —
  éphémères, recalculés serveur à chaque poll. Existent déjà (dans le composer) →
  extraits dans `OverlaySignalBuilder`.
- **signaux `posted`** : **le système poste un message → stocké → récupéré au
  prochain poll**. Primitive de plomberie générique (push→pull). La future UI
  admin « infos à transmettre » n'est qu'**un producteur** qui appelle
  `Overlay::postSignal(...)`. Remplace l'ancien concept séparé `notices`.

### Contrat simplifié : un seul tableau `alerts` avec `source`
Plus de distinction `alerts`/`notices` côté client : **un tableau `alerts`**, chaque
entrée porte `source: "derived" | "posted"`. L'overlay affiche
`severity`/`title`/`text` sans se soucier de l'origine.

### Payload (exemple)
```json
{
  "schema": "se5.wallpaper-overlay/v1",
  "generated_at": "2026-06-09T14:32:00+02:00",
  "ttl_seconds": 60,
  "identity": {
    "fullname": "Marie Dupont",
    "login": "mdupont",
    "is_admin": false,
    "main_type": "Profs"
  },
  "machine": { "name": "SALLE201-PC03", "room": "Salle 201", "os": "windows" },
  "alerts": [
    { "id": "veyon", "source": "derived", "kind": "remote_control", "severity": "critical",
      "title": "Prise de contrôle à distance en cours", "text": "…" },
    { "id": "multi_session", "source": "derived", "kind": "session", "severity": "warning",
      "title": "Sessions multiples", "text": "Sessions détectées sur : PC07, PC12",
      "machines": ["PC07", "PC12"] },
    { "id": "quota", "source": "derived", "kind": "quota", "severity": "critical",
      "title": "Stockage saturé", "text": "…",
      "partitions": [{ "label": "home", "used_mb": 4800, "soft_mb": 5000, "grace_days": 3 }] },
    { "id": "sig-7f3a", "source": "posted", "kind": "notice", "severity": "info",
      "title": "Maintenance prévue",
      "text": "Sauvegardez vos travaux avant 18h le 12/06.",
      "expires_at": "2026-06-12T18:00:00+02:00" }
  ]
}
```

### Sémantique des champs
- `schema` : versionné (forward-compat ; l'overlay refuse un major inconnu).
- `ttl_seconds` : cadence de poll / péremption conseillée (aligner sur le
  `refresh` GPO-dispatcher). L'overlay re-poll à cet intervalle.
- `identity.fullname` : `userFullname` sinon fallback `userLogin` (logique du
  composer `drawBottomBanner`). `main_type` = `mainUserType`.
- `alerts[].severity` ∈ `info|warning|critical` → pilote le **style côté overlay**
  (couleur/visibilité), **pas** cuit serveur (rendu net). Mappe les couleurs
  actuelles : veyon `critical` (rouge), multi-session `warning` (orange),
  quota soft `warning` / hard `critical`.
- `alerts[].kind` : `remote_control|session|quota` (typed, stable).
- `notices[]` : même forme que `alerts` mais **source admin** ; `expires_at`
  optionnel (auto-masquage).
- Tableaux **vides** = rien à afficher → l'overlay ne montre que `identity`.

### Contraintes de fidélité (NON négociables)
- **Submarine veyon** : si `config('sambaedu.veyon_submarine')` → **omettre**
  totalement l'alerte `veyon` du JSON (ne jamais trahir une prise en main
  discrète — iso-logique `WallpaperComposer::isVeyonActive`).
- **Consentement remote-control** : l'alerte overlay `veyon` **complète** mais ne
  **remplace pas** la variante de fond pré-cuite (signal fort) — cf. §6 et mémoire.
- **Parsabilité double** : clés stables, structure plate, tableaux d'objets →
  lisible par Rainmeter JsonParser (`[alerts][0][title]`) **et** Conky/jq
  (`.alerts[0].title`).
- **Auth iso-legacy** : aucun secret par poste ; réutilise le JWT workstation
  existant.

### Refactor serveur impliqué (sinon divergence image↔JSON)
La dérivation veyon/multi-session/quota vit aujourd'hui **dans**
`WallpaperComposer` (`isVeyonActive`, `detectMultiSessions`,
`collectBadges`, quota). Pour servir le JSON **sans diverger** de l'image cuite,
**extraire** cette dérivation dans un service partagé (p.ex.
`OverlaySignalBuilder`) consommé par : (a) le nouvel endpoint JSON, (b) le
composer legacy. Refactor pur, testable, Phase 1.

## 6quater. Phase A POC — LIVRÉE 2026-06-09 (serveur)

Implémenté sur `main` (quick-dev), testé sur VM (17/17 verts, 47 assertions) +
smoke live (`postSignal → pollPayload` contre PG réel, poste `windaubecdi`/`cdi`) :

- `GET /api/v1/workstation-config/overlay` (`OverlayController`, auth JWT
  workstation iso `apiV1`, 404 inconnu) — route `agent.v1.config.overlay`.
- Facade `App\Services\Overlay\OverlayService` : `pollPayload()` (merge
  derived+posted) + `postSignal()` (garde-fou submarine au post ET au poll).
- `OverlaySignalBuilder` : dérivés **multi-session + quota** (veyon = canal posté,
  non dérivable serveur).
- DTO `OverlayPayload` / `OverlayAlert` (un tableau `alerts`, `source=derived|posted`,
  toArray plat parsable Rainmeter+jq).
- Store `overlay_signals` (migration + modèle, scope `activeFor` ciblage joker +
  expiration) — persiste jusqu'à `expires_at`.
- `config/overlay.php` (`schema`, `ttl_seconds`). Auto-wiring container (pas de
  provider). Composer **intact** (duplication assumée).
- Tests : `tests/Feature/Api/V1/Config/OverlayApiV1Test.php`,
  `tests/Unit/Services/Overlay/OverlaySignalBuilderTest.php`.

**Limite connue** : `toWallpaperContext` ne fournit pas le fullname AD ni l'admin
(16.13) → `identity.fullname` = login pour l'instant (enrichissement AD = future
story). Non committé (en attente revue Henri).

## 6quinquies. Phase B POC — adaptateurs client (2026-06-09)

Artefacts sous `resources/overlay/` (authored server-side, non testés sur poste
GUI ; JSON/jq/regex/syntaxe validés sur hôte) :

- **Facade client = fichier local `overlay.json`** (modèle « agent écrit local,
  skin lit local »). Deux adaptateurs de part et d'autre :
  - **fetch** (`fetch/overlay-fetch.{ps1,sh}`) : poll authentifié (JWT
    workstation) → écriture atomique du fichier. Seul porteur de l'auth.
  - **render** (`rainmeter/SambaEduOverlay.ini`, `conky/sambaedu-overlay.conkyrc`) :
    lit le fichier, affiche identité + machine + alertes. Aucun secret.
- **Swap d'outil = nouvel adaptateur render uniquement.** Le JWT n'entre jamais
  dans une config Rainmeter/Conky.
- Conky : liste **complète** des alertes (`jq`). Rainmeter (regex WebParser, zéro
  dépendance) : identité + machine + **1re** alerte ; liste complète = variante
  JsonParser.dll (notée).
- Caveats : non testé sur poste réel ; câblage du store JWT = TODO en tête des
  `fetch` ; install/autostart de l'outil = spike §6bis.

## 7. À trancher / prochaines étapes

- [ ] Valider la cible overlay (Rainmeter+Conky) vs pont PowerBGInfo.
- [x] Définir le **contrat JSON** côté info → **v1 draftée en §6ter**
      (identity + alerts dérivées + notices admin). Reste : valider le draft,
      modéliser le stockage des `notices` admin (table + UI), implémenter
      `OverlaySignalBuilder` + l'endpoint.
- [ ] Cadence de poll par défaut de l'overlay (aligner sur l'intervalle
      « refresh » retenu côté GPO-dispatcher).
- [ ] PoC overlay : un skin Rainmeter + un Conky minimal lisant le même JSON
      (nom/prénom + 1 alerte conditionnelle).
- [ ] Stratégie autostart/packaging du composant (dépend du canal `applications`).
- [ ] **Auto-déploiement** : héberger l'installeur Rainmeter sur SE5 + valider
      `/S /AUTOSTARTUP=1 /DESKTOPSHORTCUT=0` en SYSTEM-startup (vs winget dégaté).
- [ ] **Linux** : trancher `conky-all` en dépendance du paquet sambaedu vs
      install-if-absent dans `startup.linux` (miroir se4XP).
- [ ] Test auto-réparation sur **poste migré** (`%PROGRAMFILES%\SambaEdu` vide) :
      l'install ne doit pas dépendre du robocopy WinPE.

## 8. Sources

- PowerBGInfo (MIT) — https://github.com/EvotecIT/PowerBGInfo · https://evotec.xyz/powershell-modules/powerbginfo-powershell-module/
- BGInfo (non OSS, EULA Sysinternals) — https://learn.microsoft.com/en-us/sysinternals/downloads/bginfo
- Rainmeter (GPLv2) WebParser/JSON — https://docs.rainmeter.net/manual/measures/webparser/ · https://github.com/e2e8/rainmeter-jsonparser
- Conky (GPLv3) — https://github.com/brndnmtthws/conky · https://www.xda-developers.com/conky-guide/
- Rainmeter install silencieux (flags `/S /AUTOSTARTUP /DESKTOPSHORTCUT`) — https://docs.rainmeter.net/manual/installing-rainmeter/
- winget en SYSTEM force machine-scope — https://docs.level.io/en/articles/10573892-winget-troubleshooting
- Conky Debian `conky-all` + autostart `.desktop` — https://help.ubuntu.com/community/SettingUpConky · https://packages.debian.org/bullseye/all/conky/download
