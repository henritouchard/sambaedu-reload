# Overlay poste — adaptateurs client (POC)

Artefacts client du POC « successeur du bandeau wallpaper » : afficher
l'identité utilisateur + des alertes en **surcouche** d'un fond d'écran statique,
au lieu du bandeau cuit dans le JPEG par `WallpaperComposer`.

> Contexte / décisions : `_bmad-output/planning-artifacts/spike-wallpaper-overlay-tools-2026-06-09.md`
> et mémoire `project_overlay_poc_phase_a`.

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
| Windows | `%PROGRAMDATA%\SambaEdu\overlay.json` |
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
