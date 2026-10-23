module.exports = {
	extends: [
		'@nextcloud'
	],
	settings: {
		'import/resolver': {
			node: {
				extensions: ['.js', '.vue', '.json'],
			},
		},
	},
	rules: {
		'import/no-unresolved': 'off',
		'n/no-missing-import': 'off',
		'node/no-missing-import': 'off',
		'padded-blocks': 'off',
		'no-console': 'off', // Allow console.log for debugging
		'indent': 'off',
		'comma-dangle': 'off',
		'quotes': 'off',
		'semi': 'off',
		'operator-linebreak': 'off',
		'no-trailing-spaces': 'off',
		'no-multiple-empty-lines': 'off',
		// Keep only the important rules
		'no-unused-vars': 'warn',
		'no-undef': 'error'
	}
};
