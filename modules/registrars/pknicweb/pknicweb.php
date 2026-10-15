<?php

/**
 * WHMCS registrar module: PKNIC via web-panel automation.
 *
 * RegisterDomain / RenewDomain / SaveNameservers never touch PKNIC during the customer's request;
 * they validate, store and enqueue. The cron runner (hooks.php / cron/process.php) does the work.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use PknicWeb\Config;
use PknicWeb\JobQueue;
use PknicWeb\LoginException;
use PknicWeb\MapLoader;
use PknicWeb\Notifier;
use PknicWeb\PknicActions;
use PknicWeb\PknicClient;
use PknicWeb\Settings;
use PknicWeb\Validator;

function pknicweb_MetaData()
{
    return [
        'DisplayName' => 'PKNIC (Web Panel Automation)',
        'APIVersion' => '1.1',
    ];
}

function pknicweb_getConfigArray()
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'PKNIC (Web Panel Automation)'],
        'Description' => [
            'Type' => 'System',
            'Value' => 'Queues orders and fulfils them through the PKNIC web panel. '
                . 'Fill modules/registrars/pknicweb/config/pknic_map.php before going live.',
        ],
        'BaseUrl' => [
            'FriendlyName' => 'PKNIC Panel Base URL',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'e.g. https://panel-host (no trailing slash). Paths in pknic_map.php are relative to this.',
        ],
        'Username' => ['FriendlyName' => 'Username', 'Type' => 'text', 'Size' => '30'],
        'Password' => ['FriendlyName' => 'Password', 'Type' => 'password', 'Size' => '30'],
        'TicketDeptId' => [
            'FriendlyName' => 'Ticket Department ID',
            'Type' => 'text',
            'Size' => '5',
            'Description' => 'Support department (ideally internal) for failure tickets. Empty = no ticket.',
        ],
        'AlertEmail' => [
            'FriendlyName' => 'Admin alert email',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Receives failure / pause alerts (also sent to admins subscribed to system emails).',
        ],
        'MaxRetries' => [
            'FriendlyName' => 'Max retries',
            'Type' => 'text',
            'Size' => '3',
            'Default' => '3',
            'Description' => 'Attempts per job before it is marked failed and escalated.',
        ],
        'Debug' => [
            'FriendlyName' => 'Debug logging',
            'Type' => 'yesno',
            'Description' => 'Also store full (masked) HTML of recent panel responses in the storage dir and log job steps to the Activity Log.',
        ],
        'StoragePath' => [
            'FriendlyName' => 'Storage path (optional)',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Directory outside the web root for the cookie jar. Default: WHMCS attachments dir /pknicweb.',
        ],
    ];
}

// ---------------------------------------------------------------------- helpers

function pknicweb_domainName(array $params): string
{
    return strtolower(trim((string) $params['sld']) . '.' . ltrim(trim((string) $params['tld']), '.'));
}

/** @return string[] ns1..ns5 as given (empties kept so positions stay aligned). */
function pknicweb_nameserversFromParams(array $params): array
{
    $out = [];
    foreach ([1, 2, 3, 4, 5] as $i) {
        $out[] = trim((string) ($params['ns' . $i] ?? ''));
    }
    return $out;
}

/** Only the data needed to register by hand or via the panel; never credentials. */
function pknicweb_registerPayload(array $params): array
{
    $keys = [
        'sld', 'tld', 'regperiod', 'userid', 'domainid',
        'firstname', 'lastname', 'companyname', 'email', 'address1', 'address2', 'city', 'state', 'postcode',
        'countrycode', 'countryname', 'phonenumber', 'phonecc', 'fullphonenumber',
        'adminfirstname', 'adminlastname', 'admincompanyname', 'adminemail', 'adminaddress1', 'adminaddress2',
        'admincity', 'adminstate', 'adminpostcode', 'admincountry', 'adminphonenumber', 'adminfullphonenumber',
    ];
    $payload = ['domain' => pknicweb_domainName($params)];
    foreach ($keys as $key) {
        if (isset($params[$key]) && is_scalar($params[$key])) {
            $payload[$key] = (string) $params[$key];
        }
    }
    $payload['tld'] = ltrim((string) ($payload['tld'] ?? ''), '.');
    $payload['regperiod'] = (string) max(1, (int) ($params['regperiod'] ?? 1));
    $payload['fullname'] = trim(($payload['firstname'] ?? '') . ' ' . ($payload['lastname'] ?? ''));
    $payload['adminfullname'] = trim(($payload['adminfirstname'] ?? '') . ' ' . ($payload['adminlastname'] ?? ''));
    foreach (pknicweb_nameserversFromParams($params) as $i => $ns) {
        $payload['ns' . ($i + 1)] = $ns;
    }
    $payload['additionalfields'] = is_array($params['additionalfields'] ?? null) ? $params['additionalfields'] : [];
    return $payload;
}

