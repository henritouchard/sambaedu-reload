#!/usr/bin/env node
// Lint éditorial bloquant des règles de rédaction (Story 52.2, AC5).
//
// Node PUR — zéro dépendance npm nouvelle. Chaîné DANS le script "build" de
// package.json, avant `vitepress build` : un motif interdit fait échouer ce
// script (exit != 0) avant même que VitePress ne soit invoqué, ce qui
// protège la publication serveur (fail-soft de la Story 52.1 : un
// `npm run build` en échec laisse le site précédent servi intact).
//
// Portée : uniquement les sources PUBLIÉES — mirror exact de `srcExclude`
// dans .vitepress/config.mjs (README.md, CONTRIBUTING.md, .templates/**),
// plus les répertoires qui ne sont jamais des sources Markdown (node_modules,
// sorties de build, .vitepress lui-même, .git).
//
// Motifs interdits (AC5) :
//  - mots « story »/« épic »/« epic » (avec ou sans numéro), insensible à la
//    casse ;
//  - codes d'exigence FR-…, NFR-…, UX-DR… ;
//  - adresses IPv4 (les exemples passent par l'établissement fictif) ;
//  - containers natifs ::: warning / ::: danger (doublons de ::: attention,
//    piège #8 de la story — un seul rendu par usage) ;
//  - tout lien /glossaire#x vers une ancre absente de glossaire.md.
//
// Motifs image (Story 52.7, AC3), en plus des motifs ci-dessus :
//  - image markdown à texte alternatif vide ;
//  - image dont la cible n'est pas sous /captures/ (URL externe http/https
//    incluse) ;
//  - cible /captures/... dont l'extension n'est pas .png, ou dont un segment
//    de chemin n'est pas kebab-case ([a-z0-9-]) ;
//  - balise <img> brute en HTML dans une source publiée.
// En complément, une sortie INFORMATIVE (jamais bloquante, n'affecte pas le
// code de sortie) liste les captures référencées dont le fichier est absent
// sous userDoc/public/ — c'est la liste de production de l'action manuelle
// ultérieure (AC5).
//
// Calibrage (piège #5) : « SE4 » et « SE5 » sont explicitement AUTORISÉS —
// aucun motif ne les touche (la charte demande de formuler un gain contre le
// legacy SE4 ; SE5 est le nom du produit). Les nombres génériques (numéros
// de version, quantités) ne sont PAS bloqués : seuls les codes à préfixe
// alphabétique + tiret (FR-, NFR-, UX-DR) sont visés, avec frontières de mot,
// pour ne jamais casser une mention du type « Firefox 128.0 ».

