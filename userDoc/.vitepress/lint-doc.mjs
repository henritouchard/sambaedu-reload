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
// Calibrage (piège #5) : « SE4 » et « SE5 » sont explicitement AUTORISÉS —
// aucun motif ne les touche (la charte demande de formuler un gain contre le
// legacy SE4 ; SE5 est le nom du produit). Les nombres génériques (numéros
// de version, quantités) ne sont PAS bloqués : seuls les codes à préfixe
// alphabétique + tiret (FR-, NFR-, UX-DR) sont visés, avec frontières de mot,
// pour ne jamais casser une mention du type « Firefox 128.0 ».

import { readFileSync, readdirSync } from 'node:fs'
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

function lintFile(filePath, relPath, glossaryAnchors) {
    const violations = []
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
    })

    return violations
}

function main() {
    const glossaryAnchors = collectGlossaryAnchors(join(srcDir, 'glossaire.md'))
    const allFiles = collectMarkdownFiles(srcDir)

    const report = []
    let examined = 0

    for (const filePath of allFiles) {
        const relPath = relative(srcDir, filePath).split('\\').join('/')
        if (!isPublished(relPath)) continue
        if (EXCEPTIONS.has(relPath)) continue
        examined++

        const violations = lintFile(filePath, relPath, glossaryAnchors)
        for (const violation of violations) {
            report.push(`${relPath}:${violation.line} → ${violation.motif}`)
        }
    }

    if (report.length > 0) {
        console.error('Lint éditorial : violations trouvées dans les sources publiées.\n')
        for (const line of report) {
            console.error(line)
        }
        console.error(`\n${report.length} violation(s). Corriger avant de reconstruire le site.`)
        process.exit(1)
    }

    console.log(`Lint éditorial : OK (${examined} fichier(s) source examiné(s)).`)
}

main()
