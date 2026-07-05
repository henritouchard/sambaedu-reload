# Extinction SE4 — suppression de `/var/www/sambaedu` - Epic 38 Breakdown

Date : 2026-07-03
Source : demande directe Henri (session 2026-07-03) — objectif : pouvoir supprimer le
repo legacy `/var/www/sambaedu` de la VM **sans impacter les fonctionnalités SE5**, et
faire remonter les erreurs silencieuses du type `gpo/firefox.php` (canal legacy encore
appelé sans qu'on s'en aperçoive). **Contrainte forte posée par Henri** : si d'anciennes
routes doivent être appelées (postes non encore migrés, postes en cours de migration),
il faut absolument y répondre **de la bonne manière** pour assurer le passage SE4 → SE5.
Mémoires projet : `project_no_legacy_transition_state`,
`project_gpo_dispatcher_static_anchor`, `project_firefox_profile_forced_no_dir_trap`,
`project_legacy_config_kill_switch`.
Recoupe et solde la **Vague 4** de `docs/audit-dependances-systeme.md`.

## Overview — état des lieux (constaté sur /vm le 2026-07-03, logs + config à l'appui)

Ce qui utilise encore `/var/www/sambaedu` aujourd'hui :

1. **Netboot iPXE** : `Alias /ipxe /var/www/sambaedu/ipxe` dans le vhost SE5 sert les
   statiques de boot (`boot.ipxe`, `undionly.kpxe`, `snponly_x64.efi`, `diconf/`,
   `png/`) référencés par `dhcpd.conf`. Suppression = plus aucun poste ne boote en PXE.
