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

# Commentaires du code

Un commentaire ne dit que ce que le code ne peut pas dire. Trois questions avant d'en écrire un :

1. Est-ce déductible en lisant les lignes en dessous ? → ne pas écrire.
2. Est-ce vérifiable avec le dépôt seul (doc, code, produit) ? → sinon, ne pas écrire.
3. Restera-t-il vrai si on réécrit le code au-dessus ? → sinon, ne pas écrire.

## Pour qui on écrit

Le lecteur est un développeur qu'on ne connaît pas. Il ouvre le dépôt sans nous,
sans notre historique, sans personne à qui demander. Un commentaire n'a de valeur
que s'il tient debout tout seul devant ce lecteur-là.

Il décrit donc le code : ce qu'il fait, ce qu'il garantit, la contrainte à laquelle
il obéit. Il ne décrit jamais les circonstances dans lesquelles il a été écrit.
« Corrigé en review », « suite à la réunion de mardi », « untel préfère cette forme »
sont des faits sur nous, pas sur le programme ; ils ne disent rien à qui n'était pas là.

Le test tient en une phrase : **si le commentaire renvoie à quelque chose que le
lecteur ne peut pas atteindre, il est inutile.** Un nom de personne, une date, une
réunion, un ticket, un numéro d'itération y échouent. Un renvoi numéroté vers une
source absente aussi : « piège n°8 », « cas 3 du tableau », « voir la story 12.4 ».
La liste n'est pas close — c'est le test qui tranche, pas elle.

Un renvoi qui échoue ne se supprime pas : il se remplace par ce qu'il désignait.
Le numéro tenait la place d'un fait ; on écrit le fait, en une phrase qui énonce
le problème et ce qu'on en fait.

```php
// ❌ Attention au piège n°8 quand on publie un payload WPKG.
// ✅ Un payload en 0660 fait échouer l'installation sans le moindre message :
//    le client WPKG lit le partage en anonyme. On publie donc en 0664.
```

## Forme

On écrit de vraies phrases : sujet, verbe, complément, une majuscule au début et un
point à la fin. Le style télégraphique (« garde anti-doublon, cf. plus bas ») économise
deux mots et coûte une relecture.

Concis ne veut pas dire tronqué. La phrase reste courte parce qu'elle ne dit qu'une
seule chose, jamais parce qu'on lui a retiré ce qui la rend compréhensible. Un
commentaire qui a besoin de son auteur pour être compris n'a pas été écrit, il a été
abrégé.

## Budget par portée

Le défaut à chaque niveau est **zéro**. Le budget est un plafond, pas un quota.
Dépasser le plafond est permis quand le commentaire porte vraiment quelque chose
que le code ne dit pas ; le laxisme est sur la longueur, jamais sur la pertinence.

| Portée | Budget | Ce qu'il dit |
|---|---|---|
| Fichier / classe | 3 à 30 lignes | Le but que la classe sert, sa place dans le système, ce qui la distingue de sa voisine, les invariants qu'elle tient. **Jamais** la liste de ses méthodes. |
| Méthode publique | 0 à 5 lignes | Rien si le nom et la signature suffisent. Sinon le contrat invisible : effet de bord, ordre imposé, ce qui est renvoyé quand il n'y a rien. |
| Bloc | 1 ligne | Seulement si le bloc existe pour une raison qu'on ne voit pas (garde, contrainte externe). |
| Ligne | exceptionnel | Voir l'entorse. |

## L'entorse

Un pavé de 5 à 10 lignes sur un bloc ou une ligne est légitime dans trois cas :

- **une contrainte externe mesurée** (AD, Samba, Nextcloud, POSIX qui se comportent autrement que prévu) ;
- **un comportement contre-intuitif** : le code fait exprès ce qui ressemble à un bug ;
- **une décision qui change le sens de la fonction** : un cas particulier qui casse la règle générale.

Condition : citer le **fait** vérifiable, jamais l'histoire.

```php
// ✅ setfacl échoue en EINVAL au-delà de ~5457 entrées ACL sur ext4.
//    D'où le groupe dérivé : une entrée par groupe, pas une par élève.

// ❌ Après discussion on a finalement choisi le groupe dérivé
//    parce que la première approche ne passait pas à l'échelle.

// ❌ Revu avec untel le 12/03 : on garde le groupe dérivé.
```

Les deux contre-exemples renvoient à une conversation. Le lecteur ne peut ni la
retrouver, ni la vérifier, ni en déduire ce qui se passerait s'il changeait le code.

## Interdits

- Syntaxe du langage ou du framework, et « pourquoi j'ai écrit ça comme ça ».
- Paraphrase du code (`// on récupère l'utilisateur` au-dessus de `$user = ...`).
- Référence à du code qui n'existe plus.
- Circonstances de rédaction : nom d'une personne, review, réunion, date, ticket,
  numéro d'itération. Le lecteur n'y a pas accès.
- Renvoi numéroté vers une source absente du dépôt (« piège n°8 », « cas 3 »).
  On écrit ce que le numéro désignait, pas le numéro.
- Bannières décoratives de section.
- Code mort commenté — git le garde.
- Historique de dev : story, epic, AC, « avant on faisait X ». Rien de `_bmad*` ne transparaît.

Une mention de ce que faisait SE4 est tolérée si elle explique une contrainte
ou un choix qui paraîtrait arbitraire autrement.
