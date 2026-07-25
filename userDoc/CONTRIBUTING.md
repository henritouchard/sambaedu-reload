# Contribuer à la documentation publique SE5

Ce fichier n'est **pas publié** (`srcExclude` dans `.vitepress/config.mjs`) :
il s'adresse aux personnes qui rédigent ou révisent une fiche, pas aux
lecteurs du site. Il fixe le gabarit commun aux deux parcours, le mode
d'emploi des encarts normalisés, la convention de lien vers le glossaire et
la charte de rédaction du projet.

Un fichier modèle copiable, qui applique tout ce qui suit, est disponible
dans [`.templates/fiche-modele.md`](.templates/fiche-modele.md) (lui aussi
exclu du build).

## Gabarit de fiche

Toute fiche des deux parcours suit cette structure :

1. **Titre = la tâche ou la question du lecteur**, jamais un nom d'écran
   technique. Écrire « Changer mon mot de passe », pas « Formulaire
   ChangePassword ».
2. **Une phrase d'intention** juste sous le titre : ce que le lecteur va
   pouvoir faire ou comprendre en lisant la fiche.
3. **« Où ça se passe »** (parcours admin) ou **contexte d'usage** (parcours
   poste) : où trouver l'écran ou la situation concernée, avant les gestes —
   c'est ICI, ou juste après les gestes qu'elle annote (point 4), qu'une
   **capture d'écran** s'insère si la fiche décrit un écran que le lecteur
   doit reconnaître pour être sûr d'être au bon endroit (voir « Captures
   d'écran » ci-dessous).
4. **Gestes numérotés** : une liste ordonnée, une action par étape.
5. **Résultat observable** : ce que le lecteur doit voir une fois les gestes
   effectués, pour savoir que ça a marché.
6. **Encarts normalisés** là où ils s'appliquent (voir ci-dessous).
7. **Liens glossaire** sur les termes maison, à leur PREMIÈRE occurrence
   seulement dans la fiche.

Ce gabarit est le point de départ imposé des fiches à venir (52.3 à 52.5) et
du guide administrateur.

## Les quatre encarts normalisés

Chaque encart est un container markdown-it enregistré une seule fois dans
`.vitepress/config.mjs` — n'écrivez jamais de mise en forme équivalente à la
main dans une fiche.

### Droit requis

```md
::: droit-requis
Il faut être administrateur du parc concerné.
:::
```

Rend le titre figé « Droit requis ». À utiliser quand un geste décrit dans la
fiche suppose une habilitation particulière.

### Quand l'effet est visible

```md
::: delai-effet immediat
:::
```

Paramètre **obligatoire**, une seule valeur parmi les trois suivantes (aucune
autre valeur, aucun oubli — le build échoue sinon avec le fichier et la
valeur fautive) :

| Valeur       | Rendu                                              | Cas d'usage                                          |
| ------------ | --------------------------------------------------- | ----------------------------------------------------- |
| `immediat`   | Effet immédiat                                       | Le changement est visible dès l'action terminée.       |
| `session`    | À la prochaine ouverture de session                  | Il faut se reconnecter pour voir le changement.        |
| `agent`      | Au prochain passage de l'agent sur le poste          | Le poste doit être allumé et joindre le serveur.        |

Le corps du container reste disponible pour une précision facultative en une
phrase, par exemple :

```md
::: delai-effet agent
Le poste doit être allumé et connecté au réseau de l'établissement.
:::
```

### Attention

```md
::: attention
Cette action ne peut pas être annulée.
:::
```

Rend le titre figé « Attention ». **C'est le seul encart d'avertissement du
site** : n'utilisez jamais les containers natifs `::: warning` ou
`::: danger` de VitePress dans une fiche — le lint les refuse (motif
`warning`/`danger` interdit), car ils produiraient un second rendu pour le
même usage.

### Ce que voit l'utilisateur du poste

```md
::: vue-poste
Une nouvelle icône apparaît sur le bureau.
:::
```

Rend le titre figé « Ce que voit l'utilisateur du poste ». À utiliser côté
parcours admin quand un geste serveur a une conséquence visible sur le poste
de l'utilisateur, pour que l'administrateur sache à quoi s'attendre en
support.

## Lien vers le glossaire

Le glossaire (`glossaire.md`, publié sous `/glossaire`) définit les mots
propres à SE5. Convention :

- lier un terme à sa **première occurrence** dans une fiche, pas à chaque
  fois qu'il apparaît ;
- lier vers l'ancre explicite du terme, jamais vers une ancre générée
  automatiquement par le titre : `[parc](/glossaire#parc)`,
  `[capacité](/glossaire#capacite)`, etc. — la liste des ancres valides est
  celle des en-têtes `{#...}` de `glossaire.md` ;
