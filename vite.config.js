import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

// Adaptive config: every port + host derives from .env so changing one place
// changes everything. See README.md "Adaptive Configuration" for rationale.
export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    // HMR host comes from APP_URL (so non-localhost dev domains work too).
    // APP_URL=http://localhost      → hmrHost=localhost
    // APP_URL=http://app.test       → hmrHost=app.test
    // APP_URL=http://192.168.1.50   → hmrHost=192.168.1.50
    const hmrHost = (() => {
        try {
            return new URL(env.APP_URL || 'http://localhost').hostname;
        } catch {
            return 'localhost';
        }
    })();

    const vitePort = Number.parseInt(env.VITE_PORT || '5173', 10);

    return {
        plugins: [
            laravel({
                input: 'resources/js/app.ts',
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            tailwindcss(),
        ],
        resolve: {
            alias: {
                '@': '/resources/js',
            },
        },
        server: {
            host: '0.0.0.0',
            port: vitePort,
            strictPort: true, // fail loudly if vitePort is taken instead of silently picking another
            hmr: {
                host: hmrHost,
            },
            watch: {
                usePolling: true,
            },
        },
    };
});
