// ─── Données ──────────────────────────────────────────────────────────────────
// Deux datasets : "sambaedu" (SER / cloisonnement legacy + refonte native)
//                 "central"  (controlHub / irundoo)
//
// Champs supportés par epic :
//   num, title, status, summary (texte court affiché au dépliage), stories[], retro
// Champs supportés par story :
//   id, title, status, note (optionnel — précise l'avancement réel ou un blocage)

const DATASETS = {
  "sambaedu": [
    {
      "num": 1,
      "title": "Fondations & Observabilité",
      "status": "done",
      "summary": "Fondations techniques : import MySQL → Postgres, catchall legacy pour bloquer les routes migrées, dashboard d'observabilité, AuthGuard, et réimplémentation native des actions machines.",
      "stories": [
        {
          "id": "1-1",
          "title": "Import des données MySQL vers le schéma PostgreSQL",
          "status": "done"
        },
        {
          "id": "1-2",
          "title": "Catchall Legacy + Blocage des Routes Migrées",
          "status": "done"
        },
        {
          "id": "1-3",
          "title": "Dashboard Legacy Monitor",
          "status": "done"
        },
        {
          "id": "1-4",
          "title": "Interface AuthGuard",
          "status": "done"
        },
        {
          "id": "1-5",
          "title": "Réimplémentation Native des Actions Power Machines",
          "status": "done"
        }
      ]
    },
    {
      "num": "1bis",
      "title": "Cloisonnement Legacy",
      "status": "done",
      "summary": "Couche de compatibilité qui isole le PHP legacy SambaEdu derrière des shims (LDAP→Eloquent, SQL→Eloquent) et des wrappers module par module. Permet de faire cohabiter legacy et refonte native le temps de migrer chaque surface fonctionnelle. Certains modules gèrent aussi leur refonte native dans les Epics dédiés (2, 4, 5, 6, 9).",
      "stories": [
        {
          "id": "1bis-1",
          "title": "Error Logger & Module Dashboard",
          "status": "done"
        },
        {
          "id": "1bis-2",
          "title": "Bootstrap & Shim LDAP→Eloquent",
          "status": "done"
        },
        {
          "id": "1bis-3",
          "title": "Shim SQL MySQL→Eloquent",
          "status": "done"
        },
        {
          "id": "1bis-4",
          "title": "Module display",
          "status": "done"
        },
        {
          "id": "1bis-5",
          "title": "Module oauth2",
          "status": "done"
        },
        {
          "id": "1bis-6",
          "title": "Modules sso + cas",
          "status": "done"
        },
        {
          "id": "1bis-7",
          "title": "Module api",
          "status": "done"
        },
        {
          "id": "1bis-8",
          "title": "Module user",
          "status": "cancelled"
        },
        {
          "id": "1bis-9",
          "title": "Module dossier_echange",
          "status": "done"
        },
        {
          "id": "1bis-10",
          "title": "Module ipxe",
          "status": "done"
        },
        {
          "id": "1bis-11",
          "title": "Module wpkg",
          "status": "superseded"
        },
        {
          "id": "1bis-12",
          "title": "Module annu2",
          "status": "cancelled"
        },
        {
          "id": "1bis-13a",
          "title": "Module parcs2",
          "status": "cancelled"
        },
        {
          "id": "1bis-13b",
          "title": "Module acls",
          "status": "cancelled"
        },
        {
          "id": "1bis-14",
          "title": "Module partages",
          "status": "superseded"
        },
        {
          "id": "1bis-15",
          "title": "Module printers",
          "status": "superseded"
        },
        {
          "id": "1bis-16",
          "title": "Module dhcp",
          "status": "done"
        },
        {
          "id": "1bis-17",
          "title": "Module bbb",
          "status": "done"
        },
        {
          "id": "1bis-18a",
          "title": "Module gpo — Includes GPO core (fondation)",
          "status": "done"
        },
        {
          "id": "1bis-18b",
          "title": "Module gpo — Interface gestion GPO (import/export)",
          "status": "done"
        },
        {
          "id": "1bis-18c",
          "title": "Module gpo — Configuration apps (Firefox, Thunderbird)",
          "status": "cancelled"
        },
        {
          "id": "1bis-18d",
          "title": "Module gpo — Fond d'écran et personnalisation",
          "status": "cancelled"
        },
        {
          "id": "1bis-18e",
          "title": "Module gpo — Scripts réseau, Veyon, Wine, associations",
          "status": "done"
        },
        {
          "id": "1bis-18f",
          "title": "Module gpo — Profils itinérants (roaming)",
          "status": "done"
        },
        {
          "id": "1bis-18g",
          "title": "Module gpo — Shims LDAP / SYSVOL (search_ad, modify_ad, Kerberos bridge)",
          "status": "done"
        },
        {
          "id": "1bis-19",
          "title": "Module infos",
          "status": "superseded"
        }
      ]
    },
    {
      "num": 2,
      "title": "Gestion des Utilisateurs SER",
      "status": "done",
      "summary": "Refonte native complète de la gestion des utilisateurs SER : création/provisioning, modifications, désactivation/suppression, statut itinérant, changements de rôle avec déplacement DN.",
      "stories": [
        {
          "id": "2-1",
          "title": "Création et Provisioning d'un Compte Utilisateur",
          "status": "done"
        },
        {
          "id": "2-2",
          "title": "Modification des Attributs d'un Utilisateur",
          "status": "done"
        },
        {
          "id": "2-3",
          "title": "Désactivation et Suppression d'un Compte Utilisateur",
          "status": "done"
        },
        {
          "id": "2-4",
          "title": "Affichage et Gestion du Statut Itinérant",
          "status": "done"
        },
        {
          "id": "2-5",
          "title": "Changement Rôle/Fonction — Déplacement DN",
          "status": "done"
        },
        {
          "id": "2-6",
          "title": "Réinitialisation des Mots de Passe en Masse",
          "status": "done"
        }
      ]
    },
    {
      "num": 3,
      "title": "Système iPXE — Boot réseau & Déploiement OS",
      "status": "done",
      "category": "post-prod",
      "summary": "Refonte native du stack iPXE : service core, menu admin, enrollment machines, install Linux/Windows, gestion ISO, clonage, post-OOBE flows. <strong>Epic clôturé 2026-05-25</strong> — 8/8 stories done (3-1..3-8), code mergé sur main. Reste Henri post-merge : <code>php artisan migrate</code> + smoke iPXE LAN poste réel (Doc QA §15 ISO + §17 post-OOBE).",
      "stories": [
        {
          "id": "3-1",
          "title": "iPXE Service Core",
          "status": "done"
        },
        {
          "id": "3-2",
          "title": "Boot et Menu Admin iPXE",
          "status": "done"
        },
        {
          "id": "3-3",
          "title": "Enrollment Machine — Parcs, Salles, Nommage",
          "status": "done"
        },
        {
          "id": "3-4",
          "title": "Installation Linux (Debian/Ubuntu)",
          "status": "done"
        },
        {
          "id": "3-5",
          "title": "Installation Windows (Sysprep/Wimboot)",
          "status": "done"
        },
        {
          "id": "3-6",
          "title": "Gestion ISO Windows",
          "status": "done"
        },
        {
          "id": "3-7",
          "title": "Clonage et Maintenance",
          "status": "done"
        },
        {
          "id": "3-8",
          "title": "Installation Windows post-OOBE flows (sysprep/nosysprep/join/renomme/post/wpkg)",
          "status": "done"
        },
        {
          "id": "3-9",
          "status": "backlog",
          "title": "Mode LTSP — boot des postes sans disque"
        }
      ]
    },
    {
      "num": 4,
      "title": "Gestion des Machines, WorkstationGroups & AppProfiles SER",
      "status": "done",
      "summary": "Inventaire et actions natives sur machines : vue par groupe physique et WorkstationGroup, actions unitaires avec feedback readiness, actions batch, crons planifiés, import CSV, association de profils d'applications.",
      "stories": [
        {
          "id": "4-1",
          "title": "Inventaire des Machines par Groupe Physique et WorkstationGroup",
          "status": "done"
        },
        {
          "id": "4-2",
          "title": "Actions Unitaires sur une Machine + Feedback Readiness",
          "status": "done"
        },
        {
          "id": "4-3",
          "title": "Actions Batch sur un WorkstationGroup",
          "status": "done"
        },
        {
          "id": "4-4",
          "title": "Crons Planifiés sur un WorkstationGroup",
          "status": "done"
        },
        {
          "id": "4-5",
          "title": "Import de Machines et Groupes Physiques depuis CSV",
          "status": "done"
        },
        {
          "id": "4-6",
          "title": "Association AppProfile à des Postes et WorkstationGroups",
          "status": "done"
        },
        {
          "id": "4-7",
          "title": "Gestion des Fonds d'Écran (Wallpapers) — Eloquent polymorphe + Capture legacy",
          "status": "done"
        },
        {
          "id": "4-8",
          "title": "Personnalisation Apps Extensible (Firefox/Thunderbird policies + extensions)",
          "status": "review"
        },
        {
          "id": "4-9",
          "title": "Sync AD machine via observer Eloquent + LdapRecord modrdn",
          "status": "review"
        },
        {
          "id": "4-10",
          "title": "Auth iPXE — restauration validation user/password + permissions",
          "status": "review"
        },
        {
          "id": "4-11",
          "title": "Unification appartenance poste↔groupe dans le pivot global (drop FK physical_room_id)",
          "status": "done"
        },
        {
          "id": "4-12",
          "status": "done",
          "title": "Peuplement des groupes AD Equipe_X par rôle (parité SE4 — ACL prof effectives)"
        },
        {
          "id": "4-13",
          "status": "done",
          "title": "Fold de l'import AD : une classe = une ligne user_groups au nom nu"
        },
        {
          "id": "4-14",
          "status": "done",
          "title": "Migration data (fusion lignes héritées) + colonne is_head_teacher sur l'arête"
        },
        {
          "id": "4-15",
          "status": "done",
          "title": "Écriture SQL→AD PP_<X> pilotée par is_head_teacher + UI Professeur principal"
        },
        {
          "id": "4-16",
          "status": "done",
          "title": "Scoper le syncFromAd global de updateGroup (onlyGroupNames) — dette LDAP"
        },
        {
          "id": "4-17",
          "status": "backlog",
          "title": "Canonicaliser le name de la ligne foldée (non-déterminisme casse mixte) — follow-up #8 review 4-16"
        }
      ]
    },
    {
      "num": 5,
      "title": "Système de Fichiers SER",
      "status": "done",
      "summary": "Gestion native des répertoires utilisateurs (home directories + quotas XFS) et des partages de classe avec ACLs POSIX.",
      "stories": [
        {
          "id": "5-1",
          "title": "Gestion des Home Directories et Quotas XFS — SPLITTÉE 2026-04-22",
          "status": "cancelled"
        },
        {
          "id": "5-1a",
          "title": "Refactor Services Filesystem (HomeDirService + XfsQuotaService)",
          "status": "done"
        },
        {
          "id": "5-1b",
          "title": "Snapshot quotas quotidien + UI utilisateur",
          "status": "done"
        },
        {
          "id": "5-1c",
          "title": "Quotas groupes + /admin/settings scaffold + flash over-quota",
          "status": "done"
        },
        {
          "id": "5-1d",
          "title": "Gaps produits : default_itinerant + purge trash + seed legacy",
          "status": "review"
        },
        {
          "id": "5-2",
          "title": "Partages de Classe et Gestion des ACLs POSIX",
          "status": "done"
        }
      ]
    },
    {
      "num": 6,
      "title": "Impression SER",
      "status": "done",
      "category": "post-prod",
      "summary": "Administration des imprimantes CUPS (consultation, gestion) et des pilotes Windows nécessaires aux déploiements client. <strong>6.1 livrée 2026-04-29, 6.2 corrections review appliquées 2026-05-20</strong> — validation VM E2E en attente (VM injoignable au dev).",
      "stories": [
        {
          "id": "6-1",
          "title": "Consultation, Gestion et Rattachement Parc des Imprimantes CUPS",
          "status": "done"
        },
        {
          "id": "6-2",
          "title": "Gestion des Pilotes Windows",
          "status": "done"
        }
      ]
    },
    {
      "num": 7,
      "title": "Délégations & Permissions Applicatives SER",
      "status": "done",
      "summary": "Droits délégués par périmètre (Spatie) : attribution par scope métier, calcul et application des permissions côté UI et API.",
      "stories": [
        {
          "id": "7-1",
          "title": "Attribution de Droits Délégués sur un Périmètre",
          "status": "done"
        },
        {
          "id": "7-2",
          "title": "Calcul et Application des Droits Spatie",
          "status": "done"
        },
        {
          "id": "7-3",
          "title": "Migration Production : bitmask legacy → rôles Spatie",
          "status": "done"
        }
      ]
    },
    {
      "num": 8,
      "title": "Réseau (DHCP/DNS) SER",
      "status": "done",
      "category": "post-prod",
      "summary": "Gestion des réservations DHCP et consultation des baux actifs depuis l'interface SER. <strong>Reportée post-prod</strong> — le shim <code>1bis-16 dhcp</code> (SHIM EXPRESS ~2h) couvre le besoin MVP, la refonte native est donc différée.",
      "stories": [
        {
          "id": "8-1",
          "title": "Gestion des Réservations DHCP et Baux Actifs",
          "status": "done"
        }
      ]
    },
    {
      "num": "8.2",
      "title": "Installation d'applications depuis le dépôt",
      "status": "done",
      "summary": "Orchestration du téléchargement et du post-traitement des applications depuis le dépôt XML : parsing recipes, vérification SHA-512, téléchargements multi-fichiers, actions delete/untar/unzip, purge et fix packages.xml.",
      "stories": [
        {
          "id": "8-2.1",
          "title": "Extraction des services depuis AppStoreService",
          "status": "done"
        },
        {
          "id": "8-2.2",
          "title": "Purge apps disparues du dépôt + fix packages.xml",
          "status": "done"
        },
        {
          "id": "8-2.3",
          "title": "Téléchargement XML recipe + vérification SHA-512",
          "status": "done"
        },
        {
          "id": "8-2.4",
          "title": "Téléchargement multi-fichiers avec hash et skip",
          "status": "done"
        },
        {
          "id": "8-2.5",
          "title": "Post-traitement (delete, untar, unzip)",
          "status": "done"
        },
        {
          "id": "8-2.6",
          "title": "Orchestration complète + nettoyage",
          "status": "done"
        },
        {
          "id": "8-2.7",
          "title": "Téléchargement non-bloquant au catalogue — installation WPKG en queue + progression Livewire",
          "status": "done"
        }
      ]
    },
    {
      "num": 9,
      "title": "Déploiement Windows SER",
      "status": "done",
      "summary": "Déploiement Windows natif : GPOs, WPKG (packages + association aux profils), scripts de démarrage, logs et rapports d'installation avec parsing détaillé des erreurs.",
      "stories": [
        {
          "id": "9-1",
          "title": "Gestion des GPOs",
          "status": "cancelled"
        },
        {
          "id": "9-2",
          "title": "Gestion des Packages WPKG et Association aux Profils",
          "status": "done"
        },
        {
          "id": "9-3",
          "title": "Gestion des Scripts de Démarrage Windows",
          "status": "cancelled"
        },
        {
          "id": "9-4",
          "title": "Logs WPKG et Rapports d'Installation",
          "status": "done"
        },
        {
          "id": "9-5",
          "title": "Parsing Logs WPKG et Affichage Détaillé des Erreurs",
          "status": "done"
        }
      ]
    },
    {
      "num": 10,
      "title": "Dépôt application controlHub",
      "status": "backlog",
      "summary": "Gestion des applications autorisées sur le SER (standalone) et passerelle avec le dépôt controlHub pour récupérer les applications validées.",
      "stories": [
        {
          "id": "10-1",
          "title": "Gestion des Apps Autorisées (Standalone vs controlHub)",
          "status": "backlog"
        },
        {
          "id": "10-2",
          "status": "backlog",
          "title": "Profils par défaut et rôles imposés par controlHub"
        }
      ]
    },
    {
      "num": 11,
      "title": "Gestion des Établissements, Itinérants & Intégrations irundoo",
      "status": "backlog",
      "summary": "Liens user↔UAI, attributs itinérants, import GPEI avec dispatch par UAI, et infrastructure Phase 2 pour recevoir les users depuis controlHub (Keycloak).",
      "stories": [
        {
          "id": "11-1",
          "title": "Gestion des Liens Utilisateur↔UAI",
          "status": "backlog"
        },
        {
          "id": "11-2",
          "title": "Gestion des Attributs Itinérants par Lien user↔UAI",
          "status": "backlog"
        },
        {
          "id": "11-3",
          "title": "Import GPEI et Dispatch par UAI",
          "status": "backlog"
        },
        {
          "id": "11-4",
          "title": "Infrastructure de Réception Users depuis controlHub (Phase 2)",
          "status": "backlog"
        },
        {
          "id": "11-5",
          "title": "Filtrage et Transmission par UAI vers chaque SER (Phase 2 Keycloak)",
          "status": "backlog"
        }
      ]
    },
    {
      "num": 12,
      "title": "Revue par l'équipe",
      "status": "in-progress",
      "summary": "Epic de gouvernance « rolling ». Point de revue collaborative récurrente avec l'équipe terrain (responsable de collège, RefNum, enseignants…) pour rouvrir et amender la matrice profils × droits applicatifs SER. Chaque story 12.x = itération de validation déclenchée par un retour terrain, un nouveau profil métier, ou une question soulevée par une story Epic 7 ou métier. Output canonique = <code>_bmad-output/planning-artifacts/profiles-rights-matrix.md</code> (référence vivante).",
      "stories": [
        {
          "id": "12-1",
          "title": "Matrice Profils × Droits Applicatifs",
          "status": "in-progress"
        }
      ]
    },
    {
      "num": 13,
      "title": "Refonte BBB & Compat BBB 3.x",
      "status": "backlog",
      "category": "post-prod",
      "summary": "<strong>Post-prod, deferred.</strong> Réécrire le module de visioconférence BigBlueButton en Laravel natif et le rendre compatible BBB serveur 3.x. Aujourd'hui shimmé Tier 3 via fork <code>sambaedu/bigbluebutton-api-php</code> 2.0.12 (cible BBB 2.4–2.6), passwords de salon supprimés en BBB 3.0, APCu bloquant, config serveurs en CSV. Cadrage étudié 2026-04-20, stories à détailler au démarrage. Cf. Epic 13 dans epics.md.",
      "stories": [
        {
          "id": "13-1",
          "title": "Modèles Eloquent bbb_servers / bbb_meetings / bbb_guest_tokens + migration config CSV legacy",
          "status": "backlog"
        },
        {
          "id": "13-2",
          "title": "Interface BbbClient + impl BbbClientV3 (lib littleredbutton/bigbluebutton-api-php 5.x, passwords→role, disabledFeatures)",
          "status": "backlog"
        },
        {
          "id": "13-3",
          "title": "Routes filesystem Laravel + pages Livewire (config admin, form prof, liste/join, records)",
          "status": "backlog"
        },
        {
          "id": "13-4",
          "title": "Invités externes : tokens signés Laravel remplaçant visio_ext APCu",
          "status": "backlog"
        },
        {
          "id": "13-5",
          "title": "Suppression module legacy legacy/modules/bbb/ + nettoyage shim legacy/stubs/bbb.inc.php",
          "status": "backlog"
        }
      ]
    },
    {
      "num": 14,
      "title": "Refactoring & Sortie du Shim Legacy (optionnel pour la mise en prod)",
      "status": "in-progress",
      "summary": "Dette technique + sortie progressive du shim legacy (legacy/modules/) vers natif Laravel avec parité fonctionnelle stricte. Aucune story 14.x ne livre de changement fonctionnel observable. Garde-fou : si une story 14.x embarque du fonctionnel, elle est requalifiée vers son epic métier. La suppression du proxy legacy + retrait du catchall ne comptent pas comme un changement observable tant que la parité est respectée. Cf. Epic 14 dans epics.md.",
      "stories": [
        {
          "id": "14-1",
          "title": "Isoler le DTO App\\Types\\User au pipeline LDAP→SQL",
          "status": "ready-for-dev"
        },
        {
          "id": "14-2",
          "title": "Module display (écrans d'affichages) natif",
          "status": "paused"
        },
        {
          "id": "14-3",
          "title": "Module oauth2 natif Laravel",
          "status": "paused"
        },
        {
          "id": "14-4",
          "title": "Filtres « quota dépassé » et « mot de passe par défaut » sur /users",
          "status": "done"
        },
        {
          "id": "14-5",
          "title": "Page /admin/system avec onglets monitoring système",
          "status": "backlog"
        },
        {
          "id": "14-6",
          "title": "Audit + refonte fix_se4.php (dernier fichier infos/)",
          "status": "backlog"
        },
        {
          "id": "14-7",
          "title": "Page d'accueil /app/home avec dispatch par rôle Spatie",
          "status": "ready-for-dev"
        }
      ]
    },
    {
      "num": 15,
      "title": "Pipeline de Déploiement WPKG natif",
      "status": "done",
      "summary": "Pipeline de distribution effective WPKG sur les postes : génération hosts.xml/profiles.xml/.ini par poste depuis Eloquent (source de vérité unique), sync AD périodique hors hot path, UI assignation apps, ingestion rapports clients native, dashboard état déploiement. Channel logs `wpkg-deploy` isolé. Stratégie : port legacy + adaptation Eloquent pour XML/.ini, réécriture pure UI/API/dashboard. Ajouté 2026-05-01 (PM session henri).",
      "stories": [
        {
          "id": "15-1",
          "title": "Fondations Pipeline Déploiement WPKG",
          "status": "done"
        },
        {
          "id": "15-2",
          "title": "Generators XML + .ini par poste",
          "status": "done"
        },
        {
          "id": "15-3",
          "title": "Modèle Eloquent suffisant pour le déploiement WPKG",
          "status": "done"
        },
        {
          "id": "15-4",
          "title": "UI admin assignation apps WPKG",
          "status": "done"
        },
        {
          "id": "15-5",
          "title": "Pipeline rapports clients + Dashboard état déploiement",
          "status": "done"
        },
        {
          "id": "15-6",
          "title": "Réglages de déploiement WPKG configurables au runtime (UI admin)",
          "status": "done"
        },
        {
          "id": "15-7",
          "title": "Bascule production + retrait shim WPKG legacy",
          "status": "cancelled"
        }
      ]
    },
    {
      "num": 16,
      "title": "Gestion native des GPOs",
      "status": "done",
      "summary": "Réécriture native du module GPO Samba (legacy `sambaedu/gpo/`, actuellement shimé via 1bis-18). UI Livewire pour listing, lecture, édition par sections (Network proxy, Veyon, Wine, Associations apps, Applications scripts — Firefox/Thunderbird/Wallpaper déjà refondus par Stories 4.7/4.8, exposés en navigation seule), CRUD GPO, liaison OU/WorkstationGroup, hook GPO → invocation `wpkg.js` (jonction Epic 15). Channel logs `gpo` (large : lecture/écriture/audit/sync/deploy) avec convention verbeuse par `action_type` transverse à l'epic. <strong>🟡 Phase 1 livrée</strong> (stories 16-1 à 16-7 développées + reviewées, <strong>0 testée</strong> au 2026-05-15). <strong>🚀 Phase 2 cadrée 2026-05-15, ajustée 2026-05-19</strong> (cf. <code>_bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md</code> + <code>sprint-change-proposal-2026-05-19.md</code>) : 8 nouvelles stories 16-8 à 16-14 — stabilisation tests Phase 1 + sécurisation HTTPS/JWT + auto-bootstrap migration postes + logs DB/UI + exposition endpoints natifs /api/v1/* + module migration simplifié SE4→SE5 + UX UI admin. <strong>Fusion logique Epic 16+17</strong> pour auth/migration/logs. Charge totale Phase 2 ~29-39j parallélisable. Décisions D1-D9 : UI Livewire conservée sous /admin/settings, HTTPS+JWT (pas mTLS), auto-bootstrap idempotent, CA root indépendant par étab, pas d'image immuable (mais design « agent-ready » pour Phase 3 et controlHub long terme). <strong>2026-05-11 — Story 16-3 splittée en 16-3a/b/c</strong> suite à l'audit 16-1 (cadrage initial sous-évalué) ; 16-3a livrée par dev sonnet + review opus + 11/13 corrections appliquées (commit <code>b59eddb</code>). DNS et réplication AD restent hors scope (réplication = central-only ; DNS local déjà géré en cascade depuis DHCP/PXE/parcs).",
      "stories": [
        {
          "id": "16-1",
          "title": "Fondations GPO natives + audit legacy",
          "status": "done"
        },
        {
          "id": "16-2",
          "title": "Listing & lecture GPO (UI native)",
          "status": "done"
        },
        {
          "id": "16-3a",
          "title": "Liens profonds vers sections déjà natives",
          "status": "done"
        },
        {
          "id": "16-3b",
          "title": "Endpoints runtime network_out + veyon_out (natifs)",
          "status": "done"
        },
        {
          "id": "16-3c",
          "title": "Wine + Associations apps",
          "status": "done"
        },
        {
          "id": "16-7",
          "title": "Portage natif applications.php (scripts startup/logon)",
          "status": "done"
        },
        {
          "id": "16-4",
          "title": "Création / duplication / suppression de GPO",
          "status": "cancelled"
        },
        {
          "id": "16-5",
          "title": "Liaison GPO ↔ OU / parc + propagation",
          "status": "done"
        },
        {
          "id": "16-6",
          "title": "Hook GPO → invocation wpkg.js côté client (jonction Epic 15)",
          "status": "done"
        },
        {
          "id": "16-8",
          "title": "Stabilisation Phase 1 — exécution tests + audit iso-legacy",
          "status": "done"
        },
        {
          "id": "16-9",
          "title": "Exposition UI admin GPO sous /admin/settings",
          "status": "done"
        },
        {
          "id": "16-10",
          "title": "Sécurisation comms — HTTPS + JWT (API v1, middleware, modèles tokens)",
          "status": "done"
        },
        {
          "id": "16-11",
          "title": "Auto-bootstrap migration postes existants",
          "status": "done"
        },
        {
          "id": "16-12",
          "title": "Logs exécution centralisés (DB + endpoint + wrapper + UI consultation)",
          "status": "done"
        },
        {
          "id": "16-13",
          "title": "Exposition endpoints natifs /api/v1/*",
          "status": "done"
        },
        {
          "id": "16-13bis",
          "title": "Module migration simplifié (SE4→SE5) + cleanup shim 1bis.18 + UI tracking",
          "status": "done"
        },
        {
          "id": "16-14",
          "title": "Améliorations UX UI admin GPO (déprogrammable)",
          "status": "done"
        },
        {
          "id": "16-15",
          "title": "Migration cache APCu direct → Cache::store() Laravel",
          "status": "done"
        }
      ]
    },
    {
      "num": 17,
      "title": "Scripts de Démarrage Windows et Linux",
      "status": "done",
      "summary": "<strong>✅ Epic 17 DONE 2026-05-26 — 6/6 stories done (17-1..17-6)</strong>. <strong>Recadré 2026-05-21 post-audit 17.1 validé</strong> : Epic 17 = compatibilité runtime des ~80 scripts versionnés par le package Debian <code>sambaedu</code> avec Epic 15 (WPKG natif) + Epic 16 (GPO natives). <strong>Pas</strong> d'éditeur utilisateur, pas de modèles Eloquent <code>WindowsScript</code>/<code>LinuxScript</code> (RESET Epic 17 2026-05-14 : scripts non éditables côté utilisateur). <strong>🟢 Story 17-1 done 2026-05-21</strong> (audit <code>audit-applications-scripts.md</code> 1700+ lignes, 7 sections A-G + I logs + annexes + Q1-Q8 résolues). Stories 17-2 → 17-6 cadrées (6 stories ~7-9j total, économie 30-40% grâce à 16-12 done + 16-13 done qui ont déjà livré l'infra logs et 8 des 10 endpoints <code>*_out.php</code>). Remplace Story 9-3 PAUSED.",
      "stories": [
        {
          "id": "17-1",
          "title": "Audit des scripts packagés Windows & Linux",
          "status": "done"
        },
        {
          "id": "17-2",
          "title": "Portage moteur applications.php + whitelist étendue + wrapper logs",
          "status": "done"
        },
        {
          "id": "17-3",
          "title": "Compat GPO orchestratrice se4_applications (Stratégie A)",
          "status": "done"
        },
        {
          "id": "17-4",
          "title": "Tests d'intégration runtime VM (5 scripts critiques)",
          "status": "done"
        },
        {
          "id": "17-5",
          "title": "Intégration WrapperScriptRenderer dans pipeline 17.2 (opt-in)",
          "status": "done"
        },
        {
          "id": "17-6",
          "title": "Portage 2 endpoints orphelins wpkg/{linux,winget}_out.php",
          "status": "done"
        }
      ]
    },
    {
      "num": 20,
      "title": "Authentification fédérée d'utilisateurs externes",
      "status": "in-progress",
      "summary": "<strong>Phase 2 — IdP externe de confiance (cadrée 2026-05-29, PM John + archi Winston claude-opus-4-8[1m]).</strong> Permet à un <strong>technicien externe</strong> (gérant plusieurs collèges, <strong>absent de l'AD</strong> local) de se connecter à une instance SE5 via un <strong>JWT signé émis par controlHub</strong> et d'obtenir une session avec un rôle. SER gagne un <strong>fournisseur d'identité externe de confiance générique</strong> — aucune notion de « central » dans le code SER (principe fondateur PRD, <code>expected_iss</code> en config). <strong>Découverte de cadrage</strong> : l'infra JWT existe déjà (Story 16-10, <code>firebase/php-jwt</code> + <code>WorkstationJwtVerifier</code> RS256/anti-rejeu) — Epic 20 <strong>calque</strong> cette infra pour un nouveau tier « utilisateur fédéré », ne réinvente pas. Réconciliation iso-legacy (<code>feedback_auth_iso_legacy</code>) : acteur humain+central (controlHub à jour), distinct de l'auth machine/poste (AD+SMB) → pas de violation « pas de Bearer per-host ». 5 stories cadrées. Artefacts : <code>epics.md §Epic 20</code>, <code>architecture.md §Authentification Fédérée</code>, <code>implementation-readiness-report-2026-05-29-epic20.md</code>.",
      "stories": [
        {
          "id": "20-1",
          "title": "Login fédéré — validation du JWT controlHub & ouverture de session externe",
          "status": "done"
        },
        {
          "id": "20-2",
          "title": "Identité externe persistante (cycle de vie + RGPD)",
          "status": "done"
        },
        {
          "id": "20-3",
          "title": "Résolution directe du rôle externe (suppression table de correspondance)",
          "status": "done"
        },
        {
          "id": "20-4",
          "title": "Audit dénormalisé des actions externes",
          "status": "done"
        },
        {
          "id": "20-5",
          "title": "Doc contrat d'intégration controlHub",
          "status": "done"
        },
        {
          "id": "20-6",
          "title": "Audit des actions Livewire fédérées",
          "status": "backlog"
        }
      ]
    },
    {
      "num": 23,
      "title": "Agent desired-state — État cible servi (contrat v1, compilation, token & iPXE)",
      "status": "done",
      "summary": "<strong>Créé 2026-06-11, clôturé 2026-06-11 — 5/5 stories done.</strong> 1er epic du successeur GPO (<code>epics-agent-desired-state.md</code>, brief + architecture dédiés). Le serveur SE5 répond à « quel est l'état cible exact de CE poste pour CE user ? » : contrat JSON <code>se5.desired-state/v1</code> figé (golden files, strict/défaut, <code>StateHasher</code>), <code>StateCompiler</code> + StateProviders (projection de la DB existante), token Sanctum per-host, enrôlement iPXE. Valeur autonome livrée : état compilé diagnosticable (curl/jq) avant tout agent — gaps archi 1, 2, 4 résolus. Suite Agent VM <strong>121 passed</strong>. <strong>Reste Henri : validation humaine e2e</strong> (curl/jq contre la VM, différée à la clôture 23.4).",
      "stories": [
        {
          "id": "23-1",
          "title": "Contrat v1 figé — schémas state & report, StateHasher, golden files",
          "status": "done"
        },
        {
          "id": "23-2",
          "title": "Cycle de vie du token agent — auth, rotation, révocation, anti-clonage",
          "status": "done"
        },
        {
          "id": "23-3",
          "title": "Enrôlement porte 1 — le token naît à l'install iPXE",
          "status": "done"
        },
        {
          "id": "23-4",
          "title": "StateCompiler — résolution des mailles, précédence, providers wallpaper/overlay",
          "status": "done"
        },
        {
          "id": "23-5",
          "title": "GET /api/v1/agent/state — état servi, ETag/304, config agent",
          "status": "done"
        }
      ]
    },
    {
      "num": 24,
      "title": "Agent desired-state — La boucle fermée en lab (agent MVP, gate palier 1)",
      "status": "done",
      "summary": "<strong>✅ CLÔTURÉ 2026-06-12</strong> — critère de complétude ATTEINT : <strong>démo live répétable</strong> validée en lab ws 49 (UI → état cible → agent Go signé → rapport → UI conformité). Bilan : 24.1 + 24.5 + 24.6 + 24.7 done, 24.2/24.3/24.4 superseded (spikes PS, valeur conservée). Login jamais bloquant, signature de code dès le premier prototype, anti-couteau-suisse tenus. Suite = Epic 25 (distribution/auto-update). | <strong>Le MVP du brief</strong> : l'admin change un wallpaper dans l'UI, le poste de lab converge, le rapport remonte, l'écart se voit. <strong>Bascule Go 2026-06-12</strong> (<code>sprint-change-proposal-2026-06-12</code>) : le prototype PowerShell (24.2/24.3/24.4, superseded) a validé la boucle ; l'agent de production est en <strong>Go</strong> (24.5 core/SYSTEM/build signé, 24.6 compagnon/handlers), la démo palier 1 (24.7, ex-24.5) jouée sur le binaire Go signé.",
      "stories": [
        {
          "id": "24-1",
          "title": "POST /api/v1/agent/report — ingestion et stockage des rapports",
          "status": "done"
        },
        {
          "id": "24-2",
          "title": "Agent squelette Windows — service SYSTEM, check-in, cache, build signé",
          "status": "superseded"
        },
        {
          "id": "24-3",
          "title": "Compagnon de session — portée user, login jamais bloquant",
          "status": "superseded"
        },
        {
          "id": "24-4",
          "title": "Handlers wallpaper + overlay — la convergence devient réelle",
          "status": "superseded"
        },
        {
          "id": "24-5",
          "title": "Agent Go — core de convergence, service SYSTEM, build signé",
          "status": "done"
        },
        {
          "id": "24-6",
          "title": "Agent Go — compagnon de session, handlers wallpaper/overlay, parité démo",
          "status": "done"
        },
        {
          "id": "24-7",
          "title": "Conformité visible — pages parc + bouton « forcer la synchro »",
          "status": "done"
        }
      ]
    },
    {
      "num": 25,
      "title": "Agent desired-state — Gestion de flotte (canari, bootstrap GPO, postes migrés)",
      "status": "done",
      "summary": "D'UN poste de lab à UN PARC : releases signées par rings (= WorkstationGroups), auto-update (canal le plus testé), « la dernière GPO de l'histoire » comme bootstrap/filet éternel, enrôlement des postes migrés avec approbation un-clic + <strong>auto-approbation en campagne de migration SE5</strong>. Gap 3 résolu ici. Prérequis de tout déploiement hors lab.",
      "stories": [
        {
          "id": "25-1",
          "title": "Releases serveur — binaires signés, manifest, rings = WorkstationGroups",
          "status": "done"
        },
        {
          "id": "25-2",
          "title": "Auto-update de l'agent — le canal le plus testé",
          "status": "done"
        },
        {
          "id": "25-3",
          "title": "Porte 2 — enrôlement postes migrés, approbation un-clic + mode campagne",
          "status": "done"
        },
        {
          "id": "25-4",
          "title": "Les deux chemins d'installation — GPO-dispatcher figée + dépôt iPXE",
          "status": "done"
        },
        {
          "id": "25-5",
          "title": "UI parc-settings/agent — rings, enrôlements en attente, releases",
          "status": "done"
        },
        {
          "id": "25-6",
          "title": "Catalogue de tools agent — upload portable Rainmeter + serving skin + toggle",
          "status": "done"
        }
      ]
    },
    {
      "num": 26,
      "title": "Agent desired-state — Environnement de poste (ex-Epic 22, rescopé)",
      "status": "done",
      "summary": "La nature du poste (partagé / personnel / nomade) devient une donnée du domaine Postgres consommée par les handlers de l'Epic 27. <strong>Rescopé 2026-06-11</strong> : AUCUN retrofit dans le canal legacy (pansement Bug C intouché — fix définitif par le handler raccourcis 27.1) ; ex-22.3 annulée. Parallélisable, prérequis de 27.1.",
      "stories": [
        {
          "id": "26-1",
          "title": "Enum WorkstationEnvironment — la nature du poste, donnée du domaine",
          "status": "done"
        },
        {
          "id": "26-2",
          "title": "Mode nomade — modèle 100 % local assumé (clôture FR29)",
          "status": "done"
        },
        {
          "id": "26-3",
          "title": "Nettoyage natif des profils (pastille tableau user + purge orphelins)",
          "status": "done"
        }
      ]
    },
    {
      "num": 27,
      "title": "Agent desired-state — Parité de compétences & extinction du legacy (gate paliers 2-3)",
      "status": "in-progress",
      "summary": "Chaque type de ressource passe au canal agent, « du simple au dur » : raccourcis → lecteurs/imprimantes → registre/associations → config d'app → applications (WPKG déclenché par l'agent). Pattern par story : 1 StateProvider + 1 handler + identifiant figé + golden file. Le canal legacy n'est PAS maintenu pendant le dev (décision 2026-06-11) ; <strong>parité = compétences à terminaison</strong>, puis extinction en bloc (27.6) = gate de la prod.",
      "stories": [
        {
          "id": "27-1",
          "title": "Handler raccourcis — le bureau converge selon la nature du poste",
          "status": "done"
        },
        {
          "id": "27-1bis",
          "title": "Rendu overlay verrouillé (Rainmeter) — accélérateur de démo",
          "status": "done"
        },
        {
          "id": "27-1ter",
          "title": "Rainmeter settings per-user writable (mode installé)",
          "status": "done"
        },
        {
          "id": "27-2",
          "title": "Handlers lecteurs & imprimantes",
          "status": "done"
        },
        {
          "id": "27-3",
          "title": "Handler registre — catalogue de réglages par parc",
          "status": "done"
        },
        {
          "id": "27-3bis",
          "title": "Handler associations de fichiers — le vice UserChoice confiné",
          "status": "review"
        },
        {
          "id": "27-3ter",
          "title": "Registre — valeur par défaut diffusée + override de valeur par parc",
          "status": "review"
        },
        {
          "id": "27-4",
          "title": "Handler config d'app — policies.json Firefox/Thunderbird (scope machine)",
          "status": "review"
        },
        {
          "id": "27-5",
          "title": "Applications — l'agent déclenche WPKG (un tuyau, deux outils)",
          "status": "done"
        },
        {
          "id": "27-6",
          "title": "Catalogue WPKG — source unique depuis le module (fix désync bundle/module + malformation)",
          "status": "review"
        },
        {
          "id": "27-14",
          "title": "Extinction du canal legacy — parité validée, la dette part en bloc",
          "status": "review"
        },
        {
          "id": "27-7",
          "title": "Icônes uploadées de raccourcis — livraison native via asset statique",
          "status": "done"
        },
        {
          "id": "27-8",
          "title": "Retrait du mode strict|default (drift policy) — convergence stricte pure",
          "status": "done"
        },
        {
          "id": "27-9",
          "title": "Réveil de l'agent au logon — cycle desired-state immédiat",
          "status": "done"
        },
        {
          "id": "27-10",
          "title": "Préchargement de l'identité machine dans l'overlay (salle en portée machine)",
          "status": "review"
        },
        {
          "id": "27-11",
          "title": "Composer d'associations par défaut — extension libre + app par nom",
          "status": "review"
        },
        {
          "id": "27-12",
          "title": "Config en capacités — registre capability-first (l'admin gère des intentions, la clé devient un détail)",
          "status": "review"
        },
        {
          "id": "27-13",
          "title": "Capacité non-registre bout-en-bout — firewall « blocage Internet examen »",
          "status": "ready-for-dev"
        },
        {
          "id": "27-15",
          "title": "Libellés de capacités — convention « sujet + état »",
          "status": "ready-for-dev"
        },
        {
          "id": "27-16",
          "title": "Déploiement auto de la GPO bootstrap SE5 + isolation OU dédiée (ex-Fork 2 manuel de 25.4)",
          "status": "done"
        },
        {
          "id": "27-17",
          "status": "done",
          "title": "Page « Configuration par défaut du parc » (couche Broadcast multi-domaines + outils obligatoires)"
        },
        {
          "id": "27-18",
          "status": "ready-for-dev",
          "title": "Overlay par défaut du parc (header variables + messages conditionnels + broadcast)"
        },
        {
          "id": "27-19",
          "status": "done",
          "title": "Livraison WPKG full HTTP (payloads servis par Apache, fin du transport SMB)"
        },
        {
          "id": "27-20",
          "status": "done",
          "title": "Staging des outils WPKG partagés (%Z%\\wpkg\\tools\\) sur le poste"
        }
      ]
    },
    {
      "num": 28,
      "title": "Contrat Amont — Réception & résolution (socle)",
      "status": "in-progress",
      "summary": "<strong>Côté local SE5 du « Contrat Managé »</strong> — l'instance consomme un <strong>contrat amont générique</strong> imposé par controlHub. <strong>Aucune notion de « central » dans SER</strong> (principe fondateur PRD) : SE5 modélise une « autorité amont » abstraite, comme le login fédéré (Epic 20) et le desired-state (Epic 23). L'instance ingère le contrat, le persiste de façon idempotente, et calcule l'état effectif <code>amont &gt; local</code> via <code>StateCompiler::specificity()</code> ; sans contrat, comportement strictement inchangé. <strong>Cadré 2026-06-26 (PM John).</strong> Sources : <code>prd-contrat-manage-se5.md</code> + <code>epics-contrat-manage-se5.md</code>. Côté controlHub = <code>handoff-controlhub-contrat-manage.md</code> (BMAD séparé).",
      "stories": [
        {
          "id": "28-1",
          "title": "Modèle et persistance du contrat amont (items, catalogue, labels, groupes imposés, état du lien)",
          "status": "review"
        },
        {
          "id": "28-2",
          "title": "Réception idempotente d'un contrat amont (NFR4)",
          "status": "backlog"
        },
        {
          "id": "28-3",
          "title": "Résolution amont > local dans StateCompiler (FR2, standalone préservé)",
          "status": "backlog"
        }
      ]
    },
    {
      "num": 29,
      "title": "Contrat Amont — Faire respecter le contrat (verrou & permissif)",
      "status": "backlog",
      "summary": "<strong>Cœur de valeur</strong> : c'est ici que la divergence non voulue entre établissements est stoppée. Le refnum ne peut plus défaire un item <strong>verrouillé</strong>, peut surcharger un item <strong>permissif</strong> au niveau d'un WorkstationGroup, et voit clairement les statuts (imposé/verrouillé/permissif). Enforcement réel via <strong>Gates scopés</strong> — inclut le correctif du trou connu <code>wpkg.*</code> (Gate global non scopé, cf. <code>project_delegation_enforcement_wpkg_gap</code>), <strong>prérequis bloquant d'Epic 31</strong>. Item verrouillé soumis au drift STRICT (27.8) ; overrides audités.",
      "stories": [
        {
          "id": "29-1",
          "title": "Scoper le Gate wpkg.* par périmètre (NFR1 — prérequis Epic 31)",
          "status": "backlog"
        },
        {
          "id": "29-2",
          "title": "Refuser la modification d'un item verrouillé (UI + service + gate)",
          "status": "backlog"
        },
        {
          "id": "29-3",
          "title": "Surcharger un item permissif par WorkstationGroup",
          "status": "backlog"
        },
        {
          "id": "29-4",
          "title": "Lisibilité refnum — statuts imposé/verrouillé/permissif dans l'UI",
          "status": "backlog"
        },
        {
          "id": "29-5",
          "title": "Drift STRICT sur item verrouillé + audit des overrides",
          "status": "backlog"
        }
      ]
    },
    {
      "num": 30,
      "title": "Contrat Amont — Cibler par labels (types de parc)",
      "status": "backlog",
      "summary": "Les WorkstationGroups sont locaux et hétérogènes (un collège a 1 salle techno, un autre en a 3) → l'amont ne peut pas cibler un groupe qu'il ne connaît pas. Indirection par <strong>label</strong> (type de parc) : l'amont définit des labels, SE5 les mappe sur ses groupes, l'amont associe un item à un label → tous les groupes portant ce label héritent. <strong>1 label max par groupe</strong> (superposition via appartenance multiple aux parcs logiques). 3 modes : <code>libre</code> (refnum assigne/crée), <code>réservé</code> (non-attribuable, ex. compta), <code>groupe imposé</code> (création garantie, ex. bureau_direction). Conflit poste = règle verrou/permissif par propriété ; cas insoluble = validation prédictive à l'assignation.",
      "stories": [
        {
          "id": "30-1",
          "title": "Réception des labels (mode libre/réservé) et des groupes imposés",
          "status": "backlog"
        },
        {
          "id": "30-2",
          "title": "Mapping d'un label par le refnum (1 label max ; réservé non attribuable)",
          "status": "backlog"
        },
        {
          "id": "30-3",
          "title": "Garantie d'existence des groupes imposés (création/réconciliation)",
          "status": "backlog"
        },
        {
          "id": "30-4",
          "title": "Résolution d'un item ciblant un label (règle verrou/permissif, pas d'ordre inter-parcs)",
          "status": "backlog"
        },
        {
          "id": "30-5",
          "title": "Validation prédictive à l'assignation (collision de verrous amont)",
          "status": "backlog"
        }
      ]
    },
    {
      "num": 31,
      "title": "Contrat Amont — Dépôt applicatif borné & install pilotée",
      "status": "backlog",
      "summary": "Central devient le dépôt applicatif faisant autorité côté instance : le canal d'install refnum reste utilisable mais <strong>filtré au catalogue amont</strong> (ajout libre, mais depuis le catalogue). L'amont peut <strong>déclencher</strong> des installs sous forme de <strong>désir d'état</strong> repris par le canal check-in de l'agent existant (idempotence/reprise). <strong>Dépend du correctif Gate <code>wpkg.*</code></strong> (story 29-1) pour que le bornage soit réellement opposable.",
      "stories": [
        {
          "id": "31-1",
          "title": "Borner le canal d'install refnum au catalogue amont (FR5)",
          "status": "backlog"
        },
        {
          "id": "31-2",
          "title": "Déclenchement d'install en désir d'état via check-in agent (FR6)",
          "status": "backlog"
        }
      ]
    },
    {
      "num": 32,
      "title": "Contrat Amont — Cycle de vie du lien & release",
      "status": "backlog",
      "summary": "À réception du signal de <strong>rupture du lien de management</strong>, SE5 libère proprement tous les verrous : les items quittent l'état imposé en conservant leur valeur courante effective, le bornage catalogue tombe, le refnum reprend la main — <strong>sans perte de ses ajouts locaux</strong>. Distinction clé : une simple <strong>indisponibilité amont</strong> (panne) ne libère rien (le dernier contrat reste en vigueur) ; seule la rupture délibérée déclenche le release. Transitions d'état du lien auditées (NFR5).",
      "stories": [
        {
          "id": "32-1",
          "title": "Release des verrous à la rupture du lien (valeurs conservées, ajouts préservés)",
          "status": "backlog"
        },
        {
          "id": "32-2",
          "title": "Indisponibilité amont vs rupture + trace des transitions du lien",
          "status": "backlog"
        }
      ]
    },
    {
      "num": 33,
      "title": "Contrat Amont — Contrat de données d'intégration controlHub↔SE5",
      "status": "backlog",
      "summary": "Formaliser et <strong>versionner</strong> le schéma d'échange partagé entre controlHub et SE5 (point de couture entre les deux BMAD, cf. §7 du handoff et §9 du mini-PRD). Source unique vérifiable : validation du payload contre le schéma versionné à l'ingestion, et <strong>rejet gracieux</strong> d'une version incompatible (sans corrompre l'état local). Durcit le format d'ingestion introduit unilatéralement en Epic 28. Coordination cross-équipe — à synchroniser avec le BMAD controlHub.",
      "stories": [
        {
          "id": "33-1",
          "title": "Schéma d'échange versionné (validation à l'ingestion)",
          "status": "backlog"
        },
        {
          "id": "33-2",
          "title": "Négociation et rejet gracieux d'une version incompatible",
          "status": "backlog"
        }
      ]
    }
  ],
  "central": [
    {
      "num": "C1",
      "title": "Migration architecture (DTOs, Livewire, DaisyUI)",
      "status": "in-progress",
      "summary": "Refonte structurelle d'irundoo : DTOs typés + Collections, migration des pages Livewire vers Blade + contrôleurs, suppression de Volt/Flux au profit de DaisyUI, APIs avec DTOs, nettoyage final. Cf. migration-archi.md.",
      "stories": [
        {
          "id": "C1-1",
          "title": "Phase 1 — Infrastructure DTOs + Collections typées",
          "status": "done"
        },
        {
          "id": "C1-2",
          "title": "Phase 2 — Migration pages Livewire → Contrôleurs + Blade",
          "status": "done"
        },
        {
          "id": "C1-3",
          "title": "Phase 3 — Remplacement Volt/Flux par DaisyUI",
          "status": "done"
        },
        {
          "id": "C1-4",
          "title": "Phase 4 — Refactorisation des APIs avec DTOs + Form Requests",
          "status": "backlog"
        },
        {
          "id": "C1-5",
          "title": "Phase 5 — Nettoyage (VoltServiceProvider, deps composer, code mort)",
          "status": "backlog"
        }
      ]
    },
    {
      "num": "C2",
      "title": "Authentification & LDAP",
      "status": "done",
      "summary": "Installation des dépendances LDAP, configuration du bind vers l'annuaire, et mise en place de l'authentification LDAP pour l'accès admin controlHub.",
      "stories": [
        {
          "id": "C2-1",
          "title": "Installation des dépendances LDAP",
          "status": "done"
        },
        {
          "id": "C2-2",
          "title": "Configuration du bind LDAP",
          "status": "done"
        },
        {
          "id": "C2-3",
          "title": "Authentification via LDAP",
          "status": "done"
        }
      ]
    },
    {
      "num": "C3",
      "title": "Protocole SE4FS ↔ ControlHub",
      "status": "done",
      "summary": "Protocole d'échange entre controlHub et les instances SE4FS : handshake initial avec token Bearer, endpoints API v1, évolutions du protocole de communication (cf. plandevsyncSe4.md).",
      "stories": [
        {
          "id": "C3-1",
          "title": "Handshake initial + émission API token",
          "status": "done"
        },
        {
          "id": "C3-2",
          "title": "Intégration SE4FS v1 (endpoints de base)",
          "status": "done"
        },
        {
          "id": "C3-3",
          "title": "Évolution protocole communication (contrat controlhub_updated_at)",
          "status": "done"
        }
      ]
    },
    {
      "num": "C4",
      "title": "Dashboard & Statistiques",
      "status": "done",
      "summary": "Dashboard admin controlHub : stats agrégées multi-instances, vue d'ensemble de la flotte, et theme switcher clair/sombre/corporate.",
      "stories": [
        {
          "id": "C4-1",
          "title": "Stats dashboard (indicateurs flotte)",
          "status": "done"
        },
        {
          "id": "C4-2",
          "title": "Admin dashboard (vue d'ensemble)",
          "status": "done"
        },
        {
          "id": "C4-3",
          "title": "Theme switcher (clair/sombre)",
          "status": "done"
        }
      ]
    },
    {
      "num": "C5",
      "title": "Gestion des instances (Admin)",
      "status": "done",
      "summary": "Interface admin pour gérer les instances SE4FS déclarées : enrôlement, clefs API, révocation, affichage des tokens. Fichiers : admin/instances, ApiKeysManager.",
      "stories": [
        {
          "id": "C5-1",
          "title": "Interface admin des instances (liste, création, édition)",
          "status": "done"
        },
        {
          "id": "C5-2",
          "title": "Gestion des API keys (génération, révocation)",
          "status": "done"
        },
        {
          "id": "C5-3",
          "title": "Fix copier-coller de la clef maître instance",
          "status": "done"
        }
      ]
    },
    {
      "num": "C6",
      "title": "Sync ControlHub → Instances (v2)",
      "status": "done",
      "summary": "Nouveau moteur de synchro bidirectionnel avec snapshot léger + hash déterministe, GET détaillés pour inspecter les divergences, CRUD unitaire, sync-manifest 3-pass (entités / relations / cleanup), dispatch par jobs multi-instances, callbacks asynchrones vers /task-result. Cf. plandevsyncSe4.md.",
      "stories": [
        {
          "id": "C6-1",
          "title": "API snapshot + hash déterministe (SHA-256 par type + global)",
          "status": "done"
        },
        {
          "id": "C6-2",
          "title": "API GET entités (shortcuts, workstation-groups, app-profiles)",
          "status": "done"
        },
        {
          "id": "C6-3",
          "title": "CRUD unitaire — shortcuts / groups / profiles",
          "status": "done"
        },
        {
          "id": "C6-4",
          "title": "Sync-manifest — convergence 3-pass (upsert / relations / cleanup)",
          "status": "done"
        },
        {
          "id": "C6-5",
          "title": "Dispatch multi-instances via jobs Laravel (parallèle)",
          "status": "done"
        },
        {
          "id": "C6-6",
          "title": "Callback asynchrone /api/sambaedu/task-result/{instance_id}",
          "status": "done"
        }
      ]
    },
    {
      "num": "C7",
      "title": "Shortcuts / AppProfiles / WorkstationGroups",
      "status": "done",
      "summary": "CRUD des entités métier controlHub : shortcuts avec icônes (Windows/Linux), AppProfiles associant des applications, WorkstationGroups hiérarchiques avec relations vers shortcuts et profils.",
      "stories": [
        {
          "id": "C7-1",
          "title": "CRUD Shortcuts (Windows + Linux + icônes base64)",
          "status": "done"
        },
        {
          "id": "C7-2",
          "title": "CRUD AppProfiles (association applications)",
          "status": "done"
        },
        {
          "id": "C7-3",
          "title": "CRUD WorkstationGroups (hiérarchie parent/enfant)",
          "status": "done"
        }
      ]
    },
    {
      "num": "C8",
      "title": "Gestion du Parc (multi-instances)",
      "status": "done",
      "summary": "Vue opérationnelle de la flotte : tableau récapitulatif des N instances (snapshots agrégés, divergences), page détail par instance, actions en masse (dispatch push vers instances). Cf. gestionParc.md, massActions.md.",
      "stories": [
        {
          "id": "C8-1",
          "title": "Tableau récap multi-instances (snapshots parallèles)",
          "status": "done"
        },
        {
          "id": "C8-2",
          "title": "Vue détail instance (inspection synchrone)",
          "status": "done"
        },
        {
          "id": "C8-3",
          "title": "Actions en masse (dispatch vers N instances)",
          "status": "done"
        }
      ]
    },
    {
      "num": "C9",
      "title": "application-depot",
      "status": "backlog",
      "summary": "Dépôt centralisé d'applications côté ControlHub : catalogue d'apps installables, packages WPKG/scripts associés, métadonnées (icône, version, OS cible), distribution vers les instances SER via le moteur de sync. À détailler.",
      "stories": []
    },
    {
      "num": "C10",
      "title": "Support Guacamole — Provisioning & Dépannage admin",
      "status": "backlog",
      "summary": "Reprise depuis le legacy SambaEdu central de tout ce qui touche Guacamole côté centralisé : provisioning du serveur Guacamole (Tomcat + guacamole.properties), création des backends HAProxy par établissement, génération des fichiers de conf par UAI, et prise en main admin des postes des collèges via <code>remote_admin_machine</code>. Le scénario \"accès domicile\" reste côté SER (sambaedu-reload). Cf. <code>_bmad-output/planning-artifacts/handoff-guacamole-controlhub.md</code> et <code>quickspec-guacamole-sambaedu-reload.md</code>.",
      "stories": [
        {
          "id": "C10-1",
          "title": "Provisioning Tomcat + guacamole.properties (server.xml RemoteIpValve + json-secret-key + params LDAP)",
          "status": "backlog"
        },
        {
          "id": "C10-2",
          "title": "Provisioning HAProxy backend par établissement (API Data Plane v2)",
          "status": "backlog"
        },
        {
          "id": "C10-3",
          "title": "Génération de la conf par UAI + déploiement vers les sites",
          "status": "backlog"
        },
        {
          "id": "C10-4",
          "title": "Dépannage admin — prise de main sur poste collège via remote_admin_machine",
          "status": "backlog"
        },
        {
          "id": "C10-5",
          "title": "Briques techniques dupliquées (crypto token signé + connection-builder + REST client Guacamole)",
          "status": "backlog"
        },
        {
          "id": "C10-6",
          "title": "Orchestration activate_etab — intégration du provisioning Guacamole dans la création d'établissement",
          "status": "backlog"
        }
      ]
    }
  ],
  "notes": [
    {
      "num": "SambaEdu",
      "title": "Notes, questions & todos — SER",
      "status": "notes",
      "summary": "Constats d'audit, questions ouvertes et todos de mise en prod côté SambaEdu / SER. À clarifier ou trancher avant industrialisation.",
      "stories": [
        {
          "id": "──",
          "title": "Questions ouvertes",
          "status": "section-header"
        },
        {
          "id": "?",
          "title": "OAuth2 / Proxy — Qui gère le proxy ENT ? Serveur central ou accès direct Laravel → ENT ? (TLS verify=false hérité du legacy)",
          "status": "todo"
        },
        {
          "id": "?",
          "title": "Test des collèges sans local : accès depuis le central à athena web — les users des collèges doivent pouvoir réinitialiser leur mdp et le modifier au niveau de Keycloak aussi",
          "status": "todo"
        },
        {
          "id": "──",
          "title": "Audits & constats techniques",
          "status": "section-header"
        },
        {
          "id": "?",
          "title": "APCu — le stub logs.inc.php appelle apcu_fetch/apcu_store (computer_lock) : fatal error si l'extension n'est plus chargée sur la VM",
          "status": "todo"
        },
        {
          "id": "?",
          "title": "Directives XML → JSON : les noeuds SambaEdu (download, delete, untar, unzip) ne servent qu'au backend Laravel — stocker en jsonb à l'ingestion éliminerait le parsing DOM et le nettoyage dans PackagesXmlService",
          "status": "todo"
        },
        {
          "id": "?",
          "title": "Appels central → SE legacy : auditer les 15 POST (parcs/action_cron, annu/sync_cron, wpkg/*, dhcp/*, gpo/del_roam, etc.) — voir all-post-call-legacy.md. Décider par endpoint : cron local Laravel / ControlHub Task / shim temporaire. P1 = wpkg_ldap_update.php",
          "status": "todo"
        },
        {
          "id": "──",
          "title": "Todo avant mise en prod",
          "status": "section-header"
        },
        {
          "id": "!",
          "title": "Les matières sont exclues des groupes car globaux dans l'AD. Voir comment je traite ça (probablement avec controlHub: importAD +dispatch avec verrouillage sur les instances)",
          "status": "todo"
        },
        {
          "id": "!",
          "title": "Queue workers + scheduler : Ne pas oublier de changer WorkingDirectory dans les .service (actuellement /var/www/sambaedu-reload) — voir finalisation-sambaedu-stable.md",
          "status": "todo"
        },
        {
          "id": "!",
          "title": "Fail au boot si une variable d'env essentielle manque (APP_KEY, APP_URL, DB_*, SAMBAEDU_LDAP_*, etc.) — échouer tôt avec un message explicite plutôt que de laisser tourner avec des valeurs par défaut silencieuses",
          "status": "todo"
        },
        {
          "id": "──",
          "title": "À tester avant mise en prod",
          "status": "section-header"
        },
        {
          "id": "1bis-5",
          "title": "OAuth2 — authentification ENT (migration legacy → Laravel)",
          "status": "done"
        },
        {
          "id": "1bis-6",
          "title": "Modules SSO + CAS",
          "status": "done"
        },
        {
          "id": "1bis-18g",
          "title": "Shims GPO (search_ad, modify_ad, bridge Kerberos) — validation e2e sur VM",
          "status": "done"
        }
      ],
      "retro": null
    },
    {
      "num": "irundoo",
      "title": "Notes, questions & todos — ControlHub / irundoo",
      "status": "notes",
      "summary": "Constats, questions et todos transverses côté controlHub / irundoo. À trancher avant d'industrialiser la v2 de synchro.",
      "stories": [
        {
          "id": "──",
          "title": "Questions ouvertes",
          "status": "section-header"
        },
        {
          "id": "?",
          "title": "Stratégie de purge du cache snapshot (TTL, invalidation à chaque mutation vs lazy)",
          "status": "todo"
        },
        {
          "id": "?",
          "title": "Callback /task-result : gestion retries et idempotence côté ControlHub (dedup par task_id ?)",
          "status": "todo"
        }
      ],
      "retro": null
    }
  ]
};