- le lint vérifie que chaque lien `/glossaire#...` pointe vers une ancre qui
  existe réellement : un lien vers une ancre absente fait échouer le build.

## Synonymes d'usage et recherche

La recherche du site est locale (moteur embarqué, aucun service externe) : elle
indexe **le texte rendu** de chaque fiche. Un lecteur qui tape un mot courant
plutôt que le terme employé par la fiche ne la trouvera que si ce mot figure
dans le texte.

Pour couvrir ce cas, une fiche peut déclarer ses synonymes d'usage par une
**ligne visible**, en italique, placée juste sous la phrase d'intention :

```markdown
*Aussi appelé : logiciel, programme.*
```

Règles :

- **uniquement un mot qu'un lecteur emploierait réellement** à la place du
  terme de la fiche (« login » pour « identifiant », « logiciel » pour
  « application »). Deux ou trois mots, pas davantage — ce n'est pas une balise
  de mots-clés ;
- **ne rien ajouter si le mot courant est déjà dans le texte** de la fiche : il
  est alors déjà indexé ;
- **jamais de texte caché** (span masqué, HTML invisible) pour « gonfler »
  l'index : c'est malhonnête pour le lecteur et illisible pour les lecteurs
  d'écran. La ligne de synonymes est visible ou elle n'existe pas.

Pour **retirer une page entière de l'index** de recherche, poser `search: false`
dans son frontmatter. C'est le seul levier d'exclusion ; aucune page publiée ne
l'utilise aujourd'hui (les sources non publiées — ce fichier, `README.md`,
`.templates/` — sont déjà hors index via `srcExclude`).

## Captures d'écran

Une capture d'écran confirme au lecteur qu'il est au bon endroit avant
d'agir : le texte suffit pour agir, l'image confirme. Une fiche reste donc
**toujours complète et utilisable sans l'image** — relisez-la comme si
l'image n'existait pas : c'est précisément l'état publié tant qu'aucune
image n'a été déposée (voir « Rendu : image ou repli textuel » ci-dessous).

### Jeu de données fictif ratifié

**Toute donnée visible dans une capture vient de ce jeu, et de lui seul.**
Jamais de nom d'établissement, de personne ou d'adresse réels — pas de
floutage ni de masquage a posteriori non plus : c'est l'**environnement de
prise de vue** qui doit être fictif de bout en bout (recadrer la zone utile,
oui ; retoucher le contenu affiché, non — un floutage rate toujours un champ
un jour).

- **Établissement** : Collège de Brumeville (commune inventée) ; domaine si
  une capture doit vraiment en montrer un : `brumeville.lan`.
- **Personnes** : élève Camille Ondine (`camille.ondine`), enseignante
  Dominique Halbran (`dominique.halbran`), référent numérique Alex Verany
  (`alex.verany`).
- **Classe** : `3B`.
- **Salle / parc** : `salle-101`, postes `poste-101-01`, `poste-101-02`, etc.
- **Imprimante** : `imprimante-salle-101` (déjà utilisé comme exemple dans
  `poste/impression.md`).

Si une future fiche a besoin d'une donnée fictive qui n'existe pas encore
dans ce jeu (un autre parc, une autre classe…), **elle s'ajoute ici** — ne
jamais inventer une donnée ad hoc dans une seule fiche ou une seule capture.

### Thème et cadrage

- **Thème clair** de l'interface, uniquement (pas de variante sombre
  capturée) ; **interface en français**.
- **Zone utile seulement** : jamais de barre d'adresse, de chrome navigateur
  ni de barre des tâches dans le cadrage — c'est ce qui garantit qu'aucune
  capture ne révèle un nom d'hôte ou une adresse de site. Recadrer est
  autorisé (et attendu) pour l'obtenir ; retoucher le contenu ne l'est pas.
- **Largeur de référence 1280 px**, export à l'échelle 1.
- **Format PNG uniquement** (pas de `.jpg`, `.webp`, `.svg` « au cas où »).

### Annotations : pastilles numérotées

Quand une capture illustre une liste de gestes numérotée d'une fiche, elle
porte des **pastilles numérotées** (cercle plein de la couleur primaire SE5,
chiffre blanc, taille homogène) posées à la production de l'image — **le
numéro d'une pastille est le numéro du geste** qu'elle illustre dans la
fiche. Si la liste de gestes change (ajout, réordonnancement, retrait), c'est
l'image qui se réannote, **au même nom de fichier** — jamais le texte de la
fiche qui s'adapte au numéro d'une pastille figée.

Pas de figure ni de légende visible sous l'image : les repères vivent DANS
l'image, et le texte alternatif porte la description en mots (voir plus
bas).

### Emplacement et nommage

Les captures vivent sous `userDoc/public/captures/`, en miroir du chemin de
la fiche qui les référence, chemin d'accès **sans son extension** :

