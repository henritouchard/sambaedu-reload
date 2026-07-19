/**
 * Gestion globale du thème (clair/sombre)
 * Ce module initialise le thème au chargement et fournit des fonctions
 * pour basculer entre les thèmes
 */

class ThemeManager {
    constructor() {
        this.init();
    }

    /**
     * Initialise le thème au chargement de la page
     */
    init() {
        // Thème sauvegardé, sinon clair par défaut (pas de détection système :
        // le choix est explicite via le toggle et persiste en localStorage)
        this.applyTheme(localStorage.getItem('theme') || 'light');
    }

    /**
     * Récupère le thème actuel
     * @returns {string} 'light' ou 'dark'
     */
    getCurrentTheme() {
        return document.documentElement.getAttribute('data-theme') || 'light';
    }

    /**
     * Vérifie si le thème sombre est actif
     * @returns {boolean}
     */
    isDark() {
        return this.getCurrentTheme() === 'dark';
    }

    /**
     * Applique un thème
     * @param {string} theme - 'light' ou 'dark'
     */
    applyTheme(theme) {
        if (theme !== 'light' && theme !== 'dark') {
            console.warn(`Thème invalide: ${theme}. Utilisation de 'light' par défaut.`);
            theme = 'light';
        }

        localStorage.setItem('theme', theme);
        document.documentElement.setAttribute('data-theme', theme);

        // Émettre un événement personnalisé pour que les composants puissent réagir
        window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme } }));
    }

    /**
     * Bascule entre les thèmes clair et sombre
     */
    toggle() {
        const currentTheme = this.getCurrentTheme();
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        this.applyTheme(newTheme);
        return newTheme;
    }

    /**
     * Active le thème clair
     */
    setLight() {
        this.applyTheme('light');
    }

    /**
     * Active le thème sombre
     */
    setDark() {
        this.applyTheme('dark');
    }
}

// Créer une instance globale
const themeManager = new ThemeManager();

// Exposer l'instance globalement pour qu'elle soit accessible partout
window.themeManager = themeManager;

// Exposer aussi une fonction toggle simple pour faciliter l'utilisation
window.toggleTheme = () => themeManager.toggle();

export default themeManager;

