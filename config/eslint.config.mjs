import js from '@eslint/js';
import vue from 'eslint-plugin-vue';
import stylistic from '@stylistic/eslint-plugin';
import globals from 'globals';

export default [
    js.configs.recommended,
    ...vue.configs['flat/recommended'],
    {
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
            },
        },
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            '@stylistic/indent': ['error', 4],
            '@stylistic/quotes': ['error', 'single'],
            '@stylistic/semi': ['error', 'always'],
            '@stylistic/comma-dangle': ['error', 'always-multiline'],
            'vue/html-indent': ['error', 4],
        },
    },
    {
        files: ['tests/**/Javascript/**', 'config/**', 'vite.config.js'],
        languageOptions: {
            globals: {
                ...globals.node,
            },
        },
    },
    {
        ignores: [
            'public/**',
            'vendor/**',
            'node_modules/**',
            'var/**',
            'reports/**',
        ],
    },
];
