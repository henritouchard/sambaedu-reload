# Overlay poste — adaptateurs client (POC)

Artefacts client du POC « successeur du bandeau wallpaper » : afficher
l'identité utilisateur + des alertes en **surcouche** d'un fond d'écran statique,
au lieu du bandeau cuit dans le JPEG par `WallpaperComposer`.

> Contexte / décisions : `_bmad-output/planning-artifacts/spike-wallpaper-overlay-tools-2026-06-09.md`
> et mémoire `project_overlay_poc_phase_a`.

> ⚠️ **Windows : le fetch POC est DÉPRÉCIÉ depuis la Story 24.4.** L'agent
> desired-state EST le fetch : le compagnon de session (handler `overlay`)
> compose et écrit `overlay.json` **per-user** sous
> `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` — `fetch/overlay-fetch.ps1`
> ne doit plus être déployé sur un poste où l'agent tourne (la skin
> `rainmeter/` pointe désormais sur le fichier per-user ; son code
> regex/meters est inchangé). **Linux (Conky + `overlay-fetch.sh`) :
> intouché** — le POC reste le chemin en place jusqu'à l'agent Linux.
> Vue serveur : `docs/agent/handlers-wallpaper-overlay.md`.

## La facade : un fichier local `overlay.json`

La frontière neutre côté client est un **fichier JSON local**. Deux adaptateurs
indépendants, de part et d'autre de cette frontière :

```
 ┌─────────────┐   poll authentifié    ┌──────────────┐   lecture fichier   ┌──────────────┐
 │  Endpoint    │  ◀───── JWT ──────    │  fetch        │  ───── écrit ────▶  │  render       │
 │  SE5 /overlay│   ──── JSON ─────▶    │  (ps1 / sh)   │   overlay.json      │ (Rainmeter/   │
 └─────────────┘                        └──────────────┘                     │   Conky)      │
                                                                              └──────────────┘
```

- **fetch** (`fetch/overlay-fetch.{ps1,sh}`) : poll de l'endpoint
  `GET /api/v1/workstation-config/overlay`, porte le **JWT workstation**, écrit
  `overlay.json` localement. C'est le seul composant qui connaît l'auth.
- **render** (`rainmeter/`, `conky/`) : lit `overlay.json` et l'affiche. **Aucun
  secret** dans la config de l'outil.

**Conséquence (= but de la facade)** : changer d'outil overlay = écrire **un
nouvel adaptateur render**. Le serveur, le contrat JSON et le fetch ne bougent
pas. Le JWT n'entre jamais dans une config Rainmeter/Conky.

C'est aussi le modèle « l'agent écrit des valeurs locales, la skin lit en local »
prévu pour la Phase 2 (cf. mémoire) : le `fetch` ici est le stub de ce futur
agent.

## Emplacement du fichier local

| OS | Chemin `overlay.json` |
|---|---|
| Windows (POC, déprécié) | `%PROGRAMDATA%\SambaEdu\overlay.json` |
| Windows (agent 24.4) | `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` (per-user, écrit par le compagnon) |
| Windows (agent 27.1bis) | `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` (per-user, **écrit par le SERVICE SYSTEM au logon**, possédé SYSTEM + ACL `<SID>:R` — infalsifiable) |
| Linux | `/run/sambaedu/overlay.json` (tmpfs, recréé au boot) |

Le `fetch` écrit ; le `render` lit. Sur Linux le fichier doit être lisible par la
session user (Conky tourne en user).

## Cadence

Le `fetch` re-poll toutes les `ttl_seconds` (champ du payload, défaut 60). Le
`render` relit le fichier à un intervalle propre (≤ ttl, p.ex. 5–15 s) pour
réagir vite à un changement déjà écrit localement.

## Contrat consommé

Voir `overlay.sample.json`. Le render n'utilise que :
`identity.fullname`, `machine.name`, `machine.room`, et le tableau `alerts[]`
(`severity` → couleur, `title`, `text`). `severity ∈ {info, warning, critical}`.

