# Legacy cmd_action fixtures — Story 3.8

Bodies cmd batch capturés via `curl http://<vm>/ipxe/Win10/action.php` sur la VM legacy `192.168.122.50` (dev), workstation `pc-techno-25` (AD `localdev.fr`).

## Inventaire

| Fixture | Source legacy | Usage parité |
|---|---|---|
| `join.txt` | `action.php:358-406` (`$cmd_join`) | ✅ Active — comparer SE5 `buildJoin(Workstation, role, ou)` |
| `renomme.txt` | `action.php:317-351` (`$cmd_renomme`) | ✅ Active — comparer SE5 `buildRenomme(Workstation, role)` |
| `post.txt` | `action.php:198-231` (`$cmd_post`) | ✅ Active — comparer SE5 `buildPost(Workstation)` |
| `wpkg.txt` | `action.php:268-311` (`$cmd_wpkg`) | ✅ Active — comparer SE5 `buildWpkg(Workstation)` |
| `oobe.txt` | `action.php:236-265` (`$cmd_oobe`) | ⚠️ Référence non-régression 3.5 — `oobe` est déjà SE5 native (recordOobeComplete). Sert à valider qu'on ne casse pas l'existant. |
| `sysprep.txt` | _Non capturable_ | ⏭️ markTestSkipped — voir « Sysprep non capturable » ci-dessous |
| `nosysprep.txt` | _Volontairement divergent_ | ⏭️ markTestSkipped — voir « Q-2 refacto clarté » ci-dessous |

## Inputs utilisés à la capture

```
POST /ipxe/Win10/action.php
name=pc-techno-25
uuid=12345678-1234-1234-1234-123456789012
mac=00:11:22:33:44:55
etape=<step>
# ret absent (= dispatcher branche A premier appel)
```

Workstation `pc-techno-25` :
- DN : `CN=pc-techno-25,OU=techno,OU=computers,DC=localdev,DC=fr`
- `netbootGUID` LDAP : `12345678-1234-1234-1234-123456789012` (registered via `register_machine_hardware` lors de la capture).
- Action programmée : aucune (`type=default`).

## Configs legacy interpolées dans les fixtures

Pour reproduire bit-équivalence avec SE5, les tests doivent surcharger `.env`/`config/sambaedu` avec :

| Clé legacy | Valeur capturée | Mapping SE5 (à valider en dev) |
|---|---|---|
| `se4install_name` | `se4install` | `config('sambaedu.se4install.name')` ou `env('SE4INSTALL_NAME')` |
| `se4install_passwd` | _(redacted — set via testing env)_ | `env('SE4INSTALL_PASSWD')` |
| `adminse_name` | `admin` | `env('SAMBAEDU_ADMINSE_NAME')` |
| `adminse_passwd` | _(redacted — set via testing env)_ | `env('SAMBAEDU_ADMINSE_PASSWD')` |
| `domain` | `localdev.fr` | `env('SAMBAEDU_DOMAIN')` / `env('SAMBAEDU_LDAP_DOMAIN')` |
| `se4fs_name` | `se4fs` | `env('SAMBAEDU_SE4FS_NAME')` |

**Pour le dev opus** : créer un helper `tests/Feature/Ipxe/Concerns/UsesLegacyFixtureConfig.php` qui set les bonnes valeurs dans `config()` au début du test, OU charger un `.env.testing` dédié avec ces valeurs. Les vraies valeurs (passwords) sont sur la VM `/var/www/sambaedu/.env` côté legacy + récupérables via `php artisan tinker` côté SE5 si déjà migrées.

## Particularités à gérer côté `assertCmdBodyEquivalent`

### 1. Line endings mixtes

Les fixtures legacy ont des line endings **mixtes CRLF + LF** :

```
join.txt    : 52 CRLF + 50 LF-only lines
oobe.txt    : 32 CRLF + 34 LF-only lines
post.txt    : 36 CRLF + 38 LF-only lines
renomme.txt : 39 CRLF + 38 LF-only lines  (mais `file` détecte CRLF only — quelques LF en sous-marin)
wpkg.txt    : 46 CRLF + 46 LF-only lines
```

