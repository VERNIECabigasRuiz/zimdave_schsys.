import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import symfonyPlugin from 'vite-plugin-symfony';

export default defineConfig({
    plugins: [
        vue(),
        tailwindcss(),
        symfonyPlugin({ stimulus: './assets/controllers.json' }),
    ],
    build: {
        rollupOptions: {
            input: {
                app: './src/Core/Presentation/assets/app.js',
            },
        },
    },
});
