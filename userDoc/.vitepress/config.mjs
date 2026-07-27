import { defineConfig } from 'vitepress'
import { statSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import container from 'markdown-it-container'

// Racine des sources VitePress (userDoc/, parent de .vitepress/) — utilisée
// par le repli mtime de transformPageData ci-dessous ET par la résolution
// des captures d'écran sous userDoc/public/ (Story 52.7).
const srcDirUrl = new URL('..', import.meta.url)

// Racine des assets statiques VitePress (userDoc/public/) : un fichier posé
// ici est servi TEL QUEL, sans le préfixe `base` dans le chemin disque (le
// préfixe n'existe que dans l'URL publiée — piège #4 de la Story 52.7).
const publicDirUrl = new URL('public/', srcDirUrl)

// Alias Apache sous lequel le site est publié (jamais à la racine — piège #1
// de la Story 52.1). Constante UNIQUE réutilisée par `base` ci-dessous.
const BASE = '/doc/'

// Socle du site de documentation publique SE5 (Story 52.1). Ce fichier pose
// l'infrastructure : deux portes (/admin/, /poste/) à sidebars séparées,
// autonomie réseau (fonts embarquées, cf. theme/index.js), date de fraîcheur
// avec repli mtime. Les quatre encarts normalisés, le glossaire et
// l'exclusion de la doc contributeur sont posés par la Story 52.2. La
// recherche (themeConfig.search) est posée par la Story 52.6. La convention
// de captures d'écran (règle image ci-dessous) est posée par la Story 52.7.

// --- Encarts normalisés (Story 52.2, AC2/AC3) --------------------------
//
// UN SEUL point de rendu par type de container : une fiche écrit
// `::: droit-requis` … `:::` et le HTML produit ici est repris tel quel sur
// tout le site (styles dans theme/custom.css). Titres FIGÉS — aucune fiche
// ne les reformule.
const CALLOUT_TITLES = {
    'droit-requis': 'Droit requis',
    attention: 'Attention',
    'vue-poste': "Ce que voit l'utilisateur du poste",
}

// Les trois temporalités d'effet, nommées une fois pour toutes (AC3). Le
// paramètre de `::: delai-effet <valeur>` DOIT être l'une de ces clés.
const DELAI_EFFET_LABELS = {
    immediat: 'Effet immédiat',
    session: 'À la prochaine ouverture de session',
    agent: 'Au prochain passage de l’agent sur le poste',
}

// Container simple (droit-requis / attention / vue-poste) : titre fixe,
// aucun paramètre attendu.
function registerSimpleCallout(md, name, title) {
    md.use(container, name, {
        render(tokens, idx) {
            if (tokens[idx].nesting === 1) {
                return `<div class="se5-callout se5-callout--${name}">\n<p class="se5-callout__title">${title}</p>\n`
            }
            return '</div>\n'
        },
    })
}

// Container `delai-effet <valeur>` : valide son paramètre au rendu. Une
// valeur absente ou inconnue fait ÉCHOUER LE BUILD (throw explicite : fichier
// + valeur reçue + valeurs admises — piège #6 de la story, indispensable
// pour ne pas perdre de temps en `npm run dev`).
function registerDelaiEffetCallout(md) {
    md.use(container, 'delai-effet', {
        render(tokens, idx, _options, env) {
            const token = tokens[idx]
            if (token.nesting !== 1) {
                return '</div>\n'
            }
            const value = token.info.trim().slice('delai-effet'.length).trim()
            const label = DELAI_EFFET_LABELS[value]
            if (!label) {
                const file = env?.relativePath ?? '(fichier inconnu)'
                const admises = Object.keys(DELAI_EFFET_LABELS).join(', ')
                throw new Error(
                    `[delai-effet] valeur invalide "${value || '(aucune)'}" dans ${file} — ` +
                        `utiliser "::: delai-effet <valeur>" avec une des valeurs suivantes : ${admises}`,
                )
            }
            return `<div class="se5-callout se5-callout--delai-effet">\n<p class="se5-callout__title">${label}</p>\n`
        },
    })
}

// --- Captures d'écran (Story 52.7, AC2) ---------------------------------
//
// UN SEUL point de rendu pour toute image markdown `![alt](/captures/...)` :
// la règle `image` de markdown-it, capturée AVANT d'être remplacée (piège
// #6 — toute image dont la cible n'est PAS sous /captures/ est déléguée
// intacte à cette règle par défaut : logo, schéma futur, rien d'autre n'est
// jamais réécrit ici). Fichier présent sous userDoc/public/captures/... →
// <img class="se5-capture">. Fichier absent → placeholder « Illustration à
// venir » + alt (repli textuel, cœur d'UX-DR5) : VitePress ne vérifie PAS
// les chemins absolus vers public/ au build, c'est cette règle qui rend le
// manque visible.
//
// ⚠️ Préfixe `base` : NE PAS le concaténer ici (vérifié par build réel, cette
// règle diverge de l'hypothèse initiale de la story — piège #4 tel
// qu'énoncé était FAUX à l'épreuve). Le HTML produit par cette règle est
// splicé dans le même template Vue que celui du rendu markdown natif ; la
// transformation d'assets de `@vitejs/plugin-vue` (transformAssetUrls)
// s'applique donc IDENTIQUEMENT aux deux : tout `src` absolu (`/...`) est
// réécrit en import résolu contre `publicDir`, et Vite y injecte `base` tout
// seul au build. Émettre `src="${BASE}${src}"` fait chercher à Rollup un
// fichier sous `public/doc/captures/...` (qui n'existe pas) → échec de build
// (`Rollup failed to resolve import "/doc/captures/..."`), constaté en
// preuve locale (Task 5). Le `src` émis ici reste donc le chemin SITE-ROOT
// (`/captures/...`), exactement comme le ferait la règle par défaut.
function escapeHtml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
}

