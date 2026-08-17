# Décisions — extensions

> **Ce que couvre cette fiche.** Pourquoi le domaine est fait ainsi. Une section
> par décision : le contexte, ce qui a été tranché, ce que ça coûte.

---

## 1. Une extension n'hérite de rien

**Contexte.** Sur SE4, ajouter une fonctionnalité voulait dire modifier SE4 :
même dépôt, même base, mêmes droits. Un établissement ne pouvait rien ajouter, et
tout ajout héritait des accès de l'application entière.

**Décision.** Une extension apprend l'identité de l'utilisateur par un **jeton
signé**, et c'est tout ce qu'elle obtient. Ni base, ni annuaire, ni session SE5.

**Conséquences.**
- Un tiers peut publier une extension et la faire installer sans passer par
  nous.
- Une extension compromise ne donne accès qu'à ce que son jeton dit.
- Elle ne peut **rien** faire que le protocole d'identité ne permette. Certains
  besoins légitimes n'auront donc pas de réponse — c'est le prix de la frontière.
- Un test d'architecture surveille cette isolation, plutôt qu'une consigne de
  relecture.

## 2. Deux natures, qui ne se croisent jamais

**Contexte.** « Ajouter une tuile vers un service existant » et « déployer un
logiciel sur le serveur » n'ont en commun que d'apparaître au même endroit.

**Décision.** Deux types — `link` et `app` — avec des transitions **disjointes**.
Une opération d'installation refuse un `link` ; l'intégration d'un lien refuse une
`app`.

**Conséquences.**
- **Aucun clic dans l'interface ne peut déclencher une installation système** par
  inadvertance.
- Le coût est deux jeux de transitions à maintenir. En contrepartie, le geste
  anodin reste anodin.
- Le canal en ligne de commande a précédé l'interface, délibérément : scriptable
  et auditable d'abord, cliquable ensuite.

## 3. La clé d'une source est retenue une fois

**Contexte.** Une source d'extensions signe ses catalogues. Si l'on
retéléchargeait sa clé à chaque synchronisation, une source compromise
substituerait la sienne et signerait ce qu'elle veut.

**Décision.** La clé est **épinglée à l'ajout**, par l'un de deux chemins
seulement : collée par l'administrateur, ou lue **une seule fois** et
**uniquement en HTTPS**. Elle n'est ensuite jamais retéléchargée.

**Conséquences.**
- Un dépôt qui change de clé **passe en erreur**. C'est voulu.
- Une rotation légitime est un retrait puis un réajout — deux actes journalisés,
  décidés par un humain.
- Une adresse en clair sans clé collée est **refusée** : sur un canal non
  chiffré, la signature ne prouverait plus rien.
- C'est le modèle des clés d'hôtes SSH et du trousseau d'`apt` — familier à qui
  exploite un serveur.

## 4. Un seul format de signature, sans algorithme négociable

**Contexte.** La signature couvre l'index du dépôt. Les manifestes qu'il contient
déclarent l'empreinte de chaque paquet.

**Décision.** Ed25519, et **rien d'autre**. L'empreinte du paquet est
transitivement couverte par la signature de l'index : vérifier le paquet contre
l'empreinte déclarée **est** la vérification contre la clé de la source.

**Conséquences.**
- **La famille de failles « algorithme confondu » n'existe pas ici par
  construction** : il n'y a rien à replier, aucune négociation.
- Aucune dépendance nouvelle — la bibliothèque est dans le cœur du langage. Elle
  est néanmoins déclarée explicitement : une dépendance implicite est une panne
  différée.
- Le service qui vérifie est **pur** : trois chaînes, un booléen. Il se teste
  exhaustivement avec des clés fabriquées à la volée, sans fichier binaire figé.
- Signer un dépôt tient en trois lignes côté éditeur tiers — c'est ce qui rend le
  modèle praticable pour un tiers.

## 5. Chaque étape d'installation sait se défaire

**Contexte.** Une installation qui échoue à mi-chemin laisse soit une
**installation zombie** — marquée installée, service absent, interface qui ment —
soit rien du tout.

**Décision.** Neuf étapes, chacune avec sa compensation. Échec à l'étape N :
annulation des précédentes en ordre inverse, journal nommant l'étape, relance
depuis le début.

**Conséquences.**
- **La base est écrite en tout dernier.** Si elle échoue, le système est déjà
  revenu à l'état propre. L'inverse est précisément l'installation zombie.
- L'adresse web est publiée **en dernier geste système** : jamais de page
  d'erreur servie sur une adresse qu'on vient d'ouvrir.
- **La désinstallation est le plan inverse, tolérante à l'absence** — donc elle
  sert aussi d'outil de nettoyage d'un état dégradé imprévu.
- Chaque compensation est isolée : l'une qui échoue n'empêche pas les suivantes.
  C'est du mieux-effort assumé, pas une garantie.

## 6. Toute la surface privilégiée tient dans une interface

**Contexte.** Installer un paquet et activer un service demandent les droits
d'administration système. C'est la partie dangereuse.

