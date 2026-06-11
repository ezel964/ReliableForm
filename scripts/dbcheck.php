<?php

declare(strict_types=1);

/**
 * MySQL probe used by setup.sh / launch.sh (scripts/common.sh mysql_running).
 * Connects through the app's own kernel (lib/DB.php — 3s connect timeout,
 * ERRMODE_EXCEPTION) so it validates exactly what the app will use.
 *
 * Exit codes:
 *   0 = server up, app database reachable with the .env credentials
 *   1 = server up, but the database (or app user/grants) is missing → run setup.sh
 *   2 = server down / unreachable
 */

require __DIR__ . '/../lib/bootstrap.php';

try {
    DB::pdo()->query('SELECT 1');
    exit(0);
} catch (PDOException $e) {
    $code = (int) $e->getCode();
    $msg = $e->getMessage();
    // 1049 = Unknown database; 1044/1045 = access denied for the app user.
    // Either way the SERVER answered — bootstrap (setup.sh) is what's missing.
    if (
        $code === 1049 || $code === 1044 || $code === 1045
        || strpos($msg, 'Unknown database') !== false
        || strpos($msg, 'Access denied') !== false
    ) {
        fwrite(STDERR, "mysql probe: server is up, but database/user is not provisioned: {$msg}\n");
        exit(1);
    }
    fwrite(STDERR, "mysql probe: server unreachable: {$msg}\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'mysql probe: ' . $e->getMessage() . "\n");
    exit(2);
}
