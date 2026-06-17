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
    /** Default location of the Rspack build manifest. */
    public static function manifestPath(): string
    {
        return RF_ROOT . '/frontend/build/asset-manifest.json';
    }

    /** @return array<string, string> */
    private static function manifest(?string $path = null): array
    {
        $path ??= self::manifestPath();
        return is_file($path)
            ? (json_decode((string) file_get_contents($path), true) ?: [])
            : [];
    }

    /**
     * Has the React bundle for $app been built? Page handlers use this to serve
     * the SPA shell when present and fall back to the legacy PHP view otherwise
     * (a safe, reversible strangler migration).
     */
    public static function has(string $app): bool
    {
        $m = self::manifest();
        return isset($m[$app . '.js']);
    }

    /**
     * @param array{
     *   bootstrap?: array<mixed>|null,
     *   manifestPath?: string,
     *   title?: string,
     *   csrf?: string,
     *   apiKey?: string
     * } $opts
     */
    public static function render(string $app, array $opts = []): string
    {
        $manifestPath = $opts['manifestPath'] ?? self::manifestPath();
        $manifest = self::manifest($manifestPath);

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
        // The SPA calls the sessionless /v1 API with the owner's own key (Bearer).
        // Injected only for the logged-in owner's first-party shell.
        if (!empty($opts['apiKey'])) {
            $router['API_KEY'] = (string) $opts['apiKey'];
        }

        return render('spa_shell', [
            'title' => $opts['title'] ?? 'ReliableForm',
            'router' => $router,
            'bootstrap' => $opts['bootstrap'] ?? null,
            'scripts' => $scripts,
            'app' => $app,
        ]);
    }
}