function pknicweb_actions(array $params): PknicActions
{
    return new PknicActions(new PknicClient(Config::fromParams($params), MapLoader::load()));
}

function pknicweb_validateParams(array $params): ?string
{
    return Validator::validateDomain((string) $params['sld'], (string) $params['tld']);
}

// ---------------------------------------------------------------------- WHMCS functions

function pknicweb_RegisterDomain($params)
{
    $domain = pknicweb_domainName($params);
    $nameservers = pknicweb_nameserversFromParams($params);
    $error = pknicweb_validateParams($params) ?? Validator::validateNameservers($nameservers);
    if ($error !== null) {
        return ['error' => $error];
    }

    try {
        $queue = new JobQueue();
        $existing = $queue->findOpen($domain, 'register');
        if ($existing !== null) {
            return ['success' => true, 'pending' => true, 'pendingMessage' => "Registration already queued (job #{$existing->id})"];
        }
        $domainId = (int) $params['domainid'];
        $queue->saveNameservers($domainId, Validator::normalizeNameservers($nameservers), false);
        $id = $queue->enqueue($domainId, $domain, 'register', pknicweb_registerPayload($params));
        logActivity("PKNIC: registration of $domain queued (job #$id)", (int) ($params['userid'] ?? 0));

        return ['success' => true, 'pending' => true, 'pendingMessage' => "Queued for PKNIC registration (job #$id)"];
    } catch (\Throwable $e) {
        return ['error' => 'Could not queue the registration: ' . $e->getMessage()];
    }
}

function pknicweb_RenewDomain($params)
{
    $error = pknicweb_validateParams($params);
    if ($error !== null) {
        return ['error' => $error];
    }
    $domain = pknicweb_domainName($params);

    try {
        $queue = new JobQueue();
        $existing = $queue->findOpen($domain, 'renew');
        if ($existing !== null) {
            // A second renewal while one is still queued is almost always a double click.
            logActivity("PKNIC: renewal of $domain already queued (job #{$existing->id}), not queued again", (int) ($params['userid'] ?? 0));
            return ['success' => true];
        }
        $id = $queue->enqueue((int) $params['domainid'], $domain, 'renew', [
            'domain' => $domain,
            'regperiod' => (string) max(1, (int) ($params['regperiod'] ?? 1)),
            'userid' => (string) ($params['userid'] ?? ''),
        ]);
        logActivity("PKNIC: renewal of $domain queued (job #$id)", (int) ($params['userid'] ?? 0));
        return ['success' => true];
    } catch (\Throwable $e) {
        return ['error' => 'Could not queue the renewal: ' . $e->getMessage()];
    }
}

function pknicweb_GetNameservers($params)
{
    $domainId = (int) $params['domainid'];
    try {
        $queue = new JobQueue();
        $local = $queue->getNameservers($domainId);
        if ($local === null) {
            $settings = new Settings();
            if ($settings->isPaused()) {
                return ['error' => 'Nameservers are not available right now (PKNIC automation paused).'];
            }
            try {
                $info = pknicweb_actions($params)->getDomainInfo(pknicweb_domainName($params));
                $settings->recordLoginSuccess();
            } catch (LoginException $e) {
                $settings->recordLoginFailure($e->getMessage(), new Notifier(Config::fromParams($params)));
                throw $e;
            }
            if (!$info['found'] || !$info['nameservers']) {
                return ['error' => 'Nameservers could not be read from PKNIC.'];
            }
            $local = $info['nameservers'];
            $queue->markNameserversSynced($domainId, $local);
        }
        $out = [];
        foreach ([1, 2, 3, 4, 5] as $i) {
            $out['ns' . $i] = (string) ($local[$i - 1] ?? '');
        }
        return $out;
    } catch (\Throwable $e) {
        return ['error' => 'Could not load nameservers: ' . $e->getMessage()];
    }
}

