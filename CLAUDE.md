# Attention

- Ce code est édité sur une machine host et tourne sur une machine virtuelle dans ce Host. toute commande à run sera à run via ssh, ça ne sert à rien de regarder dans les fichiers de config locaux.
- Le code est synchronisé via inotify en cas de CRUD de fichier dans /sambaedu-reload/* (branche main). Ne jamais sync manuellement le code avec la VM. Si code non sync: me notifier et attendre.

# Cibles d'exécution SSH

Quand le user mentionne `/vm` ou `/lab1` dans un prompt, cela indique où les commandes doivent être exécutées.

- `/vm` — **cible par défaut** : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50` (path projet : `/var/www/sambaedu-reload`)
- `/lab1` — serveur distant : `ssh -i ~/.ssh/id_Lab1 -p 2221 root@192.168.101.2`

En l'absence d'indication, utiliser `/vm`.

> **Worktrees git** : ne jamais interagir avec la VM ou les serveurs distants depuis un worktree git, sauf contre-indication explicite du user.
  
# Arborescence et routing

Nous implémentons une sorte de  filesystem-based router web.php suivra la convention de l'arborescence: les fichiers des routes se trouvent dans /laravel/resources/views/pages/

- chaque dossier est le nom de la route (users=>/app/users). dans chacun de ces dossiers, index.blade.php sera la racine,
- on pourra décomposer les parties spécifiques de la page dasn un dossier _partials/
- les sous routes suivront le même patern (eg: app/users/new/index.blade.php)
- la partie spécifiquement front sera implémentée via des livewire sfc (singlefile components)
- les partials qui ne nécessitent pas de reactivité particulière pourront être de simples fichiers blade.php sans livewire.

# composants spécifiques

## modale

- on utiliser la modale réutilisable et son bouton de déclenchement  

## notifications utilisateurs

- on utilisera le trait WithToasts laravel/app/Components/Traits/WithToasts.php
