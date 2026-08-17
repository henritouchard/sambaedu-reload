# Décisions — plan de fichiers

> **Ce que couvre cette fiche.** Pourquoi le domaine est fait ainsi. Une section
> par décision : le contexte, ce qui a été tranché, ce que ça coûte.

---

## 1. Où vivent les fichiers devient une décision

**Contexte.** Dans SE4, rien de tout cela n'était une décision. L'annuaire et les
stratégies de groupe portaient à la fois la donnée et le comportement du poste :
les fichiers étaient sur le serveur de fichiers parce qu'il n'y avait pas d'autre
endroit, les lecteurs `K:` et `H:` étaient un fait de la configuration Windows, et
les droits d'un dossier de classe étaient une liste d'accès écrite par un script
au moment où quelqu'un cliquait. Changer d'idée supposait de rejouer des scripts
et d'espérer que le parc suive.

**Décision.** La vérité est **une ligne de base de données**. Le serveur en dérive
un état désiré par couple (poste, utilisateur) ; l'agent compare et converge.

**Conséquences.**
- Un espace peut vivre **ailleurs que sur le serveur de fichiers**, et le poste
  s'en aperçoit sans qu'aucun script de session ne change.
- L'écriture des droits devient un **contrat** que plusieurs implémentations
  honorent.
- Ce que le poste reçoit se **déduit** ; il ne se configure plus case par case.

## 2. Deux décisions d'instance, jamais un réglage par salle

**Contexte.** La tentation d'un réglage fin — cet espace au cloud pour les
lycéens, sur le serveur pour les collégiens — est permanente.

**Décision.** **Deux** décisions, une par espace, valables pour tout
l'établissement. Aucun réglage par salle, par parc ni par population.

**Conséquences.**
- La compilation de l'état d'un poste reste lisible : deux valeurs, pas une
  matrice.
- Un établissement qui voudrait deux régimes doit choisir. C'est assumé : une
  matrice publics × emplacements a été envisagée puis **abandonnée**, parce
  qu'elle multipliait les états sans qu'aucun besoin réel ne les distingue.
- Les **répertoires réseau gérés** restent hors de ce plan : chacun porte sa
  propre autorité, choisie à sa création.

## 3. Un cloud, ou aucun — et « les deux » est irreprésentable

**Contexte.** Deux produits cloud sont pris en charge. Deux interrupteurs
indépendants auraient permis l'état « les deux à la fois ».

**Décision.** Une **valeur à trois positions**, pas deux booléens. Un emplacement
ne peut désigner que le serveur de fichiers ou **exactement** le cloud actif.

**Conséquences.**
- L'incohérence n'est pas interdite par un test : elle est **impossible à
  écrire**. L'objet est immuable, son constructeur privé, une fabrique unique.
- La garde est **rejouée à la lecture**, donc opposable à une ligne forgée en SQL.
- « Aucun cloud » est une **valeur**, pas une absence. Un type nullable rouvrirait
  l'état « non décidé ».

## 4. Une lettre de lecteur ne pointe jamais un cloud

**Contexte.** SE4 servait tout en SMB. La tentation de « monter le cloud comme un
lecteur » pour préserver les habitudes est forte, et un montage de système de
fichiers virtuel existe.

**Décision.** Les lecteurs réseau sont **toujours** servis en SMB. Un espace au
cloud n'a **aucun** chemin SMB, et s'atteint par le navigateur ou par le client
natif de l'éditeur — vocabulaire fermé, pas de troisième voie.

**Conséquences.**
- Le raccourci « Mes fichiers en ligne » n'est pas une règle d'établissement :
  c'est une **conséquence technique**.
- Le montage a été examiné et **écarté** : pilote noyau sur chaque poste,
  protocole lent, verrous bureautiques mal gérés, ni conflit ni reprise hors
  ligne, et ce n'est pas le chemin supporté par l'éditeur.
