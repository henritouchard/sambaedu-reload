import { defineConfig } from 'vitepress'
import { statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import container from 'markdown-it-container'

// Racine des sources VitePress (userDoc/, parent de .vitepress/) — utilisée
// par le repli mtime de transformPageData ci-dessous.
const srcDirUrl = new URL('..', import.meta.url)

// Socle du site de documentation publique SE5 (Story 52.1). Ce fichier pose
// l'infrastructure : deux portes (/admin/, /poste/) à sidebars séparées,
// autonomie réseau (fonts embarquées, cf. theme/index.js), date de fraîcheur
// avec repli mtime. Les quatre encarts normalisés, le glossaire et
// l'exclusion de la doc contributeur sont posés par la Story 52.2. La
// recherche (themeConfig.search) reste hors périmètre (52.6).

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

export default defineConfig({
    lang: 'fr-FR',
    title: 'Documentation SE5',
    description: "Documentation publique de l'environnement numérique de l'établissement.",

    // OBLIGATOIRE : le site est publié sous l'alias Apache /doc (jamais à la
    // racine). Sans ce réglage, tous les assets et liens internes 404ent une
    // fois déployés (piège #1 de la story 52.1).
    base: '/doc/',

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
    // themeConfig.search NON posé : recherche locale réservée à la story 52.6.

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
        },
    },

    themeConfig: {
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
            '/admin/': [
                {
                    text: 'Administration SE5',
                    items: [{ text: "Vue d'ensemble", link: '/admin/' }],
                },
            ],
            '/poste/': [
                {
                    text: 'Mon poste',
                    items: [{ text: "Vue d'ensemble", link: '/poste/' }],
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
