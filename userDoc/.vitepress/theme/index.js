// Thème du site de documentation publique SE5 — identité visuelle IBM Plex
// embarquée (AC8, Task 3). `theme-without-fonts` évite d'embarquer Inter (la
// police par défaut de VitePress) en plus d'IBM Plex (piège #11).
import DefaultTheme from 'vitepress/theme-without-fonts'

// Fonts IBM Plex — miroir de resources/js/app.js l.3-8 (mêmes graisses),
// REDÉCLARÉES ici depuis les dépendances propres de userDoc/package.json.
// Jamais d'import depuis ../../node_modules (chaîne strictement isolée,
// NFR-D3).
import '@fontsource/ibm-plex-sans/400.css'
import '@fontsource/ibm-plex-sans/500.css'
import '@fontsource/ibm-plex-sans/600.css'
import '@fontsource/ibm-plex-sans/700.css'
import '@fontsource/ibm-plex-mono/400.css'
import '@fontsource/ibm-plex-mono/500.css'

import './custom.css'

export default DefaultTheme