C'est dû aux heredoc PHP du legacy qui utilisent `"\r"` à la fin de chaque ligne d'instruction mais oublient certaines lignes (heredoc PHP convertit `\n` interne en `\n` brut, pas en `\r\n`).

**Décision 3.8** : le SE5 doit émettre **CRLF strict** (D6, AC3.3 — Windows poste plus robuste). Le helper `assertCmdBodyEquivalent` doit donc :
1. **Normaliser** le CRLF dans la fixture ET dans le body SE5 avant comparison : `preg_replace("/\r?\n/", "\n", $s)`.
2. **Masquer** les lignes header REM contenant `$id`, `$uuid`, `$ret` (qui varient à chaque appel ou inputs) : `preg_replace('/^REM\s+pour\s+.*$/m', 'REM <HEADER VARS>', $s)`.
3. Comparer ligne à ligne avec un diff structurel.

### 2. `$cmd_sysprep` legacy = dead code (sysprep non capturable)

`$cmd_sysprep` (lignes 73-144 `action.php`) est défini **MAIS jamais référencé par le dispatcher** legacy (`grep -n '$cmd_sysprep\b' legacy/modules/ipxe/Win10/action.php` ne trouve que la définition ligne 73). 

Le dispatcher (lignes 416-429) sert `$cmd_nosysprep` (151-192) pour `etape=sysprep` quand `type ∈ {clonage, clonage2}`. Quand `type=default`, **aucun body cmd** n'est servi (juste cmd_header REM seul).

**Conséquence parité** :
- Impossible de capturer une fixture legacy pour `cmd_sysprep` strict — le legacy ne l'a jamais émis en prod.
- La capture avec `type=clonage` aurait nécessité une programmation APCu FPM (set_action via menu admin web SE5) — non triviale, skipped pour cette story.

**Conséquence portage SE5** :
- Le dev opus DOIT quand même porter `cmd/sysprep.blade.php` (le bloc legacy 73-144) car il contient la **logique sysprep+generalize** complète utilisée au 2e boot via `:autologon` (registry autorun se4install + sysprep.exe /generalize /oobe + curl ret=1). Sans ce template, le flow `sysprep` complet 3.8 reste muet sur le 2e boot.
- Le test parité `it_generates_cmd_sysprep_byte_equivalent_to_legacy_fixture` doit faire `$this->markTestSkipped('Legacy never serves cmd_sysprep block as response body — see _README.md')`.
- Le test structurel `it_renders_cmd_sysprep_with_autologon_block_and_curl_callback` doit valider la **structure attendue** (présence de labels `:gpo`, `:autologon`, `:sysprep`, curl avec ret=1, etc.).

### 3. `nosysprep` — refacto clarté (Q-2)

Décision Henri 2026-05-25 : refacto clarté = le poste SE5 curl `etape=nosysprep` distinct (pas `etape=sysprep&ret=2` comme legacy). 

**Conséquence parité** : pas de fixture legacy pour `nosysprep` strict (le legacy ne sert pas de body sur `etape=nosysprep` non plus — lignes 430-434, juste set_progress 50% sans body).

Le test parité `it_generates_cmd_nosysprep_byte_equivalent_to_legacy_fixture` doit faire `$this->markTestSkipped('Q-2 refacto clarté — SE5 diverges from legacy intentionally for state machine clarity. See story 3.8 D2/D7.')`.

Le test structurel doit valider que `buildNosysprep()` génère un body cohérent avec logique attendue (autologon adminse + Remove-Computer + curl etape=nosysprep&ret=0).

## Régénération future

Si Henri veut une fixture `sysprep.txt` complète (mode clonage) :

```bash
# Sur /vm via menu admin web SE5 :
# 1. Naviguer admin → parc → pc-techno-25 → Programmer action → Clonage (type=clonage, role=modele)
# 2. Vérifier APCu FPM : `sudo -u www-admin php-fpm <one-liner-fetch_action>` (ou tinker SE5)
# 3. curl --data 'name=pc-techno-25&uuid=12345678-...&etape=sysprep&mac=...' http://localhost/ipxe/Win10/action.php > sysprep.txt
# 4. Restore default : admin → parc → pc-techno-25 → Annuler action
```

Tant que la fixture sysprep n'est pas régénérée, le test parité reste skipped.
