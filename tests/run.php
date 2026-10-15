<?php

/**
 * Offline tests: runs PknicClient / PknicActions against tests/fixtures/*.html.
 *   php tests/run.php
 */

declare(strict_types=1);

require __DIR__ . '/../modules/registrars/pknicweb/lib/bootstrap.php';
require __DIR__ . '/FixtureTransport.php';

use PknicWeb\AwaitingConfirmationException;
use PknicWeb\Config;
use PknicWeb\InsufficientBalanceException;
use PknicWeb\LoginException;
use PknicWeb\MapException;
use PknicWeb\MapLoader;
use PknicWeb\NonRetryableException;
use PknicWeb\OptionChoice;
use PknicWeb\PknicActions;
use PknicWeb\PknicClient;
use PknicWeb\PknicException;
use PknicWeb\Selector;
use PknicWeb\Validator;

const PASSWORD = 's3cr3t&pass!';
const COOKIE = 'SESS-abcdef1234567890';

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;
$GLOBALS['logs'] = [];

function check(bool $condition, string $what): void
{
    if ($condition) {
        $GLOBALS['passed']++;
        return;
    }
    $GLOBALS['failed']++;
    echo "    FAIL: $what\n";
}

function same(mixed $expected, mixed $actual, string $what): void
{
    check($expected === $actual, $what . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function test(string $name, callable $fn): void
{
    echo "- $name\n";
    try {
        $fn();
    } catch (\Throwable $e) {
        $GLOBALS['failed']++;
        echo '    FAIL: unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function throws(string $class, callable $fn, ?string $contains = null): ?\Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        check($e instanceof $class, "expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        if ($contains !== null) {
            check(stripos($e->getMessage(), $contains) !== false, "message contains \"$contains\": " . $e->getMessage());
        }
        return $e;
    }
    check(false, "expected $class to be thrown");
    return null;
}

function client(FixtureTransport $transport, array $mapOverrides = []): PknicClient
{
    $config = new Config('https://panel.test', 'reseller', PASSWORD, 0, '', 3, false, sys_get_temp_dir());
    $map = array_merge(MapLoader::load(__DIR__ . '/fixtures/test_map.php'), $mapOverrides);
    return new PknicClient($config, $map, $transport, function (string $action, string $req, string $resp, string $processed, array $replace) {
        $GLOBALS['logs'][] = $action . "\n" . $req . "\n" . $resp;
    });
}

/** Panel where the session is already valid; domain.php serves $domainPages[id] in order. */
function panel(array $extra = [], array $domainPages = []): FixtureTransport
{
    $cursor = [];
    $domainPages += ['42' => ['domain_42.html'], '77' => ['domain_77.html']];
    return new FixtureTransport($extra + [
        'GET /dashboard.php' => 'dashboard.html',
        'GET /login.php' => 'login.html',
        'POST /login.php' => 'dashboard.html',
        'GET /domains.php' => 'domains.html',
        'GET /domain.php' => function (array $r) use (&$cursor, $domainPages) {
            parse_str((string) parse_url($r['url'], PHP_URL_QUERY), $q);
            $id = (string) ($q['id'] ?? '');
            $pages = $domainPages[$id] ?? ['domains.html'];
            $i = $cursor[$id] ?? 0;
            $cursor[$id] = $i + 1;
            return $pages[min($i, count($pages) - 1)];
        },
    ]);
}

// ---------------------------------------------------------------------------------------------

test('Selector: CSS to XPath', function () {
    same('//form[@id="loginForm"]', Selector::toXPath('form#loginForm'), 'tag#id');
    same('//*[contains(concat(" ", normalize-space(@class), " "), " alert ")]', Selector::toXPath('.alert'), '.class');
    same('//table//td[contains(normalize-space(.), "Expiry")]/following-sibling::*[1][self::td]', Selector::toXPath('table td:contains("Expiry") + td'), 'contains + adjacent');
    same('//ul/li[not(preceding-sibling::*)]', Selector::toXPath('ul > li:first-child'), 'child + first-child');
    same('//input[@name="ns1"] | //input[starts-with(@name, "ns")]', Selector::toXPath('input[name="ns1"], input[name^=ns]'), 'groups + attr ops');
    same('//td[1]', Selector::toXPath('//td[1]'), 'xpath passthrough');
    same('//td', Selector::toXPath('xpath://td'), 'xpath: prefix');
    throws(MapException::class, fn () => Selector::toXPath('td::bad'));
});

test('Selector: texts() with CSS, XPath and regex', function () {
    $html = file_get_contents(__DIR__ . '/fixtures/domain_42.html');
    same(['NS1.OLDHOST.NET' . "\n" . 'ns2.oldhost.net.'], Selector::texts($html, 'td:contains("Name Servers") + td'), 'css with <br> as newline');
    same(['15-10-2027'], Selector::texts($html, '//td[.="Expiry Date"]/following-sibling::td[1]'), 'xpath');
    same(['15-10-2027'], Selector::texts($html, 'regex:#Expiry Date</td><td>([^<]+)#'), 'regex group 1');
    same(['ns1.oldhost.net'], Selector::texts(file_get_contents(__DIR__ . '/fixtures/ns_form.html'), 'input[name=ns1]'), 'input value');
});

test('Form extraction: hidden inputs, existing values, relative action', function () {
    $c = client(panel());
    $form = $c->extractForm(file_get_contents(__DIR__ . '/fixtures/ns_form.html'), 'https://panel.test/panel/ns.php?id=42', '#nsForm');
    same('https://panel.test/panel/update_ns.php?id=42', $form['action'], 'relative action resolved');
    same('POST', $form['method'], 'method');
    same([
        'csrf' => 'ns-token-999', 'domain_id' => '42', 'ns1' => 'ns1.oldhost.net', 'ns2' => 'ns2.oldhost.net',
        'ns3' => 'ns3.oldhost.net', 'ns4' => '', 'ttl' => '86400', 'note' => 'keep me',
    ], $form['fields'], 'fields (buttons excluded, selected option, textarea)');

    $login = $c->extractForm(file_get_contents(__DIR__ . '/fixtures/login.html'), 'https://panel.test/login.php', 'form#loginForm');
    check(isset($login['fields']['remember']) && !isset($login['fields']['newsletter']), 'only checked checkboxes');
    check(!isset($login['fields']['disabled_field']) && !isset($login['fields']['btnLogin']), 'disabled + submit excluded');
    same(null, $c->extractForm('<p>none</p>', 'https://panel.test/', 'form'), 'missing form => null');
});

test('URL resolution', function () {
    $b = 'https://panel.test/a/b/page.php?x=1#frag';
    same('https://panel.test/a/b/page.php?x=1', PknicClient::resolveUrl($b, ''), 'empty');
    same('https://panel.test/a/b/next.php', PknicClient::resolveUrl($b, 'next.php'), 'relative');
    same('https://panel.test/a/up.php?q=1', PknicClient::resolveUrl($b, '../up.php?q=1'), 'dot-dot');
    same('https://panel.test/root.php', PknicClient::resolveUrl($b, '/root.php'), 'absolute path');
    same('https://panel.test/a/b/page.php?y=2', PknicClient::resolveUrl($b, '?y=2'), 'query only');
    same('https://cdn.test/x', PknicClient::resolveUrl($b, '//cdn.test/x'), 'protocol-relative');
    same('https://other.test/', PknicClient::resolveUrl($b, 'https://other.test/'), 'absolute url');
});

test('ensureLogin: logs in with hidden tokens and verifies the marker', function () {
    $t = new FixtureTransport([
        'GET /dashboard.php' => 'login.html',
        'GET /login.php' => 'login.html',
        'POST /login.php' => 'dashboard.html',
    ]);
    $c = client($t);
    $c->ensureLogin();
    same(['GET', 'GET', 'POST'], array_column($t->requests, 'method'), 'check page, login form, post');
    $post = FixtureTransport::parseBody((string) $t->posts()[0]['body']);
    same('csrf-abc123', $post['_token'] ?? null, 'csrf token sent');
    same('/dashboard.php', $post['return'] ?? null, 'other hidden input sent');
    same('reseller', $post['user_name'] ?? null, 'username');
    same(PASSWORD, $post['user_pass'] ?? null, 'password');
    same('Sign in', $post['btnLogin'] ?? null, 'login_extra_fields');
    same('https://panel.test/login.php', $t->posts()[0]['url'], 'posted to form action');

    $before = count($t->requests);
    $c->ensureLogin();
    same($before, count($t->requests), 'second ensureLogin uses cached state');
});

test('ensureLogin: already logged in => no login POST', function () {
    $t = panel();
    client($t)->ensureLogin();
    same(1, count($t->requests), 'only the check page');
});

test('ensureLogin: wrong password throws LoginException with panel message', function () {
    $t = new FixtureTransport([
        'GET /dashboard.php' => 'login.html',
        'GET /login.php' => 'login.html',
        'POST /login.php' => 'login_failed.html',
    ]);
    throws(LoginException::class, fn () => client($t)->ensureLogin(), 'Invalid username or password');
});

test('ensureLogin: marker missing after login throws', function () {
    $t = new FixtureTransport([
        'GET /dashboard.php' => 'login.html',
        'GET /login.php' => 'login.html',
        'POST /login.php' => 'register_form.html',
    ]);
    throws(LoginException::class, fn () => client($t, ['logged_in_marker' => 'NeverPresent'])->ensureLogin(), 'marker');
});

test('Re-login once when the session expires mid-session', function () {
    $t = panel(['GET /domain.php' => ['login.html', 'domain_42.html']]);
    $a = new PknicActions(client($t));
    $info = $a->getDomainInfo('example.com.pk');
    same(true, $info['found'], 'found after re-login');
    same(1, count($t->posts()), 'exactly one login POST');
});

test('getDomainInfo: id lookup, nameservers, expiry, status', function () {
    $t = panel();
    $info = (new PknicActions(client($t)))->getDomainInfo('example.com.pk');
    same(['ns1.oldhost.net', 'ns2.oldhost.net'], $info['nameservers'], 'nameservers normalised');
    same('2027-10-15', $info['expiry'], 'expiry parsed with date_format');
    same('active', $info['status'], 'status');
    check(str_contains(end($t->requests)['url'], 'domain.php?id=42'), '{id} resolved from domain list');
});

test('updateNameservers: merges form, clears unused slots, verifies', function () {
    $t = panel([
        'GET /ns.php' => 'ns_form.html',
        'POST /update_ns.php' => 'ns_success.html',
    ], ['42' => ['domain_42_new_ns.html']]);
    $r = (new PknicActions(client($t)))->updateNameservers('example.com.pk', ['NS1.newhost.pk', 'ns2.newhost.pk.']);
    same(['ns2.newhost.pk', 'ns1.newhost.pk'], $r['nameservers'], 'read back from domain page');
    $post = FixtureTransport::parseBody((string) $t->posts()[0]['body']);
    same('ns-token-999', $post['csrf'] ?? null, 'hidden csrf kept');
    same('ns1.newhost.pk', $post['ns1'] ?? null, 'ns1');
    same('', $post['ns3'] ?? null, 'ns3 cleared');
    same('86400', $post['ttl'] ?? null, 'existing select value kept');
});

test('updateNameservers: mismatch after update is an error', function () {
    $t = panel(['GET /ns.php' => 'ns_form.html', 'POST /update_ns.php' => 'ns_success.html']);
    throws(PknicException::class, fn () => (new PknicActions(client($t)))->updateNameservers('example.com.pk', ['ns1.newhost.pk', 'ns2.newhost.pk']), 'verification failed');
});

test('Known error text is returned (from error_message_selector)', function () {
    $t = panel(['GET /ns.php' => 'ns_form.html', 'POST /update_ns.php' => 'ns_locked.html']);
    throws(PknicException::class, fn () => (new PknicActions(client($t)))->updateNameservers('example.com.pk', ['ns1.a.pk', 'ns2.a.pk']), 'Domain is locked for modification (contact support)');
});

test('Error text inside <script> is ignored', function () {
    $info = (new PknicActions(client(panel())))->parseDomainInfo(file_get_contents(__DIR__ . '/fixtures/domain_42.html'));
    same(true, $info['found'], 'page with script text still parses');
    same(null, PknicClient::matchAny(PknicClient::visibleText(file_get_contents(__DIR__ . '/fixtures/domain_42.html')), ['Domain is locked']), 'script text not visible');
});

function registerPanel(array $extra = []): FixtureTransport
{
    return panel($extra + [
        'GET /domains.php' => ['domains.html', 'domains_after_register.html'],
        'GET /register.php' => 'register_form.html',
        'POST /register.php' => fn (array $r) => str_contains($r['url'], 'step=3') ? 'register_success.html' : 'register_confirm.html',
    ]);
}

function shopPayload(array $extra = []): array
{
    return $extra + [
        'domain' => 'newshop.pk', 'regperiod' => '2', 'fullname' => 'Ali Khan', 'countryname' => 'Pakistan', 'countrycode' => 'PK',
        'ns1' => 'ns1.shophost.pk', 'ns2' => 'NS2.shophost.pk', 'additionalfields' => ['CNIC' => '12345-1234567-1'],
    ];
}

test('registerDomain: form + selects + confirm step, then confirmed in domain list', function () {
    $t = registerPanel();
    $saved = null;
    $r = (new PknicActions(client($t)))->registerDomain(shopPayload(['countryname' => 'United Arab Emirates', 'countrycode' => 'AE']), function (array $p) use (&$saved) {
        $saved = $p;
    });
    same('2027-10-16', $r['expiry'], 'expiry read after registration');
    same(false, $r['ns_pending'], 'nameserver set matched');
    check(!empty($saved['submitted_at']), 'submitted_at checkpointed');
    $posts = $t->posts();
    same(2, count($posts), 'form + confirm POSTs');
    $first = FixtureTransport::parseBody((string) $posts[0]['body']);
    same('newshop.pk', $first['domain_name'] ?? null, 'domain field (textarea)');
    same('2Y', $first['years'] ?? null, 'transform applied');
    same('12345-1234567-1', $first['cnic'] ?? null, 'additionalfields lookup');
    same('AE', $first['country'] ?? null, 'country chosen by option text');
    same('12', $first['nsset'] ?? null, 'nameserver set chosen by its hostnames (order/case-insensitive)');
    same('self', $first['techContact'] ?? null, 'untouched select keeps default');
    same('1', $first['agree'] ?? null, 'static field');
    same('reg-tok-1', $first['reg_token'] ?? null, 'hidden token');
    same('ORD-555', FixtureTransport::parseBody((string) $posts[1]['body'])['order_ref'] ?? null, 'confirm form hidden field');
});

test('registerDomain: no matching nameserver set and no add page => applies without one, ns_pending', function () {
    $t = registerPanel();
    $c = client($t, ['ns_set_add_page' => '']);
    $r = (new PknicActions($c))->registerDomain(shopPayload(['ns1' => 'ns1.other.pk', 'ns2' => 'ns2.other.pk']));
    same(true, $r['ns_pending'], 'ns_pending flagged');
    same('', FixtureTransport::parseBody((string) $t->posts()[0]['body'])['nsset'] ?? null, 'nsset left at its empty default');
});

test('registerDomain: no matching set => creates it via ns_set_add_page, then selects it', function () {
    $t = registerPanel([
        'GET /register.php' => ['register_form.html', 'register_form_newset.html'],
        'GET /nsset.php' => 'nsset_form.html',
        'POST /nsset.php' => 'nsset_saved.html',
    ]);
    $r = (new PknicActions(client($t)))->registerDomain(shopPayload(['ns1' => 'ns1.brandnew.pk', 'ns2' => 'ns2.brandnew.pk']));
    same(false, $r['ns_pending'], 'set found after creating it');
    $posts = $t->posts();
    same('https://panel.test/nsset.php', $posts[0]['url'], 'set created first');
    $set = FixtureTransport::parseBody((string) $posts[0]['body']);
    same(['ns1.brandnew.pk', 'ns2.brandnew.pk', ''], [$set['h1'], $set['h2'], $set['h3']], 'set hostnames');
    same('13', FixtureTransport::parseBody((string) $posts[1]['body'])['nsset'] ?? null, 'new set selected');
});

test('registerDomain: unknown country => NonRetryable before anything is posted', function () {
    $t = registerPanel();
    throws(NonRetryableException::class, fn () => (new PknicActions(client($t)))->registerDomain(shopPayload(['countryname' => 'Atlantis', 'countrycode' => 'XX'])), 'country');
    same(0, count($t->posts()), 'nothing posted');
});

test('registerDomain: already in the account => no POST (idempotent retry)', function () {
    $t = panel(['GET /domains.php' => 'domains_after_register.html']);
    $r = (new PknicActions(client($t)))->registerDomain(['domain' => 'newshop.pk', 'regperiod' => '1']);
    same(0, count($t->posts()), 'nothing submitted');
    same('2027-10-16', $r['expiry'], 'expiry read');
});

test('registerDomain: accepted but not yet listed => AwaitingConfirmation, retry never re-applies', function () {
    $t = registerPanel(['GET /domains.php' => 'domains.html']);
    $a = new PknicActions(client($t));
    $saved = null;
    $checkpoint = function (array $p) use (&$saved) {
        $saved = $p;
    };
    throws(AwaitingConfirmationException::class, fn () => $a->registerDomain(shopPayload(), $checkpoint), 'waiting');
    $postsAfterFirst = count($t->posts());
    throws(AwaitingConfirmationException::class, fn () => $a->registerDomain($saved), 'waiting');
    same($postsAfterFirst, count($t->posts()), 'second run only re-checks');

    $old = ['submitted_at' => date('Y-m-d H:i:s', time() - 80 * 3600)] + $saved;
    throws(NonRetryableException::class, fn () => $a->registerDomain($old), 'still not in the account');

    $t2 = panel(['GET /domains.php' => 'domains_after_register.html']);
    $r = (new PknicActions(client($t2)))->registerDomain($saved);
    same('2027-10-16', $r['expiry'], 'confirmed once listed');
    same(0, count($t2->posts()), 'no POST on confirmation');
});

test('registerDomain: low balance on the confirmation page => InsufficientBalance, Confirm never clicked', function () {
    $t = registerPanel(['POST /register.php' => 'register_confirm_nobalance.html', 'GET /domains.php' => 'domains.html']);
    $saved = null;
    $checkpoint = function (array $p) use (&$saved) {
        $saved = $p;
    };
    throws(InsufficientBalanceException::class, fn () => (new PknicActions(client($t)))->registerDomain(shopPayload(), $checkpoint), 'balance');
    same(1, count($t->posts()), 'only the application form was posted');
    same(null, $saved, 'not checkpointed as submitted, so it is re-applied after a top-up');
});

test('registerDomain: no confirmation page => error quotes the PKNIC page text', function () {
    $t = registerPanel(['POST /register.php' => 'register_rejected.html', 'GET /domains.php' => 'domains.html']);
    throws(PknicException::class, fn () => (new PknicActions(client($t)))->registerDomain(shopPayload()), 'name is reserved');
});

test('OptionChoice matching', function () {
    $opts = ['' => 'Select', '5' => 'Set A (ns1.a.pk ns2.a.pk ns3.a.pk)', '6' => 'Set B (ns1.a.pk, ns2.a.pk)'];
    same('6', (new OptionChoice(['ns2.a.pk', 'ns1.a.pk'], 'nameservers'))->resolve($opts), 'exact set, not superset');
    same(null, (new OptionChoice(['ns1.a.pk'], 'nameservers'))->resolve($opts), 'subset does not match');
    same('PK', (new OptionChoice(['pk']))->resolve(['AF' => 'Afghanistan', 'PK' => 'Pakistan']), 'by value');
    same('PK', (new OptionChoice(['Pakistan']))->resolve(['AF' => 'Afghanistan', 'PK' => 'Pakistan']), 'by text');
});

test('containsDomain does not match a longer name', function () {
    check(!PknicActions::containsDomain('<a>xnewshop.pk</a> <a>newshop.pk.com</a> newshop.pkg', 'newshop.pk'), 'no false positives');
    check(PknicActions::containsDomain('<td>NewShop.pk</td>', 'newshop.pk'), 'case-insensitive match');
});

test('renewDomain: records expiry first, verifies the new expiry', function () {
    $t = panel([
        'GET /renew.php' => 'renew_form.html',
        'POST /renew.php' => 'renew_success.html',
    ], ['42' => ['domain_42.html', 'domain_42_renewed.html']]);
    $saved = null;
    $r = (new PknicActions(client($t)))->renewDomain(['domain' => 'example.com.pk', 'regperiod' => '1'], function (array $p) use (&$saved) {
        $saved = $p;
    });
    same('2027-10-15', $saved['expiry_before'] ?? null, 'checkpoint saved expiry_before');
    same('2028-10-15', $r['expiry'], 'new expiry');
    $post = $t->posts()[0];
    same('https://panel.test/renew.php?id=42', $post['url'], 'empty action posts to the page itself');
    same('1', FixtureTransport::parseBody((string) $post['body'])['years'] ?? null, 'years');
});

test('renewDomain: retry after a renewal that went through => no second POST', function () {
    $t = panel(['GET /renew.php' => 'renew_form.html'], ['42' => ['domain_42_renewed.html']]);
    $r = (new PknicActions(client($t)))->renewDomain(['domain' => 'example.com.pk', 'regperiod' => '1', 'expiry_before' => '2027-10-15']);
    same(0, count($t->posts()), 'no POST');
    same('2028-10-15', $r['expiry'], 'reports current expiry');
});

test('renewDomain: success text but expiry unchanged => NonRetryable', function () {
    $t = panel(['GET /renew.php' => 'renew_form.html', 'POST /renew.php' => 'renew_success.html']);
    throws(NonRetryableException::class, fn () => (new PknicActions(client($t)))->renewDomain(['domain' => 'example.com.pk', 'regperiod' => '1']), 'unchanged');
});

test('checkAvailability', function () {
    $t = panel(['GET /whois.php' => fn (array $r) => str_contains($r['url'], 'freename') ? 'whois_available.html' : 'whois_taken.html']);
    $a = new PknicActions(client($t));
    same(true, $a->checkAvailability('freename.pk'), 'available');
    same(false, $a->checkAvailability('myexample.pk'), 'taken ("not available" wins over "available")');
});

test('Logs: password and session cookie are masked, HTML truncated', function () {
    $GLOBALS['logs'] = [];
    $t = new FixtureTransport([
        'GET /dashboard.php' => 'login.html',
        'GET /login.php' => 'login.html',
        'POST /login.php' => 'dashboard.html',
    ]);
    client($t)->ensureLogin();
    $all = implode("\n", $GLOBALS['logs']);
    check($all !== '', 'something was logged');
    check(!str_contains($all, PASSWORD) && !str_contains($all, urlencode(PASSWORD)), 'password never logged');
    check(!str_contains($all, COOKIE), 'session cookie never logged');
    check(str_contains($all, '********'), 'mask present');
    check(strlen(PknicClient::truncate(str_repeat('x', 10000))) < 3100, 'truncate ~3000 chars');
});

test('Shipped pknic_map.php: unfilled values raise MapException, availability off', function () {
    $map = MapLoader::load();
    check(!MapLoader::has('availability_page', $map), 'CheckAvailability disabled by default');
    check(!MapLoader::isFilled($map['register_fields']), 'register_fields detected as TODO');
    $config = new Config('https://panel.test', 'u', 'p', 0, '', 3, false, sys_get_temp_dir());
    $c = new PknicClient($config, $map, panel(), fn () => null);
    throws(MapException::class, fn () => $c->ensureLogin(), 'logged_in_check_page');
    throws(MapException::class, fn () => (new PknicActions($c))->registerDomain(['domain' => 'abcd.pk']), 'register_fields');
});

test('Date parsing', function () {
    same('2027-10-15', PknicActions::parseDate('Expires on 15-10-2027', 'd-m-Y'), 'embedded with format');
    same('2027-10-15', PknicActions::parseDate('2027-10-15'), 'ISO');
    same('2027-10-15', PknicActions::parseDate('15 Oct 2027'), 'text month');
    same(null, PknicActions::parseDate('n/a'), 'unparseable');
});

test('Validator: domain names', function () {
    same(null, Validator::validateDomain('abcd', 'pk'), '4 chars under .pk ok');
    check(Validator::validateDomain('abc', 'pk') !== null, '3 chars under .pk rejected');
    same(null, Validator::validateDomain('ab', 'com.pk'), 'short under com.pk ok');
    check(Validator::validateDomain('-abcd', 'pk') !== null, 'leading hyphen');
    check(Validator::validateDomain('abcd-', 'pk') !== null, 'trailing hyphen');
    check(Validator::validateDomain('ab_cd', 'pk') !== null, 'underscore');
    check(Validator::validateDomain(str_repeat('a', 64), 'pk') !== null, '64 chars');
    same(null, Validator::validateDomain(str_repeat('a', 63), 'net.pk'), '63 chars ok');
    check(Validator::validateDomain('abcd', 'edu.pk') !== null, 'unsupported tld');
    same(null, Validator::validateDomain('My-Shop1', '.org.pk'), 'case + leading dot normalised');
});

test('Validator: nameservers', function () {
    same(null, Validator::validateNameservers(['ns1.host.pk', 'NS2.host.pk.', '', '']), 'valid');
    check(Validator::validateNameservers(['ns1.host.pk', '']) !== null, 'only one');
    check(Validator::validateNameservers(['ns1.host.pk', 'ns1.HOST.pk']) !== null, 'duplicate');
    check(Validator::validateNameservers(['ns1.host.pk', 'localhost']) !== null, 'not an FQDN');
    check(Validator::validateNameservers(['ns1.host.pk', '1.2.3.4']) !== null, 'IP address');
    check(Validator::validateNameservers(['ns1.host.pk', 'bad_ns.host.pk']) !== null, 'underscore');
});

echo "\n{$GLOBALS['passed']} passed, {$GLOBALS['failed']} failed\n";
exit($GLOBALS['failed'] > 0 ? 1 : 0);
