# Diagnostic d'instance

> **Ce que couvre cette fiche.** La commande qui répond à « cette instance
> est-elle saine ? », ce qu'elle vérifie, et comment lire son verdict.
>
> Ce qu'elle ne couvre pas : réparer. Le diagnostic ne touche à rien.

---

## En une phrase

**`sambaedu:doctor` est la première commande à taper sur une instance dont on
ne sait rien.** Elle passe en revue une vingtaine de prérequis, dit lesquels
tiennent, et rend un verdict dans son code de retour.

Elle est strictement en lecture. Elle ne répare rien, et c'est ce qui la rend
sûre à lancer n'importe quand.

## Le pourquoi

Sur SE4, savoir si un serveur était sain demandait de connaître le serveur :
tester le lien vers le contrôleur de domaine à la main, vérifier qu'un partage
existait, se souvenir que le cache PHP est configuré séparément pour le web et
pour la ligne de commande. **Ce savoir n'existait que dans la tête de qui avait
déjà réparé la panne.**

Le diagnostic transforme ce savoir en code. Chaque piège rencontré une fois
devient une vérification que la machine refait à chaque appel — et, surtout, qui
dit **quoi faire** quand elle échoue.

## S'en servir

```
php artisan sambaedu:doctor                  tout
php artisan sambaedu:doctor --tag=gpo        un domaine
php artisan sambaedu:doctor --tag=gpo,cache  plusieurs
php artisan sambaedu:doctor --json           exploitable par un script
```

**Le verdict est dans le code de retour** — c'est ce qui distingue cette commande
des commandes de sonde, qui se contentent de constater :

| Code | Sens |
| --- | --- |
| `0` | Tout va bien |
| `1` | Des avertissements seulement — l'instance fonctionne, en partie dégradée |
| `2` | Au moins une erreur — quelque chose ne marchera pas |

`install.sh` et `update.sh` l'appellent en fin de parcours et lisent ce code.
Une instance fraîchement installée ne se déclare donc pas saine toute seule :
elle le prouve.

## Qui lance la commande change la réponse

Le rapport commence par une ligne qui n'est pas décorative :

```
sambaedu:doctor — running as www-admin (uid=997, sapi=cli)
```

**Trois vérifications au moins dépendent de l'identité de l'appelant** — les
droits sur les fichiers privés de Samba, le ticket Kerberos, les listes de
contrôle d'accès. Un diagnostic lancé en `root` peut être vert alors que
l'application, qui tourne sous un autre compte, échoue.

> **Le cas d'école est le cache mémoire.** Il se configure séparément pour le
> serveur web et pour la ligne de commande. Installé pour le web et absent en
> ligne de commande, les pages fonctionnent et les commandes échouent en
> silence. Le rapport affiche donc l'interface PHP utilisée : c'est la seule
> façon de savoir de quel côté on regarde.

