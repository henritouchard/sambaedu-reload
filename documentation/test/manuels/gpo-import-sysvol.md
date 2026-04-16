# Test manuel — Import GPO via SYSVOL (Story 1bis.18g)

> Procédure de validation e2e VM pour les shims GPO/SYSVOL/Kerberos introduits
> par la story 1bis.18g. Non automatisable host-side (requiert AD Samba réel +
> ticket Kerberos + SYSVOL SMB vivant).

## Prérequis

- VM SER démarrée et accessible en SSH :
  ```
  ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
  ```
- DC Samba fonctionnel sur la VM (`samba-tool gpo listall` répond).
- Ticket Kerberos valide dans `KRB5CCNAME`. Si absent ou expiré :
  ```
  /usr/share/sambaedu/sbin/renew_ticket.sh
  ```
- Le code w1bis déployé côté `/var/www/sambaedu-reload/` (la chaîne de sync
  habituelle — le watch local ne sync que `sambaedu-reload/`).

## Scénario 1 — Import nominal (AC #6)

### Étapes

1. **Snapshot avant import** (sur la VM) :
   ```
   samba-tool gpo listall > /tmp/gpo-before.txt
   grep -c "Wallpaper" /tmp/gpo-before.txt  # devrait être 0
   ```

2. **Depuis le host**, naviguer : `https://<vm>/gpo/gpo-maj.php`.

3. Cocher la case GPO **« Wallpaper »**. Cliquer **Valider**.

4. **Attendu UI** : bandeau vert « Importation via Git OK ».

### Vérifications

- [ ] GPO créée côté AD :
  ```
  samba-tool gpo listall | grep Wallpaper
  # Attendu : une ligne avec un GUID {XXXXXXXX-...}
  ```

- [ ] SYSVOL contient le fichier Registry.pol spécialisé :
  ```
  GUID=$(samba-tool gpo listall | grep -A1 "Wallpaper" | grep GPO | awk '{print $2}')
  DOMAIN=$(samba-tool domain info 127.0.0.1 | grep '^Domain' | awk '{print $3}')
  smbclient -k "//${DOMAIN}/SYSVOL" -c "ls \\${DOMAIN}\\Policies\\${GUID}\\"
  # Attendu : Registry.pol présent
  ```

- [ ] Pas d'ERROR/CRITICAL dans le log applicatif (AC #12) :
  ```
  tail -50 /var/www/sambaedu-reload/laravel/storage/logs/laravel.log \
    | grep -E '\[ERROR\]|\[CRITICAL\]'
  # Attendu : aucune ligne, ou rien de postérieur au clic Valider
  ```

## Scénario 2 — Idempotence (AC #7)

### Étapes

Sans quitter la page, **re-cliquer Valider** (2ᵉ soumission du même import).

### Vérifications

- [ ] Bandeau UI : « Importation via Git OK » (ou équivalent explicite — le
  point-clé est l'absence d'erreur).

- [ ] **Pas** de nouvel appel `samba-tool gpo create` (pas d'erreur
  `GPO already existing` dans les logs). Vérifier dans laravel.log qu'il n'y
  a pas de stack trace sur `gpocreate`.

- [ ] Côté AD, toujours **une seule** entrée Wallpaper :
  ```
  samba-tool gpo listall | grep -c "Wallpaper"
  # Attendu : 1 (pas 2)
  ```

- [ ] Le `versionnumber` a été incrémenté (`specialise_gpo` + `modify_ad(type=gpo)`
  + `update_gpo_sysvol`) :
  ```
  ldbsearch -H /var/lib/samba/private/sam.ldb '(displayname=Wallpaper)' versionnumber
  # Attendu : versionnumber incrémenté par rapport au 1ᵉʳ import
  ```

## Troubleshooting

### « Kerberos ticket expired »

Dans laravel.log, cherche : `NT_STATUS_NO_LOGON_SERVERS` ou
`sysvol_put: smbclient failed` :
```
/usr/share/sambaedu/sbin/renew_ticket.sh
klist  # confirmer ticket actif
```
Puis relancer le scénario.

### « LDAP connection refused / timeout »

Les timeouts shim GPO sont calibrés : `LDAP_OPT_NETWORK_TIMEOUT=10s` et
`LDAP_OPT_TIMELIMIT=30s` (cf. `legacy/ldap.inc.php::_shim_gpo_ldap_connect`).
Si le DC hang (rare mais possible sur LocalKDC saturé) :
```
systemctl status samba-ad-dc
journalctl -u samba-ad-dc -n 50
systemctl restart samba-ad-dc  # en dernier recours
```

### GPO recréée au 2ᵉ submit (bug initial avant 18g)

Si ce comportement réapparaît, vérifier que les shims `search_ad(type=gpo)`
sont bien chargés : dans laravel.log cherche
`_shim_gpo_search: ldap_search(...) failed` — ce serait une régression #2 de
la review. Reproduire host-side avec `php artisan test --filter=LegacyGpoShims`.

## Résultats à reporter

Une fois les 2 scénarios validés, compléter dans la story
`_bmad-output/implementation-artifacts/1bis-18g-module-gpo-shims-ldap-sysvol.md` :

- Section **Dev Agent Record → Debug Log References** : coller la sortie de
  `samba-tool gpo listall` avant/après import + le `smbclient ls` du SYSVOL.
- Cocher **T6.2** et **T6.3** de la Phase 6.
- Basculer `sprint-status.yaml` : `1bis-18g: review` → `done`.

## Références

- Story : `_bmad-output/implementation-artifacts/1bis-18g-module-gpo-shims-ldap-sysvol.md`
- Code review : `_bmad-output/codeReviews/1bis-18g.md`
- Shims : `legacy/ldap.inc.php::_shim_gpo_*`, `legacy/gpo_shim.inc.php`
- Sprint change proposal d'origine : `_bmad-output/planning-artifacts/sprint-change-proposal-2026-04-15.md`
