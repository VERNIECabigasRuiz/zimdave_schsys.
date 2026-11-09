import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    root: fileURLToPath(new URL('..', import.meta.url)),
    cacheDir: 'reports/vitest/.cache',
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['tests/**/Javascript/**/*.test.js'],
        // One worker at a time: the machine is shared
        pool: 'forks',
        maxWorkers: 1,
        reporters: ['default', 'junit'],
        outputFile: {
            junit: 'reports/vitest/junit.xml',
        },
        coverage: {
            provider: 'v8',
            include: ['src/*/Presentation/assets/**/*.{js,vue}'],
            // The Vite entry files only run inside Vite; the build and the browser tests check them
            exclude: ['src/Core/Presentation/assets/app.js', 'src/Core/Presentation/assets/bootstrap.js'],
            reportsDirectory: 'reports/vitest/coverage',
            reporter: ['text', 'clover'],
            thresholds: {
                lines: 100,
                functions: 100,
                branches: 100,
                statements: 100,
            },
        },
    },
});
