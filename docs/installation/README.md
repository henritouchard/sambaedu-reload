# 📦 Installation & Mise à jour

Documentation complète pour l'installation et la mise à jour de SambaEdu.

## 🚀 Démarrage rapide (une seule commande!)

```bash
cd /var/www/sambaedu-reload
sudo ./scripts/install.sh
```

**C'est tout!** Le script gère automatiquement:
- ✅ Installation Docker
- ✅ Génération du `.env` (avec interaction)
- ✅ Déploiement PostgreSQL + Redis
- ✅ Installation Composer
- ✅ Installation et build NPM
- ✅ Migrations de base de données
- ✅ Optimisation applicative

## 📑 Documentation complète

### Installation
- **[INSTALL.md](./INSTALL.md)** - Guide d'installation complet
  - Prérequis système
  - Installation en une commande
  - Interaction utilisateur
  - Dépannage rapide

### Scripts
- **[SCRIPTS.md](./SCRIPTS.md)** - Référence technique détaillée (12KB)
  - `scripts/install.sh` - Installation complète
  - `scripts/update.sh` - Mise à jour application
  - `scripts/cleanup.sh` - Gestion des services
  - `scripts/create-env.sh` - Génération configuration
  - Flux complet avec diagrammes
  - Commandes de vérification

### Dépannage
- **[TROUBLESHOOTING.md](./TROUBLESHOOTING.md)** - Résolution des problèmes (9.2KB)
  - 🐳 Problèmes Docker
  - 🔌 Problèmes de ports
  - 🔐 Problèmes de permissions
  - 💾 Problèmes base de données
  - 🚀 Problèmes d'application
  - 📝 Problèmes de scripts
  - 🔍 Diagnostic et commands utiles

## ⚙️ Mises à jour régulières

Après une installation initiale, pour mettre à jour:

```bash
sudo ./scripts/update.sh
```

Cela met à jour:
- Dépendances PHP (Composer)
- Frontend (NPM)
- Base de données (migrations)
- Configuration Apache
- Services systemd

## 📋 Autres catégories de documentation

- **`architecture/`** - Architecture technique de l'application
- **`databases/`** - Configuration des bases de données (LDAP, PostgreSQL)
- **`CLI/`** - Commandes Artisan et outils CLI
- **`Laravel/`** - Spécificités Laravel (Livewire, etc.)
- **`UX/`** - Interface utilisateur et composants
- **`Best practices/`** - Bonnes pratiques de développement
- **`misc/`** - Divers (GPO, outils, swagger)
- **`installation/`** - Installation et mise à jour ← **vous êtes ici**

## 🎯 Flux d'installation visuel

```
┌─────────────────────────────────────────┐
│  sudo ./scripts/install.sh              │
├─────────────────────────────────────────┤
│                                         │
│  Phase 1: Vérifications ✓               │
│  - bash, Docker, Docker Compose         │
│  - PHP, Composer, NPM                   │
│                                         │
│  Phase 2: Configuration ✓               │
│  - Génère .env                          │
│  - Vous demande de vérifier/éditer      │
│  - Déploie Docker                       │
│                                         │
│  Phase 3: Dépendances ✓                 │
│  - Composer install                     │
│  - NPM install + build                  │
│                                         │
│  Phase 4: Base de données ✓             │
│  - php artisan migrate:fresh            │
│                                         │
│  Phase 5: Optimisation ✓                │
│  - php artisan sambaedu:app:update      │
│  - Caches + routes + vues               │
│                                         │
│  ✅ Installation terminée!              │
│                                         │
└─────────────────────────────────────────┘
```

## 🔍 Besoin d'aide?

1. **Installation échoue?** → Voir [TROUBLESHOOTING.md](./TROUBLESHOOTING.md)
2. **Questions sur les scripts?** → Voir [SCRIPTS.md](./SCRIPTS.md)
3. **Configuration personnalisée?** → Voir [INSTALL.md](./INSTALL.md)
