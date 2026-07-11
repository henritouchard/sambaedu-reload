# Story 8.3 : Sous-réseaux DHCP (VLANs) — CRUD natif + scripts DHCP versionnés

Status: done

> **Story Epic 8 #3** — Réouverture de l'Epic 8 (clôturé 2026-05-13 après la 8.1). Porte la dernière
> fonctionnalité DHCP legacy non couverte : la **gestion des sous-réseaux/VLANs** de `sambaedu/dhcp/config.php`.
> La clé `8-2` étant historiquement consommée par la série appstore (8-2.x), cette story est numérotée 8.3.
>
> **Analyse legacy complète réalisée par le SM le 2026-07-03** (session d'analyse dédiée, synthèse § Investigation
> Legacy ci-dessous). Décision structurante actée avec Henri : **SQL source de vérité + export fichier de
> params + réutilisation de `make_dhcpd_conf.sh`** — PAS de génération native de `dhcpd.conf` (zone iPXE à risque).
>
> **Second volet (demande explicite Henri)** : versionner dans le repo les scripts système DHCP qui ne vivent
> aujourd'hui que dans les paquets Debian figés `sambaedu-*` 4.17.36 — un déploiement futur sans socle SE4
> perdrait `make_dhcpd_conf.sh` silencieusement (`update.sh:738` se contente d'un warning).
> Réf : `docs/audit-dependances-systeme.md` §2.2bis (table de suivi ajoutée le 2026-07-03).

---

## Story

As a **responsable de collège**,
I want créer, modifier et supprimer des sous-réseaux DHCP (VLANs) avec leurs plages dynamiques, depuis la page réseau native,
So que le serveur DHCP desserve plusieurs VLANs (routage inter-VLAN) sans édition manuelle de `dhcpd.conf` ni passage par l'UI legacy — et sans les pièges destructeurs du legacy (purge des baux à l'affichage, sauvegarde partielle, pas de suppression possible).

---

## Acceptance Criteria

**AC1 — Listing des sous-réseaux**
**Given** je consulte `/app/network/dhcp` (gate `viewAny-dhcp`)
**When** j'ouvre l'onglet « Sous-réseaux »
**Then** je vois le sous-réseau par défaut (lecture seule, source `sambaedu.conf.d/dhcp.conf`) et la liste des VLANs gérés (table SQL) : n° VLAN, réseau CIDR, gateway, plage(s) dynamique(s)

**AC2 — Création d'un VLAN**
**Given** je crée un sous-réseau (gate `manage-dhcp`)
**When** je saisis n° VLAN, réseau en notation CIDR (ex. `192.168.20.0/24`), gateway, plage(s) dynamique(s) et éventuel `extra_option`, puis valide
**Then** le sous-réseau est persisté en SQL, le fichier `dhcp-subnets.conf` est régénéré atomiquement, `make_dhcpd_conf.sh` est relancé, et un toast confirme
**And** la validation refuse en bloc (transaction tout-ou-rien, aucune écriture partielle) : CIDR invalide, n° VLAN hors 1..999 ou déjà pris, gateway hors réseau, plage hors réseau, begin > end, réseau chevauchant un autre sous-réseau (défaut inclus), ou plage dynamique recouvrant l'IP d'une réservation DHCP existante

**AC3 — Modification / Suppression**
**Given** un VLAN existant
**When** je le modifie ou le supprime (modale de confirmation pour la suppression)
**Then** la base ET le fichier exporté reflètent l'état, le service est rechargé — la suppression retire réellement les clés `dhcp_*_N` (capacité absente du legacy)

**AC4 — Plages dynamiques multiples**
**Given** un sous-réseau (VLAN)
**When** je déclare plusieurs plages dynamiques
**Then** l'export émet `dhcp_begin_range_<N>` + `dhcp_begin_range_<N>_<j>` (j contigu à partir de 1) et le `dhcpd.conf` généré contient plusieurs lignes `range` — l'UI expose enfin ce que le générateur legacy sait déjà consommer

**AC5 — Mode dégradé (pattern AC6 de la 8.1)**
**Given** une mutation dont le reload échoue (`DhcpCommandException`)
**When** l'erreur remonte
**Then** la mutation SQL + l'export fichier sont conservés, un toast warning explicite invite au reload manuel — on ne perd jamais la saisie

