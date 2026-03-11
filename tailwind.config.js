/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
    "./app/View/Components/**/*.php",
    "./app/Livewire/**/*.php",
    "./storage/framework/views/*.php",
  ],
  theme: {
    extend: {},
  },
  safelist: [
    // Classes dynamiques pour les alertes
    {
      pattern: /^(bg|border|text)-(error|success|warning|info|primary|secondary)\/?\d*$/,
      variants: ['hover', 'focus', 'active']
    },
    // Classes avec opacité
    {
      pattern: /^(bg|border|text)-(error|success|warning|info|primary|secondary)\/\d+$/
    },
    // Classes de statut
    {
      pattern: /^status-(error|success|warning|info|primary|secondary)$/
    },
    // Classes responsive pour display
    'hidden',
    'md:flex',
    'md:grid',
    'md:block',
    'lg:grid-cols-4',
    'md:grid-cols-2',
  ],
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography'),
    require('daisyui'),
  ],
  daisyui: {
    themes: [
      "light",    // Thème clair par défaut
      "dark",     // Thème sombre par défaut
    ],
    darkTheme: "dark",     // Thème utilisé en mode sombre
    base: true,
    styled: true,
    utils: true,
    rtl: false,
    prefix: "",
    logs: false,
  },
}