**Lancez-le comme l'application tourne** — `sudo -u www-admin php artisan
sambaedu:doctor`. C'est ce que font les scripts d'installation.

## Ce qui est vérifié

Dix-neuf contrôles, regroupés par domaine. Le nom du groupe est le mot à passer
à `--tag`.

| Groupe | Contrôle | Ce que son échec signifie |
| --- | --- | --- |
| `ad` | Liaison à l'annuaire | Aucune connexion utilisateur ne fonctionnera |
| `apache` | Configuration du serveur web | Les alias et en-têtes attendus manquent |
| `cache` | Cache mémoire | Les commandes échouent en silence là où les pages passent |
| `controlhub` | Lien amont | Le serveur amont n'est pas joignable, ou aucun lien n'est établi |
| `database` | Connexion à la base | Rien ne fonctionne |
| `extensions` | Backends joignables | Une extension installée ne répond pas |
| `extensions` | Clients d'identité | Des clients fantômes subsistent, ou un manque |
| `extensions` | Journal d'audit | Des événements attendent d'être acquittés |
| `filesystem` | Export SMB des partages | Les répertoires et droits existent, mais l'agent échoue au montage |
| `filesystem` | Dérive des lecteurs réseau | Des lecteurs gérés ont divergé de leur état voulu |
| `gpo` | Contrôleur de domaine joignable | Aucune écriture d'annuaire |
| `gpo` | Binaire `samba-tool` | Idem |
| `gpo` | `samba-tool gpo listall` | Le binaire est là mais n'aboutit pas |
| `gpo` | Ticket Kerberos | Les commandes d'annuaire ne s'authentifient pas |
| `gpo` | Fichiers privés de Samba | Droits insuffisants sur les fichiers d'identité |
| `gpo` | Chemin SYSVOL | Le volume partagé n'est pas accessible |
| `gpo` | Version de gabarit épinglée | Un gabarit modifié sans changement de version ne sera **jamais republié** |
| `ipxe` | Configuration du démarrage réseau | Les postes ne démarreront pas sur le réseau |
| `queue` | File des travaux | **Voir ci-dessous** |

### Deux contrôles qui méritent d'être compris

**La file des travaux.** Depuis que la mise en place des droits passe par une
file, un ouvrier arrêté ne produit **aucun symptôme** : les écrans annoncent
« engagé », les travaux s'empilent, rien n'échoue jamais. C'est un signal qui
n'atteint pas son destinataire, et qui se lit comme un succès.

> Le seuil porte sur **l'ancienneté**, jamais sur le volume. Une file chargée
> mais vivante est un système qui travaille ; alerter dessus apprendrait à
> l'exploitant à ignorer le contrôle, ce qui est pire que de ne pas l'avoir. Un
> seul travail disponible depuis vingt minutes est un signal ; mille travaux
> posés il y a dix secondes n'en sont pas un.
>
> Le seuil est une constante, pas un réglage : **un contrôle dont la sensibilité
> s'ajuste finit toujours par être ajusté jusqu'au silence.**

**La version de gabarit épinglée.** Modifier le contenu d'un gabarit de
configuration de poste sans en changer la version ne déclenche aucune
republication : les postes restent sur l'ancienne version, et rien ne le dit. Le
contrôle compare l'empreinte du contenu à la version déclarée.

## Les trois niveaux

| Niveau | Sens | Effet sur le verdict |
| --- | --- | --- |
| ✓ | Prérequis satisfait | — |
| ⚠ | Non satisfait, **non bloquant** — dégradation possible | Code de retour 1 |
| ✗ | Non satisfait, **bloquant** — une fonctionnalité ne marchera pas | Code de retour 2 |

Chaque résultat porte un **détail** — ce qui a été constaté — et, quand c'est
possible, un **remède** : la ligne que l'opérateur lit en premier quand ça
échoue.

**Un registre illisible produit un avertissement, jamais une exception.** Une
table absente ou une base injoignable est une information, pas un plantage : le
diagnostic doit rendre son rapport même quand une partie du système est à terre.
Et si un contrôle lève malgré tout une exception, la commande l'attrape, la
transforme en erreur nommant le contrôle fautif, et **continue les autres**.

## Ce que le diagnostic n'est pas

**Il ne répare rien.** Aucun contrôle n'écrit où que ce soit. C'est un contrat :
si une vérification a besoin d'écrire, ce n'est pas une vérification, c'est un
script de provisionnement.

**Il ne double pas les commandes de sonde.** `ext:health:check` **constate**
l'état des extensions et rend toujours 0 — un service arrêté volontairement n'est
pas une erreur. `sambaedu:doctor --tag=extensions` **juge**. Deux verbes, deux
destinataires.

**Il ne double pas le runbook d'installation.** Celui-ci vérifie qu'un service
est en place au moment où on l'installe ; le diagnostic constate, en
exploitation, qu'il a cessé de fonctionner. Deux moments.

**Il n'est pas planifié.** Il ne tourne qu'à la demande, ou en fin
d'installation et de mise à jour.

## Ajouter un contrôle

Créer une classe `<Quelquechose>Check` dans `app/Doctor/Checks/<Groupe>/`, qui
implémente `App\Doctor\EnvironmentCheck`. Elle est découverte au scan du
répertoire : **aucun registre à modifier**, aucun tableau à tenir à jour. Le nom
du sous-dossier devient le groupe.

Trois obligations : le contrôle est **sans effet de bord**, il est
**idempotent**, et il fournit un remède quand il échoue.

## Invariants

- **Lecture seule, sans exception.** Un contrôle qui écrit n'a pas sa place ici.
- **Le verdict vit dans le code de retour**, pas dans le texte affiché. C'est ce
  qu'un script lit.
- **Un contrôle qui échoue n'interrompt pas les autres.** Un rapport partiel
  serait pire qu'un rapport complet portant une erreur.
- **Le seuil d'un contrôle est une constante.** Rendre une sensibilité
  réglable, c'est préparer son extinction.
- **Le rapport dit toujours sous quelle identité il a été produit.** Sans cela,
  un résultat vert ne veut rien dire.

## Manques connus

- **Aucune checklist de pré-production.** C'est le seul domaine dans ce cas — ce
  qui est ironique pour l'outil qui sert justement à vérifier.
- **Le regroupement est dérivé du nom de dossier**, pas déclaré. Deux contrôles
  du même sujet rangés dans deux dossiers différents apparaîtraient sous deux
  groupes sans que rien ne le signale.
- **Rien ne garantit qu'un contrôle propose un remède.** C'est une convention
  écrite dans l'interface, pas une contrainte vérifiée.
- **Aucun contrôle ne couvre le domaine identité au-delà de la liaison**, ni le
  déploiement applicatif, ni l'impression.

## Carte du code

| Rôle | Fichier |
| --- | --- |
| Contrat d'un contrôle | `app/Doctor/EnvironmentCheck.php` |
| Résultat et remède | `app/Doctor/CheckResult.php` |
| Échelle de gravité | `app/Doctor/Level.php` |
| Découverte, exécution, verdict | `app/Console/Commands/SambaEduDoctorCommand.php` |
| Les contrôles | `app/Doctor/Checks/<Groupe>/` |

Appelé par `scripts/install.sh` et `scripts/update.sh`.
Tests : `tests/Unit/Doctor/Checks/`.

## Aller plus loin

- La taxonomie des commandes : [exploitation.md](exploitation.md)
- Ce que le diagnostic vérifie, domaine par domaine :
  [`../auth/`](../auth/README.md) · [`../ipxe/`](../ipxe/README.md) ·
  [`../filesystem/`](../filesystem/README.md)
