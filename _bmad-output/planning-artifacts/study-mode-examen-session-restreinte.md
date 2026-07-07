# Étude — Mode examen : session élève restreinte

> Statut : étude d'intégration (pré-epic). Décisions structurantes tranchées avec le user (§7).
> Voisins : `epics-capacites-v2.md`, `epics-mecanismes-hors-registre.md`, `epics-agent-desired-state.md`.

## 1. Besoin

Un administrateur peut basculer un contexte en **mode examen** : à la prochaine session, l'élève
se connecte dans un environnement **« presque rien »** — pas d'accès internet, seules quelques
applications autorisées, le reste indisponible. Le mode est déterminé par un **profil d'examen
prédéfini** que l'admin choisit et applique.

Formulation retenue : on ne « bloque » pas, on **définit positivement** ce qui est accessible
pendant l'examen (liste blanche d'apps + internet coupé). Constructif plutôt que soustractif.

## 2. Où ça se branche — rien de neuf côté moteur

Le mode examen n'est pas un sous-système : c'est **un état effectif très restrictif, à haute
priorité, appliqué au logon suivant**. Il réutilise le triptyque existant :

- **Capacités (Epic 27.3 / capacités v2)** — internet coupé, apps whitelistées, durcissements
  sont des capacités typées, jamais des scripts.
- **Agent desired-state** — l'agent applique l'état cible au logon et reporte.
- **Précédence (`StateCompiler::specificity()`)** — un profil examen doit **gagner** ; c'est le
  cran le plus verrouillant assigné à la maille adéquate.

## 3. Granularité — SALLE (parc physique), pas l'élève

Le mécanisme dicte la granularité. La restriction phare, « pas d'internet », passe par la seule
capacité de coupure existante `internet_access` (mécanisme **`firewall`**, seed
`2026_07_04_120000_seed_capability_internet_access.php`), qui est **à portée MACHINE** (service
SYSTEM, une règle pare-feu par poste ; `FirewallCapabilityProvider::scope() = Machine`). Un flag
posé sur l'**élève** ne couperait pas le web — la coupure suit la **machine**.

Pourquoi machine et pas user : la règle pare-feu vit dans le **store global de la machine**,
persistant, partagé par toutes les sessions — contrairement au registre HKCU qui vit dans le hive
utilisateur (chargé/déchargé automatiquement au logon/logoff). Faire du per-user firewall
imposerait un **cycle de vie transitoire** (poser au logon / retirer au logoff) que le modèle
desired-state convergent n'a pas — et fragile pour un examen (fuite sur l'élève suivant si le
retrait rate, fenêtre internet si la pose course avec le logon). Une règle machine « bête et
méchante » sur la salle est au contraire **robuste** : verrouillée toute la fenêtre d'examen,
sans course ni fuite.

→ **Le profil examen s'assigne au parc physique = salle d'examen.** Qui s'y connecte hérite de la
session bridée. C'est le scénario réel : les élèves composent dans une salle donnée.

### 3.1 Exception poste enseignant — override par précédence

Le classement de spécificité (`StateCompiler.php:402-414`, le plus bas gagne) :

```
Upstream(locked) -1 > User 0 > UserGroup 1 > Poste 2 > ParcLogique 3 > ParcPhysique 4 > Broadcast 5 > UpstreamPermissive 6
```

**Parc logique (3) bat parc physique (4)** — inversion volontaire (Story 27.3 / D-Q3). Donc salle
physique `internet_access=off` vs groupe logique enseignant `internet_access=on` : **l'enseignant
gagne, il garde internet.** Usage natif, zéro dev.

⚠️ **Condition opérationnelle** : la résolution est **par capacité**. Un groupe logique enseignant
qui existe mais reste **muet** sur `internet_access` n'émet aucun candidat → c'est le `off` de la
salle qui s'applique **à l'enseignant aussi**. Le groupe enseignant doit donc **explicitement**
porter `internet_access=on`.

### 3.2 Intégrité — le `off` salle n'est pas verrouillé

Aucun verrou LOCAL n'existe : le seul « imbattable » (rang -1) vient d'un contrat amont controlHub
(hors sujet ici). Le `off` posé sur la salle (rang 4) est donc battable par tout ce qui est plus
spécifique (parc logique, override poste, groupe user, user). L'intégrité repose sur l'**hypothèse**
qu'aucun poste élève de la salle ne porte, par ailleurs, un `internet_access=on` plus spécifique.
Vrai en pratique, non *garanti* par le modèle.

Voie de durcissement (V2 si besoin) : poser le `off` examen sur un **groupe logique** « examen »
(rang 3) et l'exception enseignant sur un **override poste** (rang 2). Le `off` ne cède alors plus
qu'au niveau poste/user. Coûte la gestion d'un groupe logique dédié → hors V1.

## 4. « Presque rien » applicatif — la seule vraie brique neuve

### 4.1 Disponible aujourd'hui
- **Mécanisme `registry_list` autoritatif** (`AbstractRegistryListCapabilityProvider` +
  `agent/shared/handler_registry_list.go`) : écrit une liste close `\1..N` et **réconcilie**
  (purge les entrées numériques en trop). Exactement la sémantique d'une whitelist.