function registerCaptureImageRule(md) {
    const defaultImageRender =
        md.renderer.rules.image ??
        ((tokens, idx, options, env, self) => self.renderToken(tokens, idx, options))

    md.renderer.rules.image = (tokens, idx, options, env, self) => {
        const token = tokens[idx]
        const srcIndex = token.attrIndex('src')
        const src = srcIndex >= 0 ? token.attrs[srcIndex][1] : ''

        // Toute image hors /captures/ (logo, schéma futur, URL externe) est
        // déléguée SANS modification à la règle par défaut — elle est de
        // toute façon bloquée dans les sources publiées par le lint (AC3).
        if (!src.startsWith('/captures/')) {
            return defaultImageRender(tokens, idx, options, env, self)
        }

        // Même résolution d'alt que la règle par défaut de markdown-it
        // (renderInlineAsText sur les enfants) : gère un alt purement texte
        // comme un alt avec emphase imbriquée, sans jamais laisser passer de
        // markup brut dans l'attribut.
        const altHtml = escapeHtml(self.renderInlineAsText(token.children, options, env))
        const publicPath = fileURLToPath(new URL('.' + src, publicDirUrl))

        if (existsSync(publicPath)) {
            return `<img class="se5-capture" src="${src}" alt="${altHtml}" loading="lazy">`
        }

        // Éléments `span` (rendus en bloc par le CSS), et non `div`/`p` : une
        // image seule est enveloppée par markdown-it dans un `<p>`, or un bloc
        // à l'intérieur d'un `<p>` est du HTML invalide (le navigateur ferme le
        // `<p>` prématurément). Des `span` en `display:block` donnent le même
        // rendu tout en restant valides à l'intérieur du paragraphe.
        return (
            '<span class="se5-capture-placeholder">' +
            '<span class="se5-capture-placeholder__label">Illustration à venir</span>' +
            `<span class="se5-capture-placeholder__alt">${altHtml}</span>` +
            '</span>'
        )
    }
}

