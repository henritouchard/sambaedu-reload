<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" class="w-full h-full">
    <defs>
        <!-- Masque pour cacher le cercle sous les PC -->
        <mask id="pcMask">
            <!-- Tout visible -->
            <rect width="100%" height="100%" fill="white" />

            <!-- Zones à masquer (emplacements des PC) -->
            <rect x="200" y="40" width="240" height="185" rx="20" fill="black" />
            <rect x="40" y="360" width="240" height="185" rx="20" fill="black" />
            <rect x="360" y="360" width="240" height="185" rx="20" fill="black" />
        </mask>
    </defs>

    <!-- Cercle de connexion (masqué sous les PC) -->
    <path d="M320 120
   C450 120 520 220 520 320
   C520 420 450 520 320 520
   C190 520 120 420 120 320
   C120 220 190 120 320 120Z" fill="none" stroke="currentColor" stroke-width="42" stroke-linecap="round"
        mask="url(#pcMask)" />

    <!-- PC du haut -->
    <g stroke="currentColor" stroke-width="40" fill="none">
        <rect x="200" y="40" width="240" height="150" rx="15" />
        <rect x="280" y="200" width="80" height="30" fill="currentColor" />
    </g>

    <!-- PC bas gauche -->
    <g stroke="currentColor" stroke-width="40" fill="none">
        <rect x="20" y="360" width="240" height="150" rx="15" />
        <rect x="100" y="520" width="80" height="30" fill="currentColor" />
    </g>

    <!-- PC bas droite -->
    <g stroke="currentColor" stroke-width="40" fill="none">
        <rect x="380" y="360" width="240" height="150" rx="15" />
        <rect x="460" y="520" width="80" height="30" fill="currentColor" />
    </g>
</svg>