2. **Génération DHCP/DNS** : cron `make_dhcpd_conf.sh` (5 min) → `dhcp/
   script_make_reservations.php` + `dhcp/dnsupdate.php` (réservations + DNS depuis
   l'AD). Fonction vivante la plus lourde — **la Story 8.3 (sous-réseaux DHCP/VLANs) en
   chantier est le véhicule de son remplacement natif** ; l'epic 38 en dépend (38.6).
3. **Canal client legacy** : les postes (y compris des postes DÉJÀ migrés à l'agent,
   ex. `.103`) appellent encore `gpo/applications.php` et `gpo/shortcuts_out.php` au
   logon via des **crochets locaux au poste** (curl → `%windir%\applications-*.cmd`
   exécuté). Ni SYSVOL (GPO `applications` = coquille vide sur /vm) ni le serveur ne
   portent le crochet. C'est exactement le mécanisme de l'incident Firefox
   (`project_firefox_profile_forced_no_dir_trap`). Le mécanisme EXACT du crochet
   (tâche planifiée / GPO locale / clé Run / Userinit) n'est pas encore identifié :
   un diagnostic embarqué (surcharge VM `/etc/sambaedu/applications/firefox/
   logon.windows`, « ffdiag v2 ») l'inventorie au prochain logon d'un poste —
   résultat à consigner en création de story 38.3.
4. **Crons serveur legacy** (`/etc/cron.d/sambaedu-*` → `action_cron_php.sh` → curl) :
   `sync_cron` (ENT), `update_stats`, `rep_cloud_cron`, `repquota`, `wpkg_*`,
   `test_ent`, `mfa_ent`, `clean_connexions`, `action_cron`, `check_config`,
   `delete_temp_users`.
5. **4 chemins de code SE5 font des `require` dans `/var/www/sambaedu/includes`** :
   `legacy/bootstrap.php:58,77-90` (consommé par `AgentBootstrapPublisher:451` —
   `import_gpo` pour la GPO bootstrap agent —, `LegacyCatchallController`,
   `LegacyEmbedService`, `RoamingProfileService`), `LegacyConfigBridge:224-226`,
   `RemoteAccessService:37-50,217` (Guacamole), `ShortcutsService:503` (fallback).
6. **Piège catchall** : `LegacyCatchallController:117-119` → `abort(500)` sur TOUTE URL
   non matchée si `legacy_path` n'existe plus.

Ce qui est déjà éteint de fait : le service de boot iPXE legacy **port 909** (n'écoute
plus, zéro hit), le fragment de migration HTTP (27.14), le shim WPKG hosts/profiles
(27.5). Le **vecteur de migration d'un poste SE4 est l'AD, pas HTTP** : la GPO
`SE_agent_bootstrap` est liée à `OU=computers` (vérifiée sur /vm) → tout poste joint au
domaine installe l'agent au startup, puis converge en natif.

## Décisions structurantes

- **D1 — Tombstone ≠ canal maintenu.** La doctrine 27.14 (`project_no_legacy_
  transition_state` : pas d'état transitoire, extinction en bloc) est préservée. Les
  routes legacy appelées par les postes reçoivent des réponses **terminales, typées et
  inertes** (script no-op valide, XML vide valide, Content-Type correct) — jamais une
  réimplémentation, et surtout **jamais une page d'erreur HTML sur un endpoint dont la
  réponse est exécutée** (le crochet client fait `curl > x.cmd` puis `call x.cmd` ; le
  démon Linux fait `eval` du corps).
- **D2 — La migration passe par l'AD, pas par HTTP.** Les tombstones n'installent rien
  et n'exécutent rien d'actif : servir du code exécutable sur un canal non authentifié
  est structurellement un C2 (leçon bibliothèque de capacités, D-36). Le **nettoyage des
  crochets legacy locaux au poste passe par l'agent** (canal authentifié, testé,
  idempotent, reporté) — story 38.3.
- **D3 — Extinction observable avant destruction.** `legacy_catchall_logs` +
  compteurs de hits tombstone = instrument de mesure. Étape « extinction à blanc »
  réversible (`a2dissite sambaedu-legacy` + `mv /var/www/sambaedu{,.off}`) obligatoire
  avant toute suppression — c'est elle qui fait remonter les appels résiduels du type
  firefox.php sans rien casser définitivement.
- **D4 — Legacy absent = 404, jamais 500.** Le catchall doit dégrader proprement.
- **D5 — Le sort des fonctions métier legacy est une décision produit par fonction**
  (ENT, quotas, partages cloud, stats), pas un effet de bord de l'extinction. Chaque
  abandon est acté et documenté ; chaque portage va au scheduler Laravel (cf. questions
  ouvertes Q2/Q3).
- **D6 — Les modules legacy in-repo survivent à l'extinction.** `legacy/modules/*`
  (BBB Tier 3, shim dhcp, …) servis par le catchall en containment `legacy/modules/`
  restent fonctionnels : leurs `require` doivent résoudre dans `legacy/` du repo (stubs
  in-repo), plus jamais via l'include_path vers `/var/www/sambaedu/includes`. Le retrait
  complet du catchall reste du ressort de l'Epic 14 (sortie du shim), pas de cet epic.

## Inventaire des routes appelées par les postes (source : repo legacy, balayage 2026-07-03)

Canal GPO applications (startup/logon/logoff/shutdown, réponse = script exécuté) :
`gpo/applications.php` (moteur central, params `os/action/user/machine/interpreter/…`,
se ré-appelle lui-même en phase `system` et en ack `ret=0`), `gpo/shortcuts_out.php`
(script + `action=file` .lnk/.desktop + `action=icon` .ico/.png),
`gpo/wallpaper_out.php` (image), `gpo/network_out.php` (bash, Linux),
`gpo/veyon_out.php`, `gpo/no_internet_out.php`, `gpo/associations_out.php`,
`gpo/firefox_out.php` (JSON policies), `gpo/thunderbird_out.php`,
`partages/cloud_out.php`, `gpo/del_roam.php` (bash, purge profils).

Canal WPKG legacy (wpkg.cmd → wpkg-client.vbs → wpkg-se4.js, réponse = XML) :
`wpkg/hosts_xml_out.php`, `wpkg/profiles_xml_out.php`, `wpkg/packages_xml_out.php`,
`wpkg/wpkg_log.php`. (`wpkg/linux_out.php` et `wpkg/winget_out.php` sont DÉJÀ natifs —
17.6 — et restent.) Les rapports WPKG clients passent par SMB, pas HTTP.

Canal install legacy (machine à états, réponse = .cmd/XML) : `ipxe/Win10/action.php`
(+ `/ipxe/<version>/action.php`), `ipxe/Win10/sysprep.xml.php`,
`ipxe/Win10/unattend.xml.php`, `ipxe/linux/action.php` (+ démon `autorun` Linux qui
`eval` le corps en boucle), `ipxe/sysrescuecd/*`, `ipxe/clonezilla/*`. Plus atteignable
depuis un boot neuf (dhcpd chaîne le natif, port 909 mort) — seul un poste en vol au
moment de l'extinction serait touché (acceptable, doctrine zéro-prod).

Divers : `printers/out_printers.php` (bash lpadmin, postes Linux),
`wpkg/download_prefix.php` (préfixes Wine, Linux). Zéro hit observé sur /vm — usage réel
à mesurer par instance (D3) avant de trancher leur tombstone (cf. Q4).

## Garde-fous d'epic (toutes stories)

- Toute route tombstone/native DOIT rester **avant le catchall** `{path}` — test
  garde-fou d'ordre de routes obligatoire (patron `WpkgOutRoutesTest`,
  `project_api_routes_arch_test_window_trap` pour routes/api.php).
