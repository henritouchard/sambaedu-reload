# Dette technique Auth — registre Epic 16 (Phase 2 → Phase 3)

Document vivant qui trace les éléments de dette technique du module **Auth V1**
(Stories 16.10 + 16.11 + suivantes Phase 2). Chaque entrée doit pointer vers la
story source, la décision d'acceptation (date + valideur), les mitigations en
place, et la solution prévue pour sortir de la dette.

Pattern iso `docs/tech-debt-gpo.md`.

## Registre (ouvert)

| ID                | Story | Type dette                                                                | Acceptée            | Sortie prévue     |
|-------------------|-------|----------------------------------------------------------------------------|---------------------|-------------------|
| TD-16.11-MITM     | 16.11 | Fenêtre MITM courte au bootstrap (`curl -k`)                              | 2026-05-18 Henri    | Phase 3+          |

---

## TD-16.11-MITM — Fenêtre MITM curl -k au premier bootstrap

**Story source** : 16.11 (Auto-bootstrap migration postes existants)
**Acceptée** : 2026-05-18 par Henri (Phase 2 dual-mode — Q3 post-review)
**À résoudre en** : Phase 3 (pré-déploiement CA root via WPKG/GPO machine)

### Description

Le fragment de migration auto-bootstrap (`resources/views/auth/v1/bootstrap-fragment-{cmd,sh}.blade.php`) télécharge le script complet de bootstrap via `curl -k --insecure` :

- Windows : `curl.exe -kfsS "https://se4fs-XXX/api/v1/agent/bootstrap.cmd"`
- Linux   : `curl -kfsS  "https://se4fs-XXX/api/v1/agent/bootstrap.sh"`

Le flag `-k` désactive la vérification du CA — nécessaire **par construction** car le CA root du serveur Sambaedu n'est pas encore installé côté poste au moment de cette première requête (chicken-and-egg : c'est le bootstrap lui-même qui installe le CA root via `certutil -addstore Root` (Win) ou `update-ca-certificates` (Linux)).

Le script complet lui-même, une fois téléchargé, installe le CA root puis bascule sur des appels `curl --cacert <CA>` ou `Invoke-RestMethod` avec validation HTTPS native (cf. `bootstrap-cmd.blade.php` et `bootstrap-sh.blade.php` post-Q1.c).

### Modèle de menace

Cf. `docs/qa/domains/auth.md` → section "Limitation Phase 2 — fenêtre MITM courte au bootstrap".

Surface effective :

- Insider LAN (élève sur Wi-Fi école, technicien malveillant, poste compromis).
- Capable d'**ARP spoof** ou de tenir un **proxy transparent** sur le segment LAN du poste cible.
- Pendant la fenêtre courte du premier boot/logon post-déploiement Phase 2 du poste.

Exploitation réussie = installation persistante d'un CA root malicieux côté poste → MITM permanent sur toutes les communications HTTPS Auth V1 subséquentes.

### Mitigations Phase 2 (en place dès 16.11)

1. **EnsureLanIp /24 LAN-only** : les endpoints `/api/v1/agent/bootstrap.{cmd,sh}` + `/api/v1/agent/enroll` sont restreints aux subnets RFC1918 par défaut (configurable `AUTH_V1_BOOTSTRAP_ALLOWED_SUBNETS`). Un attaquant doit déjà être physiquement présent sur le LAN scolaire — pas d'attaque depuis Internet. Cf. `app/Auth/V1/Http/Middleware/EnsureLanIp.php`.

2. **Traçabilité des rejets** : `workstation_migration_attempts` insère un row `status='failed'` à chaque rejet (Q2 post-review, cf. `app/Auth/V1/Services/MigrationAttemptRecorder.php`). Un attaquant qui retente en boucle crée du bruit traçable.

3. **Monitoring ratio** : `php artisan migration:health-check --threshold=0.05` (daily schedule) loggue `auth.migration.health.alert` critical si le ratio failed/total > 5%. Une campagne MITM massive (qui ferait chuter les enrolls réels) ressort.

4. **Doc QA — scénario fingerprint check post-migration** : `docs/qa/domains/auth.md` documente la procédure de comparaison du fingerprint sha256 du CA root pinné côté poste vs côté serveur. Permet de détecter manuellement (campagne périodique ou suite à alerte) les postes dont le CA root pinné diffère du CA officiel — signe probable d'un MITM exploité au bootstrap.

### Solution Phase 3+ (sortie de la dette)

Pré-déployer le CA root sur **tous les postes du parc avant le premier bootstrap**, supprimant ainsi le besoin de `curl -k`.

**Option 1 — WPKG package machine** (recommandée — pattern iso 15.x déjà éprouvé) :

1. Créer un package WPKG `sambaedu-ca-root` (parité `wpkg-deploy` Story 15.2).
2. Le package installe le CA root via :
   - Windows : `certutil.exe -addstore -f "Root" "<chemin-cacert>"`
   - Linux   : `cp <cacert> /usr/local/share/ca-certificates/sambaedu-ca.crt && update-ca-certificates`
3. Le package est marqué `priority=HIGH` et déployé en amont des autres packages (s'installe au prochain `wpkg.js` invocation, **avant** le premier startup/logon).
4. Une fois le parc complet « inoculé » (95% des postes ont reçu le package), supprimer le flag `-k` du fragment dans `bootstrap-fragment-{cmd,sh}.blade.php`.

**Option 2 — GPO machine Computer Configuration → Trusted Root CAs** (Active Directory natif) :

1. Importer le CA root dans la GPO `Default Domain Policy` (ou une GPO machine dédiée) sous `Computer Configuration → Policies → Windows Settings → Security Settings → Public Key Policies → Trusted Root Certification Authorities`.
2. Forcer `gpupdate /force` côté postes (ou attendre le cycle naturel).
3. Pour Linux, utiliser un mécanisme équivalent (Ansible / package debian sambaedu-ca-cert) — pas géré par AD GPO mais par un canal indépendant (DHCP option / startup script légitime).

### Critères de sortie de la dette

- [ ] CA root installé sur ≥ 95% du parc Windows via WPKG ou GPO machine.
- [ ] CA root installé sur ≥ 95% du parc Linux via Ansible / package debian.
- [ ] Test smoke : un poste qui télécharge `bootstrap.{cmd,sh}` depuis un MITM (avec un CA root différent) doit échouer le SSL handshake (`curl: (60) SSL certificate problem`) → confirmation que `-k` peut être retiré sans casser les postes légitimes.
- [ ] Retirer `-k` du fragment dans `bootstrap-fragment-{cmd,sh}.blade.php` (substitution `curl.exe -kfsS` → `curl.exe -fsS` Win, `curl -kfsS` → `curl -fsS` Linux).
- [ ] Mettre à jour `docs/qa/domains/auth.md` pour retirer la section "Limitation Phase 2 — fenêtre MITM courte au bootstrap" et la déplacer dans une section "Historique des limitations résolues".
- [ ] Marquer cette entrée TD comme `[résolue]` ci-dessus avec date.

### Anti-patterns à éviter Phase 3

- ❌ Ne PAS introduire une whitelist de CA fingerprints côté poste hardcodée — ça réintroduit un secret partagé dans le script Blade.
- ❌ Ne PAS basculer en mode `curl --cacert <CA-via-DNS-TXT-record>` — la résolution DNS pré-bootstrap est elle-même attaquable (DNS spoofing LAN).
- ✅ La solution propre est bien le **pré-déploiement du CA out-of-band** (WPKG / GPO machine / image préparée), pas un mécanisme cryptographique supplémentaire dans le fragment.
