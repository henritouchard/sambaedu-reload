---
type: spike
title: Ancrage Windows — GPO-dispatcher statique (gate MVP successeur GPO)
date: 2026-06-08
author: henri
architect: Winston
relates_to: product-brief-gpo-successor-2026-06-08.md
status: préalable lecture-code FAIT (serveur prêt) — reste le PoC client
---

# Spike — Ancrage Windows (gate MVP « successeur GPO »)

> Gate de la **Phase A** du brief `product-brief-gpo-successor-2026-06-08.md`
> (direction actée « A puis B »). Tant que ce spike n'est pas GO, on n'écrit
> pas de PRD ni de stories d'implémentation.

## 1. La question

> **Comment un poste Windows déclenche-t-il, sur chaque événement de cycle de vie,
> un appel à l'API SE5 et l'exécution du script renvoyé — sans GPO de configuration ?**

Précision qui rétrécit le spike : aujourd'hui sous Windows, c'est déjà un script
(déposé par la GPO dans SYSVOL) qui appelle l'endpoint. La GPO ne fait que
*déclencher*. On ne réinvente pas le flux `fetch → exécute` — on **fige le
déclencheur** et on déporte toute l'intelligence côté serveur.

## 2. Décision d'architecture : la GPO-dispatcher statique

Le poste Windows **garde des GPO**, mais des GPO **génériques, figées, jamais
ré-éditées**. Leur seul rôle : sur chaque événement, appeler l'endpoint SE5
correspondant, qui répond quoi faire (ou rien).

> « quand `shutdown` survient → appelle `/…/shutdown` ; il me dira quoi faire. »

Conséquences :

- plus de « GPO par fonction » → quelques GPO **par événement**, statiques ;
- le refnum ne touche **jamais** une GPO (plomberie invisible) ;
- plus de `Registry.pol` généré, plus de churn SYSVOL, plus de spécialisation ;
- couplage AD réduit à « la GPO existe et est liée », posé une fois ;
- convergence OS : GPO-dispatcher Windows = `cron @reboot`/`systemd`/`PAM` Linux —
  **deux déclencheurs bêtes vers la même API** ;
- l'auth reste **iso-legacy** : le script envoie `machine` + `uuid` SMBIOS +
  `os=windows`, le serveur résout contre l'AD (handshake APCu `id` existant).
  Pas de secret par poste (cf. contrainte parc pas-à-jour).

Ce pattern **esquive l'inconnu effrayant** (« peut-on appliquer sans GPO ? ») :
on garde la GPO pour ce qu'elle fait bien (lancer un script sur un event), on
arrête de l'utiliser pour ce qu'elle fait mal (contenu de config, registre,
prolifération).

## 3. Matrice de déclencheurs

| Event | Porté par | Appelle | Contexte |
|---|---|---|---|
| startup | GPO script (Machine) | `/…/startup` | SYSTEM |
| logon | GPO script (User) | `/…/logon` | user |
| logoff | GPO script (User) | `/…/logoff` | user |
| shutdown | GPO script (Machine) | `/…/shutdown` | SYSTEM |
| **refresh périodique** | **tâche planifiée** (posée 1× par la GPO) | `/…/refresh` | SYSTEM (+user) |
| lock / unlock *(bonus)* | tâche planifiée (session state triggers) | `/…/lock`,`/…/unlock` | user |

Le **refresh périodique** est le remplaçant explicite et contrôlable du
*background refresh* GPO (~90-120 min), nécessaire pour le cas **« poste jamais
éteint, session jamais fermée »** : une GPO à scripts d'événements ne se
redéclenche pas toute seule, donc une tâche planifiée « toutes les N min →
`/…/refresh` » comble le trou. Elle peut être **installée par la GPO-dispatcher
elle-même** → tout reste de la plomberie statique.

## 4. Préalable (lecture de code) — FAIT le 2026-06-08

**Question : que renvoie réellement le serveur SE5 pour `os=windows` ?**
**Verdict : le serveur est déjà prêt côté Windows. Aucun correctif serveur requis
pour le PoC.**

Résultats :

- **Actions** : énum confirmée `startup`, `logon`, `logoff`, `shutdown`, `wpkg`,
  + variantes `-system`/`-server`/`-once`, préfixe `remote-`. Regex
  `/^((remote)-)?([a-z]*)(-(system|server|once))?$/U`
  (`ApplicationsScriptsController.php:59`). `logoff` filtre par contexte,
  `shutdown` non filtré (`ApplicationScriptsAssembler.php:183-189`, iso-legacy).
- **`applications.php` × os=windows** : produit un **cmd batch non vide** pour
  startup / logon / logoff (shutdown = minimal mais fonctionnel). Fixtures de
  référence existantes : `tests/Fixtures/Gpo/applications/windows_*/expected.cmd`
  (ex. `windows_startup_firewall` = 245 lignes).