- Réponses des endpoints « exécutés » : corps inerte uniquement, Content-Type exact,
  `withoutMiddleware(['web'])` (appels machine sans session/CSRF), protection
  `local.request` + throttle iso 17.6.
- Story agent Go (38.3) : bump `agent/shared/version.go` + publication manuelle
  (update.sh ne publie jamais ; piège « handler absent du binaire publié »).
- VM : migrations jamais auto-jouées (`migrate:status` avant e2e) ; `.env`/config →
  `config:cache` + chown ; **inotify ne sync pas les deletes** — les suppressions sur la
  VM se font à la main côté VM, et l'extinction à blanc est une opération VM pure.
- Ne PAS toucher `/etc/sambaedu`, `/usr/share/sambaedu`, `/var/sambaedu` dans cet epic
  (autres vagues de l'audit) — seule exception : les crons `/etc/cron.d/sambaedu-*`
  qui ne servent que le legacy web (38.5).
- Reco dev : **fable** pour 38.3 (agent Go) et 38.4 (GPO/Kerberos/sécurité), opus pour
  38.1, 38.2, 38.5 ; 38.6 = orchestration + ops, à mener en dev-cycle avec Henri.

## Questions ouvertes Henri (avant dev)

- **Q1 (38.2/38.3) — TRANCHÉE (Henri, session incident Firefox 2026-07-03 soir)** :
  tombstones purs + nettoyage par l'agent (« tuer ce process et s'y substituer avec
  l'agent »). Un poste non migré garde ses crochets jusqu'à sa migration (bootstrap
  GPO), leurs appels ne font plus rien — c'est le comportement cible 27.14.
- **Q2 (38.5)** — Fonctions ENT (`sync_cron`, `mfa_ent`, `test_ent`) : utilisées sur
  les instances cibles ? Si oui, le portage est un chantier propre (epic dédié, PAS
  38.5) ; si non, abandon acté. À trancher.
- **Q3 (38.5)** — Quotas (`repquota`), partages cloud (`rep_cloud_cron`), stats
  (`update_stats`), purge profils (`clean_profiles.sh`) : porter au scheduler Laravel
  (audit Vague 3) ou abandonner ? Défaut proposé : trancher par fonction en création de
  story ; le minimum livrable = retrait des crons + décision documentée.
- **Q4 (38.2)** — Postes Linux : le canal `applications.php os=linux` sert encore la
  config FF/TB des postes Linux non agentés (l'agent Linux est post-MVP,
  `project_linux_no_gpo_http_scripts`). Tombstoner ce canal coupe cette config aux
  postes Linux. Acceptable (parc Linux marginal / config par install) ou faut-il un
  équivalent natif minimal avant ? Idem `printers/out_printers.php`. Défaut proposé :
  mesurer les hits réels par instance (D3) et trancher sur donnée.
- **Q5 (38.3)** — Artefacts par-user Mozilla laissés par le canal : le fragment
  firefox du blob logon a écrit sur CHAQUE profil Windows des `profiles.ini`/
  `installs.ini` FORCÉS (`sambaedu.default`, `Locked=1`, hash install
  `308046B0AF4A39CB` ; idem Thunderbird). Une fois le canal éteint (38.2) et les
  crochets retirés (38.3), plus rien ne les réécrit NI ne crée le dossier → Firefox
  « profil manquant ou inaccessible » DÉFINITIF pour tout compte Windows n'ayant pas
  ouvert de session pendant la fenêtre du fix /etc (c'est l'incident du 2026-07-03 :
  tuer le crochet sans nettoyer son état laisse le parc cassé). Le nettoyage 38.3
  doit donc couvrir explicitement ces paires `.ini` (dans chaque `C:\Users\*`, quand
  elles référencent `sambaedu.default`). Cible produit à trancher : **(a) vanilla —
  supprimer les paires `.ini`, Firefox re-crée et gère son profil localement (défaut
  proposé, `feedback_no_overengineered_choices`)** ; (b) profil forcé local
  `sambaedu.default` posé par l'agent (iso-legacy poste perso) ; (c) profil dans le
  home = Mécanisme B roaming (hors epic 38 — story dédiée, cf. docblock
  `AppConfigStateProvider` « story roaming de suivi »).

## Epic List

### Epic 38 : Extinction SE4 — suppression de `/var/www/sambaedu`

Rendre le repo legacy supprimable sans casse : relocaliser ce que le vhost SE5 sert
encore depuis le legacy (38.1), répondre nativement et proprement à toutes les routes
que les postes appellent encore (38.2), nettoyer les crochets legacy des postes par
l'agent (38.3), sortir les `require` FS legacy du code serveur (38.4), débrancher les
crons et fonctions serveur résiduelles (38.5), puis éteindre à blanc, observer, et
supprimer définitivement (38.6). Séquencement : 38.1→38.4 indépendantes entre elles ;
38.5 après décisions Q2/Q3 ; 38.6 ferme la marche et est **gated par la Story 8.3**
(DHCP natif) et par le silence des logs d'extinction.

---

## Epic 38 : Extinction SE4 — suppression de `/var/www/sambaedu`

### Story 38.1 : Relocalisation des statiques iPXE + catchall dégradant proprement

En tant qu'exploitant,
je veux que plus rien de ce que sert Apache ne vive dans `/var/www/sambaedu`,
afin que la suppression du repo legacy ne touche ni le netboot ni le routing web.

**Acceptance Criteria:**

**Given** les statiques de boot (`boot.ipxe`, `undionly.kpxe`, `snponly_x64.efi`,
`diconf/`, `png/`) aujourd'hui sous `/var/www/sambaedu/ipxe`
**When** ils sont versionnés dans le repo SE5 (ex. `resources/ipxe/static/`) et
provisionnés par `update.sh` (patron `ensure_*`) vers un emplacement servi hors legacy
**Then** l'alias `/ipxe` de `scripts/setupApache.sh` pointe sur le nouvel emplacement,
`FallbackResource /index.php` conservé (les routes Laravel `/ipxe/boot`, `/ipxe/admin`,
enrollment… continuent de primer sur les URL sans fichier physique)
**And** la **racine TFTP** est vérifiée : si `tftpd`/dnsmasq sert `undionly.kpxe` /
`snponly_x64.efi` depuis `/var/www/sambaedu/ipxe`, elle est repointée — un poste BIOS et
un poste UEFI rebootent en PXE avec `/var/www/sambaedu` renommé (test e2e VM)
**And** `dhcpd.conf` reste inchangé (mêmes filenames, même URL de chain).

**Given** `LegacyCatchallController::handle` avec `legacy_path` absent du disque
**When** une URL non matchée arrive
**Then** réponse **404** (plus jamais `abort(500)`), toujours loggée
(`LEGACY_LOG_404`) — le monitoring d'extinction reste fonctionnel sans le FS legacy.

### Story 38.2 : Tombstones natifs du canal client legacy

En tant que responsable du parc,
je veux que chaque route encore appelée par un poste SE4 reçoive une réponse native
terminale correcte,
afin qu'aucun poste ne casse ni n'exécute du HTML d'erreur pendant et après l'extinction.

**Acceptance Criteria:**

**Given** les routes du canal client legacy (inventaire ci-dessus) : `gpo/
{applications,shortcuts_out,wallpaper_out,network_out,veyon_out,no_internet_out,
associations_out,firefox_out,thunderbird_out}.php`, `partages/cloud_out.php`,
`wpkg/{hosts,profiles,packages}_xml_out.php`, `wpkg/wpkg_log.php`,
`ipxe/Win10/{action,sysprep.xml,unattend.xml}.php`, `ipxe/linux/action.php`
(+ selon Q4 : `printers/out_printers.php`, `wpkg/download_prefix.php`)
**When** un poste les appelle (GET/POST, sans session)
**Then** chacune répond nativement AVANT le catchall, en **200 typé inerte** :
script no-op syntaxiquement valide selon `os`/`interpreter` (cmd/bash/ps1) pour les
endpoints exécutés, XML vide valide (`<wpkg/>`-like) pour les `*_xml_out`, 204 pour les
images (`wallpaper_out`), JSON vide valide pour firefox/thunderbird
**And** `gpo/del_roam.php` / `gpo/no_roam.php` conservent leurs redirections natives
existantes (early-returns du catchall).

**Given** l'observabilité d'extinction (D3)
**When** un tombstone est touché
**Then** le hit est journalisé avec route + machine/user/IP + horodatage (canal log ou
table dédiée), consultable/agrégeable pour mesurer l'extinction du troupeau — c'est le
critère GO de la 38.6.

**Given** le kill-switch `LEGACY_CONFIG_CHANNEL_ENABLED` (ne gate plus que
`wpkg/{linux,winget}_out.php`)
**When** les tombstones sont livrés
**Then** le flag et le middleware `EnsureLegacyConfigChannelEnabled` sont supprimés
(la sémantique 410 est remplacée par les tombstones) ; `linux_out`/`winget_out`
restent natifs, protégés `local.request` + throttle, inchangés fonctionnellement
**And** un test d'ordre de routes verrouille tombstones < catchall.

**Given** les correctifs transitoires de l'incident Firefox 2026-07-03 : entrée
`noop:` du catchall pour `gpo/shortcuts_out.php` (`blocked_legacy_routes` +
convention `noop:` de `LegacyCatchallController` — posée parce qu'un 302 CALLé par
cmd avortait le batch logon entier) et surcharge diagnostique VM
`/etc/sambaedu/applications/firefox/logon.windows` (MD `sambaedu.default` + ffdiag)
**When** le tombstone natif `gpo/applications.php` est en place (le blob n'est plus
servi, donc plus aucun fragment ne s'exécute)
**Then** l'entrée `noop:` de `shortcuts_out` est retirée de `blocked_legacy_routes`
(supersédée par le tombstone natif ; la convention `noop:` du contrôleur peut rester
comme mécanisme générique) et la surcharge `/etc` est retirée sur la VM (opération VM
pure — exception au garde-fou « ne pas toucher /etc/sambaedu », c'est notre artefact
d'incident, plus le paquet).

### Story 38.3 : Nettoyage des crochets legacy des postes via l'agent

En tant que responsable du parc,
je veux que l'agent retire des postes les crochets clients SE4 (curl applications,
déclencheurs WPKG legacy, helpers obsolètes),
afin que les postes migrés cessent d'appeler le canal legacy — par le canal authentifié,
pas par du code servi en HTTP (D2).

**Acceptance Criteria:**

**Given** un poste enrôlé dont l'état local contient des artefacts legacy (inventaire
précis en création de story — alimenté par le ffdiag v2, cf. Overview pt 3 : crochets
`applications-*.cmd`/tâches planifiées ou scripts GPO locaux qui curl-ent
`gpo/*.php`, déclencheurs `se4_wpkg` locaux, helpers `%ProgramFiles%\SambaEdu`
obsolètes, et paires `profiles.ini`/`installs.ini` forcées `sambaedu.default` dans
chaque `C:\Users\*` — Firefox ET Thunderbird, traitement selon Q5)
**When** l'agent converge (module de type Resource/Reconcile, patron tools-manifest
27.20, gated par un réglage serveur)
**Then** les artefacts sont supprimés de façon idempotente, l'action est rapportée
(reporting standard), et un poste sain (sans artefacts) ne rapporte rien
**And** `agent/shared/version.go` bumpé, publication manuelle rappelée dans la story,
e2e sur poste lab migré : plus AUCUN hit `gpo/*.php` dans les logs après convergence
+ reboot + logon.