- **L'argument du poste partagé ne disparaît pas, il se déplace** : le client
  natif **doit** être réglé en fichiers à la demande, sinon chaque session
  télécharge l'espace complet de son utilisateur sur le disque de la salle.

## 5. Le plan est neutre — la ligne de coupe

**Contexte.** SE4 dérivait les permissions au moment d'écrire, en mêlant le
« quoi » et le « comment » dans le même script.

**Décision.** Un plan résolu ne contient **ni** mode système, **ni** ligne de
liste d'accès, **ni** nom de groupe Unix, **ni** chemin absolu. Il dit **quoi** ;
le **comment** appartient au backend, après la ligne.

**Conséquences.**
- La résolution se **teste sans base, sans disque, sans faux appels système**.
  C'est le dividende, et il se dilapide au premier service d'exécution injecté.
- Deux règles d'architecture tiennent la ligne. L'une est un scan textuel : elle
  attrape l'étourderie, pas une dépendance construite à l'exécution. La limite est
  écrite là où elle vit, plutôt que promise plus large.
- Le plan est **comparable octet pour octet** — sans quoi la détection d'écart
  serait mort-née.

## 6. Les droits se disent en quatre verbes, jamais en niveaux

**Contexte.** L'ancien vocabulaire était binaire : lecture seule, ou
lecture-écriture.

**Décision.** Quatre verbes — lire, éditer, créer, supprimer — dont le périmètre
est **normatif** et épinglé par un test de documentation.

**Conséquences.**
- **Renommer = créer + supprimer**, et **déplacer** consulte **deux** octrois.
  Ce ne sont pas des cas particuliers, ce sont des compositions.
- La découpe est fidèle au mécanisme des **deux** plans de fichiers visés :
  écrire le contenu et écrire l'entrée du dossier ne sont pas la même
  autorisation, et les confondre donne à un déposant le droit d'effacer le travail
  des autres.
- La comparaison devient une **égalité d'ensembles** : deux octrois peuvent être
  **incomparables**, et « moindre » n'a plus de sens.
- Les recettes livrées sont **maximalement permissives** — c'est le seul mappage
  qui ne retire d'accès à personne. Les raffiner est un travail d'écran.

## 7. Une audience est un sujet abstrait — c'est une mesure

**Contexte.** La façon naturelle d'accorder un accès à « tous les profs de 3A »
est d'énumérer les profs.

**Décision.** Une audience s'exprime par **un sujet abstrait** — un groupe,
éventuellement qualifié d'un rôle d'arête — jamais par une énumération.

**Conséquences.**
- La mesure qui tranche : poser des entrées nominatives est **quadratique** —
  7,16 s à 1 000 entrées, **63 s à 3 000** — et bute sur une **limite dure à
  5 457 entrées** où l'appel système échoue. Le même octroi par groupe dérivé
  coûte 0,35 s.
- Le nombre de sujets est **indépendant de l'effectif**.
- L'énumération reste légitime là où elle coûte **une entrée par nœud** : les
  dossiers par membre, et une personne nommément désignée.
- La garde ne peut pas vivre dans le résolveur pur : un rôle **peut** légitimement
  désigner une personne. C'est le **choix du sujet** qui se garde, dans la couche
  qui assemble.

## 8. Le plan dit aussi ce qui n'a rien reçu

**Contexte.** En POSIX, l'implicite suffit : pas d'entrée, pas d'accès.

**Décision.** Chaque nœud porte la **clôture** — les rôles de la recette sans
octroi ici. Entièrement **dérivée**, jamais saisie.

**Conséquences.**
- L'implicite est **faux ailleurs**, et ça a été mesuré : un octroi sur un ancêtre
  **propage**, un retrait est **accepté sans effet**, et la relecture rend un accès
  là où on demandait zéro. Sans clôture, un backend à propagation fabrique une
  **fuite silencieuse sur le dossier privé des enseignants**.
- **Ce n'est pas une interdiction** : la clôture ne dit pas « interdire à X », elle
  constate « X n'a rien reçu ». Elle n'ouvre aucun degré de liberté nouveau.
