# Ultradev Epic 38 — Questions bloquantes et décisions

Run ultradev démarré le 2026-07-10.

## Q1 — Auto-nettoyage des crochets (38.2/38.3)

**Statut : TRANCHÉE en amont** (Henri, session incident Firefox 2026-07-03 soir) : tombstones purs + nettoyage par l'agent. Cf. epics-extinction-se4.md.

## Q2 — Fonctions ENT (sync_cron, mfa_ent, test_ent) — 38.5

**Posée le 2026-07-10 (AskUserQuestion). Réponse Henri : « Abandon acté »** — crons retirés, abandon documenté (doc + backlog), réouvrable en epic dédié si besoin réel.

## Q3 — Quotas / cloud / stats / purge profils — 38.5

**Posée le 2026-07-10. Réponse Henri : « à vérifier — il me semble qu'on a déjà un cron hors legacy pour les quotas. Pour les autres, voir ce que ça fait exactement ; a priori il faudra quand même les porter. »**

Reconnaissance orchestrateur (2026-07-10) :
- **Quotas** : CONFIRMÉ — SE5 a déjà `quota:snapshot` quotidien 03h00 (story 5.1b, `app/Console/Kernel.php:97`, parse `xfs_quota -x -c 'report'` → `users.quota_snapshot`) + `trash:purge` togglable. Le cron legacy `infos/repquota.php` (toutes les 4 min, `repquota(true)`) est le rafraîchissement legacy équivalent → **abandonnable** (la fonction est portée depuis 5.1b).
- **update_stats** (toutes les min) : agrège `connexions` (MySQL stats legacy) en quarts d'heure pour les graphes de fréquentation SE4. SE5 a son propre signal de présence (check-in agent + shutdown). Portage = décision produit (stats de fréquentation natives ?).
- **rep_cloud_cron** (toutes les min + 5h05 action=cloud) : sync des partages vers Nextcloud (`apply_user_cloud`, comptes cloud). Recoupe la direction « Plan fichiers — SMB-first, NC ensuite » : le portage relève du chantier Nextcloud (epic dédié), pas de 38.5.
- **clean_profiles.sh** (02h00, script du paquet `/usr/share/sambaedu/sbin`) : purge de profils — SE5 a `profiles:snapshot` (ProfilesSnapshotCommand) ; la purge elle-même à évaluer.

**Décision retenue (à confirmer fin de vague 2)** : 38.5 = minimum livrable (retrait des crons + décision par fonction documentée) ; portages actés comme stories/epics dédiés : stats natives (produit), cloud→chantier NC, quotas déjà portés (5.1b), clean_profiles à évaluer.

## Q4 — Canal Linux + printers/out_printers.php + wpkg/download_prefix.php — 38.2

**Posée le 2026-07-10. Réponse Henri : « pour les hits il faut surtout vérifier auprès de /lab1 étab. Que fait download_prefix ? »**

Reconnaissance orchestrateur :
- **download_prefix.php** = miroir local des préfixes Wine : appelé par les clients Linux à l'installation du package `sambaedu-application` d'une appli Wine ; le serveur télécharge `deb.sambaedu.org/wpkg/files/wine/<prefix>.img` (+ .sha256) vers `/var/sambaedu/unattended/install/wine/`. **Absent du /var/www/sambaedu déployé sur la VM de dev** (présent uniquement dans le repo legacy).
- **Mesure lab1** : `ssh -p 2221 root@192.168.101.2` INJOIGNABLE le 2026-07-10 (timeout). À retenter avant la vague 2 (38.2). Si toujours indisponible : à mesurer manuellement.

**Statut : OUVERTE** — tranchée sur données lab1 avant le dev de 38.2.

**2026-07-10 (fin vague 1)** : lab1 injoignable → question re-posée à Henri. Réponse : « lab1 est dispo à présent » → **MESURE FAITE** (~2 semaines de logs Apache lab1 étab, se4fs-0991229y) :
- Canal encore vivant : applications.php **250**, shortcuts_out 32, firefox_out 17, wallpaper_out 14, hosts/profiles_xml_out 5+5 → confirme la nécessité des tombstones.
- `download_prefix.php` : **0 hit** ; `cloud_out.php` : **0 hit** → tombstonés.
- `out_printers.php` : 2 hits, `network_out.php` : 2 hits — **poste Linux actif réel** (172.20.1.101, curl, dernier 2026-06-26) → canal Linux EN USAGE sur lab1.

**DÉCISION (périmètre sûr, sur donnée)** : 38.2 tombstone tout le canal Windows + wpkg xml_out + install + download_prefix + cloud_out ; **exception bornée** : `applications.php os=linux`, `gpo/network_out.php`, `printers/out_printers.php` restent proxifiés via le catchall (fonctionnels + mesurés), critère de sortie = extinction mesurée du poste Linux ou agent Linux (post-MVP). Documenté dans la story 38.2.

## Remontée vague 1 (info, pas une question) — GPO domaine « applications » pleine

Le ffdiag v2 (dépouillé en création de story 38.3) révèle que sur le DC de dev, la GPO de domaine « applications » `{D418994B-0F25-4C3D-8627-4EB4F913BC12}` est **PLEINE et liée à la racine** (contredit l'hypothèse « coquille vide » de l'Overview d'epic) : scripts logon/logoff/startup/shutdown qui curl-ent `gpo/applications.php` + GPP ScheduledTasks (`removePolicy=0`). Conséquence : le critère GO « zéro hit » de la 38.6 exige la **neutralisation de la GPO sur la VM dev** : la VIDER (coquille + bump GPT.INI) — **jamais la délier ni la supprimer** (GPO globale multi-étabs, précision Henri 2026-07-10). C'est le mécanisme STANDARD du legacy : import de coquilles vides `se4_*.zip` depuis `/usr/share/sambaedu/gpo/` par le script d'install/update — c'est l'état constaté sur lab1 (coquilles). La VM dev n'a pas ces zips (seul `sambaedu-gpo/SE_agent_bootstrap` présent) : la mise à jour neutralisante n'y est jamais passée, d'où la GPO restée pleine. Reproduire la coquille via l'outillage natif 38.4 (NativeGpoPublisher) ou le shell zip legacy. Consigné au runbook QA agent § 38.3 et à intégrer à la 38.6.

## Q5 — Paires profiles.ini/installs.ini forcées sambaedu.default — 38.3

**Posée le 2026-07-10. Réponse Henri : « Vanilla »** (option (a) du cadrage) — l'agent supprime les paires .ini référençant `sambaedu.default` (Firefox ET Thunderbird) ; les navigateurs recréent et gèrent leur profil localement. Pas de profil forcé posé par l'agent.