- **Collapse déjà faite** : le script `startup` Windows **agrège déjà ~toutes
  les fonctions** (associations, chrome, edge, firefox, firewall, folders, glpi,
  printers, rdp, shortcuts, thunderbird, veyon, wallpaper, winget, wpkg) — la
  prolifération « une GPO par fonction » est donc déjà ramenée à un seul script
  serveur pour tout ce qui transite par `applications.php`.
- **Sous-endpoints Windows** : firefox / thunderbird / wallpaper / veyon /
  shortcuts / associations = **peuplés pour Windows**. Seul `network_out` renvoie
  vide pour Windows, **intentionnellement** (`NetworkOutController.php:58` —
  proxy/802.1x Linux-only ; le réseau Windows = registre natif = Phase B+).
  Pas un blocage.
- **Auth/identité OS-agnostique** : résolution machine/user AD + handshake
  `apps.$id` APCu (TTL 1800s) **identiques** Windows/Linux, aucune branche OS à
  ajouter (`ApplicationScriptsGenerator.php:67-258`).
- **Format Windows** : cmd batch en **cp1252** (`ApplicationsScriptsController.php:384`),
  qui bootstrappe un **wrapper PowerShell** téléchargé au runtime
  (`?interpreter=powershell`) et détecte SPEED/UUID/DOMAINSID via PowerShell.

**Conséquence :** le risque serveur s'effondre. Le PoC se concentre entièrement
sur le **déclencheur (GPO-dispatcher)** et l'**opérationnel** (fetch+exécute,
contextes de privilèges, refresh) — voir §5. `startup/windows` est l'action la
plus riche → cible idéale du PoC, **sans aucune modification serveur**.

## 4ter. Inventaire GPO réelles (VM `localdev.fr`, 2026-06-08)

`samba-tool gpo listall` sur la VM (se4fs). 13 GPO fonctionnelles SambaEdu
(hors 2 GPO système standard v0) :

| GPO | Version | Catégorie | Note |
|---|---:|---|---|
| applications | 2 228 426 | **dispatcher** (`se4_applications`) | le tuyau cible |
| wpkg | 1 638 564 | moteur déclaratif (`se4_wpkg`) | Phase B+ |
| proxy | 23 330 816 | réseau/proxy (registre) | churn extrême |
| redirections | 11 599 900 | redirection dossiers / itinérants | churn extrême |
| lecteurs reseau | 6 160 384 | lecteurs mappés (GPP) | churn extrême |
| sysprep | 2 162 713 | config sysprep | churn élevé |
| Bureau | 917 520 | bureau (registre) | contenu |
| Wallpaper | 786 443 | fond d'écran | contenu |
| veille_off | 589 837 | veille off (registre) | contenu |
| imprimantes | 262 145 | imprimantes (GPP, natif Windows) | Phase B+ |
| optimisations | 117 | tweaks registre | quasi figée |
| WOL | 39 | wake-on-lan | quasi figée |
| acces distant | 20 | RDP/accès distant | quasi figée |

Lecture : la **version** = nb de republications. `proxy`/`redirections`/
`lecteurs reseau` (millions) sont **re-spécialisées en permanence** = le « churn
SYSVOL pour rien » dénoncé dans le brief. `optimisations`/`WOL`/`acces distant`
(dizaines) sont déjà des GPO **statiques jamais retouchées**.

Constat de cadrage : le **dispatcher existe** (`applications`), mais **~11 GPO de
contenu par fonction** cohabitent — c'est l'objet du décommissionnement
(Phase B), pas le tuyau.

**Liens (résolu 2026-06-08, `samba-tool gpo listcontainers`)** : les **13 GPO
fonctionnelles sont liées à la racine du domaine** (`DC=localdev,DC=fr`) →
toutes **actives**, appliquées à tous les postes par héritage. **Aucune
vestigiale.** Donc le décommissionnement = **migrer l'effet de chacune des 11
fonctions** (vers le dispatcher si impératif, sinon natif Phase B+), pas
supprimer du poids mort.

Observation : liaison à la **racine du domaine**, pas par OU → sur cette VM la
différenciation salle/parc ne passe **pas** par la liaison GPO↔OU mais par la
résolution serveur (`applications.php`). Nuance le grief « dichotomie
physique/logique imposée par GPO » (à reconfirmer en prod).

**Reste ouvert (nécessite SYSVOL — accès kerberos pénible, défère Phase B)** :
le **type de contenu** exact de chacune des 11 (Registry.pol / GPP / scripts)
→ décide pour chacune : absorbable par le dispatcher (impératif) vs natif
Phase B+ (registre complexe, imprimantes GPP, wpkg déclaratif).

## 5. PoC (sur le poste Windows de la VM)

