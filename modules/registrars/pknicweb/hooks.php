<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use PknicWeb\Runner;
use PknicWeb\Validator;

/** Process the PKNIC queue after every WHMCS cron run. The DB lock makes it safe next to cron/process.php. */
add_hook('AfterCronJob', 1, function ($vars) {
    try {
        $result = Runner::run('AfterCronJob', 8, 150);
        if (($result['processed'] ?? 0) > 0) {
            logActivity(sprintf('PKNIC queue (cron hook): %d job(s) processed, %s', $result['processed'], $result['status']));
        }
    } catch (\Throwable $e) {
        logActivity('PKNIC queue (cron hook) error: ' . $e->getMessage());
    }
});

/** Reject invalid .pk names in the cart before an order is placed. */
add_hook('ShoppingCartValidateDomain', 1, function ($vars) {
    $tld = ltrim((string) ($vars['tld'] ?? ''), '.');
    $option = (string) ($vars['domainoption'] ?? 'register');
    if (!Validator::isPkTld($tld) || !in_array($option, ['register', 'transfer'], true)) {
        return [];
    }
    $error = Validator::validateDomain((string) ($vars['sld'] ?? ''), $tld);
    return $error !== null ? [$error] : [];
});

/** Validate custom nameservers entered on the cart's domain configuration step for .pk registrations. */
add_hook('ShoppingCartValidateDomainsConfig', 1, function ($vars) {
    $hasPk = false;
    foreach ((array) ($_SESSION['cart']['domains'] ?? []) as $item) {
        $domain = strtolower((string) ($item['domain'] ?? ''));
        $tld = substr($domain, strpos($domain, '.') + 1);
        if (($item['type'] ?? 'register') === 'register' && Validator::isPkTld($tld)) {
            $hasPk = true;
            break;
        }
    }
    if (!$hasPk) {
        return [];
    }

    $nameservers = [];
    foreach ([1, 2, 3, 4, 5] as $i) {
        $nameservers[] = (string) ($vars['domainns' . $i] ?? $_POST['domainns' . $i] ?? '');
    }
    if (Validator::normalizeNameservers($nameservers) === []) {
        return []; // Left empty: WHMCS uses the default nameservers.
    }
    $error = Validator::validateNameservers($nameservers);
    return $error !== null ? [$error] : [];
});
