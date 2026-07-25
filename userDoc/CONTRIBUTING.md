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
   poste) : où trouver l'écran ou la situation concernée, avant les gestes.
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
- un lien `/glossaire#x` vers une ancre qui n'existe pas dans `glossaire.md`.

« SE4 » et « SE5 » sont explicitement autorisés (cf. charte ci-dessus) ; les
nombres génériques (versions, quantités) ne sont pas bloqués.

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
