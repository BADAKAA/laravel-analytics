import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import { resolve } from 'path';
import { build } from 'vite';

import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import { resolve } from 'path';
import { build } from 'vite';

function buildClientPlugin() {
    return {
        name: 'build-client',
        apply: 'build',
        async closeBundle() {
            // Skip if this is already a nested build
            if (process.env.VITE_BUILDING_CLIENT) return;

            // Build client.ts after the main build completes
            process.env.VITE_BUILDING_CLIENT = '1';
            await build({
                build: {
                    emptyOutDir: false,
                    manifest: false,
                    rollupOptions: {
                        input: resolve(__dirname, 'resources/js/client.ts'),
                        output: {
                            entryFileNames: 'client.js',
                            dir: 'public/build',
                        },
                    },
                },
            });
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        buildClientPlugin(),
    ],
});