**AC6 — Scripts DHCP versionnés (greenfield-proof)**
**Given** un déploiement sans les paquets `sambaedu-*`
**When** `scripts/update.sh` s'exécute
**Then** `ensure_dhcp_scripts()` installe `scripts/system/make_dhcpd_conf.sh` et `scripts/system/dhcp-dyndns.sh` vers `/usr/share/sambaedu/sbin/` (chemin canonique = contrat sudoers/cron/config), idempotent, exécutable, avec log clair
**And** la copie SE5 de `make_dhcpd_conf.sh` n'appelle plus `action_cron_php.sh dhcp/script_make_reservations.php` (c'est `DhcpService::exportReservationsFile()` qui écrit `reservations.inc`) et inclut `reservations.inc` s'il existe

**AC7 — Jamais de comportement destructeur**
**Given** toute interaction avec la page ou le service
**Then** aucune autoconf AD-sites implicite, aucune purge de `/var/lib/dhcp/dhcpd.leases`, aucun stop du service — seuls l'export + `make_dhcpd_conf.sh` (qui restart lui-même) sont déclenchés, et uniquement sur mutation explicite

---

## Tasks / Subtasks

- [x] **T1 — Migration + modèle** (AC1-AC4)
  - [x] Migration `dhcp_subnets` : `vlan_id` int UNIQUE (1..999), `network` string/45 (CIDR complet, ex. `192.168.20.0/24`), `gateway` string/45, `ranges` json (`[{"begin":"…","end":"…"}, …]`, min 1), `extra_option` string nullable, `description` string nullable, timestamps
  - [x] Modèle `App\Models\DhcpSubnet` : casts (`ranges` => array), PHPDoc @property, pas de relation dure
