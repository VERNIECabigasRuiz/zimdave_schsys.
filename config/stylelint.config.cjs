module.exports = {
    extends: 'stylelint-config-standard',
    ignoreFiles: ['public/**', 'vendor/**', 'node_modules/**'],
    rules: {
        'at-rule-no-unknown': [true, {
            ignoreAtRules: ['theme', 'source', 'utility', 'variant', 'custom-variant', 'plugin', 'reference', 'apply'],
        }],
        // Tailwind reads only the plain string form of @import
        'import-notation': 'string',
        // Class names follow BEM: block, block__element, block--modifier
        'selector-class-pattern': [
            '^[a-z][a-z0-9]*(-[a-z0-9]+)*(__[a-z0-9]+(-[a-z0-9]+)*)?(--[a-z0-9]+(-[a-z0-9]+)*)?$',
            { message: 'Expected class selector "%s" to follow BEM (block__element--modifier)' },
        ],
        // The stylesheets keep the notation older phone browsers read
        'color-function-notation': 'legacy',
        'color-function-alias-notation': null,
        'alpha-value-notation': 'number',
        'media-feature-range-notation': 'prefix',
        'selector-not-notation': 'simple',
        // Font family names keep their usual case
        'value-keyword-case': ['lower', { ignoreProperties: ['font-family', '/^--font/'] }],
        // Reordering or merging rules to satisfy these would change which rule wins
        'no-descending-specificity': null,
        'no-duplicate-selectors': null,
    },
};
