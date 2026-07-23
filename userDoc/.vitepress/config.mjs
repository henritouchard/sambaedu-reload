import { defineConfig } from 'vitepress'
import { statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

// Racine des sources VitePress (userDoc/, parent de .vitepress/) — utilisée
// par le repli mtime de transformPageData ci-dessous.
const srcDirUrl = new URL('..', import.meta.url)

// Socle du site de documentation publique SE5 (Story 52.1). Ce fichier ne pose
// QUE l'infrastructure : deux portes (/admin/, /poste/) à sidebars séparées,
// autonomie réseau (fonts embarquées, cf. theme/index.js), date de fraîcheur
// avec repli mtime. Le CONTENU des fiches, le glossaire, la recherche
// (themeConfig.search) et les encarts sont hors périmètre (52.2 → 52.8).

export default defineConfig({
    lang: 'fr-FR',
    title: 'Documentation SE5',
    description: "Documentation publique de l'environnement numérique de l'établissement.",

    // OBLIGATOIRE : le site est publié sous l'alias Apache /doc (jamais à la
    // racine). Sans ce réglage, tous les assets et liens internes 404ent une
    // fois déployés (piège #1 de la story 52.1).
    base: '/doc/',

    // README.md est la doc de MAINTENANCE de userDoc/ (à l'usage des devs :
    // commande de build, qui publie, piège des fantômes de source) — PAS une
    // fiche du site public. Sans cette exclusion, VitePress la compile comme
    // une page ordinaire et l'expose sous /doc/README.html, y compris ses
    // détails d'infra serveur (chemins VM, ssh) — violation NFR-D1.
    srcExclude: ['README.md'],

    // Date de dernière mise à jour affichée sur chaque page (AC6), calculée au
    // build depuis l'historique git. AUCUN champ de date saisi à la main dans
    // le frontmatter des sources.
    lastUpdated: true,

    // cleanUrls NON activé : le laisser à false (défaut) évite d'exiger des
    // réécritures Apache sous l'alias statique (piège #9).
    // ignoreDeadLinks NON posé : le défaut strict de VitePress fait échouer le
    // build sur un lien interne mort (AC7) — ne JAMAIS le désactiver ici.
    // themeConfig.search NON posé : recherche locale réservée à la story 52.6.

    themeConfig: {
        nav: [
            { text: 'Accueil', link: '/' },
            { text: "J'administre SE5", link: '/admin/' },
            { text: "J'utilise mon poste", link: '/poste/' },
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
        // bascule de thème, pied de page précédent/suivant, menu latéral.
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