## Déploiement (cible — hors POC)

1. **Installer l'outil** (auto-réparation `startup` SYSTEM, install-if-absent) :
   - Windows : installeur Rainmeter NSIS `Rainmeter-x.y.z.exe /S /AUTOSTARTUP=1 /DESKTOPSHORTCUT=0`
     (PAS `winget` en SYSTEM — force le machine-scope, peu fiable).
   - Linux : `apt install conky-all` depuis le miroir se4XP (ou dépendance du
     paquet Debian sambaedu).
2. **Déposer** l'adaptateur render + le `fetch` ; planifier le `fetch`
   (tâche planifiée Win / timer systemd ou autostart Linux).
3. **Autostart** du render pour la session (Rainmeter `/AUTOSTARTUP=1` ;
   Conky `~/.config/autostart/`).

> Détail install/auto-réparation : spike §6bis.

## Modèle d'auth / sécurité (à connaître)

- Le `workstation_uuid` est **authentifié** (claim JWT). En revanche le param
  `user` est un **indice NON authentifié** (iso-legacy, identique à
  `WallpaperController::apiV1`). Conséquence : les données *user-scoped* (quota,
  multi-session, signaux postés ciblant un user) ne sont protégées que par cet
  indice — un poste authentifié qui se déclare `?user=X` reçoit les infos de X.
  C'est le modèle d'auth assumé du parc (pas de secret par poste). Durcissement
  futur = lier le login au JWT (review finding D).
- Le **JWT ne vit que dans le `fetch`** (header HTTP). Les configs render ne
  contiennent aucun secret, et le `fetch` ne logge pas le token.

## Rendu VERROUILLÉ (Story 27.1bis)

À partir de 27.1bis, l'agent ne se contente plus d'**écrire la donnée** : il
gère le **cycle de vie du rendu** et le **durcit**. Trois changements par rapport
au POC ci-dessus :

