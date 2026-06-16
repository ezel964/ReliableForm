const path = require('path');

module.exports = {
  mode: 'production',
  entry: { telemetry: './src/index.ts' },
  output: {
    path: path.resolve(__dirname, '../../../build'),
    filename: '[name].[contenthash:8].js',
    clean: false,
  },
  resolve: {
    extensions: ['.ts', '.js'],
  },
  module: {
    rules: [
      {
        test: /\.ts$/,
        use: {
          loader: 'builtin:swc-loader',
          options: { jsc: { parser: { syntax: 'typescript' } } },
        },
        type: 'javascript/auto',
      },
    ],
  },
  optimization: { minimize: true },
};
