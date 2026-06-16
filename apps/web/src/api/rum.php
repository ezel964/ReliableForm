<?php

declare(strict_types=1);

/**
 * POST /v1/rum — browser Core Web Vitals sink. Sessionless beacon: no auth, no
 * cookie, returns 204 with no body. Body arrives as application/json or as
 * text/plain (navigator.sendBeacon's default). Server-side sampling via
 * RUM_SAMPLE_RATE. Valid metrics become a StatsD timing web.client.<metric>.
 */

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);

http_response_code(204);
header_remove('Content-Type');

if (!is_array($payload)) {
    Metrics::increment('web.client.rum_malformed');
    exit;
}

$sample = (float) Config::get('RUM_SAMPLE_RATE', '1.0');
if ($sample < 1.0 && mt_rand() / mt_getrandmax() > $sample) {
    exit; // dropped by sampling
}

$stat = rum_stat_for($payload);
if ($stat === null) {
    exit;
}

Metrics::timing($stat['stat'], $stat['value']);
exit;
