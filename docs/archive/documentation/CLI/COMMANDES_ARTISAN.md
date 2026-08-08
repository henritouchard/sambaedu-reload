# Commandes Artisan SambaEdu

Ce document présente les commandes Artisan personnalisées développées pour faciliter la maintenance et l'administration du projet SambaEdu.

## 📝 Documentation API

### `sambaedu:update-docs`

Met à jour la documentation API Swagger de SambaEdu.

**Usage :**
```bash
# Mettre à jour la documentation
php artisan sambaedu:update-docs

# Vérifier uniquement si la documentation est à jour
php artisan sambaedu:update-docs --check

# Exécution silencieuse (pour scripts automatisés)
php artisan sambaedu:update-docs --quiet

# Forcer la régénération
php artisan sambaedu:update-docs --force
```

**Fonctionnalités :**
- ✅ Génération automatique de la documentation Swagger
- 🔍 Vérification de l'état de la documentation
- ⚠️ Détection des contrôleurs modifiés depuis la dernière génération
- 📊 Informations d'accès à la documentation
- 🛡️ Validation de la configuration L5-Swagger

**Exemple de sortie :**
```
🔄 Mise à jour de la documentation API SambaEdu...

✓ Configuration L5-Swagger OK
📝 Génération de la documentation Swagger...

✅ Documentation générée avec succès !

📍 Accès à la documentation :
   • Interface Swagger UI : http://192.168.122.50/api/documentation
   • Fichier JSON OpenAPI : http://192.168.122.50/api/documentation.json
```

---

## 🧹 Gestion des caches

### `sambaedu:clear-cache`

Nettoie intelligemment tous les caches Laravel avec options granulaires.

**Usage :**
```bash
# Nettoyer tous les caches (par défaut)
php artisan sambaedu:clear-cache

# Nettoyer seulement le cache de configuration
php artisan sambaedu:clear-cache --config

# Nettoyer seulement le cache des routes
php artisan sambaedu:clear-cache --routes

# Nettoyer seulement le cache des vues
php artisan sambaedu:clear-cache --views

# Nettoyer seulement le cache applicatif
php artisan sambaedu:clear-cache --app

# Nettoyer et optimiser (reconstruire les caches)
php artisan sambaedu:clear-cache --optimize

# Exécution silencieuse
php artisan sambaedu:clear-cache --quiet
```

**Fonctionnalités :**
- 🧹 Nettoyage sélectif ou complet des caches
- 📁 Suppression des fichiers temporaires (logs anciens, sessions expirées)
- ⚡ Option d'optimisation (reconstruction des caches)
- 📊 Affichage du statut des caches après nettoyage
- 🔍 Reporting détaillé des actions effectuées

**Types de caches gérés :**
- **Configuration** : `bootstrap/cache/config.php`
- **Routes** : `bootstrap/cache/routes-v7.php`
- **Vues** : `storage/framework/views/*`
- **Applicatif** : Cache Redis/File configuré
- **Temporaires** : Logs > 7 jours, sessions > 1 heure

--- 

## 🔄 Workflows recommandés

### Déploiement complet
```bash
# 1. Nettoyer les caches
php artisan sambaedu:clear-cache

# 3. Optimiser l'application
php artisan sambaedu:clear-cache --optimize

# 4. Mettre à jour la documentation
php artisan sambaedu:update-docs
```

### Maintenance quotidienne
```bash
# Vérifier l'état de la documentation
php artisan sambaedu:update-docs --check

# Nettoyer les fichiers temporaires
php artisan sambaedu:clear-cache --quiet
```

### Débogage
```bash
# Nettoyer tous les caches en mode verbeux
php artisan sambaedu:clear-cache

# Régénérer la documentation
php artisan sambaedu:update-docs --force
```

---

## 📋 Liste complète des commandes

| Commande | Description | Options principales |
|----------|-------------|-------------------|
| `sambaedu:update-docs` | Met à jour la documentation API | `--check`, `--quiet`, `--force` |
| `sambaedu:clear-cache` | Nettoie les caches Laravel | `--config`, `--routes`, `--views`, `--app`, `--optimize` |

---

## 🛠️ Intégration avec les scripts de déploiement

Ces commandes peuvent être intégrées dans vos scripts de déploiement automatisé :

```bash
#!/bin/bash
# Script de déploiement SambaEdu

echo "🚀 Déploiement SambaEdu..."

# Mise à jour du code
git pull origin main

# Installation des dépendances
composer install --no-dev --optimize-autoloader

# Maintenance Laravel
php artisan sambaedu:clear-cache --quiet
php artisan migrate --force
php artisan sambaedu:clear-cache --optimize --quiet
php artisan sambaedu:update-docs --quiet

echo "✅ Déploiement terminé !"
```

---

## 📞 Support

En cas de problème avec ces commandes :

1. **Vérifiez les logs** : `tail -f storage/logs/laravel.log`
2. **Testez en mode simulation** : Utilisez `--dry-run` quand disponible
4. **Mode verbeux** : Évitez `--quiet` pour voir les détails

Pour plus d'informations sur la documentation API, consultez `README_API_DOCUMENTATION.md`. 