1. **Provisioning portable par l'agent** (plus d'install NSIS manuelle). Rainmeter
   est extrait en mode **PORTABLE** (zéro registre) sous
   `C:\ProgramData\SambaEdu\Rainmeter\app\`, **posé par le SERVICE SYSTEM au
   bootstrap** (download via la route dédiée `GET /api/v1/agent/tools/<filename>`,
   **SHA-256 vérifié AVANT extraction**, install-if-absent idempotent). **Jamais**
   par un handler runtime (« handler jamais installeur »), **jamais** MSI/NSIS/
   winget. *(Depuis 25.6, l'artefact et son SHA-256 viennent du catalogue serveur
   `agent_tools` via le manifest — voir la section « Catalogue de tools » plus bas ;
   tool absent/désactivé → provisioning inerte, Rainmeter absent reste gracieux.)*

2. **`overlay.json` écrit par le SERVICE SYSTEM au logon** (session-change WTS,
   `WTS_SESSION_LOGON`), **possédé SYSTEM avec ACL `<SID>:R`** : l'élève **lit**,
   ne **falsifie jamais** la donnée affichée (NFR5). Le chemin per-user
   `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` est conservé (le `JsonPath` de la
   skin ne change pas). **Écriture événementielle au logon uniquement** (Q1 =
   logon-only : les alertes live ne sont pas rafraîchies en cours de session —
   assumé). L'overlay a quitté la map du compagnon (D1). La composition
   (`ComposeOverlayDocument`) est réutilisée à l'identique (format byte-compatible
   inchangé).

3. **Verrouillage du rendu**. La skin est posée en **UTF-16 LE + BOM** (conversion
   à la pose depuis la source UTF-8 du repo — sinon mojibake `Â·`). Un
   **`Rainmeter.ini` durci** (`TrayIcon=0`, et sur la section d'instance de la skin
   `Draggable=0` / `ClickThrough=1` / `KeepOnScreen=1` + position épinglée) est posé
   sous `C:\ProgramData\SambaEdu\Rainmeter\` en **ACL Users:R, SYSTEM/Admins full**
   (la skin seule ne suffit pas à verrouiller — c'est le `Rainmeter.ini` sous ACL
   qui le fait). Un **watchdog** côté compagnon (droits user) relance
   `Rainmeter.exe` s'il disparaît (idempotent, borné, meurt au logoff). **Pas
   d'obfuscation de process** (D7) : l'élève voit/tue son `Rainmeter.exe` → le
   watchdog répond.

> La skin canonique reste `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini`
> (UTF-8) — l'**autorité**. Depuis 25.6 elle n'est plus embarquée : voir la
> section suivante.

## Catalogue de tools — skin servie + portable uploadé (Story 25.6)

25.6 fait du **portable Rainmeter ET de la skin** des **assets gérés côté
serveur**, toggleables depuis l'UI — là où 27.1bis les livrait en bouche-trou
(portable déposé à la main + hash figé dans le binaire ; skin embarquée
`go:embed`).

- **Skin SERVIE (l'embed est retiré, D1)**. La skin canonique
  `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini` (UTF-8, toujours
  l'autorité) est **provisionnée** sous
  `storage/assets/overlay/rainmeter/SambaEduOverlay.ini` (`OverlaySkinProvisioner`,
  copie idempotente, `chown www-admin`) et **servie** par la **route agent
  authentifiée** `GET /api/v1/agent/overlay-skin` (token'd, PAS d'alias public).
  L'agent la **télécharge** (vérif SHA-256 avant écriture) puis la convertit
  UTF-16 LE + BOM à la pose (logique 27.1bis inchangée). Le `go:embed`
  (`agent/shared/rainmeter_embed.go`, `embedded/`, son test) est **SUPPRIMÉ** :
  retoucher la skin canonique ne nécessite **plus de recompiler l'agent**.

- **Portable uploadé + toggle (catalogue `agent_tools`, D2/D5)**. L'admin
  **importe** le `.zip` portable depuis `parc-settings/agent/` (section « Outils
  du parc ») : le serveur valide (extension/MIME/taille, **structure ZIP**
  `Rainmeter.exe` + `Skins/`), **calcule le SHA-256** (jamais un hash client),
  range le fichier sous `storage/agent/tools/`. Un **toggle** active/désactive le
  déploiement (global — D3). Le checksum du portable vient désormais de l'**état
  servi** (manifest `GET /api/v1/agent/tools-manifest`), plus de la constante Go
  `RainmeterToolChecksum` (retirée). Désactivé → no-op côté agent, **sans
  désinstaller** (D4).

- **Golden overlay INTOUCHÉ** : le manifest tool/skin est un endpoint **dédié**
  (pas un item desired-state) ; `ComposeOverlayDocument` et le rendu verrouillé
  (ACL/watchdog/overlay.json SYSTEM) sont **réutilisés tels quels**.

## ⚠️ Caveats POC

- **Non testé sur poste réel** (rendu GUI Rainmeter/Conky non validé ici).
- **Câblage JWT à faire** : les `fetch` lisent le token depuis un chemin
  paramétrable en tête de script (`TODO`) — à brancher sur le store réel du
  token workstation (enroll/refresh 16.10).
- **Rainmeter = regex WebParser** (zéro dépendance) : fragile si un champ
  `title`/`text` contient un **guillemet `"`** ou si l'ordre des clés du contrat
  change. Atténué côté serveur (`OverlayService::sanitizeText` aplatit les
  retours-ligne), mais pour la **prod, préférer `JsonParser.dll`** (gère
  l'escaping/nesting). Conky (jq = vrai parseur JSON) n'est pas concerné.
- **Purge des signaux expirés** non incluse (le poll les filtre via `expires_at`
  mais ne les supprime pas) : prévoir un job de purge avant prod (review
  finding I).
