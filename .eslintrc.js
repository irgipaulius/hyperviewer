module.exports = {
	extends: [
		'@nextcloud'
	],
	rules: {
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
