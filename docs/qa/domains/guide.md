# QA Manuel — Domaine Guide des fonctionnalités

> Runbook E2E pour le **Guide des fonctionnalités** (documentation how-to gatée
> par permissions). Append-only : chaque story ajoute une section avec ses
> scénarios numérotés stables.

**Stories couvertes** : 40.1 (socle hub + mécanisme de gating réutilisable +
domaine pilote « Utilisateurs »). _Stories futures 40.2, 40.3… ajouteront un
domaine documenté par story (Partages, Machines, WPKG…) en réutilisant le
composant `x-molecules.feature-guide-item`._

**Principe métier** : le Guide n'est **jamais fermé**. Toute fonctionnalité est
**toujours affichée** ; celle à laquelle l'utilisateur n'a pas droit est
**verrouillée** (grisée + cadenas + rappel du droit requis), jamais masquée.

**Code de référence (Story 40.1)** :

- `routes/web.php` — routes `app.guide` et `app.guide.utilisateurs` (groupe `app.`, **aucun** middleware `can:`)
- `resources/views/pages/guide/index.blade.php` — hub (domaines fonctionnels + compteur accessibles/total)
- `resources/views/pages/guide/utilisateurs/index.blade.php` — domaine pilote « Utilisateurs » (6 permissions `user`)
- `resources/views/components/molecules/feature-guide-item.blade.php` — composant de gating réutilisable (état déverrouillé/verrouillé, décision d'accès injectable)
- `app/Support/Help/FeatureGuideRegistry.php` — contenu how-to authored (objectif + étapes + lien), ancré sur `SambaPermission`
- `resources/views/components/organisms/sidebar.blade.php` — entrée « Guide » (hors `@can`, visible pour tous)
- `app/Enums/SambaPermission.php` — source des domaines/labels (`groupedByCategory()`, `categoryLabel()`, `label()`)
- Tests : `tests/Feature/Guide/GuideTest.php`

---

## Pré-requis communs

### Système (VM SE4FS)

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Cache Spatie reset : `php artisan permission:cache-reset`
- Permissions + rôles seedés : `php artisan db:seed --class=PermissionSeeder`
- **Après ajout des routes `app.guide*`** : `php artisan route:cache` puis
  `chown www-admin:www-admin bootstrap/cache/*.php` (cf. contrainte projet
  « routes cachées ») — sans quoi les nouvelles routes tombent dans le catch-all
  legacy (404).

### Comptes de test (rôles Spatie)

Pour vérifier le gating, il faut un compte par profil représentatif :

- **élève** (`eleve`) — **0** permission → domaine Utilisateurs `0 / 6`.
- **professeur** (`prof`) — `user.read` + `user.password.init` → `2 / 6`.
- **super-admin** (`super-admin`) — toutes les permissions → `6 / 6`.

Assigner un rôle : page « Gestion des droits » → onglet « Recherche utilisateur »,
ou en base via `\App\Models\User::where('login', …)->first()->assignRole('prof')`.

---

## Section 1 — Accès au hub `/app/guide`

### Scénario 1.1 — Le Guide est visible dans la navigation pour tous

1. Se connecter avec un compte **élève** (aucun droit).
2. Vérifier la présence de l'entrée **« Guide »** (icône point d'interrogation)
   dans le menu latéral, **au-dessus** de « Réglages ».
3. **Attendu** : l'entrée est visible même sans aucune permission (elle est
   hors `@can`). « Réglages » reste, lui, réservé à `server.admin`.

### Scénario 1.2 — Le hub est ouvert à tout utilisateur authentifié

1. Toujours connecté en **élève**, cliquer sur « Guide » (ou aller sur `/app/guide`).
2. **Attendu** : la page s'affiche (HTTP 200), **aucun 403**. Elle liste les
   **domaines fonctionnels** (Utilisateurs, Partages, Machines, WPKG, Serveur…).