```
userDoc/public/captures/<chemin-de-la-fiche-sans-extension>/<ecran>.png
```

Exemple : la fiche `poste/mon-compte/changer-mon-mot-de-passe.md` référence
une capture sous
`captures/poste/mon-compte/changer-mon-mot-de-passe/ecran-de-securite.png`.

Une capture partagée par plusieurs fiches (cas rare) va dans un dossier
`communs/` du parcours concerné : `captures/<parcours>/communs/<ecran>.png`.

**Nommage : kebab-case sans accent** (uniquement `a-z`, `0-9` et `-`), **le
nom décrit ce que montre l'écran** (`ecran-de-securite.png`) — **jamais de
numéro d'ordre** (`01.png`, `02.png` sont interdits) : insérer une nouvelle
capture entre deux autres ne doit renommer aucun fichier existant.

**Règle de remplacement** :

- l'interface évolue mais l'écran reste **le même sujet** → même nom, on
  **écrase** le fichier — zéro modification de texte dans la fiche ;
- l'écran change de nature ou disparaît → **nouveau nom** + retouche du
  texte de la fiche qui le référence (cas normal d'évolution d'une fiche).

### Texte alternatif (non optionnel)

L'alt n'est **jamais optionnel** : il est à la fois le contenu accessible de
l'image ET le texte affiché par le placeholder tant qu'aucune image n'existe
— un alt vide **fait échouer le build** (lint, voir plus bas), sans
exception.

- une phrase qui décrit **ce que montre l'écran** ;
- si l'image porte des pastilles numérotées, l'alt **annonce les repères** et
  ce qu'ils pointent (« avec le champ identifiant, repère 1, et le bouton de
  validation, repère 2 ») ;
- ne commence **jamais** par « Capture d'écran de » (l'image elle-même dit
  déjà que c'en est une) ;
- ne contient que des données du jeu fictif ratifié ci-dessus.

### Rendu : image ou repli textuel

Une capture s'insère en **markdown natif**, rien d'autre :

```markdown
![Texte alternatif qui décrit l'écran](/captures/poste/mon-compte/changer-mon-mot-de-passe/ecran-de-securite.png)
```

Pas de container dédié, pas de balise `<img>` en HTML brut dans une fiche —
le lint la refuse (voir plus bas). Le rendu est produit par un **point
unique** : la règle `image` de markdown-it, surchargée dans
`markdown.config(md)` de `.vitepress/config.mjs` (même patron que les quatre
encarts normalisés) :

- **le fichier existe** sous `userDoc/public/captures/...` → une image
  (`<img class="se5-capture">`, stylée dans `theme/custom.css`) ;
- **le fichier n'existe pas** → un bloc discret « Illustration à venir »
  affichant le texte alternatif (`.se5-capture-placeholder`).

Le passage d'un état à l'autre se fait par **simple dépôt (ou retrait) du
fichier au nom prévu — zéro modification de texte.** C'est tout l'intérêt de
poser la référence dans la fiche dès maintenant, avant que l'image existe.

### Ce que le lint bloque, en plus des motifs déjà listés

`lint-doc.mjs` (chaîné dans `npm run build`, cf. section suivante) bloque
aussi, en `fichier:ligne → motif` :

- une image markdown à texte alternatif vide ;
- une image dont la cible n'est pas sous `/captures/` (y compris une URL
  externe `http://`/`https://`) ;
- une cible `/captures/...` dont l'extension n'est pas `.png`, ou dont un
  segment de chemin n'est pas kebab-case ;
- une balise `<img>` en HTML brut.

En complément, le lint affiche une sortie **informative, jamais bloquante** :
la liste des captures référencées par les fiches dont le fichier est encore
absent sous `userDoc/public/`. C'est très exactement la liste de production à
dérouler (checklist ci-dessous) pour chaque capture qui reste à prendre.

### Checklist de production (étape manuelle, séparée de la rédaction)

Avant de déposer un fichier PNG au nom déjà référencé par une fiche,
vérifier :

