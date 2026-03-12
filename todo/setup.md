# Setup Apache : remplacer sambaedu par sambaedu-reload

## Situation actuelle

| Site | Fichier | Port | DocumentRoot |
|------|---------|------|--------------|
| sambaedu (actif) | `/etc/apache2/sites-enabled/sambaedu.conf` | 80 | `/var/www/sambaedu/` |
| sambaedu-reload (actif) | `/etc/apache2/sites-enabled/sambaedu-reload.conf` | 8080 | `/var/www/sambaedu-reload/public` |
| default-ssl (actif) | `/etc/apache2/sites-available/default-ssl.conf` | 443 | `/var/www/html` (à corriger) |

## Ce qu'il faut faire

### 1. Désactiver l'ancien site

```bash
sudo a2dissite sambaedu.conf
```

### 2. Modifier sambaedu-reload.conf pour écouter sur les ports 80 et 443

Fichier : `/etc/apache2/sites-available/sambaedu-reload.conf`

```apache
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/sambaedu-reload/public

    <FilesMatch "\.php$">
        SetHandler "proxy:fcgi://127.0.0.1:9000/"
    </FilesMatch>

    <Directory /var/www/sambaedu-reload/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog /var/log/apache2/sambaedu-reload-error.log
    CustomLog /var/log/apache2/sambaedu-reload-access.log combined
</VirtualHost>

<VirtualHost *:443>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/sambaedu-reload/public

    SSLEngine on
    SSLCertificateFile      /etc/ssl/certs/ssl-cert-snakeoil.pem
    SSLCertificateKeyFile   /etc/ssl/private/ssl-cert-snakeoil.key

    <FilesMatch "\.php$">
        SetHandler "proxy:fcgi://127.0.0.1:9000/"
    </FilesMatch>

    <Directory /var/www/sambaedu-reload/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog /var/log/apache2/sambaedu-reload-ssl-error.log
    CustomLog /var/log/apache2/sambaedu-reload-ssl-access.log combined
</VirtualHost>
```

> Le fichier source dans le projet est `config/apache/sambaedu-reload.conf` — à mettre à jour aussi pour cohérence.

### 3. S'assurer que le module SSL est activé

```bash
sudo a2enmod ssl
```

### 4. Désactiver le default-ssl qui prend le port 443

```bash
sudo a2dissite default-ssl.conf
```

### 5. Activer et recharger

```bash
sudo a2ensite sambaedu-reload.conf
sudo systemctl reload apache2
```

## Notes

- Le certificat utilisé est le snakeoil auto-signé Debian (`ssl-cert-snakeoil.pem`). Si un vrai certificat (Let's Encrypt, etc.) est disponible, remplacer `SSLCertificateFile` et `SSLCertificateKeyFile` en conséquence.
- L'ancien `sambaedu.conf` contient des alias spécifiques (`/images`, `/os`, `/doc/`, `/cgi-bin/`) qui pointent vers `/var/sambaedu/`. Vérifier si ces routes sont encore nécessaires ou si elles doivent être portées dans la nouvelle conf.
- `sambaedu-reload.conf` utilise PHP-FPM via `proxy:fcgi://127.0.0.1:9000/` — s'assurer que PHP-FPM tourne bien avant de recharger.
