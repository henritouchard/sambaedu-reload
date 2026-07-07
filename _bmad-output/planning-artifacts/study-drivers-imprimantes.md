# Étude de cadrage — Refonte de la gestion des drivers d'imprimantes

> Note de cadrage préalable (avant toute implémentation). Objectif : poser
> l'existant, les points de friction, et les directions possibles avec
> coût/risque, pour décider ensemble de la cible.

## 1. Existant (résumé factuel)

Modèle « deux couches » assumé : **SE5 ne remplace ni CUPS ni Samba**, il les
complète. Deux runtimes font foi, une couche DB (SE5) porte l'audit + le
rattachement métier.

| Runtime (vérité) | Couche SE5 (DB) | Réconciliation |
|---|---|---|
| CUPS (`lpstat`/`lpadmin`) → imprimante, URI, PPD | table `printers` | `printers:sync` (cron 03:30) |
| Samba `[print$]` (`rpcclient enumdrivers`) → drivers Windows publiés | table `printer_drivers` | `printer-drivers:sync` (cron 03:35) |

- La table `printer_drivers` **ne stocke pas les fichiers** : seulement l'audit
  et le rattachement driver ↔ imprimante. Les binaires vivent dans
  `/var/lib/samba/printers/x64/`, servis par le share `[print$]`.
- **Déploiement vers les postes = point-and-print SMB implicite** : un poste qui
  se connecte à `\\se4fs\<imprimante>` récupère le driver depuis
  `\\se4fs\print$\x64\3\`. Aucun code registre / GPO / WPKG dans ce flux.
- **L'agent Go** installe la *connexion* imprimante (payload
  `PrintersStateProvider`) + `SetDefaultPrinter`. Il ne gère **pas** les fichiers
  driver.

### Le flux d'ajout de driver actuel (le cœur du problème)

Décalqué iso-legacy (`sambaedu/includes/printers.inc.php`), il passe par un
**poste Windows 10 « pivot »** — orchestré par
`app/Services/Print/PrintDriverService.php` (~1100 l.) :

1. L'admin installe le driver **manuellement** sur un poste W10 et y partage une
   imprimante locale.
2. UI → `rpcclient enumprinters <pivot>` puis `getdriver "<name>" <pivot>`.
3. `smbclient //pivot/print$ -c 'cd x64\3;get <file> ...'` → copie dans
   `/var/lib/samba/printers/x64/` + `chown www-admin`.
4. `rpcclient adddriver "Windows x64" "<Name>:<Path>:<Data>:<Config>:..." "3"`.
5. INSERT SE5 + `rpcclient setdriver "<printer>" "<driver>"`.
   Auth : tout en `--use-kerberos=required` (compte machine `se4fs$`).

## 2. Points de friction (relevés dans le code / doc)

1. **Dépendance à un poste W10 pivot** allumé et joignable en Kerberos : friction
   opérationnelle forte, nombreuses exceptions dédiées (pivot injoignable,
   ticket Kerberos expiré).
2. **Pas d'upload direct `.inf`/`.zip`** : `PrinterDriver.source` prévoit
   `inf-upload` mais **non implémenté**.
3. **x64 uniquement** (`ARCHITECTURE_ALLOWED = ['x64']`) : tout le code est câblé
   `adddriver "Windows x64"` + `DRIVERS_DIR_X64`. x86 reporté.
4. **Aucune vérif de signature Authenticode** (choix assumé, WARNING doc) : un
   driver malicieux peut être distribué.
5. **PK composite** `(printer_cups_name, architecture)` → casse `find()`, route
   binding, `save()` ; contournements manuels partout.
6. **États partiels non transactionnels** : « driver enregistré Samba mais attach
   échoué » géré via `$pendingAttachDriver` + bouton « Réessayer ». Rollback
   fichiers best-effort. Samba n'est pas transactionnel.
7. **Incohérence précédence défaut** : doc dit « physique > logique »,
   `PrintersStateProvider::resolveDefaultCupsName()` code « logique > physique ».
   À trancher.
8. **Nom CUPS non renommable** (PK = nom, CASCADE) : rename = tout casser.
9. **Sudoers à packager à la main** (4 entrées rpcclient/smbclient/chown/rm), non
   déployées automatiquement.
10. **Dette legacy** : `wpkg-client.vbs`, `app/Gpo/` cohabitent sans participer au
    flux driver → confusion « par où passe le déploiement ».

## 3. Directions possibles

### Option A — Upload direct de paquet driver (.inf / .zip)
Supprimer la dépendance au poste pivot : l'admin dépose un paquet driver signé
depuis l'UI, SE5 le publie côté serveur (`pnputil`-like côté Samba / `rpcclient
adddriver` à partir des fichiers extraits).
- **Gain** : plus de poste W10 tiers, workflow autonome, reproductible.
- **Coût / risque** : élevé. Parsing `.inf`, résolution des dépendances de
  fichiers, mapping vers le format `adddriver` strict, gestion x86/x64, validation
  du paquet. C'est le gros chantier.

### Option B — Fiabiliser le flux pivot existant
Garder le pivot mais réduire la friction : retry/rollback robustes et
transactionnels-au-mieux, **signature Authenticode**, préflight (pivot joignable +
ticket Kerberos valide) avec messages clairs, packaging auto du sudoers.
- **Gain** : incrémental, faible risque, améliore le vécu à court terme.
- **Coût / risque** : moyen-faible. Ne lève pas la dépendance au poste pivot.

### Option C — Repenser le canal (agent Go pousse le driver)
Faire porter le driver par l'agent (le poste installe le driver localement via
`pnputil`/point-and-print policy) plutôt que par le share `[print$]`.
- **Gain** : s'aligne sur la trajectoire « tout par l'agent », découple de Samba
  `print$`, ouvre le multi-arch naturellement.
- **Coût / risque** : élevé + change le modèle de vérité. À évaluer vs la stratégie
  d'extinction legacy et le rôle de l'agent.

## 4. Questions ouvertes pour le cadrage

1. **Cible du poste pivot** : on veut le supprimer (A/C) ou juste le fiabiliser (B) ?
2. **Multi-architecture** (x86) : besoin réel dans le parc, ou x64-only acceptable ?
3. **Signature Authenticode** : exigence sécurité ferme ou best-effort ?
4. **Trajectoire agent** : le driver doit-il à terme passer par l'agent Go
   (option C) en cohérence avec l'extinction du canal Samba legacy ?
5. **Précédence défaut** (physique vs logique) : trancher l'incohérence doc/code.

## 5. Références

- `docs/domains/printers.md` (doc de domaine, 611 l.) — point de départ.
- `app/Services/Print/PrintDriverService.php` — toute la logique Samba/rpcclient.
- `resources/views/pages/parc/_partials/printers-tab.blade.php` — orchestration UI.
- `app/Services/Agent/Providers/PrintersStateProvider.php` — canal agent (connexion).
- `_bmad-output/implementation-artifacts/6-2-gestion-des-pilotes-windows.md` — story
  d'origine + décisions D1-D14.