### Story 38.4 : Sortie des `require` FS legacy du code serveur

En tant que dev,
je veux que plus aucun chemin de code SE5 ne fasse de `require`/`include` sous
`/var/www/sambaedu`,
afin que la suppression du repo ne puisse plus provoquer de fatal PHP.

**Acceptance Criteria:**

**Given** `AgentBootstrapPublisher::callLegacyImportGpo` (dépendance `import_gpo`
legacy, dette documentée)
**When** la publication de la GPO bootstrap est portée en natif (création GPO + copie
SYSVOL + `GPT.INI` version bump + `setLink` — `GpoService` natif existant + smbclient)
**Then** parité fonctionnelle stricte : publication + republication (bump `GPT.INI`,
`project_gpo_template_edit_needs_version_bump`), ticket Kerberos `www-admin` (keytab,
`project_sysvol_write_needs_wwwadmin_kinit`), pas de lien racine implicite
(`project_import_gpo_auto_root_link`), e2e VM : GPO republiée et appliquée.

**Given** `RemoteAccessService` (Guacamole), `RoamingProfileService`,
`ShortcutsService` (fallback :503), `LegacyConfigBridge` (:224-226)
**When** les fonctions legacy consommées sont portées (ou leurs fallbacks retirés
lorsqu'un équivalent natif existe déjà)
**Then** `legacy/bootstrap.php` ne référence plus `/var/www/sambaedu` (ni en
`require_once` ni en include_path) — les modules in-repo `legacy/modules/*` (BBB, shim
dhcp) résolvent leurs includes dans `legacy/` du repo exclusivement (D6) et restent
fonctionnels (tests des modules Tier 3 verts)
**And** `config('sambaedu.legacy_path')` n'est plus consulté par aucun chemin runtime
hors catchall/monitoring, `policies_temp_path` (config morte) supprimée.

### Story 38.5 : Débranchement des crons et fonctions serveur résiduelles

En tant qu'exploitant,
je veux que plus aucun cron ni écran SE5 n'invoque le web legacy,
afin que le serveur soit fonctionnellement autonome avant l'extinction à blanc.

**Acceptance Criteria:**

**Given** les décisions Q2/Q3 tranchées (ENT, quotas, cloud, stats, clean_profiles)
**When** les crons `/etc/cron.d/sambaedu-{web-common,wpkg,shares,boot-server}` sont
retirés (install/update.sh ne les provisionne plus ; `make_dhcpd_conf.sh` remplacé par
la Story 8.3)
**Then** chaque fonction est soit portée au scheduler Laravel (story ou epic dédié
référencé), soit abandonnée avec trace écrite (doc + backlog), et plus AUCUN POST
`*.php` legacy n'apparaît dans les logs Apache serveur (les crons étaient la
majorité du trafic : `sync_cron`, `update_stats`, `rep_cloud_cron`, `wpkg_*`…).

