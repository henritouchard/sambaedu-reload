---
title: Créer un compte
description: "Inscrire un élève, un enseignant ou un personnel, et remettre l'identifiant et le mot de passe initial affichés une seule fois."
---

# Créer un compte

Cette fiche explique comment inscrire un nouvel élève, enseignant ou personnel,
et comment récupérer l'identifiant et le mot de passe à lui remettre.

## Où ça se passe

Menu **Pilotage**, entrée **Utilisateurs**, onglet **Utilisateurs**. Le bouton
**Nouvel utilisateur**, au-dessus de la liste, ouvre le formulaire de création.

![Formulaire de création d'un compte, avec le nom et le prénom (repère 1), la catégorie Élèves, Profs ou Administratifs (repère 2) et la classe ou la fonction associée (repère 3).](/captures/admin/utilisateurs/creer-un-compte/formulaire-nouvel-utilisateur.png)

::: droit-requis
Il faut être administrateur des utilisateurs.
:::

## Les gestes

1. Sur l'onglet **Utilisateurs**, cliquez sur **Nouvel utilisateur**.
2. Renseignez le **nom** et le **prénom** : ces deux champs sont obligatoires.
3. Choisissez la **catégorie** du compte : **Élèves**, **Profs** ou
   **Administratifs**.
4. Complétez le rattachement qui découle de la catégorie :
   - un **élève** est rattaché à **une** classe ;
   - un **personnel administratif** reçoit une **fonction** ;
   - un **enseignant** reçoit une ou plusieurs **classes**, ou une fonction.
5. Laissez si vous le souhaitez l'**identifiant**, la **date de naissance** et
   le **mot de passe** vides : ces trois champs sont facultatifs (un mot de
   passe saisi doit compter entre 8 et 13 caractères).
6. Validez la création.

Quand vous laissez les champs facultatifs vides, l'identifiant est **généré
automatiquement** à partir du nom et du prénom, et le mot de passe initial est
**dérivé de la date de naissance** si elle est renseignée, sinon **tiré au
hasard**.

::: delai-effet immediat
Le compte est utilisable **dès la validation** : il peut ouvrir une session sur
n'importe quel poste de l'établissement, avec son
[espace personnel](/glossaire#espace-personnel) déjà créé et ses groupes déjà
rattachés.
:::

## Résultat observable

Après la validation, vous arrivez sur la **fiche du nouveau compte**.
L'identifiant et le mot de passe initial y sont affichés **une seule fois**,
masqués par défaut : les boutons **Révéler** et **Copier** permettent de les
lire et de les transmettre. Un identifiant déjà utilisé fait refuser la
création — choisissez-en un autre.

::: attention
Notez ou remettez le mot de passe **maintenant** : il ne sera **plus
ré-affichable** une fois que vous aurez quitté cette fiche. Le serveur ne le
conserve pas.
:::

::: vue-poste
À sa **première connexion**, le poste demandera au titulaire du compte de
choisir lui-même un nouveau mot de passe pour remplacer celui que vous lui avez
remis (voir
[On me demande de changer mon mot de passe](/poste/mon-compte/changement-impose)).
:::
