const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'client/admin/index.js' ),
		checkout: path.resolve( __dirname, 'client/checkout/index.js' ),
		catalog: path.resolve( __dirname, 'client/catalog/index.js' ),
		payments: path.resolve( __dirname, 'client/payments/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build' ),
	},
	externals: {
		...defaultConfig.externals,
		'@woocommerce/blocks-checkout': [ 'wc', 'blocksCheckout' ],
		'@woocommerce/blocks-registry': [ 'wc', 'wcBlocksRegistry' ],
		'@woocommerce/settings': [ 'wc', 'wcSettings' ],
	},
};
