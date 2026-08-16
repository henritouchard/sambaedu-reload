# Décisions — authentification & SSO

> **Ce que couvre cette fiche.** Pourquoi le domaine est fait ainsi. Une section
> par décision : le contexte hérité de SE4, ce qui a été tranché, ce que ça
> coûte. Le *comment* vit dans les fiches techniques.

---

## 1. Quatre mécanismes, et pas un de plus

**Contexte.** SE4 avait une authentification : l'annuaire. Elle suffisait parce
qu'un seul type de visiteur existait — un humain devant un navigateur. Les
postes ne s'authentifiaient pas : ils appelaient des scripts sur le réseau
local, et **être branché valait autorisation**.

**Décision.** Quatre mécanismes distincts, un par nature de visiteur : session
web pour un humain, jeton signé pour une machine, protocole standard pour un
logiciel tiers, billet d'entrée signé pour quelqu'un venu d'ailleurs.

**Conséquences.**
- Aucun ne peut se substituer à un autre, et c'est voulu. Vouloir n'en faire
  qu'un reviendrait à donner à une machine les moyens d'un humain, ou l'inverse.
- Le coût est réel : quatre chemins à maintenir, quatre jeux de tests, quatre
  façons d'échouer. En contrepartie, un défaut dans l'un n'atteint pas les
  autres.
- **Le réseau n'autorise plus rien à lui seul.** Il reste une condition — les
  points d'entrée sensibles sont limités aux adresses privées — mais ce n'est
  plus une preuve d'identité.

## 2. Le mot de passe reste chez l'annuaire

**Contexte.** L'annuaire de l'établissement est l'autorité sur les comptes des
élèves et des personnels. Il l'était sur SE4, il le reste.

**Décision.** SE5 **ne stocke aucun mot de passe d'utilisateur**. Il demande à
l'annuaire de valider un couple, et n'en garde rien.

**Conséquences.**
- Une base SE5 compromise ne livre aucun mot de passe.
- SE5 dépend de l'annuaire pour toute connexion humaine : annuaire injoignable,
  personne n'entre.
- **La ligne en base est créée à la connexion**, quand la validation vient de
  réussir. C'est le seul moment où l'on sait que la personne existe vraiment, et
  cela n'arrive qu'une fois par session.
- Le contrôle de session, lui, **ne lit que la base**. Ce qui garde l'accès aux
  pages n'est plus dérivé de l'annuaire à l'exécution.

## 3. Un poste prouve qu'il est ce poste

**Contexte.** Un agent applique un état de configuration sur chaque machine.
Répondre « au réseau local » ne suffit plus : deux machines du même réseau ne
doivent pas recevoir le même état, et une machine sortie du parc ne doit plus
rien recevoir.

**Décision.** Le poste reçoit à l'enrôlement un couple de jetons — l'un pour
agir, court ; l'autre pour renouveler, long — et le certificat de l'autorité du
serveur, qu'il épingle.

**Conséquences.**
- **Un jeton de renouvellement ne sert qu'une fois.** Une seconde présentation
  signifie qu'il en existe une copie : le serveur révoque alors **tous** les
  jetons du poste. Le légitime se réenrôlera, le voleur n'a plus rien.
- **Une limite est assumée** : les jetons d'accès déjà émis survivent à cette
  cascade, faute d'en tenir la liste. La fenêtre est bornée à leur durée de vie.
- **Ré-enrôler n'invalide rien.** Un enrôlement raté en cours de déploiement ne
  doit pas couper un poste qui fonctionne.
- Seuls deux fichiers manipulent la bibliothèque de signature. Un test
  d'architecture l'impose : sans cette frontière, la vérification finirait
  recopiée à moitié dans un contrôleur.

## 4. Une extension apprend qui, jamais comment

**Contexte.** Des logiciels tiers s'intègrent à SE5. Ils ont besoin de savoir
qui est l'utilisateur ; ils n'ont besoin ni de son mot de passe, ni d'un accès à
la base.

**Décision.** SE5 est **fournisseur d'identité** au sens du protocole standard.
L'extension obtient un document signé qui dit qui est la personne, et rien
d'autre.

**Conséquences.**
- **Le détour par un code à usage unique n'est pas une formalité.** Ce qui
  transite par le navigateur peut être lu ; le code seul ne sert à rien sans le
  secret du client, qui ne quitte jamais le serveur de l'extension.
- **L'ordre de validation est la sécurité elle-même** : le client et l'adresse
  de retour d'abord, tout le reste ensuite. Renvoyer un refus vers une adresse
  non validée ferait de SE5 un tremplin de redirection.
- **La preuve de possession est obligatoire, dans sa forme forte seulement.**
  Sans elle, un code intercepté suffit à obtenir l'identité.
- Tout vient de la base. Une extension qui interroge SE5 ne déclenche aucun
  aller-retour vers l'annuaire.

## 5. Ce qui sort vers une extension est gelé

**Contexte.** À partir de la première intégration, ce que SE5 émet est consommé
par du code que nous n'écrivons pas et que nous ne redéployons pas.

**Décision.** Le contrat est **additif seulement** : on peut ajouter une
information, jamais en retirer une, la renommer, ni changer son type.

**Conséquences.**
- **Une clé de trop est une dette permanente.** On n'émet que ce qui a été
  décidé.
