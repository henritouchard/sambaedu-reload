import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: [
                'app/**',
                'resources/**',
                'routes/**',
            ],
            detectTls: false,
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        hmr: {
            host: 'localhost'
        },
        watch: {
            ignored: [
                '**/vendor/**',
                '**/node_modules/**',
                '**/storage/**',
                '**/bootstrap/cache/**'
            ]
        }
    },
    build: {
        rollupOptions: {
            external: [],
            output: {
                assetFileNames: (assetInfo) => {
                    // Copier les polices FontAwesome dans le bon dossier
                    if (assetInfo.name && assetInfo.name.match(/\.(woff2?|eot|ttf|otf)$/)) {
                        return 'webfonts/[name][extname]';
                    }
                    return 'assets/[name]-[hash][extname]';
                }
            }
        },
        assetsDir: 'assets'
    }
});
