# Le plafond de stockage et la corbeille

> **Ce que couvre cette fiche.** Comment un plafond se résout, ce qu'il fait
> réellement quand il est dépassé, et ce que la « corbeille » est vraiment.

---

## 1. Un seul plafond d'instance, trois étages de résolution

La résolution s'arrête à la **première** réponse trouvée :

1. la règle **nominative**, posée sur le compte ;
2. sinon, une règle de **groupe** parmi les groupes du compte ;
3. sinon, le **défaut d'instance** — une ligne par partition, la même pour tout
   compte qu'aucune des deux premières ne couvre ;
4. **aucune règle du tout signifie illimité.**

Il n'existe **plus de profils de quota**. Le plafond ne se devine plus par
comparaison de sous-chaînes sur des noms de groupes, et un même compte ne peut
plus recevoir deux plafonds différents selon l'écran qui pose la question. Un
budget particulier pour une population donnée se pose en **règle de groupe**, où
il se voit.

Enregistrer un plafond d'instance **n'est pas l'appliquer** : la valeur écrite
n'atteint les comptes existants qu'au geste explicite qui la porte, et ce geste
annonce avant de l'exécuter combien de comptes sont couverts et combien passeraient
immédiatement en dépassement.

> ### ⚠️ Écart connu — une règle de groupe illimitée perd contre une règle bornée
>
> **Le mécanisme.** « Illimité » se code par un plafond dur à zéro. La sélection
> de la règle de groupe trie les règles applicables par plafond dur **décroissant**
> puis ne charge que la première. Zéro étant la plus petite valeur numérique, une
> règle illimitée finit **dernière** et n'est jamais chargée dès qu'un autre groupe
> du compte porte un plafond chiffré. La branche qui honore l'illimité s'exécute
> **après** cette sélection : elle n'est donc atteinte que si **toutes** les règles
> de groupe du compte sont illimitées.
>
> **Ce qu'un exploitant observe.** Un compte membre d'un groupe « illimité » et
> d'un groupe borné reçoit le plafond **borné**. Poser l'illimité sur un groupe ne
> garantit rien tant que le compte appartient à un autre groupe réglé.
>
> **Ce n'est pas voulu.** Le commentaire du code annonce une priorité que la
> requête a déjà supprimée, et le même raisonnement métier est correctement
> implémenté ailleurs dans le dépôt — le regroupement des anciens profils retient
> l'illimité **avant** de chercher un maximum. Les deux divergent sur la même règle.

## 2. Un dépassement ne bloque pas tout de suite

Un plafond n'est pas une valeur : c'est un **seuil**, une **tolérance**, un point
de blocage **calculé** et un **délai**.

| Ce qui compose un plafond | Ce que c'est |
| --- | --- |
| **Plafond souple** | la valeur saisie. Au-delà, le compte est **en dépassement** — il écrit encore. |
| **Dépassement toléré** | un pourcentage, saisi lui aussi. Il ne se règle pas en valeur absolue. |
| **Plafond dur** | le souple augmenté de ce pourcentage. **Il se calcule, il ne se saisit pas** — l'écran l'affiche sans l'offrir. Au-delà, l'écriture est refusée par le système de fichiers, sans délai ni recours. |
| **Période de grâce** | un délai **en jours**, réglé par partition, entre le franchissement du souple et le blocage effectif de l'écriture. |

Entre le souple et le dur, l'utilisateur dispose de la période de grâce pour
redescendre. Passé ce délai — ou dès le plafond dur atteint, ce qui arrive en
premier —, **l'écriture est bloquée**.

> C'est ce délai, et pas le plafond lui-même, qui décide **quand un compte est
> réellement arrêté**. Lire le dépassement comme un blocage immédiat conduit à
> régler des plafonds beaucoup trop larges pour compenser une brutalité qui
> n'existe pas.

Les deux valeurs sont posées sur le système de fichiers, et le délai l'est par un
appel distinct. **L'échec de cet appel ne fait pas échouer l'enregistrement** : la
valeur reste en base, et l'application est signalée comme reportée — un serveur de
fichiers momentanément muet ne doit pas faire perdre une décision d'exploitation.

**L'utilisateur en dépassement est averti à l'ouverture de session**, une seule
fois par session, à partir du dernier relevé connu. C'est le seul canal par lequel
il l'apprend avant de heurter le refus d'écriture ; il n'est jamais bloquant, et
un incident dans ce message ne peut pas empêcher une connexion.

## 3. Le regroupement des anciens défauts, et ce qu'il a élargi

Les défauts par profil ont été **regroupés en un seul défaut par partition**, et
la valeur retenue est **la plus large** de celles qui existaient.

Le motif est asymétrique, et c'est ce qui a tranché. Un plafond qui **rétrécit**
arrête des gens en écriture sans que personne n'ait cliqué, et il le fait sur
**deux plans** — le système de fichiers, et le cloud, dont le balayage de
provisionnement réécrit le plafond de chaque compte depuis cette même règle. Un
plafond qui **s'élargit** ne bloque personne et **n'alloue aucun disque** : un
quota plafonne, il n'attribue rien.

⚠️ **La conséquence se regarde en face** : sur une instance qui portait les
anciens défauts, **tout le monde monte à l'ex-valeur la plus large**, élèves
compris. Personne ne perd de place, mais le garde-fou qui bornait les comptes
d'élèves a disparu, et le dimensionnement disque de l'établissement change. Le
resserrement est un **geste explicite**, et l'écran annonce avant le clic combien
de comptes basculeraient en dépassement.

