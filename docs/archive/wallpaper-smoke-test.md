# Smoke test VM — Story 4.7

> Procédure manuelle à exécuter par henri sur la VM (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`) après synchronisation du worktree `wallpapers` sur `/var/www/sambaedu-reload/` (le watcher inotifywait actuel surveille `sambaedu-reload`, pas `wallpapers` — cf. `feedback_no_rsync.md` à ajuster si besoin).

## 0. Prérequis

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu-reload
php -m | grep -E '^(imagick|apcu)$'          # doit retourner les deux
```

## 1. Lancer les tests

```bash
php vendor/bin/phpunit --filter 'Wallpaper' --colors=never 2>&1 | tail -40
```

Attendus :
- `WallpaperResolverTest` : 14 tests verts
- `WallpaperComposerTest` : 9 tests verts (skippé sans Imagick)
- `LegacyOutEndpointTest` : 8 tests verts
- `WallpaperUploadServiceTest` : 7 tests verts
- `WallpaperFromFilesystemSeederTest` : 7 tests verts

## 2. Seeder filesystem → DB

```bash
php artisan db:seed --class=WallpaperFromFilesystemSeeder
# attendu : "Wallpaper seed — X scannés, Y importés, Z skippés, N orphans"
```

## 3. Désactivation legacy (atomique)

```bash
cd /var/www/sambaedu/gpo
mv wallpaper_out.php wallpaper_out.php.legacy
```

## 4. Smoke tests endpoint

```bash
# 400 sur action inconnue
curl -sS -o /dev/null -w '%{http_code}\n' -X POST http://localhost/gpo/wallpaper_out.php \
  -F 'action=icone' -F 'id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
# attendu : 400

# 404 sur id inconnu
curl -sS -o /dev/null -w '%{http_code}\n' -X POST http://localhost/gpo/wallpaper_out.php \
  -F 'action=wallpaper' -F 'id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
# attendu : 404 (APCu vide)

# 200 + JPEG sur contexte valide (faut un vrai id APCu issu d'un login légitime)
# Se connecter à SER, récupérer $_APCU dans un dump ou générer via logon
```

## 5. Côté client Linux

```bash
# Sur un poste samba-edu-client
sudo /usr/share/sambaedu/applications/wallpaper/logon.linux
ls -la $HOME/.config/wallpaper-*.jpg
file $HOME/.config/wallpaper-*.jpg
# attendu : "JPEG image data, JFIF standard"
```

## 6. Galerie de prévisualisation design (optionnel)

```bash
php artisan wallpaper:preview
ls storage/app/wallpaper-previews/
# ouvrir les PNG dans un visualiseur (scp vers machine locale si besoin)
```

## 7. Rollback express (si KO)

```bash
cd /var/www/sambaedu/gpo
mv wallpaper_out.php.legacy wallpaper_out.php
# le legacy reprend en 30s (Apache match FS en premier)
```

## 8. Rebuild badges (one-shot, après éventuelle modif SVG sources)

```bash
php artisan wallpaper:rebuild-badges
```
