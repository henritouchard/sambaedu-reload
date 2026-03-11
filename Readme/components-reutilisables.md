# Composants Réutilisables - Guide d'utilisation

## Vue d'ensemble

Ce guide explique comment utiliser les composants réutilisables créés pour afficher les activités utilisateur dans l'interface.

## Composants disponibles

### 1. Composant Livewire : `UserActivityItem`

**Fichier :** `app/Livewire/Components/UserActivityItem.php`
**Vue :** `resources/views/livewire/components/user-activity-item.blade.php`

**Utilisation :**
```blade
<livewire:components.user-activity-item 
    initials="JD"
    name="Jean Dupont"
    action="s'est connecté"
    time-ago="Il y a 5 minutes"
    color="primary"
/>
```

**Paramètres :**
- `initials` : Initiales de l'utilisateur (affichées dans l'avatar)
- `name` : Nom complet de l'utilisateur
- `action` : Description de l'action effectuée
- `time-ago` : Temps écoulé depuis l'action
- `color` : Couleur de l'avatar (primary, success, warning, info, secondary, accent, error, neutral)

### 2. Composant Blade pur : `user-activity-item`

**Fichier :** `resources/views/components/user-activity-item.blade.php`

**Utilisation :**
```blade
<x-user-activity-item 
    initials="ML"
    name="Marie Leblanc"
    action="a imprimé un document"
    time-ago="Il y a 12 minutes"
    color="success"
/>
```

## Quand utiliser quel composant ?

### Composant Livewire
- Quand vous avez besoin de réactivité en temps réel
- Pour des mises à jour dynamiques
- Quand vous voulez gérer l'état côté serveur

### Composant Blade pur
- Pour des affichages statiques
- Quand vous n'avez pas besoin de réactivité
- Plus léger et plus rapide à charger

## Exemples d'utilisation avancée

### Avec des données dynamiques
```blade
@php
    $activities = [
        ['initials' => 'AB', 'name' => 'Alice Bernard', 'action' => 'a téléchargé un fichier', 'timeAgo' => 'Il y a 2 minutes', 'color' => 'info'],
        ['initials' => 'CD', 'name' => 'Claude Dubois', 'action' => 'a modifié un document', 'timeAgo' => 'Il y a 15 minutes', 'color' => 'secondary'],
    ];
@endphp

@foreach($activities as $activity)
    <x-user-activity-item 
        :initials="$activity['initials']"
        :name="$activity['name']"
        :action="$activity['action']"
        :time-ago="$activity['timeAgo']"
        :color="$activity['color']"
    />
@endforeach
```

### Personnalisation des couleurs
Les couleurs disponibles correspondent aux classes DaisyUI :
- `primary` : Bleu principal
- `success` : Vert
- `warning` : Orange
- `error` : Rouge
- `info` : Bleu clair
- `secondary` : Gris
- `accent` : Couleur d'accent
- `neutral` : Neutre

## Création de nouveaux composants

Pour créer un nouveau composant réutilisable :

1. **Composant Livewire :**
   - Créer la classe dans `app/Livewire/Components/`
   - Créer la vue dans `resources/views/livewire/components/`

2. **Composant Blade :**
   - Créer le fichier dans `resources/views/components/`
   - Utiliser la directive `@props` pour définir les propriétés

## Bonnes pratiques

1. **Nommage :** Utilisez des noms descriptifs et cohérents
2. **Props :** Définissez des valeurs par défaut quand c'est possible
3. **Documentation :** Commentez vos composants
4. **Réutilisabilité :** Gardez les composants génériques et flexibles
5. **Performance :** Utilisez des composants Blade pour du contenu statique