- Elle devient **comparable** chez qui la matérialise — et attrape une règle de
  masque retirée à la main, invisible autrement sur un écran tout vert.

## 9. Aucune suppression implicite

**Contexte.** Un modèle qui sait désactiver sait vite détruire.

**Décision.** **Rien, dans ce vocabulaire, n'exprime la suppression d'un nœud.**
Désactiver vide des octrois ; le dossier et les données restent.

**Conséquences.**
- Un nœud « activable » désactivé **reste dans le plan** — sérialisé, comparable.
- « Suspendu, observé vide » est **conforme** : une désactivation ne se relit pas
  comme une suppression à réparer.
- **Supprimer un groupe ne déprovisionne rien.** La ligne du partage survit, son
  lien passe à vide, l'administrateur décide.
- **Aucun octet n'est déplacé par une décision d'emplacement**, dans aucun sens.
  Changer l'emplacement d'un espace peuplé est donc **refusé** : autoriser
  reviendrait à promettre un déménagement que personne n'exécute.

## 10. Un contrat sans booléen

**Contexte.** Le premier backend est local et synchrone. Les suivants sont des
interfaces distantes, asynchrones, à réussites partielles.

**Décision.** Six méthodes, **aucune ne rend un booléen**. Le statut est **par
nœud**, jamais global.

**Conséquences.**
- Un contrat dessiné sur le cas local se casse au premier backend distant, et il
  se casse **silencieusement** : un « vrai » peut venir d'une interface qui n'a
  rien fait.
- **Trois façons de ne rien faire**, jamais écrasées l'une sur l'autre : **non
  exprimable** (limite permanente du backend), **non implémenté** (dette de notre
  code), **non exécuté** (par conception). Dire « non supporté » dans les trois cas
  serait écrire une contre-vérité.
- **Aucun code de transport** n'entre dans un rapport : trois sémantiques natives
  de « déjà fait » sont normalisées en un état.
- Un backend d'**aperçu**, pur, prouve que le contrat n'est pas le serveur de
  fichiers déguisé.

## 11. Les deux arbres coexistent, et la bascule sera explicite

**Contexte.** L'arbre de classe historique est celui qui sert les établissements.
L'arbre neuf, décrit par recette, est peuplé et comparé.

**Décision.** Les deux vivent sur des **zones disjointes**, avec des autorités
différentes. **Ils ne doivent pas être factorisés.**

**Conséquences.**
- Deux commandes de peuplement distinctes, qui ne s'appellent pas.
- La bascule sera une **décision explicite**, avec aperçu avant exécution, jamais
  l'effet de bord d'une commande.
- **L'accrochage n'est pas la matérialisation** : seules les recettes d'**arbre**
  se matérialisent à la création d'un groupe. Sinon chaque classe naîtrait avec un
  partage plat que personne n'a demandé.

## 12. Le serveur de fichiers reste, tant que le flux cloud n'est pas établi

**Contexte.** Le cloud est en service, mais le chemin d'accès des utilisateurs ne
l'est pas encore.

**Décision.** Le serveur de fichiers historique **n'est pas déprécié**. Le critère
de bascule est l'**usage réel** — pas une préférence d'architecture.

**Conséquences.**
- Le partage personnel SMB **reste en service pour l'agent** quelle que soit la
  décision : il porte deux publics, un seul déménage.
- Deux implémentations de droits doivent être maintenues en parallèle, avec les
  écarts de sémantique que le contrat rend visibles.
- Le déplacement effectif des données est un **travail distinct**, non livré.

## Aller plus loin

Les mécanismes : [emplacements.md](emplacements.md) ·
[recettes-et-plan.md](recettes-et-plan.md) · [backends.md](backends.md) ·
[poste.md](poste.md) · [partages-classe.md](partages-classe.md) ·
[quotas-et-corbeille.md](quotas-et-corbeille.md)