import { readFileSync, readdirSync, existsSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const srcDir = fileURLToPath(new URL('..', import.meta.url))

// Répertoires jamais parcourus : infra de build, jamais des sources.
const EXCLUDED_DIRS = new Set(['node_modules', 'dist', '.vitepress', '.templates', '.git'])

// Fichiers non publiés à la racine de userDoc/ — MIROIR de `srcExclude`
// dans .vitepress/config.mjs (piège #4 : sans cet alignement, la doc
// contributeur - qui contient précisément les mots interdits ci-dessous -
// ferait échouer son propre lint).
const EXCLUDED_ROOT_FILES = new Set(['README.md', 'CONTRIBUTING.md'])

// Exceptions par fichier (chemin relatif à userDoc/, ex. 'poste/exemple.md')
// — VIDE au départ (AC5). Un futur faux positif avéré s'ajoute ICI, avec un
// commentaire qui explique pourquoi, jamais par un contournement du motif
// lui-même ni par une exclusion de répertoire entière.
const EXCEPTIONS = new Set([
    // 'poste/exemple.md',
])

const FORBIDDEN_PATTERNS = [
    {
        // \b ne fonctionne pas de façon fiable devant un caractère accentué
        // (« é » n'est pas un caractère "word" pour \b en JS) : on borne
        // nous-mêmes avec des lookarounds sur une classe de lettres qui
        // inclut les accentuées, sinon « épic » ne serait jamais détecté.
        // Les CHIFFRES sont volontairement HORS de la classe de frontière :
        // sinon une forme collée à un numéro (« story52 », « épic5 ») passe à
        // travers. Le suffixe pluriel est couvert (« stories », « épics »).
        source: '(?<![A-Za-zÀ-ÖØ-öø-ÿ_])(stor(?:y|ies)|épics?|epics?)(?![A-Za-zÀ-ÖØ-öø-ÿ_])',
        flags: 'gi',
        motif: 'mot « story »/« épic »/« epic » (sing./plur.) interdit dans une fiche publiée',
    },
    {
        source: '\\b(FR|NFR)-[A-Za-z0-9]+\\b|\\bUX-DR\\d*\\b',
        flags: 'g',
        motif: "code d'exigence (FR-…/NFR-…/UX-DR…) interdit dans une fiche publiée",
    },
    {
        source: '\\b\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\b',
        flags: 'g',
        motif: "adresse IPv4 interdite — utiliser l'établissement fictif des exemples",
    },
    {
        source: '^\\s*:::\\s*(warning|danger)\\b',
        flags: 'gi',
        motif: '::: warning / ::: danger natifs interdits — utiliser ::: attention',
    },
]

// Motifs image (Story 52.7, AC3) — regex volontairement simple (même patron
// pragmatique que FORBIDDEN_PATTERNS ci-dessus, pas un parseur markdown
// complet) : suffisant tant qu'un alt de fiche n'imbrique pas de crochets.
// `![alt](src "titre optionnel")` : alt = groupe 1, src = groupe 2 (jusqu'au
// premier espace ou à la parenthèse fermante).
const MARKDOWN_IMAGE_REGEX = /!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g

// Balise <img> brute en HTML dans une source publiée — le mécanisme
// d'insertion est markdown natif UNIQUEMENT (décision de design 52.7) ;
// écrire du HTML brut contourne le placeholder ET le lint des cibles.
const RAW_IMG_TAG_REGEX = /<img\b/gi

// Un segment de chemin (dossier ou nom de fichier sans extension) valide :
// lettres minuscules et chiffres, tirets simples entre segments — jamais de
// majuscule, d'espace, d'accent ni de numéro d'ordre imposé par la forme
// elle-même (le motif n'interdit pas les chiffres, seulement les caractères
// hors kebab-case).
const KEBAB_SEGMENT_REGEX = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

function collectMarkdownFiles(dir, out = []) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        if (entry.isDirectory()) {
            if (EXCLUDED_DIRS.has(entry.name)) continue
            collectMarkdownFiles(join(dir, entry.name), out)
            continue
        }
        if (!entry.name.endsWith('.md')) continue
        out.push(join(dir, entry.name))
    }
    return out
}

function isPublished(relPath) {
    // Racine de userDoc/ uniquement pour README.md/CONTRIBUTING.md (miroir
    // exact de srcExclude — un fichier de même nom dans un sous-dossier
    // resterait publié, comme VitePress le ferait lui-même).
    if (EXCLUDED_ROOT_FILES.has(relPath)) return false
    return true
}