- La liste exacte de ce qui sort est verrouillée par test — et pas seulement par
  des vérifications d'absence, qui n'attrapent que ce à quoi on a pensé.
- **L'identifiant de l'utilisateur a un point unique de résolution.** Aucun
  autre fichier ne le construit. En changer doit coûter une méthode, pas une
  fouille — et le jour venu, il n'y aura rien à chercher ailleurs.
- Le vocabulaire du rôle est fermé : jamais un nom de rôle technique interne ne
  fuit vers l'extérieur.

## 6. Deux révocations, deux portées

**Contexte.** Retirer une donnée à une extension et lui couper l'accès sont deux
gestes différents, que le mot « révoquer » confond.

**Décision.** Deux opérations distinctes : couper le **client** — il n'obtient
plus rien — ou retirer un **périmètre accordé** — la connexion continue, une
information cesse d'être servie.

**Conséquences.**
- Les confondre transformerait un réglage de confidentialité en panne de
  connexion.
- **Le défaut est fermé** : une liste d'accords vide ne donne rien, elle ne
  donne pas tout.
- Un client n'est **jamais supprimé**, seulement désactivé : son historique
  reste lisible.
- **Le secret d'un client n'existe en clair qu'une fois**, à sa déclaration. Un
  secret perdu se remplace, il ne se retrouve pas.

## 7. Un externe entre sans compte, mais rien n'est perdu de vue

**Contexte.** Un technicien intervient sur plusieurs établissements. Lui créer
un compte dans chaque annuaire multiplierait les mots de passe, les comptes à
retirer à son départ, et les oublis.

**Décision.** Il arrive avec un billet signé par l'autorité amont. SE5 le
vérifie, ouvre une session marquée comme fédérée, et **journalise tout ce qu'il
fait ensuite**.

**Conséquences.**
- **L'autorité qui sait si cette personne travaille encore là est en amont**,
  pas dans l'établissement. C'est le point du modèle.
- La traçabilité est la contrepartie, et elle est **totale** : un test
  d'architecture vérifie qu'aucune page n'y échappe. Une page ajoutée sans elle
  fait échouer la suite.
- **Le rôle annoncé doit exister en base ; il n'est jamais créé.** Un système
  qui crée le rôle qu'on lui demande laisse l'extérieur définir ses propres
  droits.
- **Le billet arrive en POST, jamais dans l'adresse** : un paramètre d'URL finit
  dans les journaux, l'historique et l'en-tête de provenance.
- Une identité externe n'est **jamais supprimée**, seulement anonymisée en fin
  de conservation — irréversible par construction, ce qu'on attend d'une purge.

## 8. Aucun secret ne survit dans une trace

**Contexte.** Un domaine d'authentification manipule en permanence des valeurs
dont la fuite est le défaut. Un journal est précisément l'endroit où l'on
regarde quand quelque chose ne va pas.

**Décision.** Aucun mot de passe, aucun jeton complet, aucun secret en clair ne
touche un journal — sous aucune forme, pas même tronquée.

**Conséquences.**
- Les traces portent des identifiants de jeton, des empreintes, des préfixes.
- **Aucune donnée personnelle** dans les journaux d'identité externe : ni nom,
  ni adresse électronique, ni identifiant en clair.
- Les jetons de renouvellement sont stockés **par empreinte**. Leur valeur ne
  quitte le serveur qu'une fois, dans la réponse qui les crée.
- Diagnostiquer demande donc de corréler des identifiants plutôt que de lire des
  valeurs. C'est plus lent, et c'est le prix.

## 9. Le témoin d'intégration ne triche pas

**Contexte.** Vérifier qu'un fournisseur d'identité fonctionne demande un
client. Un client écrit dans le même dépôt est tenté de prendre des raccourcis.

**Décision.** Le témoin s'interdit tout accès à la base, à l'annuaire, aux
services de SE5 et à l'utilisateur connecté. Un test d'architecture l'impose.

**Conséquences.**
- **Un témoin qui triche ne prouve rien** : s'il lisait la session SE5, il
  validerait la connexion à SE5, pas le protocole.
- Il échoue exactement là où une vraie extension échouerait — c'est tout son
  intérêt.
- Il démontre l'isolation **par contrat**. L'isolation **par processus** est un
  sujet distinct, qui appartient au domaine extensions.

## 10. Une seule dette est acceptée, et elle est nommée

**Contexte.** Un poste tout neuf n'a pas encore l'autorité de certification du
serveur. C'est le script d'amorçage qui l'installe — et pour le télécharger, il
faut appeler le serveur.

**Décision.** Le premier appel se fait sans vérifier le certificat. La dette est
**formellement acceptée, datée et tracée**
([`tech-debt-auth.md`](../tech-debt-auth.md)).

**Conséquences.**
- La fenêtre est courte — un seul appel — et limitée aux adresses privées : il
  faut être physiquement sur le réseau de l'établissement.
- Une exploitation réussie donne un accès durable : l'attaquant installerait sa
  propre autorité sur le poste.
- **La sortie est connue** : pré-installer l'autorité par un autre canal avant
  le premier amorçage.
- Le fait qu'elle soit écrite est le point. Une dette nommée se solde ; une
  dette tue se découvre.

## Aller plus loin

Les mécanismes : [session humaine](session-humaine.md) ·
[poste ↔ serveur](poste-serveur.md) ·
[fournisseur d'identité](fournisseur-oidc.md) ·
[login fédéré](login-federe.md)