- **Chaîne prouvée bout-en-bout** : la capacité `blocked_executables` (`DisallowRun`, seed
  `2026_07_03_110000_seed_capabilities_registry_list_lot.php:104-158`) tourne déjà — mais c'est
  une **blacklist** Explorer.

### 4.2 Le quick win : capacité `RestrictRun` (whitelist)
Le jumeau whitelist de `DisallowRun`, **`RestrictRun`** (même clé `Policies\Explorer`, sous-clé
`RestrictRun\1..N`), **n'est pas seedé** mais réutilise **100 % de l'existant** — zéro nouveau code
agent. Bi-projection : flag registry `RestrictRun=1` + registry_list des `.exe` autorisés.
Bonus : `RestrictRun` vit en **HKCU** (écrit par le compagnon de session) → **per-user, hive
utilisateur, nettoyage automatique au logoff**, le bon cycle de vie.

### 4.3 La limite honnête de RestrictRun
Ne filtre **que ce qu'Explorer.exe lance** (menu Démarrer, Exécuter, double-clic). Ne bloque **pas**
un `.exe` lancé par script, processus enfant, chemin UNC, tâche planifiée, app UWP. → Niveau
**« environnement épuré »** (élève coopératif), **pas** anti-triche. Un contrôle contournable est
un risque de fausse sécurité — assumé explicitement pour la V1.

### 4.4 La couche correcte (V2) : AppLocker / WDAC
Règles par éditeur/hash/chemin **appliquées par le noyau** → attrape scripts et processus enfants.
Vrai kiosk. **Entièrement à créer** : nouveau mécanisme agent (policy AppLocker via GPO locale /
`Set-AppLockerPolicy` + service `AppIDSvc`, ou SRP registre) + catalogue. Gros lot → évolution.

### 4.5 La donnée manquante, nécessaire dans les deux cas : app → exécutable(s)
Le catalogue connaît les apps par **paquet WPKG / ProgId** (`Application`, `NativeApplication`,
`AppProfile`), **jamais par nom d'exécutable**. RestrictRun a besoin des `.exe` ; AppLocker des
éditeurs/hash. Cette correspondance **n'existe nulle part** → à ajouter au catalogue. Pièce commune
V1+V2. **La liste positive « apps autorisées » est agnostique du mécanisme** : construite une fois,
elle se compile en RestrictRun aujourd'hui, en AppLocker demain, sans changer l'UX admin.

## 5. Impasses confirmées
- **fs_acl** (`FsAclCapabilityProvider`) : pas de droit `execute` exposé (vocabulaire
  `list_folder/read/write/modify`), portée Machine, deny géographique sur Program Files qui casse
  le système. Non.
- **Overlay Rainmeter** (`OverlayStateProvider`) : décoratif, volontairement *on-desktop*
  (`AlwaysOnTop=-2`, jamais au-dessus des apps). Aucun verrouillage de shell/bureau.
- **Fichier hosts** : mauvaise couche — ne travaille que sur des noms (pas d'IP, pas de wildcard
  « tout sauf »), contourné par le DNS-over-HTTPS des navigateurs et les IP brutes, machine-global
  sans variante user. Zéro gain sur le firewall qui fait déjà « tout sauf le privé (dont SE5) ».

## 6. Périmètre V1
- Profil examen = **liste positive d'apps autorisées** + `internet_access=off` + durcissements
  existants (`llmnr_disabled`, `offline_files_disabled`).
- Assigné à la **salle** (parc physique) ; enseignant via groupe logique `internet_access=on`.
- Apps compilées en **`RestrictRun`** (niveau épuré assumé).
- **Flag manuel persistant** : assigner / retirer. Garde-fou control-hub anti-oubli.
- **Pas de kiosk visuel** (bureau standard).

**Hors V1 (évolutions) :** mécanisme AppLocker/WDAC anti-triche (même liste d'apps) ; durcissement
intégrité via groupe logique examen + override poste ; per-user firewall ; kiosk visuel (Assigned
Access) ; créneau planifié / bascule automatique.

## 7. Décisions structurantes (tranchées avec Henri, 2026-07-07)
1. **Granularité = salle (parc physique)** — l'élève individuel abandonné car la coupure internet
   est machine-scoped ; la salle couvre 100 % du besoin réel. (V1)
2. **Temporalité = flag manuel persistant** — pas d'ordonnanceur ni de créneau. (V1)
3. **Restriction = profils prédéfinis / liste positive d'apps autorisées** — pas de reset+whitelist
   soustractif ad hoc.
4. **Internet coupé via `internet_access=off` (firewall existant)** — pas de firewall Epic 36 à
   attendre, pas de hosts, pas de proxy/DNS.
5. **Exception enseignant via précédence logique>physique** — condition : le groupe logique porte
   explicitement `internet_access=on`.
6. **Apps V1 = RestrictRun (épuré, contournable assumé)**, AppLocker/WDAC = V2 sur la même donnée.
7. **Pas de kiosk visuel en V1.**
