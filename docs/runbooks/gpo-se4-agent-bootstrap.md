# Runbook — Publication de la GPO-dispatcher figée `se4_agent_bootstrap`

> **Story 25.4 (Fork 2).** La GPO `se4_agent_bootstrap` est **le dernier
> artefact AD, jamais ré-édité** : on la publie **une fois**, puis toute
> évolution de l'agent passe par l'**auto-update** (Story 25.2). La publication
> est **manuelle** (pas d'automatisation applicative dans cette story) —
> justifié : ROI quasi nul d'automatiser une publication unique, et le
> bloqueur de droits SYSVOL connu (`www-sambaedu` n'a que READ → `mkdir`/`put`
> ACCESS_DENIED, `smbclient` sort en 0 = **faux succès**) impose de toute façon
> le workaround **Administrator**.

## 0. Pré-requis serveur

- PKI initialisée : `php artisan auth:ca:init` (sinon `/api/v1/agent/ca` → 503).
- Une release **stable** publiée : `php artisan agent:release:publish … --stable`
  (le `/api/v1/agent/stable*` sert cette release).
- Le LAN des postes est dans l'allowlist `local.request`
  (`config('sambaedu.wpkg.report_ingestion_allowed_ips')` / DB).

## 1. Source du template

- **Source versionnée** : `resources/gpo/se4_agent_bootstrap/` (repo).
- **Cible serveur** : `/usr/share/sambaedu/gpo/se4_agent_bootstrap/`
  (hors git, convention `project_storage_convention_non_versioned`).

Structure :

```
se4_agent_bootstrap/
├── GPT.INI                                  # [General] displayName=se4_agent_bootstrap ; [CSE] gPCMachineExtensionNames (Scripts)
└── Machine/
    └── Scripts/
        ├── scripts.ini                      # [Startup] 0CmdLine=startup.cmd
        └── Startup/
            └── startup.cmd                  # dispatcher générique (CRLF), AUCUNE logique métier
```

Le `startup.cmd` (générique) : (a) déploie la CA dans `LocalMachine\Root`
(`certutil -addstore -f Root`, idempotent), (b) télécharge le binaire stable
vers `%ProgramFiles%\SambaEdu\Agent\agent.exe` (emplacement DÉFINITIF), (c)
`agent.exe install -server-url http://%SE4FS%` (idempotent → installe **ou**
répare), (d) (re)crée la tâche planifiée `SambaEduAgent-Bootstrap-Refresh`
(SYSTEM, 240 min) qui rejoue (a)-(c) — le filet éternel. Seule spécialisation :
`###_SE4FS_NAME_###` (nom serveur, figé 1× à la publication).

## 2. Déployer la source vers le serveur (one-shot)

```bash
sudo mkdir -p /usr/share/sambaedu/gpo
sudo cp -r resources/gpo/se4_agent_bootstrap /usr/share/sambaedu/gpo/
# Interpoler le nom du serveur (figé une fois) :
sudo sed -i 's/###_SE4FS_NAME_###/<se5.mondomaine.lan>/g' \
  /usr/share/sambaedu/gpo/se4_agent_bootstrap/Machine/Scripts/Startup/startup.cmd
```

> Conserver les fins de ligne **CRLF** du `.cmd` (un LF seul échoue
> silencieusement côté Windows — mémoire `project_migration_passthrough_gpo_lab`).

## 3. Publier vers SYSVOL — workaround Administrator (bloqueur de droits connu)

`www-sambaedu` n'a que **READ** sur SYSVOL : un `import_gpo`/`smbclient put`
sort en **0** sans rien écrire (faux succès). On publie donc **en
Administrator** (workaround éprouvé — 11 GPO publiées 2026-06-08) :

```bash
# Sur le contrôleur de domaine (ou via samba-tool gpo / smbclient en Administrator).
# 1. Créer la GPO (si absente) :
samba-tool gpo create "se4_agent_bootstrap" -U Administrator

# 2. Déposer le contenu du template dans le dossier SYSVOL de la GPO créée :
#    \\<domaine>\SYSVOL\<domaine>\Policies\{GUID}\
#    en y copiant GPT.INI + Machine\ (Scripts\scripts.ini + Scripts\Startup\startup.cmd).
smbclient //<dc>/sysvol -U Administrator -c '
  cd "<domaine>/Policies/{GUID}";
  put GPT.INI GPT.INI;
  mkdir Machine; cd Machine; mkdir Scripts; cd Scripts;
  put scripts.ini scripts.ini;
  mkdir Startup; cd Startup; put startup.cmd startup.cmd
'
```

> **Vérifier l'écriture réelle** (le faux succès `www-sambaedu` ne doit pas
> tromper) : relire le fichier déposé (`get startup.cmd -` ) et comparer la
> taille/contenu.

