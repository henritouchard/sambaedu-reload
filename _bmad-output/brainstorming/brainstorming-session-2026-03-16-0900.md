---
stepsCompleted: [1, 2, 3, 4]
inputDocuments: [sambaedu-reload/, irundoo/, sambaedu/, _bmad/Project-SE.md]
session_topic: 'Stratégie de livraison et architecture technique du duo sambaedu-reload + irundoo'
session_goals: 'Prioriser ce qui reste à faire, définir le MVP parité legacy, résoudre les décisions architecturales en suspens'
selected_approach: 'progressive-flow'
techniques_used: ['first-principles', 'constraint-mapping', 'six-thinking-hats', 'decision-tree-mapping']
ideas_generated: [28]
context_file: ''
---

# Brainstorming Session Results

**Facilitateur:** henri
**Date:** 2026-03-16

---

## Session Overview

**Topic:** Stratégie de livraison + architecture technique — duo sambaedu-reload (SER, local ~40%) + irundoo (central ~55%) pour remplacer le legacy SambaEdu

**Goals:**
- A. Stratégie de livraison : priorisation, MVP parité legacy, plan de bascule
- B. Architecture technique : décisions en suspens (Repository vs Eloquent, migration LdapRecord, stratégie de tests, sécurité inter-instances)

### Contexte clé
- PostgreSQL déjà en place (Docker), MySQL = legacy uniquement ou import temporaire
- Plus de fallback legacy (écritures séparées, accès legacy sera coupé)
- Import depuis AD opérationnel, import depuis MySQL legacy manquant (associations AppProfile ↔ Apps)
- Livewire 4 beta + DaisyUI pour l'UI
- Spatie Permissions pour dissocier droits app / droits Windows
- Tests : VM locale (tout casser), sandbox prod + 3 collèges bêta
- Snapshots Proxmox avant toute migration en prod
- **SER open-source, irundoo commercial** — développés en parallèle, SER indépendant d'irundoo par design
- Architecture 3 couches : SFC (UI+validation) → Services (métier, réutilisé par API) → Models (data SQL+AD)

---

## Phase 1 — First Principles : Décisions Architecturales

### Identité & AD

