# Changelog legacy SambaEdu (SE4) — pull du 2026-05-28

Plage : `cbbba0009..933efd65a` sur `main` (versions XP **4.17.628 → 4.17.677**).
115 commits, ~1 700 lignes, 49 fichiers. Source : `../sambaedu/`.

Légende pertinence pour le refacto **SE5 (sambaedu-reload)** :
- 🟢 à porter / aligner
- 🟡 à évaluer (dépend du scope ou déjà couvert)
- 🔴 hors scope (legacy-only / infra Debian / iso-existant)

---

## 1. Mode examen / `no_internet` — gros chantier 🟢

Réécriture complète du mécanisme de coupure Internet pour comptes temporaires (mode examen / candidats).

- `df5f0df97` Réécriture de `no_internet` en PowerShell (`…/unattended/install/os/SambaEdu/no_internet.ps1`, +521 lignes)
- `1a5b96516` mode examen
- `c4e92f1fd` mise en place comptes temp candidat avec coupure internet
- `38609f452` optim `no_internet` via `apcu_store`
- `4ff1998e9` allow local network
- `0ee01267e` gestion déconnexion
- `399b9add3` correction activation règles
- `3396dd900` allow curl
- Nouveau endpoint : `gpo/no_internet_out.php`

→ **À porter** : aligner le portage GPO/scripts de l'Epic scripts WPKG/Windows. Vérifier qu'on n'a pas déjà couvert dans l'Epic 17 (scripts) — sinon créer une story dédiée "mode examen".

---

## 2. Mots de passe — harmonisation & sécurité 🟢

- `fae4ab8dd` meilleure gestion des mdp
- `d9015da8c` harmonisation `$rules['min-pwd-length']` & `$rules['complexity']`
- `dd7516029` correction vérif complexité mdp
- `76b931d9a` affichage longueur correcte
- `18d84f35f` on ne divise pas par zéro (calcul complexité)
- `bee707194` clarification de la réinit des mdp
- `601a5901a` **protection contre changement de mdp admin sur etabs** (central-only)
- `244972173` protection `read.user`

Fichiers : `annu/mod_pwd.php`, `includes/ldap.inc.php`, `includes/user.interface.inc.php`.

→ **À porter** : les règles `min-pwd-length`/`complexity` sont des invariants métier, à reprendre dans la couche domain SE5. La protection admin-etabs touche au split central/local ([[legacy_central_vs_local_split]]).

---

## 3. Samba / Kerberos / SMB 🟡

- `381ebb5af` ajout `krb5.keytab` pour krb5i
- `88a345fd9` régénération keytab si inexistant (`sambaedu-shares.postinst`)
- `92c567a42` nettoyage clé `invalid_users` dans `update_smb_conf`
- `30870f174` correction `update_smb_conf` (`samba.inc.php`)
- `fcb642d81`, increments `version_smb_conf`
- `65a7ac7f4` **sécurisation NFS4**

Fichiers : `samba.inc.php` (+57), `samba-tool.inc.php` (+113), `sambaedu-shares.postinst`.

→ **À évaluer** : auth iso-legacy ([[feedback_auth_iso_legacy]]), à confronter à l'état SE5. La sécurisation NFS4 mérite un audit côté `controlHub`/SE5.

---

## 4. Guacamole — bascule mode token 🟡

- `3e114033a` bascule guacamole en mode token

→ **À évaluer** : fork interne ([[sambaedu_guacamole_fork]]), scope = supporter l'existant ([[feedback_guacamole_scope]]). Vérifier que SE5 expose bien le flux token.

---

## 5. Profils navigateurs (Chrome / Edge / Firefox) 🟡

- `744733028` profils chrome et edge **locaux par défaut**
- `6658c5e81` tentative repasser Edge/Chrome en **local + roaming**
- `b5118dcd0` tentative roaming chrome/edge v2
- `ea8aa5aaa` **désactivation GenerativeAI in Firefox**

Fichiers : `applications/chrome/{logon.windows,redirects.json}`, `applications/edge/*`, `applications/firefox/default.json`.

→ **À évaluer** : couvert par le portage scripts Windows (Epic 17). Pas de logique SE5 spécifique mais à embarquer côté distribution.

---

