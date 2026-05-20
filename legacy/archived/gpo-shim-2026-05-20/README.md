# Archive : gpo_shim.inc.php (Story 1bis.18g)

Archivé le 2026-05-20 par Story 16.13bis (Sprint Change Proposal 2026-05-19).

## Raison

Le modèle de migration SE4 → SE5 ayant basculé en **fragment+reboot
stateless** (`App\Auth\V1\Migration`), le shim 1bis.18g (bridge Kerberos
pour `gpo_shim` + fallbacks SYSVOL) n'est plus utilisé par
`sambaedu-reload` :

- Les fonctions natives Laravel (`App\Gpo\*Service`) couvrent désormais
  la lecture/écriture GPO sans avoir besoin des fallbacks `_shim_gpo_*`.
- Le bootstrap legacy `legacy/bootstrap.php` n'effectue plus le
  `require_once 'gpo_shim.inc.php'`.

## Helpers laissés dans `legacy/ldap.inc.php`

Les helpers `_shim_gpo_*` définis dans `legacy/ldap.inc.php` (lignes
~298+, 362, 754, 1029) restent en place — ils sont guard-protégés par
`function_exists()` et n'introduisent aucune surcharge à l'init quand
ils ne sont pas appelés. Leur suppression complète pourra être traitée
dans une story de cleanup ultérieure si nécessaire.

## Stubs `legacy/stubs/gpo_deps.inc.php`

Le fichier `legacy/stubs/gpo_deps.inc.php` est conservé : il est aussi
consommé par `legacy/stubs/partages.inc.php`, donc pas exclusivement lié
au shim 1bis.18g.

## Références

- `_bmad-output/implementation-artifacts/16-13bis-module-migration-simplifie.md`
- `_bmad-output/planning-artifacts/sprint-change-proposal-2026-05-19.md`
- Story 1bis.18g (création initiale du shim)
