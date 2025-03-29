const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
    ...defaultConfig,
    entry: {
        'checkout-blocks': './src/checkout-blocks.js',
    },
    output: {
        path: path.resolve(__dirname, 'assets'),
        filename: '[name].js',
    },
}; 