**Given** l'embed legacy (`LegacyEmbedService`) et sa dernière route
(`/users/groups/legacy-new` → `annu2/add_group.php`, + titre `annu/import_gpei.php`)
**When** la création de groupe et l'import GPEI sont soit portés en natif, soit
débranchés (décision en création de story, `feedback_understand_business_before_design`)
**Then** `LegacyEmbedService`/`LegacyEmbedController` sont supprimés du repo.

### Story 38.6 : Extinction à blanc, observation, suppression définitive

En tant que Henri,
je veux éteindre le legacy de façon réversible, observer, puis supprimer,
afin de constater toutes les erreurs résiduelles (classe firefox.php) sans rien casser
définitivement.

**Acceptance Criteria:**

**Given** 38.1→38.5 done, Story 8.3 livrée (DHCP natif), et les tombstones en place
**When** l'extinction à blanc est exécutée sur la cible (`a2dissite sambaedu-legacy` +
`systemctl reload apache2` + `mv /var/www/sambaedu /var/www/sambaedu.off`)
**Then** procédure + rollback (une commande) documentés (runbook), le parc fonctionne :
boot PXE, install Windows native, logon avec agent, WPKG natif, Guacamole, GPO
bootstrap — vérifiés e2e
**And** période d'observation N jours : `legacy_catchall_logs` + logs tombstones
analysés ; **critère GO** = zéro appel legacy non-tombstone ; tout hit inattendu est
traité (nouvelle story ou fix) avant de continuer.

**Given** le critère GO atteint
**When** la suppression définitive est faite (`trash /var/www/sambaedu.off` — jamais
rm -rf) et le code nettoyé
**Then** sont retirés : le proxy catchall vers le vhost legacy (`legacy_base_url`,
vhost `sambaedu-legacy.conf` de `setupApache.sh`, `scripts/restoreLegacyApache.sh`,
`scripts/old/*`), les env `LEGACY_PATH`/`LEGACY_BASE_URL` (+ entrées `.env.example`),
la connexion `LEGACY_DB_*` (one-shot quota déjà consommé)
**And** le catchall lui-même reste UNIQUEMENT pour `legacy/modules/*` in-repo (D6 —
son retrait complet = Epic 14), `docs/audit-dependances-systeme.md` Vague 4 marquée
soldée, doc domaine mise à jour (`feedback_doc_follows_code`).
