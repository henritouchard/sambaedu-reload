- [ ] 8-2-7 => téléchargement de nouveau wpkg bloquants

# Suppression du code mort VM (Story 27.14) — à faire APRÈS merge sur main

> Deux nettoyages distincts sur la VM (jamais depuis un worktree) : (1) neutralisation réversible des **fichiers de config serveur** legacy (Bucket A), (2) suppression des **fichiers de code fantômes** (deletes non propagés par inotify).

## 1. Neutralisation réversible des fichiers config serveur (Bucket A — vérifié sur la VM 2026-06-19)

> Détail complet + cartographie A/B/C/D + rollback : story `27-14`, section « Cartographie serveur RÉELLE ». Pré-check sûr : `find /usr/share/sambaedu/gpo -iname '*agent*bootstrap*'` DOIT être vide (le bootstrap est dans le repo, pas ici).

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
# Templates GPO de config legacy (dépôt git vendoré ; bootstrap absent d'ici, confirmé)
mv -v /usr/share/sambaedu/gpo/sambaedu-gpo    /usr/share/sambaedu/gpo/sambaedu-gpo.disabled-27.14
mv -v /usr/share/sambaedu/gpo/etab_Bureau.zip /usr/share/sambaedu/gpo/etab_Bureau.zip.disabled-27.14
# Surcharges config app legacy (firefox/default.json + gpos.json = seuls non-vides ; thunderbird/veyon vides)
mv -v /etc/sambaedu/applications/firefox     /etc/sambaedu/applications/firefox.disabled-27.14
mv -v /etc/sambaedu/applications/gpos.json   /etc/sambaedu/applications/gpos.json.disabled-27.14
mv -v /etc/sambaedu/applications/thunderbird /etc/sambaedu/applications/thunderbird.disabled-27.14
mv -v /etc/sambaedu/applications/veyon       /etc/sambaedu/applications/veyon.disabled-27.14
# NE PAS toucher : shortcuts/ (B, encore lu), winget/+wallpaper/ (D), sambaedu.conf/keytab/... (D),
#   /var/sambaedu/unattended/install/ (D), paquets sambaedu-client-* + se4.list (B).
# Observation ~1-2 semaines → suppression sèche (trash) des *.disabled-27.14 si zéro régression.
```

## 2. Story 27-14 — Nettoyage post-merge sur la VM (fantômes inotify)

> **Contexte** : `inotify` synchronise les créations/modifications de fichiers vers la VM mais **PAS les suppressions** (cf. mémoire `project_inotify_no_delete_sync`). Après merge de `worktree-nolegacy` sur `main`, les **103 fichiers de code supprimés** par 27-14 resteront présents sous `/var/www/sambaedu-reload/` côté VM. Sans nettoyage : classes mortes encore autoloadables, et surtout **suite de tests rouge sur la VM** (les tests fantômes référencent des classes supprimées). À faire **après le merge**, sur la VM, jamais depuis un worktree.

- [ ] **27-14.a** — Supprimer les 103 fichiers fantômes sur la VM
- [ ] **27-14.b** — Supprimer les répertoires désormais vides
- [ ] **27-14.c** — Régénérer autoload + caches (inotify ne lance ni `composer` ni `artisan`)
- [ ] **27-14.d** — Vérifier (`artisan about` + suite ciblée verte)
- [ ] **27-14.e** — (rappel) neutraliser les fichiers serveur Bucket A — voir story `27-14`, section « Plan de neutralisation Bucket A »

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu-reload

# --- 27-14.a : supprimer les fichiers fantômes (rm -f par fichier, jamais rm -rf) ---
while read -r f; do [ -n "$f" ] && rm -fv "$f"; done <<'EOF'
app/Auth/V1/Migration/Exceptions/CaUnavailableException.php
app/Auth/V1/Migration/Http/Controllers/MigrationController.php
app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php
app/Auth/V1/Migration/Services/MigrationStatusChecker.php
app/Auth/V1/Migration/Support/MigrationMessages.php
app/Console/Commands/AuditApplicationsGpoTemplateCommand.php
app/Console/Commands/CompileShortcutsCommand.php
app/Dto/Gpo/WorkstationConfigContext.php
app/Gpo/Dto/WpkgGpoSyncReport.php
app/Gpo/Enums/WpkgGpoSyncSeverity.php
app/Gpo/Services/ApplicationLoggerService.php
app/Gpo/Services/ApplicationScriptsAssembler.php
app/Gpo/Services/ApplicationScriptsGenerator.php
app/Gpo/Services/ApplicationTemplatesScanner.php
app/Gpo/Services/AssociationsResolver.php
app/Gpo/Services/GpoPublisher.php
app/Gpo/Services/NetworkScriptGenerator.php
app/Gpo/Services/ReadUserManager.php
app/Gpo/Services/VeyonConfigGenerator.php
app/Gpo/Services/WorkstationConfigContextResolver.php
app/Gpo/Services/WpkgGpoSynchronizer.php
app/Http/Controllers/Api/v1/ShortcutExportController.php
app/Http/Controllers/AppPolicyController.php
app/Http/Controllers/Gpo/ApplicationsScriptsController.php
app/Http/Controllers/Gpo/AssociationsOutController.php
app/Http/Controllers/Gpo/NetworkOutController.php
app/Http/Controllers/Gpo/VeyonOutController.php
app/Http/Controllers/OverlayController.php
app/Models/CompiledShortcut.php
app/Observers/ShortcutObserver.php
app/Services/ShortcutCompilerService.php
app/Services/WindowsLnkGenerator.php
app/Wpkg/Deployment/Console/Commands/WpkgGpoSyncCommand.php
legacy/modules/gpo/applications.php
legacy/modules/gpo/associations_out.php
legacy/modules/gpo/gestion_gpo.php
legacy/modules/gpo/gpo-export.php
legacy/modules/gpo/gpo-maj.php
legacy/modules/gpo/network_out.php
legacy/modules/gpo/veyon_out.php
legacy/modules/gpo/wine.php
resources/views/auth/v1/migration/fragment-cmd.blade.php
resources/views/auth/v1/migration/fragment-noop-cmd.blade.php
resources/views/auth/v1/migration/fragment-noop-sh.blade.php
resources/views/auth/v1/migration/fragment-sh.blade.php
tests/Architecture/ApiV1ConfigRoutesTest.php
tests/Architecture/Migration/MigrationModuleArchitectureTest.php
tests/Concerns/AssertsScriptParity.php
tests/Feature/Api/V1/Config/ApiV1ConfigSecurityTest.php
tests/Feature/Api/V1/Config/ApplicationsScriptsApiV1Test.php
tests/Feature/Api/V1/Config/AssociationsApiV1Test.php
tests/Feature/Api/V1/Config/FirefoxApiV1Test.php
tests/Feature/Api/V1/Config/NetworkApiV1Test.php
tests/Feature/Api/V1/Config/OverlayApiV1Test.php
tests/Feature/Api/V1/Config/ShortcutsApiV1Test.php
tests/Feature/Api/V1/Config/ThunderbirdApiV1Test.php
tests/Feature/Api/V1/Config/VeyonApiV1Test.php
tests/Feature/Api/V1/Config/WallpaperApiV1Test.php
tests/Feature/AppCustomization/AppPolicyCanonicalEndpointTest.php
tests/Feature/AppCustomization/AppPolicyLegacyEndpointTest.php
tests/Feature/Auth/V1/Migration/MigrationControllerTest.php
tests/Feature/Auth/V1/Migration/MigrationE2EScenarioTest.php
tests/Feature/Console/Wpkg/WpkgGpoSyncCommandTest.php
tests/Feature/Gpo/ApplicationsScriptsApiV1Test.php
tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php
tests/Feature/Gpo/ApplicationsScriptsComparisonTest.php
tests/Feature/Gpo/ApplicationsScriptsCriticalParityTest.php
tests/Feature/Gpo/ApplicationsScriptsEndpointTest.php
tests/Feature/Gpo/ApplicationsScriptsLocalOverrideTest.php
tests/Feature/Gpo/ApplicationsScriptsSecurityTest.php
tests/Feature/Gpo/ApplicationsScriptsWrapperIntegrationTest.php
tests/Feature/Gpo/AssociationsOutComparisonTest.php
tests/Feature/Gpo/AssociationsOutEndpointTest.php
tests/Feature/Gpo/AssociationsOutRouteRegistrationTest.php
tests/Feature/Gpo/AuditApplicationsGpoTemplateCommandTest.php
tests/Feature/Gpo/GpoDetailPublishTest.php
tests/Feature/Gpo/GpoIndexPublishTest.php
tests/Feature/Gpo/LegacyGestionGpoRedirectTest.php
tests/Feature/Gpo/NetworkOutComparisonTest.php
tests/Feature/Gpo/NetworkOutEndpointTest.php
tests/Feature/Gpo/NetworkOutSecurityTest.php
tests/Feature/Gpo/NetworkVeyonRouteRegistrationTest.php
tests/Feature/Gpo/VeyonOutComparisonTest.php
tests/Feature/Gpo/VeyonOutEndpointTest.php
tests/Feature/Gpo/WpkgDeploymentPageTest.php
tests/Feature/Legacy/LegacyModuleGpoGestionTest.php
tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php
tests/Feature/Shortcuts/ShortcutExportComparisonTest.php
tests/Feature/Wallpaper/LegacyOutEndpointTest.php
tests/Unit/Auth/V1/Migration/MigrationFragmentRendererTest.php
tests/Unit/Auth/V1/Migration/MigrationStatusCheckerTest.php
tests/Unit/Gpo/ApplicationLoggerServiceTest.php
tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php
tests/Unit/Gpo/ApplicationScriptsGeneratorTest.php
tests/Unit/Gpo/ApplicationTemplatesScannerTest.php
tests/Unit/Gpo/AssociationsResolverTest.php
tests/Unit/Gpo/Dto/WpkgGpoSyncReportTest.php
tests/Unit/Gpo/NetworkScriptGeneratorTest.php
tests/Unit/Gpo/ReadUserManagerTest.php
tests/Unit/Gpo/Services/WorkstationConfigContextResolverTest.php
tests/Unit/Gpo/SubstitutionsTest.php
tests/Unit/Gpo/VeyonConfigGeneratorTest.php
tests/Unit/Gpo/WpkgGpoSynchronizerTest.php
EOF

# --- 27-14.b : supprimer les répertoires désormais vides (find -empty -delete = sûr, jamais rm -rf) ---
find app/Auth/V1/Migration tests/Architecture/Migration tests/Feature/Auth/V1/Migration \
     tests/Unit/Auth/V1/Migration resources/views/auth/v1/migration tests/Feature/Api/V1/Config \
     tests/Feature/Legacy tests/Unit/Gpo/Dto tests/Unit/Gpo/Services tests/Feature/Console/Wpkg \
     -depth -type d -empty -delete 2>/dev/null
# (find -empty ne touche que les dossiers vides : aucun risque pour les dossiers à survivants — ex. app/Gpo/Services, tests/Feature/Shortcuts)

# --- 27-14.c : régénérer autoload + caches (inotify ne lance ni composer ni artisan) ---
composer dump-autoload -o
php artisan config:cache    # config/sambaedu.php a changé (cf. project_vm_config_cache_not_synced)
php artisan route:cache     # routes/web.php + api.php ont changé (cf. project_route_cache_vm_ephemeral_test_routes)
php artisan view:clear      # vues blade de migration supprimées
chown -R www-admin:www-admin bootstrap/cache   # PHP-FPM tourne en www-admin (cf. project_php_fpm_user_www_admin)

# --- 27-14.d : vérifier ---
php artisan about >/dev/null && echo "boot OK"
php artisan route:list 2>/dev/null | grep -E 'gpo/.*_out|workstation-config|shortcuts/export|app-policy' && echo "RESTE DU LEGACY !" || echo "0 route legacy de config OK"
# (optionnel) suite ciblée — JAMAIS un run massif (cf. project_vm_phpunit_bulk_run_false_failures) :
# php artisan test --filter 'Gpo|Shortcut|Agent|Architecture'
```

> **Garde-fous** : ne PAS toucher au bootstrap `resources/gpo/se4_agent_bootstrap/` (survit) ni aux fichiers MODIFIÉS (eux sont synchronisés normalement par inotify). La liste ci-dessus = uniquement les fichiers en statut `D` du diff 27-14.
