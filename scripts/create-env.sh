#!/bin/bash
set -e

# Copier .env.example à .env
cp .env.example .env

# Générer APP_KEY sans bootstrap Laravel (vendor/ pas encore installé)
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
sed -i "s|APP_KEY=.*|APP_KEY=$APP_KEY|" .env

# Générer SE4FS_INSTANCE_ID et SE4FS_INSTANCE_API_KEY
if command -v uuidgen >/dev/null 2>&1; then
    SE4FS_INSTANCE_ID=$(uuidgen)
    sed -i "s|SE4FS_INSTANCE_ID=.*|SE4FS_INSTANCE_ID=$SE4FS_INSTANCE_ID|" .env
fi

SE4FS_INSTANCE_API_KEY=$(php -r 'echo "se4fs_instance_".bin2hex(random_bytes(16));')
sed -i "s|SE4FS_INSTANCE_API_KEY=.*|SE4FS_INSTANCE_API_KEY=$SE4FS_INSTANCE_API_KEY|" .env

# Générer un mot de passe Redis sécurisé (10 premiers caractères)
REDIS_PASSWORD=$(openssl rand -base64 32 | cut -c1-10)
sed -i "s|REDIS_PASSWORD=.*|REDIS_PASSWORD=$REDIS_PASSWORD|" .env

# Charger les valeurs depuis /etc/sambaedu/sambaedu.conf si disponible
if [ -f "/etc/sambaedu/sambaedu.conf" ]; then
    source <(grep -E '^[a-z_]+ = ' /etc/sambaedu/sambaedu.conf | sed 's/ = /=/g')

    [ -n "$se4ad_ip" ] && sed -i "s|SAMBAEDU_SE4AD_IP=.*|SAMBAEDU_SE4AD_IP=$se4ad_ip|" .env

    # SE4AD_ETAB_IP : utiliser se4ad_etab_ip si défini, sinon fallback à se4ad_ip
    se4ad_etab_ip_value="${se4ad_etab_ip:-$se4ad_ip}"
    [ -n "$se4ad_etab_ip_value" ] && sed -i "s|SAMBAEDU_SE4AD_ETAB_IP=.*|SAMBAEDU_SE4AD_ETAB_IP=$se4ad_etab_ip_value|" .env
    [ -n "$se4ad_etab_ip_value" ] && sed -i "s|SAMBAEDU_LDAP_HOST=.*|SAMBAEDU_LDAP_HOST=$se4ad_etab_ip_value|" .env
    [ -n "$ldap_port" ] && sed -i "s|SAMBAEDU_LDAP_PORT=.*|SAMBAEDU_LDAP_PORT=$ldap_port|" .env
    [ -n "$ldap_base_dn" ] && sed -i "s|SAMBAEDU_LDAP_BASE_DN=.*|SAMBAEDU_LDAP_BASE_DN=\"$ldap_base_dn\"|" .env
    [ -n "$ldap_admin_name" ] && sed -i "s|SAMBAEDU_LDAP_ADMIN_USER=.*|SAMBAEDU_LDAP_ADMIN_USER=$ldap_admin_name|" .env
    [ -n "$ldap_admin_passwd" ] && sed -i "s|SAMBAEDU_LDAP_ADMIN_PASSWORD=.*|SAMBAEDU_LDAP_ADMIN_PASSWORD=$ldap_admin_passwd|" .env
    [ -n "$domain" ] && sed -i "s|SAMBAEDU_LDAP_DOMAIN=.*|SAMBAEDU_LDAP_DOMAIN=$domain|" .env
    [ -n "$sql_passwd" ] && sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$sql_passwd|" .env
    [ -n "$se4_url" ] && sed -i "s|APP_URL=.*|APP_URL=http://$se4_url|" .env
fi

# Charger les valeurs depuis /etc/msmtprc (configuration SMTP)
if [ -f "/etc/msmtprc" ]; then
    mail_host=$(grep '^host ' /etc/msmtprc | awk '{print $2}' | head -1)
    mail_port=$(grep '^port ' /etc/msmtprc | awk '{print $2}' | head -1)
    mail_from=$(grep '^from ' /etc/msmtprc | awk '{print $2}' | head -1)
    mail_tls=$(grep '^tls ' /etc/msmtprc | awk '{print $2}' | head -1)
    mail_user=$(grep '^user ' /etc/msmtprc | awk '{print $2}' | head -1)

    [ -n "$mail_host" ] && sed -i "s|MAIL_HOST=.*|MAIL_HOST=$mail_host|" .env
    [ -n "$mail_port" ] && sed -i "s|MAIL_PORT=.*|MAIL_PORT=$mail_port|" .env
    [ -n "$mail_from" ] && sed -i "s|MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=\"$mail_from\"|" .env
    [ "$mail_tls" = "on" ] && sed -i "s|MAIL_ENCRYPTION=.*|MAIL_ENCRYPTION=tls|" .env
    [ -n "$mail_user" ] && sed -i "s|MAIL_USERNAME=.*|MAIL_USERNAME=$mail_user|" .env
fi

# Charger l'adresse root depuis /etc/aliases (MAIL_FROM_NAME)
if [ -f "/etc/aliases" ]; then
    root_mail=$(grep '^root:' /etc/aliases | awk '{print $2}' | head -1)
    [ -n "$root_mail" ] && sed -i "s|MAIL_FROM_NAME=.*|MAIL_FROM_NAME=\"SambaEdu <$root_mail>\"|" .env
fi

echo ".env créé avec succès"