## 6. iPXE / installation Windows 🟡

- `fdee6980a` réorganisation `ipxe/installation-windows.php`
- `59785f926`, `dc37c5e7d` changement logo windows + nouveau `windows11.png`
- `7999aa0e6` edit `sambaedu-ipxe.install`

→ **À évaluer** : selon avancement portage iPXE dans SE5.

---

## 7. Auto-upgrades Debian (security) 🔴

- `ed023ddc3` mise en place upgrades auto security
- `291c6f561` auto-upgrades
- `1873aab03` Trixie : `install_backports` dans `utils.inc.sh`

Nouveaux : `20auto-upgrades.example`, `50unattended-upgrades-server.example`.

→ **Hors scope SE5** : infra packaging Debian, à conserver legacy.

---

## 8. LDAP / annuaire 🟢

- `dff2a1a54` optimisation LDAP (`includes/ldap.inc.php`, -70 lignes refacto)
- `9a600c57a` warning `$name['fonction']` dans `list_classes_filtered`
- `401842982` correction si pas de classe dans l'étab
- `28df4e7dc` **suppression encodages HTML dans URL passés au JSON de config** (sécurité/parsing)
- `be403871f` corrections actions sur fiche utilisateur

Fichiers : `annu/grouplist_csv.php`, `annu/ldap_cleaner.php`, `annu/mod_user_entry.php`, `includes/annu.inc.php`, `includes/ldap.inc.php`.

→ **À porter** : optims LDAP + correction encodage URL à reprendre dans la couche annuaire SE5.

---

## 9. ENT / OpenENT 🟡

- `e061aee68` affichage configuration SSO uniquement en OpenENT (`ent.inc.php`, +95 lignes)

→ **À évaluer** : selon état du portage ENT dans SE5.

---

## 10. Clustershell pour clinux 🟡

- `e03c35a58` conf clustershell pour les clinux
- `073bd218f` ajout liste clinux pour clustershell

→ **À évaluer** : touche au scope client-linux ; voir [[legacy_central_vs_local_split]].

---

## 11. Config / check_config 🟡

- `6f615ed17` check_config lors de la MAJ des paquets
- `0cee64033` pas de retour pour check_config
- Fichiers : `config/check_config.php` (+30), `conf_modules.php`, `conf_params.php`, `includes/config.inc.php` (+118).

→ **À évaluer** : si SE5 garde une logique check_config, à aligner.

---

## 12. UI / textes / coquilles 🔴

- `b9701fd1c` infobulles `conf_modules.php`
- `64b973a0f` infobulles `fonc_parc.inc.php`
- `a1b574a27` harmonisation texte machine (`cherche_machine.php`)
- `cd43bd4a7` majuscules début de phrases (`partages.inc.php`)
- `f63aac454` affichage description PC
- Multiples coquilles.

→ **Hors scope porting** : UI SE5 = nouvelle stack Livewire, ces ajustements ne se reportent pas tels quels.

---

## 13. Divers 🟡

- `4c7863158` correction script startup wine inutile
- `wpkg/wpkg_ldap_update.php` (-6 lignes) — à comparer avec [[project_story_17-1_done]] (Epic 17)
- `includes/remote.inc.php` (+90 lignes), `includes/gpo.inc.php` (+24), `includes/ihm.inc.php` (+20)
- `includes/bbb.inc.php` (BigBlueButton), `includes/parcs.inc.php`

---

## Synthèse — candidats prioritaires à porter

| Priorité | Sujet | Story / Epic suggérée |
|---|---|---|
| 🔥 | **Mode examen / no_internet** | nouvelle story (lien Epic 17 scripts) |
| 🔥 | **Règles mdp harmonisées** + protection admin etabs | story sécurité/domain |
| 🔥 | **Optims LDAP** + suppression encodage HTML URL JSON | story annuaire |
| ⚠️ | NFS4 hardening | audit infra |
| ⚠️ | Guacamole mode token | vérif iso SE5 |
| ⚠️ | Profils Chrome/Edge/Firefox + désactivation GenAI FF | scripts WPKG |

---

*Pour creuser un sujet en détail, demander : `git show <sha>` ou `git log -p cbbba0009..933efd65a -- <path>` dans `../sambaedu/`.*
