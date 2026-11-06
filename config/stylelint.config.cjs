module.exports = {
    extends: 'stylelint-config-standard',
    ignoreFiles: ['public/**', 'vendor/**', 'node_modules/**'],
    rules: {
        'at-rule-no-unknown': [true, {
            ignoreAtRules: ['theme', 'source', 'utility', 'variant', 'custom-variant', 'plugin', 'reference', 'apply'],
        }],
        // Tailwind reads only the plain string form of @import
        'import-notation': 'string',
    },
};