## 3bis. Poser l'attribut AD `gPCMachineExtensionNames` — SANS QUOI LA GPO EST INERTE

> ⚠️ **Piège critique de publication manuelle.** Windows ne déclenche la
> **Scripts CSE** (qui exécute `startup.cmd`) que si l'objet AD de la GPO porte
> l'attribut LDAP `gPCMachineExtensionNames`. Le `[CSE]` du `GPT.INI` est la
> **convention de template SambaEdu** (lue par `GpoTemplateRegistry`), **pas**
> le canal que lit le client Windows : à la publication réelle, la valeur doit
> vivre dans l'**attribut LDAP de l'objet `groupPolicyContainer`**. `samba-tool
> gpo create` crée une GPO **sans** cet attribut → GPO liée, visible dans GPMC,
> mais `startup.cmd` **ne s'exécute jamais** (échec silencieux).

Deux options :

- **Option A (recommandée) — publier via le mécanisme `import_gpo` legacy**, qui
  régénère le `GPT.INI` SYSVOL en `[General]` seul ET pose `gPCMachineExtensionNames`
  comme attribut LDAP (`modify_ad`) en une passe. C'est ce qui a publié les 11
  GPO du 2026-06-08.
- **Option B — pose manuelle de l'attribut** (si publication par `smbclient put`
  brut, §3). Sur le DC, en Administrator :

  ```bash
  # DN de l'objet GPO : CN={GUID},CN=Policies,CN=System,<DN domaine>
  cat > /tmp/se4_agent_bootstrap_cse.ldif <<'EOF'
  dn: CN={GUID},CN=Policies,CN=System,<DC=mondomaine,DC=lan>
  changetype: modify
  replace: gPCMachineExtensionNames
  gPCMachineExtensionNames: [{42B5FAAE-6536-11D2-AE5A-0000F87571E3}{40B6664F-4972-11D1-A7CA-0000F87571E3}]
  EOF
  ldbmodify -H /var/lib/samba/private/sam.ldb /tmp/se4_agent_bootstrap_cse.ldif
  # Incrémenter versionNumber de l'objet GPO ET le champ Version du GPT.INI
  # (de 1 vers une valeur > 0) pour forcer la réapplication côté postes.
  ```

> **À valider impérativement au smoke lab** (§5) avant tout déploiement parc :
> `gpresult /H rapport.html` sur un poste de test doit lister `se4_agent_bootstrap`
> sous **Computer Configuration → … → Scripts**, et l'Event Viewer (GroupPolicy,
> ID 4000/4001/5312) doit tracer l'exécution du startup script. Une GPO listée
> mais **sans** ligne Scripts = attribut CSE manquant (refaire §3bis).

## 4. Lier la GPO

Lier `se4_agent_bootstrap` à la **racine du domaine** (couvre tout le parc) ou à
l'**OU Parcs** :

```bash
samba-tool gpo setlink "<DN cible>" "{GUID}" -U Administrator
```

## 5. Vérifier sur un poste

1. `gpupdate /force` puis reboot.
2. Le `startup.cmd` s'exécute en **SYSTEM** au démarrage :
   - `certutil -store Root` liste la racine CA SambaEdu ;
   - `%ProgramFiles%\SambaEdu\Agent\agent.exe` présent ;
   - service `SambaEduAgent` enregistré (`sc query SambaEduAgent`) ;
   - tâche `SambaEduAgent-Bootstrap-Refresh` présente (`schtasks /Query`).
3. L'agent **demande son enrôlement** (porte 2) : une demande `pending` apparaît
   dans l'UI ; après approbation un-clic (ou campagne), le poste converge.

## 6. Le filet éternel (#27)

La même GPO **réinstalle** un agent briqué/supprimé au passage suivant (boot ou
tâche de refresh) : `agent.exe install` est idempotent (arrêt/suppression/
recréation). Le **token survit** (hors périmètre install) → un poste déjà
enrôlé repart en convergence directe, sans ré-enrôlement. Si la tâche de refresh
a été supprimée, le `startup.cmd` la **recrée** (auto-réparation).

## 7. Ne jamais confondre

- `se4_agent_bootstrap` = pose/répare **l'agent** (cette story).
- `se4_applications` = dispatcher de **config legacy** (raccourcis, wallpaper,
  etc.) — **distinct**, ne pas mélanger.
