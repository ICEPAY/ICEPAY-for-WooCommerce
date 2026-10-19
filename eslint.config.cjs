const defaults = require('@wordpress/scripts/config/eslint.config.cjs');

module.exports = [
    ...defaults,
    { ignores: ['wordpress/**', 'build/**', 'vendor/**', 'node_modules/**'] },
    {
        languageOptions: { globals: { icepay: 'readonly' } },
        settings: { react: { version: '18.0' } },
        // Keep existing formatting separate from the correctness gate.
        rules: {
            'prettier/prettier': 'off',
            // WooCommerce supplies this external through its dependency-extraction plugin.
            'import/no-unresolved': ['error', { ignore: ['^@woocommerce/blocks-registry$'] }],
        },
    },
    {
        files: ['tests/Browser/*.cjs', 'webpack.config.js', 'eslint.config.cjs'],
        rules: { 'no-console': 'off' },
    },
];