Le PoC valide la chaîne complète avec ce dont Henri dispose (poste Windows VM
joint au domaine ; pas de parc déployé legacy → vecteur « parc existant » non
testable, mais la GPO liée à l'OU couvre justement ce cas par construction).

0. **Partir de l'existant — INSPECTION FAITE (2026-06-08)** : la GPO
   `se4_applications` **EST déjà le dispatcher statique**. Constat sur le template
   (`tests/Fixtures/Gpo/se4_applications_template/`) :
   - **4 events présents** : `Machine/Scripts/Startup`, `User/Scripts/Logon`,
     `User/Scripts/Logoff`, `Machine/Scripts/Shutdown`.
   - chaque script = `curl.exe -F os=windows -F action=<event> -F user=%username%
     -F machine=%computername% http://%SE4FS%.<domain>/gpo/applications.php` →
     `call` du script téléchargé.
   - **générique/statique** : seule spécialisation = `###_SE4FS_NAME_###` /
     `###_DOMAIN_###` (nom serveur, figé 1× à la publication). Machine/user
     résolus au **runtime** (env vars) → même script pour tous les postes.
   - auth iso-legacy (machine+user, résolution AD serveur ; uuid ajouté par le
     script interne, pas le trigger).
   - **Écart avec la cible = uniquement le `refresh` périodique** (aucune tâche
     planifiée dans le template ; que des scripts d'event).
   - NB : ceci couvre le **canal applications** uniquement ; `se4_wpkg` et GPO de
     contenu natif (registre/imprimantes) restent séparées → Phase B+.
   - ⚠️ à confirmer sur matériel réel : le template fixture (libellé story 17.3)
     reflète-t-il le `.zip` réellement déployé ? (le vrai template vit sur le
     serveur/VM, hors git).
1. Poser **une GPO de test** générique (scripts Startup/Logon/Logoff/Shutdown)
   qui appellent les endpoints SE5 avec `machine`+`uuid`+`os=windows`, via
   `curl.exe` (livré Windows 10+) ou `Invoke-WebRequest` — **zéro dépendance**.
   (cp1252 + bootstrap PowerShell `?interpreter=powershell` à prévoir.)
2. Vérifier le **handshake iso-legacy** (réponse serveur identique au client Linux).
3. Exécuter le `cmd`/`ps` renvoyé et appliquer **une capacité couverte par Linux**
   (le plus simple : exécution d'un script ; sinon le proxy).
4. Vérifier les **contextes de privilèges** : SYSTEM au startup/shutdown,
   user au logon/logoff.
5. Faire **installer par la GPO** une **tâche planifiée de refresh** (schedule N min)
   et vérifier qu'elle rappelle `/…/refresh`.
6. **Auto-réparation** : le script de startup recrée la tâche refresh si absente.

## 6. Critères go / no-go

GO si, sur le poste de test :

1. la GPO-dispatcher statique déclenche les **4 events** (startup/logon/logoff/shutdown) ;
2. chaque appel est **authentifié iso-legacy** (machine+uuid, pas de secret) ;
3. le script renvoyé s'exécute et **applique au moins une capacité Linux-couverte** ;
4. les **contextes de privilèges** sont corrects ;
5. la **tâche de refresh périodique** fonctionne (cas « jamais éteint ») ;
6. la GPO n'a **jamais eu besoin d'être ré-éditée** pour tout ça (généricité confirmée).

→ GO = feu vert pour découper la Phase A en PRD/stories.

## 7. Sous-questions explicites à trancher dans le spike

- **logoff / shutdown** : confirmés portés nativement par les scripts GPO
  (ce que Task Scheduler ne fait pas proprement) — valider le contexte et le timing.
- **intervalle du refresh** périodique par défaut (ex. 30/60/90 min ?).
- **lock/unlock** : bonus à confirmer (session state triggers Task Scheduler).

## 8. Explicitement hors spike

- Capacités nativement Windows non couvertes par Linux : **registre complexe,
  WPKG avec dépendances/rollback, imprimantes** → chantier Phase B+. NB : `wpkg`
  est un **moteur déclaratif** (dépendances/versions/rollback) ≠ `applications`
  (scripts impératifs). Le canal unique unifie le **transport/déclenchement**,
  pas le modèle d'exécution — `wpkg` ne se réduit pas à un script. GPO séparées
  (`se4_applications` / `se4_wpkg`), non chevauchantes.
- **Modèle unifié versionné** (politiques propres, logs enrichis) → Phase B.
- **Suppression du chemin GPO de config legacy** → coexistence pendant la transition.
- Bootstrap « parc existant déjà déployé » par PsExec/WinRM → inutile (la GPO liée
  à l'OU le couvre) ; revalidation empirique quand postes déployés réels existeront.

## 9. Livrable & effort

Rapport de spike (verdict gate + matrice + intervalle refresh retenu) **+ PoC
fonctionnel sur le poste de test**. Effort : **jours, pas semaines**.
