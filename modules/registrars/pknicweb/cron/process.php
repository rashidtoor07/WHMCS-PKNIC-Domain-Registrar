<?php

// Standalone queue runner. Run every 5 minutes, e.g. this crontab line:
//   */5 * * * * php -q /path/to/whmcs/modules/registrars/pknicweb/cron/process.php
// Options: --max-jobs=N (default 20) --max-seconds=N (default 270)

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$whmcsRoot = dirname(__DIR__, 4);
require $whmcsRoot . '/init.php';
require_once $whmcsRoot . '/includes/registrarfunctions.php';
require_once __DIR__ . '/../lib/bootstrap.php';

$options = getopt('', ['max-jobs::', 'max-seconds::']);
$maxJobs = max(1, (int) ($options['max-jobs'] ?? 20));
$maxSeconds = max(30, (int) ($options['max-seconds'] ?? 270));

$result = PknicWeb\Runner::run('cli', $maxJobs, $maxSeconds);
echo date('c') . ' ' . json_encode($result) . PHP_EOL;
exit(str_starts_with((string) ($result['status'] ?? ''), 'error') ? 1 : 0);