function collectGlossaryAnchors(glossaryPath) {
    const anchors = new Set()
    let content
    try {
        content = readFileSync(glossaryPath, 'utf8')
    } catch {
        return anchors
    }
    const anchorRegex = /\{#([a-z0-9-]+)\}/g
    let match
    while ((match = anchorRegex.exec(content))) {
        anchors.add(match[1])
    }
    return anchors
}

// Motifs image d'une ligne (Story 52.7, AC3). `captures` reçoit une entrée
// par image markdown ciblant /captures/... (violation ou non) : c'est sur
// cette liste, filtrée ensuite par présence réelle du fichier, que se
// construit la sortie informative non bloquante (AC5).
function checkImagePatterns(line, lineNo, violations, captures) {
    MARKDOWN_IMAGE_REGEX.lastIndex = 0
    let match
    while ((match = MARKDOWN_IMAGE_REGEX.exec(line))) {
        const [, alt, src] = match

        if (alt.trim() === '') {
            violations.push({
                line: lineNo,
                motif: `image markdown à texte alternatif vide (« ${src} »)`,
            })
        }

        if (!src.startsWith('/captures/')) {
            violations.push({
                line: lineNo,
                motif: `image hors /captures/ interdite dans une source publiée — utiliser une capture (« ${src} »)`,
            })
            continue
        }

        const extMatch = /\.([A-Za-z0-9]+)$/.exec(src)
        const ext = extMatch ? extMatch[1].toLowerCase() : ''
        if (ext !== 'png') {
            violations.push({
                line: lineNo,
                motif: `capture d'extension différente de .png interdite — seul .png est autorisé (« ${src} »)`,
            })
        }

        const pathWithoutExt = src.slice('/captures/'.length).replace(/\.[A-Za-z0-9]+$/, '')
        const segments = pathWithoutExt.split('/').filter(Boolean)
        const invalidSegment = segments.find((segment) => !KEBAB_SEGMENT_REGEX.test(segment))
        if (invalidSegment !== undefined) {
            violations.push({
                line: lineNo,
                motif: `segment non kebab-case dans le chemin de capture (« ${invalidSegment} » dans ${src})`,
            })
        }

        captures.push({ line: lineNo, src })

        if (match.index === MARKDOWN_IMAGE_REGEX.lastIndex) MARKDOWN_IMAGE_REGEX.lastIndex++
    }

    RAW_IMG_TAG_REGEX.lastIndex = 0
    if (RAW_IMG_TAG_REGEX.test(line)) {
        violations.push({
            line: lineNo,
            motif: 'balise <img> brute interdite — utiliser ![alt](/captures/...) (rendu en un point unique, config.mjs)',
        })
    }
}

function lintFile(filePath, relPath, glossaryAnchors) {
    const violations = []
    const captures = []
    const content = readFileSync(filePath, 'utf8')
    const lines = content.split(/\r?\n/)

    lines.forEach((line, index) => {
        const lineNo = index + 1

        for (const pattern of FORBIDDEN_PATTERNS) {
            const regex = new RegExp(pattern.source, pattern.flags)
            let match
            while ((match = regex.exec(line))) {
                violations.push({
                    line: lineNo,
                    motif: `${pattern.motif} (« ${match[0]} »)`,
                })
                if (match.index === regex.lastIndex) regex.lastIndex++
            }
        }

        // Lien /glossaire#ancre vers une ancre qui n'existe pas.
        const glossaryLinkRegex = /\/glossaire#([a-zA-Z0-9-]+)/g
        let linkMatch
        while ((linkMatch = glossaryLinkRegex.exec(line))) {
            const anchor = linkMatch[1]
            if (!glossaryAnchors.has(anchor)) {
                violations.push({
                    line: lineNo,
                    motif: `lien vers une ancre glossaire inexistante « /glossaire#${anchor} »`,
                })
            }
        }

        checkImagePatterns(line, lineNo, violations, captures)
    })

    return { violations, captures }
}

// Sortie informative (Story 52.7, AC3/AC5) — JAMAIS bloquante, clairement
// séparée du rapport de violations : liste les captures référencées par les
// fiches dont le fichier est absent sous userDoc/public/. C'est la liste de
// production de l'action manuelle ultérieure (aucune image n'est produite
// par cette story).
function reportMissingCaptures(allCaptureRefs) {
    const missing = allCaptureRefs.filter(({ src }) => {
        const segments = src.split('/').filter(Boolean) // ex. ['captures', 'poste', ..., 'x.png']
        return !existsSync(join(srcDir, 'public', ...segments))
    })

    if (missing.length === 0) {
        console.log('Captures manquantes (informatif, non bloquant) : aucune.')
        return
    }

    console.log(
        `\nCaptures référencées mais absentes de userDoc/public/ (informatif, NON bloquant — ` +
            `liste de production pour l'action manuelle ultérieure) :`,
    )
    for (const { relPath, line, src } of missing) {
        console.log(`${relPath}:${line} → ${src}`)
    }
}

function main() {
    const glossaryAnchors = collectGlossaryAnchors(join(srcDir, 'glossaire.md'))
    const allFiles = collectMarkdownFiles(srcDir)

    const report = []
    const allCaptureRefs = []
    let examined = 0

    for (const filePath of allFiles) {
        const relPath = relative(srcDir, filePath).split('\\').join('/')
        if (!isPublished(relPath)) continue
        if (EXCEPTIONS.has(relPath)) continue
        examined++

        const { violations, captures } = lintFile(filePath, relPath, glossaryAnchors)
        for (const violation of violations) {
            report.push(`${relPath}:${violation.line} → ${violation.motif}`)
        }
        for (const capture of captures) {
            allCaptureRefs.push({ relPath, ...capture })
        }
    }

    if (report.length > 0) {
        console.error('Lint éditorial : violations trouvées dans les sources publiées.\n')
        for (const line of report) {
            console.error(line)
        }
        console.error(`\n${report.length} violation(s). Corriger avant de reconstruire le site.`)
        reportMissingCaptures(allCaptureRefs)
        process.exit(1)
    }

    console.log(`Lint éditorial : OK (${examined} fichier(s) source examiné(s)).`)
    reportMissingCaptures(allCaptureRefs)
}

main()