C'est pourquoi la carte des plafonds porte un **avertissement permanent** tant
qu'aucune valeur n'a été enregistrée à la main. Il disparaît au premier
enregistrement — à ce moment-là, le plafond en vigueur est celui de
l'administrateur.

> ⚠️ **Le regroupement est irréversible.** Le chemin de retour ne restaure rien :
> ce qui a été retiré n'existe plus que dans le journal d'audit, ligne par ligne.
> Reconstituer un budget particulier se fait à la main, en **règle de groupe**.

## 4. Ce que le contrat a appris à distinguer

Un plafond non posable est un **champ fermé avec son motif**, jamais un champ
ouvert qui accepte une valeur sans effet.

| Constat | Ce que ça veut dire |
| --- | --- |
| appliqué | la partition porte des quotas, le champ est ouvert |
| **pas de quota appliqué sur cette partition** | un fait, à corriger côté serveur |
| **mesure impossible** | SE5 **ne sait pas** — un appel en échec ne prouve pas une absence |

Écrire « il n'y a pas de quota » quand l'élévation est cassée mettrait une
contre-vérité sous les yeux de l'exploitant. La même exigence gouverne le décompte
des dépassements : « zéro dépassement constaté » et « je n'ai pas pu mesurer » ne
se confondent jamais.

Un espace qui **ne vit plus sur le serveur de fichiers** échappe à cette fermeture :
le plafond y gouverne le compte sur l'instance cloud, et laisser un système de
fichiers local hors sujet fermer ce champ fermerait le seul écran où se règle le
plafond du cloud.

## 5. Le relevé d'occupation, et son comportement en échec

Le relevé alimente une colonne que l'interface lit directement : la liste des
comptes ne mesure rien à l'affichage, ce qui est la seule façon de la garder
rapide à plusieurs milliers de comptes. Il est planifié la nuit, **après** la
purge, pour porter sur un état stable.

Son comportement en échec est **délibéré, et il se casse facilement en croyant
bien faire** :

- une partition qu'on ne sait pas interroger est **journalisée puis sautée**, et le
  relevé continue avec les suivantes ;
- un compte présent en base mais **absent du rapport** conserve son relevé
  précédent — il n'est **jamais effacé**. Un compte archivé ou momentanément
  invisible ne doit pas se lire comme une consommation **nulle** ;
- un identifiant présent dans le rapport mais **inconnu de la base** ne crée
  aucune ligne : il est compté et journalisé, rien de plus ;
- le relevé ne rend un **code d'échec** que si **toutes** les partitions ont
  échoué. Une passe partielle est un succès.

⚠️ **Un « nettoyage des relevés orphelins » casserait ce contrat** : ce qui
ressemble à une donnée périmée est ici le dernier état connu, délibérément
conservé, et une mesure manquante ne se confond jamais avec une mesure à zéro.

## 6. La corbeille n'est pas ce que son nom suggère

> ⚠️ **Ce n'est pas une corbeille d'utilisateur.** Aucun fichier supprimé par
> quelqu'un ne s'y retrouve, et rien de ce qui s'y trouve n'est récupérable par
> l'intéressé.

C'est l'**archive du répertoire personnel d'un compte désactivé**. Désactiver un
compte déplace son répertoire personnel dans une racine d'archive ; le réactiver
l'en ramène. Une commande planifiée détruit ensuite **définitivement** les archives
dont l'âge dépasse un délai de rétention, et la planification n'a lieu que si la
purge automatique est activée — la condition est réévaluée à chaque passage, la
bascule prend donc effet sans redéploiement.

Sans délai configuré, la commande **ne fait rien** et le dit : elle ne devine pas
une rétention. Chaque suppression est tracée, et un échec sur une archive
n'interrompt pas les suivantes.

**Il n'existe aucune corbeille côté cloud.** Ce mécanisme ne concerne que les
répertoires personnels servis par le serveur de fichiers.

## 7. Carte du code

| Sujet | Où |
| --- | --- |
| Résolution à trois étages, et l'écart de tri | `app/Services/Filesystem/XfsQuotaService.php` |
| Plafond dur calculé depuis le souple et le dépassement toléré | `app/Models/QuotaSetting.php` |
| Avertissement de dépassement à l'ouverture de session | `app/Listeners/NotifyQuotaOverageOnLogin.php` |
| Regroupement des anciens défauts, et son irréversibilité | `database/migrations/2026_08_15_100000_collapse_quota_profile_defaults.php` |
| Archive d'un répertoire personnel | `app/Services/Filesystem/HomeDirService.php` |
| Purge de l'archive, et sa planification conditionnelle | `app/Console/Commands/TrashPurgeCommand.php` |
| Relevé d'occupation | `app/Console/Commands/QuotaSnapshotCommand.php` |
| Reprise des plafonds depuis un serveur historique | `app/Console/Commands/QuotaSeedFromLegacyCommand.php` |
| Cartes de l'écran | `resources/views/pages/admin/settings/files/_partials/quotas-card.blade.php`, `corbeille-card.blade.php` |

## Aller plus loin

- Les répertoires que ces plafonds gouvernent :
  [partages-classe.md](partages-classe.md)
- Le plafond d'un nœud de plan, porté mais pas exécuté :
  [recettes-et-plan.md](recettes-et-plan.md)