- [ ] toutes les données visibles sont **100 % fictives** (jeu ratifié
      ci-dessus, rien d'ajouté à la volée) ;
- [ ] **thème clair**, **français**, **zone utile seulement** (pas de barre
      d'adresse, de chrome, ni de barre des tâches) ;
- [ ] **largeur 1280 px**, échelle 1, **format PNG** ;
- [ ] les **pastilles numérotées** correspondent exactement aux numéros des
      gestes de la fiche au moment de la prise de vue ;
- [ ] le **nom de fichier** est exactement celui déjà référencé par la fiche
      (aucune fiche à retoucher pour faire coïncider un nom).

Cette étape est **manuelle et séparée** de la rédaction d'une fiche : produire
une vraie capture exige une application SE5 lancée, un environnement de prise
de vue aux données fictives, et — pour les écrans du parcours poste qui
vivent sur le *secure desktop* Windows (ouverture de session, Ctrl+Alt+Suppr)
— une capture au niveau de l'hyperviseur, pas un outil de capture ordinaire.

## Charte de rédaction

Ces règles ne sont pas toutes mécanisables ; elles se relisent comme des
critères de relecture avant de proposer une fiche.

- **Jamais d'historique interne de SE5 ni de décision abandonnée.** La doc
  décrit ce qui existe aujourd'hui, pas comment on y est arrivé ni ce qui a
  été envisagé puis écarté. Une phrase comme « l'agent remplace ce que
  faisaient les stratégies Windows » est un historique interne : à proscrire.
- **Un gain se formule par rapport au legacy SE4**, jamais par rapport à un
  état antérieur de SE5. « SE4 » est autorisé dans ce sens ; « SE5 » est le
  nom du produit et reste autorisé partout.
- **Précis et concis.** Une phrase, une idée. Pas de remplissage.
- **Mermaid dès qu'un flux se décrit mieux qu'en paragraphe** — ⚠️ à ce jour
  (Story 52.2), le socle VitePress livré par la 52.1 **ne rend PAS** les
  blocs ` ```mermaid ` nativement (vérifié : aucun plugin de rendu mermaid
  n'est présent dans la chaîne markdown-it de VitePress). Tant qu'aucun
  plugin n'a été ajouté, n'utilisez PAS de bloc mermaid dans une fiche
  publiée — il resterait affiché comme un bloc de code brut. L'activation
  du rendu (ex. `vitepress-plugin-mermaid`) est à faire avec la première
  fiche qui en a réellement besoin (52.3 et suivantes), pas par anticipation
  dans cette story.
- **Le parcours poste s'adresse à un non-informaticien.** Phrases courtes,
  zéro sigle non défini au premier usage (lier au glossaire), zéro
  vocabulaire de code.
- **La doc ne décrit que le livré et stable**, vérifié dans l'interface au
  moment de l'écriture (règle « la doc suit le code »). Ce qui manque encore
  ne se décrit jamais comme disponible. S'il faut vraiment le signaler, cela
  se fait à UN endroit unique par parcours : une page « Limites connues »
  (`/admin/limites-connues` ou `/poste/limites-connues`), créée seulement au
  premier besoin réel — pas par anticipation, pas dans le corps d'une fiche.

## Ce que le lint bloque automatiquement

`npm run build` exécute d'abord `.vitepress/lint-doc.mjs` sur les sources
publiées. Il fait échouer le build (fichier + ligne + motif) sur :

- les mots « story », « épic », « epic » (avec ou sans numéro) ;
- les codes d'exigence (`FR-…`, `NFR-…`, `UX-DR…`) ;
- les adresses IPv4 (utiliser l'établissement fictif des exemples) ;
- les containers natifs `::: warning` / `::: danger` ;
- un lien `/glossaire#x` vers une ancre qui n'existe pas dans `glossaire.md` ;
- les motifs image de la section « Captures d'écran » ci-dessus (alt vide,
  cible hors `/captures/`, extension ≠ `.png`, segment non kebab-case,
  balise `<img>` brute).

« SE4 » et « SE5 » sont explicitement autorisés (cf. charte ci-dessus) ; les
nombres génériques (versions, quantités) ne sont pas bloqués.

Une sortie **informative, non bloquante** liste en plus les captures
référencées dont le fichier est absent sous `userDoc/public/` (liste de
production, cf. « Captures d'écran » ci-dessus).

Une liste d'exceptions par fichier existe en tête de `lint-doc.mjs`
(`EXCEPTIONS`, vide au départ) pour couvrir un futur faux positif sans
contourner la règle ailleurs.

## Rappel d'exploitation (hérité de la Story 52.1)

- `userDoc/` est un projet npm **isolé** de l'application : ne touchez jamais
  `package.json` / `vite.config.js` / `resources/**` à la racine du dépôt
  depuis ce dossier, ni l'inverse.
- Les sources sont synchronisées vers la VM par une boucle inotify externe au
  dépôt : ne synchronisez jamais à la main. Un `node_modules/` créé côté
  hôte pour tester un build local est gitignoré et ne doit jamais être
  commité.
- Le lockfile se régénère avec `npm install --package-lock-only` — jamais de
  `node_modules` commité, jamais d'installation manuelle côté VM en dehors de
  `scripts/update.sh`.
- Une page Markdown supprimée côté hôte n'est pas supprimée côté VM (fantôme
  de source) : elle doit être retirée à la main par ssh, avec `trash` ou
  `mv` — jamais `rm -rf`.
