# Identité & AD — le *pourquoi* (décisions structurantes)

> Couche **métier** du domaine identité : les décisions qui expliquent *pourquoi*
> le système a cette forme. La couche technique (le *comment*) est dans les
> autres fiches du domaine ; l'index est `README.md`.
>
> Format : une décision = un ADR (contexte → décision → conséquences). État de
> référence : le fonctionnement **legacy** (annuaire AD au centre de tout).

---

## ADR-1 — La base SQL devient la source ; l'import AD est transitoire

**Contexte.** Dans le legacy, l'AD est l'unique dépositaire des identités et
toute opération relit l'annuaire. Refaire SE5 directement sur LDAP rejouerait
cette dépendance lourde (latence, fragilité, requêtes partout).

**Décision.** Les identités vivent en base **PostgreSQL**, qui est la base de
travail (résolution, droits, rattachements) et a **vocation à devenir la source
de vérité**. L'**import AD → SQL** sert à **amorcer** ce miroir au moment de
migrer un établissement ; c'est un **outil de bascule, transitoire** — une fois
l'établissement migré, l'import **n'a plus vocation à tourner**.

**Conséquences.**
- Les écrans et services SE5 travaillent sur SQL, sans marteler l'annuaire.
- L'import est un mécanisme de **migration**, pas une synchronisation permanente :
  ne pas bâtir de fonctionnement courant qui suppose un import continu.
- Tant qu'un établissement coexiste avec le legacy, son import reste l'outil de
  rafraîchissement ; voir [`sync-ad.md`](sync-ad.md).

## ADR-2 — Un utilisateur = un login (`login` = `%USERNAME%`)

**Contexte.** Le login Windows ouvert par l'élève/le prof (`%USERNAME%`) doit
correspondre sans ambiguïté à une identité unique côté serveur.

**Décision.** `login` = `sAMAccountName` AD = `%USERNAME%`. C'est **l'identité
unique** : pas de second identifiant, pas d'inversion, pas de login dérivé.

**Conséquences.**
- `users.login` est **unique**, et résolu de façon **insensible à la casse**
  (`sAMAccountName` l'est ; `=` PostgreSQL ne l'est pas → comparaison sur forme
  minuscule).
- Le login est l'ancre qui relie session Windows, home, classe et droits.

## ADR-3 — Résolution par identifiant stable (`ad_guid`)

**Contexte.** Apparier un objet AD à sa ligne SQL par `cn`/`name` casse au
moindre renommage : l'objet réapparaît comme un doublon, son historique se perd.

**Décision.** L'appariement se fait sur l'**`objectGUID`** de l'AD (stocké en
`ad_guid`, immuable). Le `cn`/login/DN ne servent que de **fallback** quand le
GUID est absent.

**Conséquences.**
- Renommer un utilisateur/groupe/poste en AD **ne crée pas** de doublon : la même
  ligne SQL est mise à jour.
- Un même `ad_guid` portant deux logins SQL distincts est une **incohérence**
  signalée, pas silencieusement fusionnée.

## ADR-4 — AD local d'établissement prioritaire, sans repli central

**Contexte.** Le legacy s'appuie sur un annuaire central. Cette centralisation
est un point de fragilité et un frein à l'autonomie des établissements.

**Décision.** SE5 vise en priorité le **DC de l'établissement** ; en mode strict
(par défaut), **aucun repli automatique** sur un AD central n'est toléré — une
configuration absente est une erreur, pas un fallback silencieux.

**Conséquences.**
- Chaque établissement opère sur son annuaire local (réplica), sans dépendre du
  central pour le quotidien.
- C'est un pas vers l'allègement de la dépendance AD (objectif de long terme).

## ADR-5 — Rattachement par code d'établissement projeté dans les DN

**Contexte.** L'AD est **fédéré** : un même annuaire héberge plusieurs
établissements, chacun sous son **OU** dédiée (clé = code UAI). Les requêtes
doivent rester cantonnées au périmètre d'un établissement.

**Décision.** Le **code d'établissement** (UAI, ou `'0'` en mono-établissement)
projette un **préfixe d'OU** sur les DN construits pour les requêtes. La
construction des DN est centralisée (jamais concaténée à la main).

**Conséquences.**
- Les lectures/écritures d'un établissement restent dans **son** OU.
- Le rattachement d'un utilisateur se lit de son DN (arborescence) ou de son
  `memberOf` ; il alimente `school_code`/`school_name`.
- Les groupes transverses (profs/élèves globaux) s'interrogent explicitement au
  périmètre global.

## ADR-6 — SE5 écrit vers l'AD les objets qu'il possède (jamais les comptes)

**Contexte.** Certains objets naissent côté SE5 (un poste enrôlé, un groupe
créé) et doivent exister en AD pour rester opérables par l'infrastructure.

**Décision.** SE5 **écrit vers l'AD** ces objets-là — postes, groupes qu'il crée
— via `samba-tool` **encapsulé** (pas de shell direct, anti-injection) et de
façon **idempotente** (relit l'AD, ne fait rien si la cible est déjà atteinte).
Les comptes **utilisateurs** restent hors de ce périmètre (cf. ADR-1).

**Conséquences.**
- Création/renommage/désactivation de poste se propagent en AD sans double saisie.
- L'idempotence rend les reprises sûres (un job rejoué ne casse rien).

## ADR-7 — Identité fédérée pour les intervenants hors AD

**Contexte.** Des intervenants externes (techniciens, fédération) doivent accéder
à SE5 sans exister dans l'AD de l'établissement.

**Décision.** Une identité **fédérée** (`source = federated`) est provisionnée à
partir d'un jeton de fédération (clé stable `external_sub`), **isolée du canal
AD** : aucun import, aucune écriture AD ne la concerne.

**Conséquences.**
- Le même modèle `users` porte deux origines (`ad` / `federated`) distinguées par
  `source` ; le canal AD ignore les lignes fédérées.
- Cycle de vie **RGPD** dédié : désactivation → suppression douce → anonymisation
  des données personnelles.
