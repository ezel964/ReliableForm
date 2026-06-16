<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';
require RF_ROOT . '/apps/web/src/api/support.php';

t('valid LCP beacon maps to web.client.lcp with value passed through', function (): void {
    $stat = rum_stat_for(['metric' => 'LCP', 'value' => 1820.5]);
    assert_true($stat !== null, 'LCP is an accepted metric');
    assert_eq('web.client.lcp', $stat['stat']);
    assert_eq(1820.5, $stat['value']);
});

t('INP, TTFB, FCP, FID are accepted and lower-cased', function (): void {
    assert_eq('web.client.inp', rum_stat_for(['metric' => 'INP', 'value' => 90])['stat']);
    assert_eq('web.client.ttfb', rum_stat_for(['metric' => 'TTFB', 'value' => 120])['stat']);
    assert_eq('web.client.fcp', rum_stat_for(['metric' => 'FCP', 'value' => 800])['stat']);
    assert_eq('web.client.fid', rum_stat_for(['metric' => 'FID', 'value' => 12])['stat']);
});

t('CLS is unitless and scaled x1000', function (): void {
    $stat = rum_stat_for(['metric' => 'CLS', 'value' => 0.12]);
    assert_eq('web.client.cls', $stat['stat']);
    assert_eq(120.0, $stat['value']);
});

t('unknown metric returns null', function (): void {
    assert_true(rum_stat_for(['metric' => 'BOGUS', 'value' => 1]) === null);
});

t('missing metric returns null', function (): void {
    assert_true(rum_stat_for(['value' => 1]) === null);
});

t('non-numeric value returns null', function (): void {
    assert_true(rum_stat_for(['metric' => 'LCP', 'value' => 'fast']) === null);
    assert_true(rum_stat_for(['metric' => 'LCP']) === null);
});
