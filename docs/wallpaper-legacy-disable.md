# Désactivation legacy `gpo/wallpaper_out.php`

> Story 4.7 — Task 3.2 / AC 7. Procédure manuelle, pas d'automatisation
> destructive sur prod.

## Prérequis

- Le code Laravel story 4.7 est déployé sur la VM.
- Les tests `LegacyOutEndpointTest` passent.
- Le seeder `WallpaperFromFilesystemSeeder` a été exécuté au moins une fois :
  ```bash
  ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
    'cd /var/www/sambaedu-reload && php artisan db:seed --class=WallpaperFromFilesystemSeeder'
  ```

## Désactivation (ssh VM)

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu/gpo
# Sauvegarde atomique
mv wallpaper_out.php wallpaper_out.php.legacy
```

Laravel intercepte désormais `gpo/wallpaper_out.php` via la route
`wallpaper.legacy` (déclarée dans `routes/web.php` avant le catchall
legacy).

## Smoke test post-désactivation

```bash
# Depuis la VM, générer un id APCu fictif et vérifier la réponse
curl -sSf -X POST 'http://localhost/gpo/wallpaper_out.php' \
  -F 'action=wallpaper' \
  -F 'id=00000000000000000000000000000000' \
  -F 'format=jpg' \
  -o /tmp/test.jpg
file /tmp/test.jpg
# Attendu : "JPEG image data, JFIF standard" OU "404 Context expired"
```

## Rollback (30 secondes)

Si bug critique : restaurer le legacy.

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu/gpo
mv wallpaper_out.php.legacy wallpaper_out.php
```

La route Laravel reste en place (pas de conflit, PHP legacy est appelé
en premier par Apache car matché sur le filesystem avant le fallback
Laravel).

## Vérif côté clients (Linux)

```bash
# Sur un poste Linux avec samba-edu-client :
sudo /usr/share/sambaedu/applications/wallpaper/logon.linux
ls -la $HOME/.config/wallpaper-*.jpg
```

## Vérif côté clients (Windows)

Logoff + login. Vérifier `%WINDIR%\Web\SE4\wallpaper.jpg` (fichier mis à
jour + horodatage récent).
