#!/bin/bash

# Install docker for database

# uninstall possibly conflicting packages.
[ -f /etc/apt/sources.list.d/docker.list ] && sudo rm /etc/apt/sources.list.d/docker.list && echo "Supprimé" || echo "Fichier absent"
sudo apt remove $(dpkg --get-selections docker.io docker-compose docker-doc podman-docker containerd runc | cut -f1)

# Add Docker's official GPG key:
sudo apt update
sudo apt install ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

# Add the repository to Apt sources:
sudo tee /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
Components: stable
Signed-By: /etc/apt/keyrings/docker.asc
EOF

sudo apt update
sudo apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# clone sources
cd /root && git clone https://gitlab.sambaedu.org/sambaedu/se4.git
cd /root/se4/sources/var/www/sambaedu/laravel/scripts
chmod +x create-env.sh
