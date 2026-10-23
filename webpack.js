const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

// Configure multiple entry points
webpackConfig.entry = {
	'files-integration': path.join(__dirname, 'src', 'files-integration.js'),
	'cockpit': path.join(__dirname, 'src', 'cockpit', 'main.js'),
	'personal': path.join(__dirname, 'src', 'personal', 'main.js'),
}

// Fix output filename to avoid double app name
webpackConfig.output = {
	...webpackConfig.output,
	filename: '[name].js',
	chunkFilename: 'hyperviewer-[id].js',
	publicPath: '/apps/hyperviewer/js/',
}

// Add html-loader for importing HTML files as strings
webpackConfig.module.rules.push({
	test: /\.html$/i,
	loader: 'html-loader',
	options: {
		minimize: false
	}
})

module.exports = webpackConfig
