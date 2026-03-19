const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        'og-admin': path.resolve(__dirname, 'src/og-admin.ts'),
    },
};
