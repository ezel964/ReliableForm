<?php

declare(strict_types=1);

/**
 * Renders the React host shell for an SPA surface (JotForm's FrontendLoader
 * pattern): inject window.__rfrouter config, optional prefetched bootstrap
 * JSON, and the revision-hashed <script> tags from the build manifest. The
 * telemetry bundle is always injected first so RUM/tracing/error reporting
 * boot before the app.
 */
final class FrontendLoader
{
    /**
     * @param array{
     *   bootstrap?: array<mixed>|null,
     *   manifestPath?: string,
     *   title?: string,
     *   csrf?: string
     * } $opts
     */
    public static function render(string $app, array $opts = []): string
    {
        $manifestPath = $opts['manifestPath'] ?? (RF_ROOT . '/frontend/build/asset-manifest.json');
        $manifest = is_file($manifestPath)
            ? (json_decode((string) file_get_contents($manifestPath), true) ?: [])
            : [];

        $scripts = [];
        foreach (['telemetry.js', $app . '.js'] as $entry) {
            if (isset($manifest[$entry]) && is_string($manifest[$entry])) {
                $scripts[] = $manifest[$entry];
            }
        }

        $revisionFile = RF_ROOT . '/frontend/build/REVISION';
        $router = [
            'ENV' => Config::get('APP_ENV', 'prod'),
            'ASSET_PATH' => '/build/',
            'REVISION' => is_file($revisionFile) ? trim((string) file_get_contents($revisionFile)) : 'dev',
            'TRACE_ID' => Trace::id(),
            'CSRF' => $opts['csrf'] ?? Csrf::token(),
            'BASE_PATH' => '/',
            'ACTIVE_PAGE' => $app,
        ];

        return render('spa_shell', [
            'title' => $opts['title'] ?? 'ReliableForm',
            'router' => $router,
            'bootstrap' => $opts['bootstrap'] ?? null,
            'scripts' => $scripts,
            'app' => $app,
        ]);
    }
}
