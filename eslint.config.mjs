// Flat ESLint config for the NovotonBUN addons' frontend JS/JSX.
//
// Scope: standalone addon scripts (addon-*/js) and the React booking-engine
// source (addon-*/react-src/src). It does NOT lint JS embedded in Smarty .tpl
// templates — ESLint can't parse Smarty; Qodana keeps watch on those.
//
// The rule set is intentionally tight (high-value, low-false-positive) and maps
// to the real Qodana JS findings: dead vars/functions, `==` coercion, `var`
// redeclarations, redundant assignments. `no-undef` is OFF because these files
// rely on dozens of CS-Cart / jQuery runtime globals.
import globals from 'globals';
import react from 'eslint-plugin-react';

const sharedRules = {
    'no-unused-vars': ['warn', { args: 'none', caughtErrors: 'none', varsIgnorePattern: '^_' }],
    'eqeqeq': ['warn', 'smart'],
    'no-redeclare': 'warn',
    'no-useless-assignment': 'warn',
    'no-undef': 'off',
};

export default [
    {
        ignores: [
            'vendor/**',
            '**/node_modules/**',
            '**/*.min.js',
            '**/react-src/build/**',
            '**/react-src/dist/**',
            '**/react*-bundle.js', // built React bundle (generated, not source)
            '**/react-vendor.js', // built React vendor chunk (generated, not source)
            'addon-*/design/**', // third-party CS-Cart themes + Smarty templates
            'stubs/**',
            'var/**',
        ],
    },

    // Standalone addon scripts — classic browser scripts (var / IIFE style)
    {
        files: ['addon-*/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2021,
            sourceType: 'script',
            globals: {
                ...globals.browser,
                ...globals.jquery,
                Tygh: 'readonly',
                $: 'readonly',
                jQuery: 'readonly',
                _: 'readonly',
            },
        },
        rules: sharedRules,
    },

    // React booking-engine source — ES modules + JSX
    {
        files: ['addon-*/react-src/src/**/*.{js,jsx}'],
        plugins: { react },
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            parserOptions: { ecmaFeatures: { jsx: true } },
            globals: { ...globals.browser },
        },
        rules: {
            ...sharedRules,
            // teach no-unused-vars about JSX usage (<Foo/> counts as using Foo)
            'react/jsx-uses-vars': 'warn',
            'react/jsx-uses-react': 'warn',
            'no-unused-vars': ['warn', { args: 'none', caughtErrors: 'none', varsIgnorePattern: '^_|^React$' }],
        },
    },
];