function pknicweb_SaveNameservers($params)
{
    $nameservers = pknicweb_nameserversFromParams($params);
    $error = Validator::validateNameservers($nameservers);
    if ($error !== null) {
        return ['error' => $error];
    }
    $normalized = Validator::normalizeNameservers($nameservers);
    $domain = pknicweb_domainName($params);

    try {
        $queue = new JobQueue();
        $queue->saveNameservers((int) $params['domainid'], $normalized, false);
        $id = $queue->enqueueNameservers((int) $params['domainid'], $domain, $normalized);
        logActivity("PKNIC: nameserver change for $domain queued (job #$id): " . implode(', ', $normalized), (int) ($params['userid'] ?? 0));
        return ['success' => true];
    } catch (\Throwable $e) {
        return ['error' => 'Could not save the nameservers: ' . $e->getMessage()];
    }
}

function pknicweb_Sync($params)
{
    $domain = pknicweb_domainName($params);
    try {
        $queue = new JobQueue();
        if ($queue->hasOpenRegistration($domain)) {
            return ['error' => 'Registration is still queued for PKNIC; skipping sync.'];
        }
        $settings = new Settings();
        if ($settings->isPaused()) {
            return ['error' => 'PKNIC automation is paused: ' . $settings->pauseReason()];
        }
        try {
            $info = pknicweb_actions($params)->getDomainInfo($domain);
            $settings->recordLoginSuccess();
        } catch (LoginException $e) {
            $settings->recordLoginFailure($e->getMessage(), new Notifier(Config::fromParams($params)));
            throw $e;
        }
        if (!$info['found']) {
            return ['error' => "$domain was not found in the PKNIC account."];
        }
        if ($info['nameservers'] && $queue->findOpen($domain, 'nameservers') === null) {
            $queue->markNameserversSynced((int) $params['domainid'], $info['nameservers']);
        }

        $result = [
            'active' => $info['status'] === 'active',
            'expired' => $info['status'] === 'expired',
            'transferredAway' => false,
        ];
        if ($info['expiry'] !== null) {
            $result['expirydate'] = $info['expiry'];
        }
        return $result;
    } catch (\Throwable $e) {
        return ['error' => 'PKNIC sync failed: ' . $e->getMessage()];
    }
}

// Only offered when pknic_map.php defines a lookup page; otherwise WHMCS falls back to its own WHOIS.
if (MapLoader::has('availability_page') && !function_exists('pknicweb_CheckAvailability')) {
    function pknicweb_CheckAvailability($params)
    {
        $results = new \WHMCS\Domains\DomainLookup\ResultsList();
        $sld = strtolower((string) (!empty($params['isIdnDomain']) ? $params['punyCodeSearchTerm'] : $params['searchTerm']));
        $actions = null;

        foreach ((array) ($params['tldsToInclude'] ?? []) as $tld) {
            $tld = ltrim((string) $tld, '.');
            $result = new \WHMCS\Domains\DomainLookup\SearchResult($sld, '.' . $tld);
            if (!Validator::isPkTld($tld)) {
                $result->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_TLD_NOT_SUPPORTED);
            } elseif (Validator::validateDomain($sld, $tld) !== null) {
                $result->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_RESERVED);
            } else {
                try {
                    $actions ??= pknicweb_actions($params);
                    $available = $actions->checkAvailability("$sld.$tld");
                    $result->setStatus(match ($available) {
                        true => \WHMCS\Domains\DomainLookup\SearchResult::STATUS_NOT_REGISTERED,
                        false => \WHMCS\Domains\DomainLookup\SearchResult::STATUS_REGISTERED,
                        default => \WHMCS\Domains\DomainLookup\SearchResult::STATUS_UNKNOWN,
                    });
                } catch (\Throwable $e) {
                    $result->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_UNKNOWN);
                }
            }
            $results->append($result);
        }
        return $results;
    }
}