### Scénario 1.3 — Un invité non authentifié est redirigé

1. Se déconnecter, puis appeler `/app/guide` en navigation privée.
2. **Attendu** : redirection vers la page de connexion (middleware `sambaedu.auth`).

---

## Section 2 — Compteur « accessibles / total » par domaine

### Scénario 2.1 — Compteur cohérent selon le rôle (domaine Utilisateurs)

1. Se connecter en **professeur** (`prof`).
2. Sur `/app/guide`, repérer la carte **« Utilisateurs »**.
3. **Attendu** : le badge affiche **« 2 / 6 accessibles »** (le prof a 2 des 6
   permissions du domaine).
4. Refaire avec **élève** → **« 0 / 6 »** ; avec **super-admin** → **« 6 / 6 »**.

### Scénario 2.2 — Domaine pilote cliquable, autres « Bientôt disponible »

1. Sur `/app/guide` (n'importe quel rôle).
2. **Attendu** : la carte **« Utilisateurs »** est **cliquable** (mène à
   `/app/guide/utilisateurs`). Les autres domaines (Partages, Machines, WPKG,
   Serveur, Fonds d'écran…) sont **présents mais grisés**, badge **« Bientôt
   disponible »**, **sans lien mort**.

---

## Section 3 — Domaine pilote « Utilisateurs » : gating par fonctionnalité

### Scénario 3.1 — Non-masquage : 6 fonctionnalités toujours listées

1. Se connecter successivement en **élève**, **professeur**, **super-admin**.
2. Aller sur `/app/guide/utilisateurs`.
3. **Attendu** : dans **tous** les cas, **exactement 6 fonctionnalités** sont
   affichées (Consulter les utilisateurs, Réinitialiser les mots de passe,
   Modifier les utilisateurs, Créer des utilisateurs temporaires, Assigner des
   droits, Déléguer des droits). **Aucune n'est masquée**, quel que soit le rôle.

### Scénario 3.2 — Verrouillage pour l'élève (0/6)

1. Connecté en **élève**, ouvrir `/app/guide/utilisateurs`.
2. **Attendu** : les **6** fonctionnalités sont **verrouillées** — carte grisée
   (opacité réduite), badge **« Verrouillé »** (cadenas), mention **« Droit
   requis : … »**, et le bouton d'accès à la vraie page est **désactivé**.
3. Vérifier que le **contenu how-to reste lisible** (objectif + étapes) même
   verrouillé (valeur pédagogique).

### Scénario 3.3 — Verrouillage partiel pour le professeur (2/6)

1. Connecté en **professeur**, ouvrir `/app/guide/utilisateurs`.
2. **Attendu** :
   - **Déverrouillées (2)** : « Consulter les utilisateurs » (`user.read`) et
     « Réinitialiser les mots de passe » (`user.password.init`) — bouton d'accès
     **actif**.
   - **Verrouillées (4)** : Modifier, Créer temporaires, Assigner des droits,
     Déléguer — grisées + cadenas + bouton désactivé.

### Scénario 3.4 — Tout déverrouillé pour le super-admin (6/6)

1. Connecté en **super-admin**, ouvrir `/app/guide/utilisateurs`.
2. **Attendu** : les **6** fonctionnalités sont **déverrouillées**, aucun
   cadenas, tous les boutons d'accès actifs.

### Scénario 3.5 — Navigation vers la vraie page depuis un item déverrouillé

1. Connecté en **professeur**, sur `/app/guide/utilisateurs`.
2. Cliquer sur le bouton d'accès de « Consulter les utilisateurs ».
3. **Attendu** : redirection vers **`/app/users`** (la vraie page listant les
   comptes). Le bouton « Assigner des droits » (verrouillé) ne mène **nulle
   part** (élément désactivé, `pointer-events-none`).

### Scénario 3.6 — Bouton retour vers le hub

1. Sur `/app/guide/utilisateurs`, cliquer sur la flèche « retour » (en-tête).
2. **Attendu** : retour sur `/app/guide`.

---

## Section 4 — Domaine « Machines » (Story 40.2)

> **Story 40.2** ajoute le 2e domaine documenté = **« Machines »** (catégorie
> `computer`, **5** permissions : `computer.view`, `computer.control`,
> `computer.elevate`, `computer.install`, `computer.remote.rdp`). Réutilise le
> socle 40.1 (composant `x-molecules.feature-guide-item`, registre, hub).
>
> **Note** : la carte « Machines » du hub, décrite « Bientôt disponible » au
> Sc. 2.2 (état 40.1), est **désormais cliquable** (voir Sc. 4.5). Le Sc. 2.2
> reste valable pour les domaines encore non documentés (Partages, WPKG…).
>
> **Gating = `can()` GLOBAL** (choix produit) : bien que les permissions
> `computer.*` soient délégables scopées WorkstationGroup ailleurs dans l'app,
> le Guide répond à « as-tu ce droit *quelque part* ? » — pas « sur quel parc ».
>
> **Code de référence** : route `app.guide.machines` (`routes/web.php`,
> groupe `app.`, sans `can:`) ; page `resources/views/pages/guide/machines/index.blade.php` ;
> 5 entrées `computer` dans `FeatureGuideRegistry` ;
> `FeatureGuideRegistry::documentedDomains()` (table catégorie → route du hub) ;
> tests `tests/Feature/Guide/GuideMachinesTest.php`.
>
> **Comptes de test** : **élève** (`eleve`) → `0 / 5` ; **technicien**
> (`technicien`, `computer.view` + `computer.control`) → `2 / 5` ;
> **admin machines** (`computer-admin`, les 5 `computer.*`) → `5 / 5`.

### Scénario 4.1 — Non-masquage : 5 fonctionnalités toujours listées

1. Se connecter successivement en **élève**, **technicien**, **admin machines**.
2. Aller sur `/app/guide/machines`.
3. **Attendu** : dans **tous** les cas, **exactement 5 fonctionnalités** sont
   affichées (Voir les machines, Contrôle à distance, Admin de poste, Installer
   un poste, Bureau à distance (RDP)). **Aucune n'est masquée**, quel que soit
   le rôle.

### Scénario 4.2 — Verrouillage pour l'élève (0/5)

1. Connecté en **élève**, ouvrir `/app/guide/machines`.
2. **Attendu** : les **5** fonctionnalités sont **verrouillées** — carte grisée,
   badge **« Verrouillé »** (cadenas), mention **« Droit requis : … »**, bouton
   d'accès **désactivé**. Le contenu how-to (objectif + étapes) **reste lisible**.

### Scénario 4.3 — Verrouillage partiel pour le technicien (2/5)

1. Connecté en **technicien**, ouvrir `/app/guide/machines`.
2. **Attendu** :
   - **Déverrouillées (2)** : « Voir les machines » (`computer.view`) et
     « Contrôle à distance » (`computer.control`) — bouton d'accès **actif**.
   - **Verrouillées (3)** : « Admin de poste » (`computer.elevate`), « Installer
     un poste » (`computer.install`), « Bureau à distance (RDP) »
     (`computer.remote.rdp`) — grisées + cadenas + bouton désactivé.

### Scénario 4.4 — Tout déverrouillé pour l'admin machines (5/5)

1. Connecté en **admin machines** (`computer-admin`), ouvrir `/app/guide/machines`.
2. **Attendu** : les **5** fonctionnalités sont **déverrouillées**, aucun
   cadenas, tous les boutons d'accès actifs.

### Scénario 4.5 — Hub : carte « Machines » cliquable + compteur

1. Se connecter en **technicien**, aller sur `/app/guide`.
2. Repérer la carte **« Machines »**.
3. **Attendu** : la carte est **cliquable** (mène à `/app/guide/machines`), badge
   **« 2 / 5 accessibles »**. La carte **« Utilisateurs »** reste cliquable
   (non-régression 40.1). Les domaines encore non documentés (Partages, WPKG…)
   restent « Bientôt disponible ».

### Scénario 4.6 — Navigation vers la page parc depuis un item déverrouillé

1. Connecté en **technicien**, sur `/app/guide/machines`.
2. Cliquer sur le bouton d'accès de « Voir les machines » (déverrouillé).
3. **Attendu** : redirection vers **`/app/parc`** (la gestion du parc). Le bouton
   d'un item verrouillé (p. ex. « Admin de poste ») ne mène **nulle part**
   (élément désactivé, `pointer-events-none`).

### Scénario 4.7 — Bouton retour vers le hub

1. Sur `/app/guide/machines`, cliquer sur la flèche « retour » (en-tête).
2. **Attendu** : retour sur `/app/guide`.

---

## Checklist rapide

- [ ] Entrée « Guide » visible pour un élève (hors `@can`) — Sc. 1.1
- [ ] `/app/guide` = 200 pour un élève sans droit — Sc. 1.2
- [ ] `/app/guide` redirige un invité vers le login — Sc. 1.3
- [ ] Compteur « 2 / 6 » pour le prof sur le domaine Utilisateurs — Sc. 2.1
- [ ] Domaines non documentés = « Bientôt disponible » (pas de lien mort) — Sc. 2.2
- [ ] 6 fonctionnalités toujours listées, aucune masquée — Sc. 3.1
- [ ] Élève : 6 verrouillées (cadenas + droit requis + how-to lisible) — Sc. 3.2
- [ ] Prof : 2 déverrouillées / 4 verrouillées — Sc. 3.3
- [ ] Super-admin : 6 déverrouillées — Sc. 3.4
- [ ] Lien actif mène à la vraie page ; lien verrouillé inerte — Sc. 3.5
- [ ] Bouton retour ramène au hub — Sc. 3.6
- [ ] Machines : 5 fonctionnalités toujours listées, aucune masquée — Sc. 4.1
- [ ] Machines : élève 5 verrouillées (how-to lisible) — Sc. 4.2
- [ ] Machines : technicien 2 déverrouillées / 3 verrouillées — Sc. 4.3
- [ ] Machines : admin machines 5 déverrouillées — Sc. 4.4
- [ ] Hub : carte « Machines » cliquable + « 2 / 5 » ; Utilisateurs toujours cliquable — Sc. 4.5
- [ ] Machines : lien actif mène à `/app/parc` ; lien verrouillé inerte — Sc. 4.6
- [ ] Machines : bouton retour ramène au hub — Sc. 4.7

---

## Limites connues

- **Lien « Machines » vs droit de consultation du parc (review 40.2, finding #4)** —
  Les how-to du domaine « Machines » pointent tous vers `/app/parc` (`app.parc.index`),
  page gardée par `viewAny-workstationGroup` (≈ `computer.view`). Le Guide déverrouille
  chaque fonctionnalité sur le `can()` **global** de SA permission propre. Conséquence :
  un **profil custom** disposant p. ex. de `computer.control` **sans** `computer.view`
  verrait l'item déverrouillé mais obtiendrait un **403 propre** en cliquant le lien.
  **Aucun rôle livré n'est concerné** (technicien, admin machines, référent numérique
  ont tous `computer.view`). À surveiller uniquement si des profils custom « control/RDP
  sans view » sont créés via Gestion des droits. Décision produit assumée : le Guide
  répond « as-tu ce droit *quelque part* ? », pas « sur quel parc ».
  - _Test manuel ciblé_ : créer un profil custom `computer.control` seul, l'assigner à un
    compte, ouvrir `/app/guide/machines` → « Contrôle à distance » déverrouillé →
    cliquer → **403 attendu** (comportement connu, non un bug).
