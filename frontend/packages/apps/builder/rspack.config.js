const path = require('path');

module.exports = {
  mode: 'production',
  entry: { builder: './src/index.tsx' },
  output: {
    path: path.resolve(__dirname, '../../../build'),
    filename: '[name].[contenthash:8].js',
    clean: false,
  },
  resolve: { extensions: ['.tsx', '.ts', '.js'] },
  module: {
    rules: [
      {
        test: /\.tsx?$/,
        use: {
          loader: 'builtin:swc-loader',
          options: {
            jsc: {
              parser: { syntax: 'typescript', tsx: true },
              transform: { react: { runtime: 'automatic' } },
            },
          },
        },
        type: 'javascript/auto',
      },
    ],
  },
  optimization: { minimize: true, splitChunks: false, runtimeChunk: false },
};