**Décision.** Un contrat **volontairement étroit** : invoquer le programme
d'assistance avec ces arguments, éventuellement en lui poussant ce contenu. Pas
un exécuteur de commandes générique.

**Conséquences.**
- L'appelant ne choisit ni le programme, ni l'interpréteur, ni l'environnement.
- **Le secret ne passe que par l'entrée standard.** En argument, il apparaîtrait
  dans la liste des processus — visible par n'importe quel compte de la machine —
  et dans le journal de `sudo`. Un secret journalisé est un secret perdu.
- Le programme d'assistance **revalide tout** côté administrateur : la défense ne
  repose jamais sur l'appelant.
- L'appel est non interactif : une configuration `sudo` manquante échoue tout de
  suite au lieu de bloquer un processus de fond sur une invite invisible.
- Les tests observent cette frontière comme une **séquence d'appels**, vérifiable
  exactement.

## 7. Retirer une donnée n'est pas couper l'accès

**Contexte.** Le mot « révoquer » recouvre deux gestes très différents.

**Décision.** Retirer un périmètre accordé **ne casse pas** la connexion de
l'extension : ses utilisateurs continuent de s'y connecter, elle n'apprend
simplement plus cette information. Couper l'accès, c'est désinstaller.

**Conséquences.**
- Un administrateur peut **restreindre sans provoquer de panne**.
- L'effet est immédiat, y compris sur les jetons déjà émis : rien n'est purgé, le
  périmètre effectif est recalculé à chaque usage.
- **Il n'y a pas de ré-octroi.** Reconsentir passe par une désinstallation puis
  une réinstallation. Un bouton « réaccorder » ferait de l'écart entre demandé et
  accordé un réglage à cliquer, alors que c'est une décision.
- Ce qui n'est pas accordé n'est pas servi : le défaut est fermé.

## 8. Un écrivain par colonne

**Contexte.** Un état qui peut être muté depuis plusieurs endroits devient
rapidement incohérent, et la cause est introuvable.

**Décision.** Le statut n'est écrit **que** par le service de cycle de vie ; les
colonnes de santé **que** par le service de santé. Ces colonnes sont hors des
attributs affectables en masse.

**Conséquences.**
- La synchronisation d'un catalogue **n'écrit jamais le statut** — sinon un
  simple rechargement désintégrerait une extension en service.
- Rouvrir la porte des attributs affectables en masse suffirait à annuler la
  garantie. C'est pour cela qu'elle est fermée.
- Chercher qui a changé un état est une question à réponse unique.

## 9. Le journal trace des actes, pas des clics

**Contexte.** Un journal d'audit noyé sous le bruit n'est pas consulté, donc
n'existe pas.

**Décision.** L'acte et sa trace sont **atomiques** — même transaction. Une
opération sans effet n'écrit **rien**. La télémétrie n'y entre pas du tout.

**Conséquences.**
- Une tâche de sonde passant toutes les cinq minutes empilerait près de trois
  cents lignes par jour et par extension en panne. Elle écrit donc dans des
  colonnes, à la transition seulement.
- **Aucune purge automatique** : le volume est borné par construction, ce sont
  des actes humains. Un journal d'audit qui s'efface tout seul n'en est pas un.
- Rien de sensible n'y entre — ni adresse de source, qui peut porter un jeton
  dans ses paramètres, ni secret, ni empreinte de paquet.

## 10. Le journal s'affiche avec tolérance, le manifeste se valide fermé

**Contexte.** Deux moments opposés que l'on pourrait croire symétriques.

**Décision.** Un manifeste inconnu est **refusé** en nommant le champ fautif. Une
action de journal inconnue est **affichée telle quelle**.

**Conséquences.**
- Ce n'est pas une incohérence : on **valide une entrée**, on **affiche de
  l'historique déjà écrit**.
- Une base restaurée depuis une instance plus récente portera des actions que
  cette version ne connaît pas. Refuser de les afficher ne protégerait rien et
  effacerait de la trace de conformité à l'écran.
- Les lignes portent des copies des noms, ce qui les rend lisibles **après**
  suppression de leur cible.

## 11. La barre de navigation ne doit jamais faire tomber une page

**Contexte.** Elle est rendue sur **toute** page authentifiée.

**Décision.** Une seule requête, aucun appel réseau, aucun cache — et toute
erreur de rendu d'une tuile est absorbée.

**Conséquences.**
- Une seule extension mal formée rendrait sinon l'application entière
  inaccessible.
- Pas de cache : il faudrait l'invalider, pour un gain non mesurable sur une
  requête déjà négligeable. Un test compte les requêtes pour que ça le reste.
- La santé affichée vient de colonnes déjà remplies, jamais d'une sonde au
  rendu.

## Aller plus loin

Les mécanismes : [cycle-de-vie.md](cycle-de-vie.md) ·
[sources-et-confiance.md](sources-et-confiance.md) ·
[installation.md](installation.md) · [sante.md](sante.md)
