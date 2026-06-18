# Client WPKG (artefacts importés)

> **Statut : versionné « pour le moment ».** Ces fichiers sont le **client WPKG côté poste**. Ils n'étaient **pas** dans le code applicatif : ils sont livrés par le paquet Debian **`sambaedu-wpkg`** et déployés sur le serveur dans `/var/sambaedu/unattended/install/wpkg/`, puis servis aux postes via le partage SMB `\\<SE4FS>\install\wpkg\` (et `wpkg-client.vbs` est copié en `%WinDir%\wpkg-client.vbs` à l'install).
>
> Importés ici (depuis la VM `/var/sambaedu/unattended/install/wpkg/`, copie lecture seule) le **2026-06-18** pour analyse et suivi dans le cadre de l'Epic 27 (story 27.5 — *« Applications : l'agent déclenche WPKG »*). Direction long terme : SE5 autonome (absorber/remplacer la dépendance au `.deb`). Pour modifier le comportement client en amont, la source reste le dépôt de packaging `sambaedu-wpkg`.

## Fichiers

| Fichier | Rôle |
|---|---|
| `wpkg-se4.js` | Le moteur WPKG custom SambaEdu (le « wpkg.js », ~349 Ko). Fetch HTTP des bases (`packages_xml_out.php` / `profiles_xml_out.php?poste=` / `hosts_xml_out.php?poste=`), matching `<host>`→profil **par nom**, install/convergence des paquets. |
| `wpkg-client.vbs` | L'orchestrateur — **le déclencheur réel** : `cscript //B //NoLogo wpkg-client.vbs /NOTempo` → appelle `wpkg-se4.js /synchronize /noDownload /applymultiple:true`. C'est ce que l'agent (story 27.5) déclenchera à la place de la GPO `se4_wpkg`. |
| `wpkg-client.vbs-original` | Version **upstream** (avant customisation SambaEdu) — référence pour `diff`. |
| `wpkg.cmd` | Amorçage : pose la variable machine `%SE4FS%`, copie le `.vbs` en `%WinDir%`, lance le client. |
| `wpkg.cmd.bak-20260610` | Sauvegarde datée (redondante avec git — à élaguer). |
| `packages.xml` | Catalogue **généré** (instance VM, snapshot). Porte la `<variable name="SE4FS_NAME" value="se4fs_name" source="sambaedu"/>` substituée côté serveur. |

## Faits clés (vérifiés 2026-06-18)

- **Aucune dépendance AD** dans le chemin WPKG : le client ne lit l'AD que via `getHostGroups()` (`WinNT://…`) **wrappé `try/catch`, jamais utilisé** (matching par nom, pas par groupe). Identité poste = `WScript.Network.ComputerName` (local). → critère Keycloak (NFR7) respecté.
- **Une seule variable substituée serveur** : `SE4FS_NAME` (clé `se4fs_name`, depuis la conf serveur `/etc/sambaedu/*` — **pas l'AD**). Le legacy `packages_xml_out.php` la substitue ; le SE5 `PackagesXmlService` **non** (écart à corriger — cf. story 27.5 / D6).
- **Désalignement de noms d'endpoints** : `wpkg-se4.js` appelle les URL legacy `*_xml_out.php` alors que SE5 sert `profiles.xml` / `hosts.xml` (et n'a pas porté `packages_xml_out.php`) — cause probable du « WPKG ne fonctionne pas » (cf. story 27.5 / D6).
