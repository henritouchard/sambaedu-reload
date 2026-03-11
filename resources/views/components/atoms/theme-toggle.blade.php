@props([
    'position' => 'fixed', // 'fixed', 'relative', 'absolute'
    'size' => 'md', // 'sm', 'md', 'lg'
])

@php
    $positionClasses = match($position) {
        'fixed' => 'fixed top-4 right-4 z-50',
        'absolute' => 'absolute top-4 right-4',
        'relative' => 'relative',
        default => ''
    };
    
    $sizeClasses = match($size) {
        'sm' => 'btn-sm',
        'md' => '',
        'lg' => 'btn-lg',
        default => ''
    };
@endphp

<button 
    onclick="window.toggleTheme()" 
    class="btn btn-circle btn-ghost {{ $positionClasses }} {{ $sizeClasses }} theme-toggle-btn"
    title="Basculer le thème (clair/sombre)"
    aria-label="Basculer le thème"
>
    <!-- Icône soleil (affichée en mode sombre) -->
    <svg class="w-5 h-5 theme-icon-sun hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
    </svg>
    
    <!-- Icône lune (affichée en mode clair) -->
    <svg class="w-5 h-5 theme-icon-moon" fill="currentColor" viewBox="0 0 20 20">
        <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path>
    </svg>
</button>

<script>
    // Fonction pour mettre à jour les icônes en fonction du thème
    function updateThemeIcons() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const sunIcons = document.querySelectorAll('.theme-icon-sun');
        const moonIcons = document.querySelectorAll('.theme-icon-moon');
        
        sunIcons.forEach(icon => {
            if (isDark) {
                icon.classList.remove('hidden');
            } else {
                icon.classList.add('hidden');
            }
        });
        
        moonIcons.forEach(icon => {
            if (isDark) {
                icon.classList.add('hidden');
            } else {
                icon.classList.remove('hidden');
            }
        });
    }
    
    // Mettre à jour les icônes au chargement
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateThemeIcons);
    } else {
        updateThemeIcons();
    }
    
    // Écouter les changements de thème
    window.addEventListener('theme-changed', updateThemeIcons);
</script>

