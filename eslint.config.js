import js from '@eslint/js';
import globals from 'globals';

export default [
	js.configs.recommended,
	{
		languageOptions: {
			ecmaVersion: 2024,
			globals: {
				...globals.browser,
				...globals.jquery,
				jQuery: 'readonly',
				$: 'readonly',
				sscribe_data: 'readonly',
				SScribe: 'readonly',
			},
		},
		rules: {
			'no-unused-vars': ['warn', { argsIgnorePattern: '^_', caughtErrorsIgnorePattern: '^_' }],
			'no-console': 'error',
			'no-eval': 'error',
			'no-implied-eval': 'error',
			'no-new-func': 'error',
			'eqeqeq': ['error', 'smart'],
			'curly': ['error', 'all'],
			'semi': ['error', 'always'],
			'quotes': ['error', 'single'],
			'no-var': 'warn',
			'prefer-const': 'error',
		},
	},
	{
		ignores: ['dist/', 'node_modules/', 'vendor/', 'vendor-prefixed/'],
	},
];