- [x] **T2 — `DhcpSubnetService`** (`app/Services/Network/DhcpSubnetService.php`) (AC2-AC5)
  - [x] Validations pures et testables : `validateCidr()`, `validateVlanId()` (1..999, cf. piège #3), gateway/plages ⊂ réseau (ip2long/masque), begin ≤ end, non-chevauchement des réseaux inter-subnets ET vs sous-réseau par défaut (lu via `SambaEduConfig`), aucune plage dynamique ne recouvre l'IP d'une `dhcp_reservations` existante — messages FR ciblés via `DhcpValidationException` (réutiliser)
  - [x] CRUD `createSubnet/updateSubnet/deleteSubnet` : `DB::transaction` puis pipeline export+reload sous `Cache::lock('dhcp.reload', 30)->block(15)` — MÊME clé de lock que la 8.1 pour sérialiser avec les mutations réservations ; `LockTimeoutException` wrappée en `DhcpCommandException` (pattern exact `DhcpService::reloadAfterMutation`, dhcpService.php:237)
  - [x] `renderSubnetsConfFile(iterable $subnets): string` — pure, testable en snapshot : en-tête « généré par SE5, ne pas éditer », puis par VLAN trié : `dhcp_reseau_<N>`, `dhcp_masque_<N>` (dérivé du CIDR), `dhcp_gateway_<N>`, `dhcp_begin_range_<N>`/`dhcp_end_range_<N>` (1re plage), `dhcp_begin_range_<N>_<j>`/`dhcp_end_range_<N>_<j>` (plages 2+, j contigu dès 1), `dhcp_extra_option_<N>` si présent — format INI `clé = "valeur"` (parsé par `config.inc.sh:18`, valeurs sans espaces)
  - [x] `exportSubnetsFile()` : `AtomicFileWriter::write(config('sambaedu.dhcp.subnets_file'))` → `/etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf`
  - [x] Reload : injecter `DhcpService` et appeler `reloadService()` (déjà public, dhcpService.php:333) — ne PAS dupliquer le shellout
  - [x] `defaultSubnet(): array` — lecture seule du sous-réseau par défaut depuis `SambaEduConfig` (`dhcp_reseau`, `dhcp_masque`, `dhcp_gateway`, `dhcp_begin_range`, `dhcp_end_range`)
- [x] **T3 — Config** (AC2, AC6)
  - [x] `config/sambaedu.php` bloc `dhcp` : ajouter `subnets_file` => env(`DHCP_SUBNETS_FILE`, `/etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf`) — à placer à côté de `reservations_file`
- [x] **T4 — UI onglet « Sous-réseaux »** (AC1-AC3)
  - [x] Nouvel onglet dans `resources/views/pages/network/dhcp/index.blade.php` (3e tab à côté de Réservations/Baux) + partial `_partials/subnets-table.blade.php` ; formulaire en modale `<x-molecules.modal>` (création/édition, plages dynamiques répétables), modale de confirmation suppression ; `WithToasts` ; gates `manage-dhcp` sur toutes les actions (miroir exact des handlers réservations de la même page)
  - [x] Carte « Sous-réseau par défaut » en lecture seule (badge explicatif : géré par l'autoconf serveur)
  - [x] UX formulaires : labels au-dessus des inputs, hints utiles en tooltip uniquement, étoile sur champs obligatoires
- [x] **T5 — Scripts versionnés + update.sh** (AC6)
  - [x] Créer `scripts/system/` ; y copier `make_dhcpd_conf.sh` (récupéré de la VM `/usr/share/sambaedu/sbin/`) avec UNE adaptation : supprimer le bloc `action_cron_php.sh dhcp/script_make_reservations.php` (garder le `include reservations.inc` conditionnel) — TOUT le reste iso-octet (options boot iPXE, hooks dyndns, AppArmor, flock)
  - [x] Copier `dhcp-dyndns.sh` tel quel — cf. note : la version installée sur la VM est le variant **HTTP/curl** (délègue le DNS à `dnsupdate.php`) qui NE contient AUCUNE logique LDAP/SASL → le fix `SASL_NOCANON`/`ensure_ldap_sasl_nocanon` est **sans objet** pour ce script (copié verbatim, SHA vérifié iso-octet)
  - [x] `ensure_dhcp_scripts()` dans `scripts/update.sh` (patron des `ensure_*` existants, ex. `ensure_samba_partages_share` update.sh:595) : `install -m 755` les 2 scripts vers `/usr/share/sambaedu/sbin/`, comparaison de contenu avant écrasement (log « à jour » vs « déployé »), appelée dans la séquence principale (après `ensure_samba_partages_share`, avant `ensure_ipxe_bootstrap_native`)
  - [x] Cocher les 2 lignes correspondantes dans `docs/audit-dependances-systeme.md` §2.2bis (❌ → ✅ + chemin repo)
- [x] **T6 — Tests** (tous AC ; exécution HÔTE php8.4+sqlite, jamais sur VM)
  - [x] Unit `DhcpSubnetServiceTest` : matrice validations (CIDR, vlan_id bornes/unicité, gateway hors réseau, plage hors réseau, begin>end, chevauchements inter-VLAN + vs défaut + vs réservation), snapshot `renderSubnetsConfFile` (multi-plages, suffixes `_N_j`, tri stable), transaction tout-ou-rien
  - [x] Feature CRUD Livewire onglet sous-réseaux (create/edit/delete + gates + mode dégradé toast warning — miroir `DegradedMode` de la 8.1)
  - [x] Architecture : `DhcpSubnetService` sous `App\Services\Network\*` (couvert par `NetworkNamespaceTest` existant — vert)
  - [x] Bash : `bash -n scripts/system/*.sh` + `bash -n scripts/update.sh` OK
- [x] **T7 — Doc + QA** 
  - [x] `docs/qa/domains/network.md` : nouvelle section sous-réseaux (Section 8 CRUD + Section 9 scripts versionnés, scénarios création VLAN, multi-plages, suppression, vérif `dhcpd.conf` généré, greenfield `ensure_dhcp_scripts`) — append-only
  - [x] Doc suit le code : pas de doc domaine séparée dans cette story

---

## Dev Notes

### Investigation Legacy (SM, 2026-07-03) — ce qu'on porte et ce qu'on NE porte PAS

**Source legacy** : `sambaedu/dhcp/config.php` + `sambaedu/includes/dhcpd.inc.php` (`dhcp_config_form()` L33,
`dhcp_update_config()` L294, `get_network()` L556) + `sambaedu/includes/sites.inc.php` (`create_site_dhcp()` L261).

**Modèle legacy** : AUCUNE table — clés plates dans `/etc/sambaedu/sambaedu.conf.d/dhcp.conf`, VLAN encodé
dans le suffixe de clé (`dhcp_reseau_20`), plages multiples en double suffixe `_<vlan>_<j>` (double underscore
`__<j>` pour le VLAN 0). Générateur : `make_dhcpd_conf.sh` (paquet `sambaedu-boot-server`) boucle `i=1..1023`
sur `config_dhcp_reseau_$i` et émet les blocs `subnet {}`.

**Défauts legacy à NE PAS reproduire** (vérifiés sur code) :
1. `create_site_dhcp()` appelée à CHAQUE affichage du formulaire : écrit la conf PUIS `stop dhcpd` + **`rm dhcpd.leases`** + `start` inconditionnels (sites.inc.php:311-313). → AC7 : aucune autoconf implicite, jamais de purge. L'import AD-sites explicite est **hors scope** (différé, décision Henri).
2. Sauvegarde partielle : chaque champ `set_param()` indépendamment, une erreur n'annule pas les autres écritures. → transaction tout-ou-rien.
3. Aucune suppression de VLAN possible. → AC3.
4. `set_ip_in_lan()` = regex syntaxe IPv4 uniquement (le nom ment), masque validé comme une IP quelconque, zéro cohérence réseau. → T2 validations.
5. Bouton restart cassé : `dhcpd_restart()` appelle `/sh/share/...` (typo, dhcpd.inc.php:742). Ne rien réutiliser de cette fonction.
6. Boucle plages `j` du générateur s'arrête au premier trou → l'export SE5 émet des `j` CONTIGUS à partir de 1.
7. `dhcp_ip_min_<N>` legacy **non porté** : cette clé ne servait qu'au placement auto des réservations côté
   PHP legacy (`get_free_ip()`), jamais consommée par `make_dhcpd_conf.sh` — les réservations SE5 (8.1)
   n'utilisent pas ce mécanisme. Ne pas la modéliser ni l'exporter.

### Décisions structurantes actées (session 2026-07-03 avec Henri)

- **D1 — SQL source de vérité, export params, générateur conservé.** Table `dhcp_subnets` → rendu fichier
  `/etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf` → `make_dhcpd_conf.sh`. Vérifié : `config.inc.sh:15`
  charge TOUS les `*.conf` de `sambaedu.conf*` via `find` — le fichier dédié est vu sans toucher au
  `dhcp.conf` legacy (pas de conflit d'ownership, régénération full-file atomique iso-pattern `reservations.inc`).
- **D2 — Pas de génération native de `dhcpd.conf`.** Le script porte les options boot iPXE (arch 00:00/06/07),
  les hooks dyndns et l'AppArmor : le réécrire = refonte à risque (zone iPXE) pour zéro gain. La source SQL
  rend la génération native possible plus tard (audit §4 Vague 3).
- **D3 — Sous-réseau par défaut en LECTURE SEULE v1.** Ses clés vivent dans `dhcp.conf` (legacy/update.sh) ;
  `SambaEduConfig::set()` n'écrit que dans `sambaedu.conf` principal (SambaEduConfig.php:266) — étendre
  l'écriture aux fichiers module est hors scope.
- **D4 — `vlan_id` ∈ 1..999.** Le générateur s'arrête à `i<1024` ; le legacy limitait à 3 chiffres. 1..999,
  contrainte DB + validation service.
- **D5 — Fichier params format INI strict** `clé = "valeur"` : `config.inc.sh:18` parse par sed + word-split
  sur espaces — aucune valeur avec espace (IPs et chemins seulement ; `extra_option` = chemin sans espace, à valider).

### Réutilisation OBLIGATOIRE (ne pas réinventer — tout existe depuis la 8.1)

| Brique | Où | Usage ici |
|---|---|---|
| `DhcpService::reloadService()` | `app/Services/Network/DhcpService.php:333` | LE reload — injecter le service, ne pas dupliquer le shellout sudo |
| `Cache::lock('dhcp.reload', 30)` + `block(15)` + wrap `LockTimeoutException` | `DhcpService.php:237-254` | Même clé → sérialise subnets ET réservations |
| `AtomicFileWriter` | `app/Support/AtomicFileWriter.php` | Écriture `dhcp-subnets.conf` |
| `DhcpValidationException` / `DhcpCommandException` | `app/Services/Network/Exceptions/` | Mêmes types, mêmes toasts |
| `SambaEduConfig` | `app/Config/SambaEduConfig.php` | Lecture sous-réseau par défaut (jamais parse_ini maison) |
| Gates `viewAny-dhcp` / `manage-dhcp` | `DhcpPolicy` (epic 7) | Déjà en place, y compris sudoers make_dhcpd_conf (runbook 8.1) |
| Page + onglets + modales + toasts | `pages/network/dhcp/index.blade.php` | 3e onglet dans la MÊME page, patrons identiques |
| Channel logs `network`, préfixe `DhcpSubnetService:` | `logChannel()` pattern DhcpService.php:730 | Grep opérateurs |

### Pièges spécifiques (appris des stories précédentes)

- **VM ≠ environnement de test** : tests PHPUnit sur l'HÔTE (php8.4 + sqlite ; la VM n'a pas pdo_sqlite). Les
  migrations ne sont PAS auto-jouées sur la VM → « RESTE /vm : php artisan migrate » dans le rapport de fin.
- **SQLite n'applique pas les varchar** : borne les strings côté validation (PG 22001 invisible en test).
- **Livewire** : jamais de méthode d'action nommée `upload` ; pas d'injection de strings user dans `wire:click`
  (leçon 8.1 : passer par index/id serveur).
- **`make_dhcpd_conf.sh` régénère ET restart** le service lui-même (flock interne) — ne jamais empiler un
  restart supplémentaire. Attention : son flock `-w 10 -E 0` sort en SUCCÈS silencieux si verrou occupé —
  c'est le lock applicatif `dhcp.reload` côté SE5 qui protège réellement.
- **La copie versionnée doit préserver la logique `ipxe_script`** : le `dhcpd.conf` généré doit continuer à
  chaîner le bootstrap natif `/ipxe/boot` (cf. `ensure_ipxe_bootstrap_native`, update.sh:679 — il vérifie
  précisément ce point et re-génère au besoin).
- **`update.sh` cible le repo principal** : la story se développe sur `main` (pas de worktree pour la partie
  scripts/VM — règle projet worktree/VM).
- **Fichiers lus par PHP-FPM sur la VM** : user `www-admin` — si QA manuel crée `dhcp-subnets.conf` à la main,
  `chown www-admin`.

### Project Structure Notes

- Service : `app/Services/Network/DhcpSubnetService.php` (+ éventuel `Data/` si DTO — rester simple, array shapes suffisent)
- Modèle : `app/Models/DhcpSubnet.php` ; migration `database/migrations/`
- UI : `resources/views/pages/network/dhcp/index.blade.php` (onglet) + `_partials/subnets-table.blade.php`
- Scripts : `scripts/system/make_dhcpd_conf.sh`, `scripts/system/dhcp-dyndns.sh` ; fonction dans `scripts/update.sh`
- Aucune route nouvelle (onglet dans page existante) → pas de `route:cache` VM nécessaire

### Testing Standards

Pattern 8.1 : Unit sur service pur (validations = data provider exhaustif, render = snapshot string),
Feature Livewire avec bypass permissions du harnais existant, test architecture namespace. Cibler les
filtres (`--filter=DhcpSubnet`) — pas de run massif VM (faux échecs connus).

### Reco modèle dev

**OPUS** — service PHP + UI Livewire sur patrons éprouvés (8.1 à transposer), pas de cross-language ni de
contrat agent. La partie bash est une copie quasi-verbatim + une fonction `ensure_*` sur patron existant.

### References

- Analyse legacy : `sambaedu/includes/dhcpd.inc.php` (L33 form, L294 update, L556 get_network), `sambaedu/dhcp/config.php`, `sambaedu/includes/sites.inc.php:261`
- Script générateur (VM) : `/usr/share/sambaedu/sbin/make_dhcpd_conf.sh` (paquet `sambaedu-boot-server` 4.17.36)
- Parseur conf shell : VM `/usr/share/sambaedu/includes/config.inc.sh:15-18`
- Story précédente : `_bmad-output/implementation-artifacts/8-1-gestion-des-reservations-dhcp-et-baux-actifs.md`
- Suivi scripts hors-repo : `docs/audit-dependances-systeme.md` §2.2bis + §4 (vagues)
- Epic : `_bmad-output/planning-artifacts/epics.md` § Epic 8 / Story 8.3
- QA réseau : `docs/qa/domains/network.md`

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- `php artisan test --filter=DhcpSubnet` (HÔTE) → **30 passed (55 assertions)**.
- `php artisan test --filter="NetworkNamespaceTest|DhcpServiceTest|DhcpReservationsCrudTest|DhcpDegradedModeTest"` (non-régression 8.1 + archi) → **45 passed (61 assertions)**.
- `bash -n scripts/system/make_dhcpd_conf.sh`, `bash -n scripts/system/dhcp-dyndns.sh`, `bash -n scripts/update.sh` → OK.

### Completion Notes List

- **T1-T2** : `dhcp_subnets` (source de vérité) + `DhcpSubnetService`. Validations pures et testables ; `validateCidr()` **normalise** le réseau vers la base (host bits à zéro, ex. `192.168.20.5/24` → `192.168.20.0/24`) — les chevauchements se calculent sur `[base, broadcast]` en unsigned 32 bits (`ip2long & 0xFFFFFFFF`). Chevauchement vérifié inter-VLAN ET vs sous-réseau par défaut (lu via `SambaEduConfig::get('dhcp_reseau'|'dhcp_masque')`). Aucune plage ne peut recouvrir l'IP d'une `dhcp_reservations` existante.
- **Réutilisation stricte 8.1** : `DhcpService::reloadService()` injecté (pas de shellout dupliqué), MÊME lock `Cache::lock('dhcp.reload',30)->block(15)` (sérialise VLAN + réservations), `AtomicFileWriter`, `DhcpValidationException`/`DhcpCommandException`, channel `network`, page/onglets/modales/`WithToasts`.
- **Mode dégradé (AC5)** : mutation SQL en `DB::transaction` PUIS export+reload — un `DhcpCommandException` remonte à l'UI (toast warning) sans rollback ; le fichier de params est régénéré, la saisie n'est jamais perdue.
- **Render** : format INI strict `clé = "valeur"`, tri stable par `vlan_id`, 1re plage sans suffixe puis `_<N>_<j>` contigu dès 1 (aligné sur la boucle `config_dhcp_begin_range_"$i"_"$j"` du générateur).
- **T5 (scripts)** : `make_dhcpd_conf.sh` copié iso-octet SAUF retrait du bloc `action_cron_php.sh dhcp/script_make_reservations.php` (l'inclusion conditionnelle de `reservations.inc` conservée). `dhcp-dyndns.sh` copié verbatim (SHA vérifié). **Note SASL_NOCANON** : la version VM de `dhcp-dyndns.sh` est le variant HTTP/curl (POST vers `dnsupdate.php`), sans logique LDAP/SASL — le fix `SASL_NOCANON` ne s'y applique pas. `ensure_dhcp_scripts()` idempotent (`cmp -s` avant `install -m 755`) appelé dans `main()`.
- **Migrations NON auto-jouées sur la VM** (dev-cycle SQLite only) : `RESTE /vm : php artisan migrate`.

### File List

**Création**
- `app/Models/DhcpSubnet.php`
- `app/Services/Network/DhcpSubnetService.php`
- `database/migrations/2026_07_11_120000_create_dhcp_subnets_table.php`
- `database/factories/DhcpSubnetFactory.php`
- `resources/views/pages/network/dhcp/_partials/subnets-table.blade.php`
- `scripts/system/make_dhcpd_conf.sh`
- `scripts/system/dhcp-dyndns.sh`
- `tests/Unit/Services/Network/DhcpSubnetServiceTest.php`
- `tests/Feature/Network/DhcpSubnetsCrudTest.php`

**Modification**
- `config/sambaedu.php` (clé `dhcp.subnets_file`)
- `resources/views/pages/network/dhcp/index.blade.php` (onglet + méthodes + modales sous-réseaux)
- `scripts/update.sh` (`ensure_dhcp_scripts()` + appel dans `main()`)
- `tests/Traits/CreatesDhcpSchema.php` (schéma `dhcp_subnets`)
- `docs/audit-dependances-systeme.md` (§2.2bis — 2 lignes ❌→✅)
- `docs/qa/domains/network.md` (Section 8 + 9 + checklist 8.3, append-only)
- `docs/qa/README.md` (ligne « network » enrichie)