export default defineConfig({
    lang: 'fr-FR',
    title: 'Documentation SE5',
    description: "Documentation publique de l'environnement numérique de l'établissement.",

    // OBLIGATOIRE : le site est publié sous l'alias Apache /doc (jamais à la
    // racine). Sans ce réglage, tous les assets et liens internes 404ent une
    // fois déployés (piège #1 de la story 52.1).
    base: BASE,

    // README.md et CONTRIBUTING.md sont la doc de MAINTENANCE/RÉDACTION de
    // userDoc/ (à l'usage des devs et rédacteurs : commande de build, gabarit
    // de fiche, charte de rédaction) — PAS des fiches du site public.
    // `.templates/**` porte le modèle de fiche copiable (Story 52.2) : jamais
    // publié non plus. Sans ces exclusions, VitePress les compile comme des
    // pages ordinaires (`/doc/README.html`, `/doc/CONTRIBUTING.html`), y
    // compris leurs détails d'infra serveur et les mots que le lint interdit
    // précisément dans les sources publiées — violation NFR-D1 (piège #4).
    srcExclude: ['README.md', 'CONTRIBUTING.md', '.templates/**'],

    // Date de dernière mise à jour affichée sur chaque page (AC6), calculée au
    // build depuis l'historique git. AUCUN champ de date saisi à la main dans
    // le frontmatter des sources.
    lastUpdated: true,

    // cleanUrls NON activé : le laisser à false (défaut) évite d'exiger des
    // réécritures Apache sous l'alias statique (piège #9).
    // ignoreDeadLinks NON posé : le défaut strict de VitePress fait échouer le
    // build sur un lien interne mort (AC7) — ne JAMAIS le désactiver ici.
    // La recherche est le provider LOCAL de VitePress (themeConfig.search
    // ci-dessous) : index construit au build, moteur MiniSearch embarqué dans
    // les assets, tout s'exécute dans le navigateur. AUCUN provider externe
    // (Algolia/DocSearch) — le site doit rester consultable sans internet.

    // Encarts normalisés (AC2/AC3) : UN seul point d'enregistrement des 4
    // containers markdown-it, repris par toutes les fiches. `markdown-it-container`
    // est une dépendance EXPLICITE de userDoc/package.json (jamais comptée
    // comme transitive de VitePress).
    markdown: {
        config(md) {
            registerSimpleCallout(md, 'droit-requis', CALLOUT_TITLES['droit-requis'])
            registerSimpleCallout(md, 'attention', CALLOUT_TITLES.attention)
            registerSimpleCallout(md, 'vue-poste', CALLOUT_TITLES['vue-poste'])
            registerDelaiEffetCallout(md)

            // Captures d'écran (Story 52.7, AC2) — zone distincte des encarts
            // ci-dessus et du bloc recherche de themeConfig (Story 52.6) :
            // ne rien fusionner, ne rien réordonner de ces deux zones.
            registerCaptureImageRule(md)
        },
    },

    themeConfig: {
        // Recherche 100 % locale (index au build, MiniSearch embarqué — déjà
        // dépendance directe de VitePress, aucune dépendance nouvelle).
        // `detailedView: true` : titre ET extrait visibles d'emblée dans les
        // résultats. `processTerm` (une seule fonction, appliquée par MiniSearch
        // à l'indexation ET aux requêtes) plie les diacritiques : « depot »
        // trouve « dépôt », « eleve » trouve « élève ». Tous les libellés sont
        // en français (clé absente = défaut anglais).
        search: {
            provider: 'local',
            options: {
                detailedView: true,
                miniSearch: {
                    options: {
                        processTerm: (term) =>
                            term.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(),
                    },
                },
                translations: {
                    button: {
                        buttonText: 'Rechercher',
                        buttonAriaLabel: 'Rechercher dans la documentation',
                    },
                    modal: {
                        displayDetails: 'Afficher les extraits',
                        resetButtonTitle: 'Effacer la recherche',
                        backButtonTitle: 'Fermer la recherche',
                        noResultsText: 'Aucun résultat pour',
                        footer: {
                            selectText: 'pour ouvrir',
                            selectKeyAriaLabel: 'entrée',
                            navigateText: 'pour naviguer',
                            navigateUpKeyAriaLabel: 'flèche haut',
                            navigateDownKeyAriaLabel: 'flèche bas',
                            closeText: 'pour fermer',
                            closeKeyAriaLabel: 'échap',
                        },
                    },
                },
            },
        },

        nav: [
            { text: 'Accueil', link: '/' },
            { text: "J'administre SE5", link: '/admin/' },
            { text: "J'utilise mon poste", link: '/poste/' },
            { text: 'Glossaire', link: '/glossaire' },
        ],

        // Sidebar keyée par préfixe de chemin : un lecteur du parcours
        // /poste/ ne voit JAMAIS la navigation du parcours /admin/, et
        // réciproquement (AC1).
        sidebar: {
            // Convention ADDITIVE de la sidebar du parcours « J'administre SE5 » :
            // chaque domaine du guide ajoute SON groupe ICI au moment où ses
            // pages existent réellement, par modification strictement additive —
            // jamais par pré-remplissage. VitePress fait échouer le build sur un
            // lien interne mort (`ignoreDeadLinks` au défaut strict) : un lien
            // vers une page encore absente casserait la publication. Tant qu'un
            // domaine n'a pas de page, il est présenté en TEXTE sur la page
            // d'orientation `/admin/`, pas en lien de sidebar. Ne référencer que
            // des pages existantes (aujourd'hui : la seule « Vue d'ensemble »).
            '/admin/': [
                {
                    text: 'Administration SE5',
                    items: [{ text: "Vue d'ensemble", link: '/admin/' }],
                },
                {
                    text: 'Utilisateurs et groupes',
                    link: '/admin/utilisateurs/',
                    items: [
                        { text: 'Créer un compte', link: '/admin/utilisateurs/creer-un-compte' },
                        { text: 'Modifier un compte', link: '/admin/utilisateurs/modifier-un-compte' },
                        { text: 'Réinitialiser un mot de passe', link: '/admin/utilisateurs/reinitialiser-un-mot-de-passe' },
                        { text: 'Désactiver ou supprimer un compte', link: '/admin/utilisateurs/desactiver-ou-supprimer-un-compte' },
                        { text: "Groupes d'utilisateurs", link: '/admin/utilisateurs/groupes-d-utilisateurs' },
                        { text: 'En cas de problème', link: '/admin/utilisateurs/en-cas-de-probleme' },
                    ],
                },
                {
                    text: 'Parc et postes',
                    link: '/admin/parc/',
                    items: [
                        { text: "Lire l'état d'un poste", link: '/admin/parc/lire-l-etat-d-un-poste' },
                        { text: 'Agir sur un poste', link: '/admin/parc/agir-sur-un-poste' },
                        { text: 'Agir sur un groupe', link: '/admin/parc/agir-sur-un-groupe' },
                        { text: 'Constituer les groupes', link: '/admin/parc/constituer-les-groupes' },
                        { text: 'Salle ou parc logique', link: '/admin/parc/salle-ou-parc-logique' },
                        { text: 'En cas de problème', link: '/admin/parc/en-cas-de-probleme' },
                    ],
                },
                {
                    text: 'Applications et personnalisation',
                    link: '/admin/applications/',
                    items: [
                        { text: 'Le catalogue et le dépôt', link: '/admin/applications/catalogue-et-depot' },
                        { text: 'Affecter une application', link: '/admin/applications/affecter-une-application' },
                        { text: 'Retirer une application', link: '/admin/applications/retirer-une-application' },
                        { text: "Fonds d'écran", link: '/admin/applications/fonds-d-ecran' },
                        { text: 'Raccourcis', link: '/admin/applications/raccourcis' },
                        { text: 'Paramétrer Firefox et Thunderbird', link: '/admin/applications/parametrer-firefox-et-thunderbird' },
                        { text: 'En cas de problème', link: '/admin/applications/en-cas-de-probleme' },
                    ],
                },
                {
                    text: 'Fichiers et partages',
                    link: '/admin/fichiers/',
                    items: [
                        { text: 'Régler la politique de fichiers', link: '/admin/fichiers/politique-de-fichiers' },
                        { text: 'Le partage de classe', link: '/admin/fichiers/partage-de-classe' },
                        { text: 'Créer un partage', link: '/admin/fichiers/creer-un-partage' },
                        { text: 'Gérer les accès d\'un partage', link: '/admin/fichiers/gerer-les-acces-d-un-partage' },
                        { text: "Limiter l'espace de stockage", link: '/admin/fichiers/limiter-l-espace-de-stockage' },
                        { text: 'En cas de problème', link: '/admin/fichiers/en-cas-de-probleme' },
                    ],
                },
                {
                    text: 'Droits et délégation',
                    link: '/admin/droits/',
                    items: [
                        { text: 'Comprendre le modèle de droits', link: '/admin/droits/comprendre-le-modele-de-droits' },
                        { text: 'Les profils types', link: '/admin/droits/profils-types' },
                        { text: 'Composer un profil de droits', link: '/admin/droits/composer-un-profil' },
                        { text: 'Attribuer des droits à une personne', link: '/admin/droits/attribuer-des-droits' },
                        { text: 'Déléguer un droit sur une salle', link: '/admin/droits/deleguer-sur-une-salle' },
                        { text: 'En cas de problème', link: '/admin/droits/en-cas-de-probleme' },
                    ],
                },
            ],
            '/poste/': [
                {
                    text: 'Mon poste',
                    items: [{ text: "Vue d'ensemble", link: '/poste/' }],
                },
                {
                    text: 'Mon compte',
                    link: '/poste/mon-compte/',
                    items: [
                        { text: 'Se connecter', link: '/poste/mon-compte/se-connecter' },
                        { text: 'Changer mon mot de passe', link: '/poste/mon-compte/changer-mon-mot-de-passe' },
                        { text: 'On me demande de changer mon mot de passe', link: '/poste/mon-compte/changement-impose' },
                        { text: 'Mot de passe oublié', link: '/poste/mon-compte/mot-de-passe-oublie' },
                    ],
                },
                {
                    text: 'Mes fichiers',
                    link: '/poste/fichiers/',
                    items: [
                        { text: 'Mon espace personnel', link: '/poste/fichiers/espace-personnel' },
                        { text: 'Les espaces partagés', link: '/poste/fichiers/espaces-partages' },
                        { text: "D'un poste à l'autre", link: '/poste/fichiers/dun-poste-a-lautre' },
                    ],
                },
                {
                    text: 'Applications et impression',
                    items: [
                        { text: 'Mes applications', link: '/poste/applications' },
                        { text: 'Imprimer', link: '/poste/impression' },
                    ],
                },
            ],
        },

        // Libellés du thème en français (AC1) : retour en haut, sommaire,
        // bascule de thème, pied de page précédent/suivant, menu latéral,
        // et le lien d'accessibilité « saut au contenu » (visible au focus
        // clavier / lecteur d'écran).
        skipToContentLabel: 'Aller au contenu',
        outline: {
            label: 'Sommaire de la page',
        },
        docFooter: {
            prev: 'Page précédente',
            next: 'Page suivante',
        },
        darkModeSwitchLabel: 'Thème',
        lightModeSwitchTitle: 'Basculer en thème clair',
        darkModeSwitchTitle: 'Basculer en thème sombre',
        sidebarMenuLabel: 'Menu',
        returnToTopLabel: 'Retour en haut',
        lastUpdated: {
            text: 'Dernière mise à jour',
        },
        notFound: {
            title: 'Page introuvable',
            quote: "Cette page n'existe pas ou a été déplacée.",
            linkLabel: "Retour à l'accueil",
            linkText: "Retour à l'accueil",
        },
    },

    // Repli sans-git (Task 4) : la copie de la VM de dev n'est PAS un dépôt
    // git (vérifié 2026-07-23, cf. Dev Notes de la story) — l'historique git
    // ne produit alors AUCUNE date. En mode nominal (checkout git réel),
    // VitePress résout déjà lastUpdated depuis git et cette fonction ne fait
    // rien de plus. transformPageData tourne côté Node au build, jamais côté
    // client.
    transformPageData(pageData) {
        if (!pageData.lastUpdated && pageData.filePath) {
            try {
                const stat = statSync(fileURLToPath(new URL(pageData.filePath, srcDirUrl)))
                pageData.lastUpdated = stat.mtimeMs
            } catch {
                // Fichier introuvable (cas limite) : on laisse lastUpdated
                // vide plutôt que de faire échouer le build pour une date.
            }
        }
    },
})