**[Archi #1] : Keycloak Central Unique**
_Concept_ : Un seul Keycloak central comme source d'identité primaire. L'AD local devient un subscriber qui sync depuis Keycloak. Chaque instance SER tire ses propres users filtrés par UAI.
_Novelty_ : Élimine les 300 synchronisations inter-AD. Fallback naturel : AD local reste autonome si internet coupé. Tokens JWT validés offline pendant leur durée de vie.

**[Archi #2] : Fallback AD local dans SER**
_Concept_ : Si Keycloak central injoignable, SER bascule sur LdapRecord contre l'AD local. Quelques lignes dans le guard Laravel.
_Novelty_ : Résilience sans complexité — pas besoin de Keycloak local.

**[Archi #3] : Dual-source AD sans conflit**
_Concept_ : Keycloak écrit dans OU=People/Groups, SER/PostgreSQL écrit dans OU=Computers/Salles. Deux jobs de sync indépendants sur des OUs séparées.
_Novelty_ : AD reçoit de deux sources sans collision car les espaces de noms sont distincts.

**[Archi #4] : Filtrage UAI via irundoo**
_Concept_ : irundoo est l'autorité sur les liens user↔établissement. Chaque instance SER demande à irundoo "qui doit avoir accès à mon UAI ?" et reçoit locals + itinérants. L'AD local ne reçoit que les users pertinents.
_Novelty_ : Keycloak reste simple. La logique multi-établissement vit dans irundoo qui est fait pour ça.

**[Archi #5] : Gestion des itinérants dans irundoo**
_Concept_ : Table `user_etablissement_links { user_id, uai, type: "itinérant" }` dans irundoo. Les itinérants peuvent avoir des attributs différents (quota réduit, droits limités).
_Novelty_ : Modèle multi-établissement explicite sans polluer Keycloak.

### AD minimal

**[Archi #6] : AD réduit au strict nécessaire**
_Concept_ : AD contient uniquement OU=Salles (GPOs Windows natifs), objets computer, OU=Parcs (utilisé par SER backend uniquement, pas Windows). Tout le reste en PostgreSQL.
_Novelty_ : Profils applicatifs, raccourcis, quotas déjà en PostgreSQL → moins d'AD que le legacy ne le laissait penser.

**[Archi #7] : AppProfile comme abstraction cross-hiérarchie**
_Concept_ : Un AppProfile ("pc-de-jeu") peut être associé à des postes individuels ET à des groupes de salles, indépendamment de la hiérarchie OU=Salles. SER calcule l'union lors de l'installation.
_Novelty_ : Flexibilité maximale sans modifier la structure AD.

### Architecture code

**[Archi #8] : Pattern Services/Legacy/**
_Concept_ : Namespace `App\Services\Legacy\` pour le code réécrit depuis le legacy mais non refactoré. Chaque méthode a un commentaire avec le fichier legacy source + la raison du refactoring futur.
_Novelty_ : Rend la dette technique visible et localisée sans bloquer la livraison. Le legacy devient documentation des intentions.

**[Archi #9] : Repositories LDAP conservés, SQL purs simplifiés**
_Concept_ : Les repositories LDAP (UserRepository, WorkstationRepository) sont légitimes — ils abstraient LdapRecord. Les repositories SQL purs (AppProfileRepository partie SQL) sont des thin wrappers Eloquent à absorber dans les Models à terme.
_Novelty_ : Pas de migration urgente — distinction claire entre les deux cas d'usage.

**[Archi #10] : Architecture 3 couches confirmée**
_Concept_ : SFC Livewire (UI + validation) → Services (métier, réutilisable par API) → Models (data SQL+AD). Pas de tout-en-un dans les SFC.
_Novelty_ : Permet le découplage back/front : dev back touche services/models, dev front touche templates. Multiplicateur d'équipe direct.

---

## Phase 2 — Constraint Mapping

### Contraintes réelles (bloquantes MVP)

**[Contrainte #1] : Migration données MySQL legacy**
_Concept_ : Associations AppProfile ↔ Applications non importées. Bloquant avant toute bêta — sans ça les scripts d'installation Windows calculent mal.
_Novelty_ : Moins lourd que prévu (peu de données en MySQL). Sprint 0 dédié.

**[Contrainte #2] : FS individuel (home directories)**
_Concept_ : Lecteur K: sur Windows. Serveur : `/home/{username}`. Création via mkhome.sh (mkdir + chown + setfacl). Quotas XFS sur partition /home. Suppression en 2 temps : /home/trash/ → optionnel permanent.
_Novelty_ : Pattern sudo wrapping déjà présent dans SER (WOL, actions machines). HomeDirectoryService = complexité moyenne.

**[Contrainte #3] : Partages classes + ACLs**
_Concept_ : Répertoires /var/sambaedu/Classes/, ACLs POSIX avec héritage (setfacl -R), droits sur fichiers, dossier échange. Déjà un pont legacy → Laravel (list_rights() → PostgreSQL).
_Novelty_ : Le pont existant montre comment bridger proprement.

**[Contrainte #4] : Imprimantes (MVP)**
_Concept_ : Liste, détails, ajout/config CUPS, pilotes Windows, suppression. Services/Legacy/ acceptable.
_Novelty_ : Initialement prévu post-MVP, réintégré en MVP à la demande.

**[Contrainte #5] : Délégations**
_Concept_ : Gestion des délégations d'administration par parc/groupe.
_Novelty_ : Ajouté au Sprint 1.

**[Contrainte #6] : Parcs — actions batch + cron**
_Concept_ : Éteindre/allumer/rebooter parc entier, programmer allumage, action cron.
_Novelty_ : Allumage/extinction unitaires déjà faits. Batch = même logique, niveau supérieur.

**[Contrainte #7] : DHCP réservations + baux**
_Concept_ : Configuration DNS, import/création/script réservations, gestion des baux.
_Novelty_ : Sprint 2 après le quotidien users.

**[Contrainte #8] : GPOs + WPKG**
_Concept_ : GPOs via Services/Legacy/ (2 collègues experts à impliquer). WPKG MVP = packages au démarrage + logs + rapports. Export XML hosts/packages/profiles.
_Novelty_ : Ordre scripts Windows non contrôlable (limitation GPO acceptée). Logs WPKG nécessaires pour debug bêta.

### Contraintes gérables

**[Contrainte #9] : Import CSV machines**
_Concept_ : Import sites et machines depuis CSV.
_Novelty_ : Sprint 2, après DHCP.

**[Contrainte #10] : Couplage SER/irundoo sur AppStore**
_Concept_ : Quand irundoo est présent, il définit les apps autorisées à l'installation. SER fonctionne en mode standalone sinon (apps définies localement).
_Novelty_ : Pas de dépendance de livraison — optionnel par design.

### Contraintes écartées / acceptées

- **Régressions silencieuses** → Proxmox snapshots avant migration = rollback en 2 min
- **Coexistence legacy** → Accès legacy coupé, pas de risque de double écriture
- **Bus factor** → Risque existant, documentation SER/documentation/ à compléter
- **Migration progressive collèges** → Pas un bloquant, rollout séquentiel
- **iOS/Android** → Très long terme, hors scope

---

## Phase 3 — Six Thinking Hats

### 🤍 Blanc — Faits confirmés
- ~40% SER, ~55% irundoo
- MySQL migration légère (peu de données)
- VM locale + sandbox prod + 3 bêtas = dispositif de test solide
- Documentation existante dans SER/documentation/
- Tests comme doc = nice-to-have, pas prio MVP

### ❤️ Rouge — Intuitions validées
- WPKG : testable manuellement → régressions détectables rapidement
- MySQL migration : moins lourd qu'anticipé
- Découplage back/front : bénéfice organisationnel majeur (dev principal déteste le front, secondaire peu à l'aise en back)

### 💛 Jaune — Bénéfices confirmés
- VM locale = casser sans risque
- Découplage back/front via Livewire SFC → multiplicateur d'équipe direct
- Refonte UI complète → adoption facilitée
- Code typé + tests = moins de régressions en prod
- Sécurité : passwords en session, CAS sans SSL, bypass admin → tout ça disparaît
- Foundation Keycloak prête sans refactoring majeur (Phase 2)

### 🖤 Noir — Risques gérés
- Régressions silencieuses → **Snapshots Proxmox** avant migration
- Coexistence legacy → **Coupure accès legacy** à la migration
- Bus factor → Risque existant, documentation à compléter progressivement

### 💚 Vert — Idées retenues
- **Services/Legacy/ comme documentation vivante** : commentaires source legacy + raison refactoring futur dans chaque méthode
- **Tests de parité comme suite de régression permanente** : fixtures fixes contre AD Docker, filet pour refactorings post-MVP
- **Migration via irundoo MassActions** → Phase 2+ (rolling release requis)

### 🔵 Bleu — Décisions de processus
- SER et irundoo développés en parallèle (features souvent couplées)
- SER open-source, irundoo commercial — deux produits, un repository
- Seuil de confiance bêta : validation par Henri, pas de checklist formelle
- Documentation : compléter SER/documentation/ au fil du dev

---

## Phase 4 — Plan d'Action : Decision Tree

```
MVP sambaedu-reload
│
├── 🔴 SPRINT 0 — Débloquer les données
│   └── Migration MySQL → PostgreSQL
│       ├── Mapper exactement ce qui est en MySQL legacy
│       ├── Script d'import (associations AppProfile ↔ Apps + autres)
│       └── Valider sur VM avant tout
│
├── 🟠 SPRINT 1 — Fonctionnalités utilisateur quotidiennes
│   ├── FS individuel (HomeDirectoryService)
│   │   ├── mkhome.sh wrapper → Service Laravel
│   │   ├── XFS quotas liés
│   │   └── Archivage /home/trash à la suppression
│   ├── Partages classes (répertoires + ACLs setfacl + droits)
│   ├── Imprimantes (MVP — Services/Legacy/ acceptable)
│   ├── Délégations
│   └── Parcs : actions batch + cron allumage/extinction/reboot
│
├── 🟡 SPRINT 2 — Infrastructure réseau
│   ├── DHCP réservations + baux + DNS
│   └── Import CSV machines + sites
│
├── 🟢 SPRINT 3 — Windows & Applications
│   │   ⚠️ Couplage irundoo optionnel : AppStore service
│   │   fonctionne standalone, se branche sur irundoo si présent
│   ├── GPOs (Services/Legacy/ — impliquer 2 collègues experts)
│   ├── WPKG MVP :
│   │   ├── Packages au démarrage ✓
│   │   ├── Logs WPKG ✓ (debug bêta)
│   │   ├── Rapports ✓
│   │   └── Maintenance → clarifier depuis legacy
│   └── Scripts de démarrage (comportement hérité, ordre accepté)
│
├── 🔵 BÊTA PROGRESSIVE
│   ├── VM locale (tout casser)
│   ├── Sandbox prod (Proxmox snapshot avant)
│   └── 3 collèges bêta
│
└── 🟣 POST-MVP
    ├── Keycloak central (Phase 2)
    ├── Intégrations ENT/CSV (rentrée scolaire)
    ├── irundoo MassActions migration (rolling release requis)
    └── iOS/Android (très long terme)
```

### Dépendances entre sprints
- Sprint 1, 2, 3 nécessitent Sprint 0 complété
- Sprint 3 AppStore peut démarrer sans irundoo (standalone d'abord)
- GPOs Sprint 3 : impliquer les collègues experts dès le démarrage du sprint

---

## Synthèse des décisions prises

| Décision | Choix retenu |
|---|---|
| Keycloak | Phase 2 — architecture SER déjà compatible |
| AD scope | OU=Salles + computers uniquement en cible finale |
| Repositories LDAP | Conservés — légitimes |
| Repositories SQL purs | Absorber dans Models (post-MVP) |
| Legacy access | Coupure franche à la migration |
| Services/Legacy/ | Pattern validé pour GPOs, WPKG, imprimantes |
| Tests | AD SAMBA Docker + VM locale, parity suite post-MVP |
| SER/irundoo | Développés en parallèle, SER standalone par design |
| iOS/Android | Très long terme, hors scope |
| Imprimantes | MVP (Services/Legacy/) |
| Migration MySQL | Sprint 0 — léger, bloquant |

