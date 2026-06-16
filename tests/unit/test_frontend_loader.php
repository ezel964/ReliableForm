<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';

function rf_fixture_manifest(array $map): string
{
    $path = sys_get_temp_dir() . '/rf-manifest-' . getmypid() . '-' . uniqid() . '.json';
    file_put_contents($path, json_encode($map));
    return $path;
}

t('renders shell with telemetry + app scripts, root div, router config and bootstrap', function (): void {
    $fixture = rf_fixture_manifest([
        'telemetry.js' => '/build/telemetry.aaaa1111.js',
        'dashboard.js' => '/build/dashboard.bbbb2222.js',
    ]);
    $html = FrontendLoader::render('dashboard', [
        'manifestPath' => $fixture,
        'bootstrap' => ['forms' => []],
        'csrf' => 'tok',
    ]);
    @unlink($fixture);

    assert_true(str_contains($html, '<div id="root"></div>'), 'has #root mount');
    assert_true(str_contains($html, '/build/telemetry.aaaa1111.js'), 'injects telemetry bundle');
    assert_true(str_contains($html, '/build/dashboard.bbbb2222.js'), 'injects app bundle');
    assert_true(str_contains($html, 'window.__rfrouter'), 'injects router config');
    assert_true(str_contains($html, '"CSRF":"tok"'), 'csrf flows into router');
    assert_true(str_contains($html, 'id="rf-bootstrap"'), 'emits prefetched bootstrap');
    assert_true(str_contains($html, 'data-rf-page="dashboard"'), 'tags the page');
});

t('telemetry is always injected even when the app entry is missing; no bootstrap tag when null', function (): void {
    $fixture = rf_fixture_manifest(['telemetry.js' => '/build/telemetry.aaaa1111.js']);
    $html = FrontendLoader::render('builder', [
        'manifestPath' => $fixture,
        'csrf' => 't',
    ]);
    @unlink($fixture);

    assert_true(str_contains($html, '/build/telemetry.aaaa1111.js'), 'telemetry present');
    assert_true(!str_contains($html, 'builder.js'), 'no app bundle when manifest lacks it');
    assert_true(!str_contains($html, 'id="rf-bootstrap"'), 'no bootstrap tag when bootstrap is null');
});

t('missing manifest file degrades to no scripts but still renders a valid shell', function (): void {
    $html = FrontendLoader::render('dashboard', [
        'manifestPath' => '/nonexistent/asset-manifest.json',
        'csrf' => 't',
    ]);
    assert_true(str_contains($html, '<div id="root"></div>'), 'still renders mount');
    assert_true(str_contains($html, 'window.__rfrouter'), 'still injects router');
});
