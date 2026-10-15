<?php

declare(strict_types=1);

namespace PknicWeb;

/**
 * High-level panel operations. Each action verifies its outcome by re-reading PKNIC;
 * nothing is reported as done on the strength of a form POST alone.
 */
final class PknicActions
{
    /** @var array<string, string> */
    private array $idCache = [];

    public function __construct(private readonly PknicClient $client)
    {
    }

    public function client(): PknicClient
    {
        return $this->client;
    }

    // ------------------------------------------------------------------ register

    /**
     * Submits the domain application once, then confirms it from the account's domain list.
     * After the first POST, "submitted_at" is checkpointed so retries only re-check, never re-apply.
     *
     * @param array $payload Queue payload (domain, sld, tld, regperiod, ns1..ns5, contact fields, additionalfields).
     * @param callable(array): void|null $checkpoint Persists the payload.
     * @return array{expiry: ?string, nameservers: string[], message: string, ns_pending: bool}
     */
    public function registerDomain(array $payload, ?callable $checkpoint = null): array
    {
        $domain = strtolower((string) $payload['domain']);
        $this->client->requireMap('register_page');
        $this->client->requireMap('register_fields');
        $this->client->ensureLogin();

        if (!empty($payload['submitted_at'])) {
            if ($this->isInAccount($domain)) {
                return $this->registered($domain, (bool) ($payload['ns_pending'] ?? false));
            }
            $hours = (int) $this->client->map('register_confirm_hours', 72);
            if (time() - (int) strtotime((string) $payload['submitted_at']) > $hours * 3600) {
                throw new NonRetryableException(
                    "Application for $domain was submitted at {$payload['submitted_at']} but the domain is still not in "
                    . "the account after $hours h. Check its status in the PKNIC panel."
                );
            }
            throw new AwaitingConfirmationException(
                "Application submitted at {$payload['submitted_at']}; waiting for $domain to appear in the account"
            );
        }

        // Idempotency for anything registered outside this job (or before a crash).
        if ($this->isInAccount($domain)) {
            return $this->registered($domain, false, "$domain is already present in the PKNIC account");
        }

        $data = $payload + ['nameservers' => self::payloadNameservers($payload)];
        $fields = $this->buildFields('register_fields', $data);
        $nsPending = false;
        try {
            $resp = $this->submitWithNameserverSet('register', $domain, $fields, $data['nameservers']);
        } catch (NoMatchingOptionException $e) {
            if ($e->choice->mode !== 'nameservers') {
                throw new NonRetryableException($e->getMessage());
            }
            // No saved set with these nameservers: apply without one, set them in a follow-up job.
            unset($fields[$e->field]);
            $nsPending = true;
            $resp = $this->submitAction('register', $domain, $fields);
        }
        foreach ($fields as $value) {
            if ($value instanceof OptionChoice && $value->mode === 'nameservers' && $value->resolved === null) {
                $nsPending = true;
            }
        }

        $payload['submitted_at'] = date('Y-m-d H:i:s');
        $payload['ns_pending'] = $nsPending;
        if ($checkpoint !== null) {
            $checkpoint($payload);
        }

        $success = $this->successText($resp, 'register');
        if ($this->isInAccount($domain)) {
            return $this->registered($domain, $nsPending);
        }
        if ($success === null) {
            throw new NonRetryableException(
                "Domain application for $domain was posted but PKNIC showed neither success nor error text, and the "
                . 'domain is not in the account. Check the panel; if there is no application, cancel this job and register again.'
            );
        }
        throw new AwaitingConfirmationException("PKNIC accepted the application (\"$success\"); waiting for $domain to appear in the account");
    }

    private function registered(string $domain, bool $nsPending, ?string $message = null): array
    {
        $info = $this->getDomainInfo($domain);
        return [
            'expiry' => $info['expiry'],
            'nameservers' => $info['nameservers'],
            'ns_pending' => $nsPending,
            'message' => $message ?? ("Registered $domain" . ($info['expiry'] ? ", expires {$info['expiry']}" : ' (expiry date not readable)')),
        ];
    }

    /** @return string[] */
    private static function payloadNameservers(array $payload): array
    {
        $list = [];
        foreach ([1, 2, 3, 4, 5] as $i) {
            $list[] = (string) ($payload['ns' . $i] ?? '');
        }
        return Validator::normalizeNameservers($list);
    }

    // ------------------------------------------------------------------ renew

    /**
     * @param callable(array): void|null $checkpoint Persists the payload (expiry_before) before submitting.
     * @return array{expiry: ?string, message: string}
     */
    public function renewDomain(array $payload, ?callable $checkpoint = null): array
    {
        $domain = strtolower((string) $payload['domain']);
        $this->client->requireMap('renew_page');
        $this->client->ensureLogin();
        $verify = (bool) $this->client->map('renew_verify_expiry', true);

        if (!array_key_exists('expiry_before', $payload)) {
            $info = $this->getDomainInfo($domain);
            if (!$info['found']) {
                throw new NonRetryableException("$domain was not found in the PKNIC account, cannot renew");
            }
            $payload['expiry_before'] = $info['expiry'] ?? '';
            if ($checkpoint !== null) {
                $checkpoint($payload);
            }
        } elseif ($verify && $payload['expiry_before'] !== '') {
            $info = $this->getDomainInfo($domain);
            if ($info['expiry'] !== null && $info['expiry'] > $payload['expiry_before']) {
                return ['expiry' => $info['expiry'], 'message' => "Already renewed (expiry now {$info['expiry']})"];
            }
        }

        $before = (string) $payload['expiry_before'];
        $resp = $this->submitAction('renew', $domain, $this->buildFields('renew_fields', $payload, false));
        $success = $this->successText($resp, 'renew');

        if ($verify && $before !== '') {
            $info = $this->getDomainInfo($domain);
            if ($info['expiry'] !== null && $info['expiry'] > $before) {
                return ['expiry' => $info['expiry'], 'message' => "Renewed $domain, expiry $before -> {$info['expiry']}"];
            }
            // Retrying could renew (and charge) twice, so a human has to look.
            throw new NonRetryableException(sprintf(
                '%s; expiry date unchanged (before %s, now %s). Check the PKNIC panel manually.',
                $success !== null ? "PKNIC showed \"$success\"" : 'No success text after renewal',
                $before,
                $info['expiry'] ?? 'unreadable'
            ));
        }

        if ($success === null) {
            throw new NonRetryableException('Renewal outcome unknown (no success or error text). Check the PKNIC panel manually.');
        }
        return ['expiry' => null, 'message' => "Renewed $domain (\"$success\"); expiry not verified"];
    }

    // ------------------------------------------------------------------ nameservers

    /** @return array{nameservers: string[], message: string} */
    public function updateNameservers(string $domain, array $nameservers): array
    {
        $domain = strtolower($domain);
        $requested = Validator::normalizeNameservers($nameservers);
        $this->client->requireMap('ns_fields');
        $this->client->requireMap('ns_parse_selector');
        $this->client->ensureLogin();

        $data = ['domain' => $domain, 'nameservers' => $requested];
        foreach ([1, 2, 3, 4, 5] as $i) {
            $data['ns' . $i] = $requested[$i - 1] ?? '';
        }
        try {
            $resp = $this->submitWithNameserverSet('ns_update', $domain, $this->buildFields('ns_fields', $data), $requested);
        } catch (NoMatchingOptionException $e) {
            throw new NonRetryableException($e->getMessage() . '. Create a nameserver set with exactly these nameservers '
                . 'in the PKNIC panel (or fill the ns_set_add_* keys in pknic_map.php), then retry the job.');
        }
        $success = $this->successText($resp, 'ns_update');

        $info = $this->getDomainInfo($domain);
        $actual = $info['nameservers'];
        if (self::sameSet($requested, $actual)) {
            return ['nameservers' => $actual, 'message' => 'Nameservers verified: ' . implode(', ', $actual)];
        }
        throw new PknicException(sprintf(
            'Nameserver verification failed: requested [%s], PKNIC shows [%s]%s',
            implode(', ', $requested),
            implode(', ', $actual),
            $success !== null ? " (PKNIC had shown \"$success\")" : ''
        ));
    }

    // ------------------------------------------------------------------ info

    /**
     * @return array{found: bool, nameservers: string[], expiry: ?string, expiry_raw: ?string, status: string, status_raw: ?string}
     */
    public function getDomainInfo(string $domain): array
    {
        $domain = strtolower($domain);
        $this->client->ensureLogin();
        $resp = $this->client->get(
            (string) $this->client->requireMap('domain_info_page'),
            $this->vars($domain, 'domain_info_page'),
            'domain_info'
        );
        return $this->parseDomainInfo($resp->body);
    }

    public function parseDomainInfo(string $html): array
    {
        $map = fn (string $k) => $this->client->map($k);
        $notFound = PknicClient::matchAny(
            PknicClient::visibleText($html),
            MapLoader::textList($this->client->mapAll(), 'domain_not_found_text')
        );

        $nameservers = MapLoader::isFilled($map('ns_parse_selector'))
            ? self::extractHostnames($this->client->findTexts($html, (string) $map('ns_parse_selector')))
            : [];

        $expiryRaw = null;
        $expiry = null;
        if (MapLoader::isFilled($map('expiry_parse_selector'))) {
            $expiryRaw = $this->client->findTexts($html, (string) $map('expiry_parse_selector'))[0] ?? null;
            if ($expiryRaw !== null) {
                $expiry = self::parseDate($expiryRaw, (string) ($map('date_format') ?? ''));
            }
        }

        $statusRaw = MapLoader::isFilled($map('status_parse_selector'))
            ? ($this->client->findTexts($html, (string) $map('status_parse_selector'))[0] ?? null)
            : null;

        return [
            'found' => $notFound === null && ($nameservers !== [] || $expiry !== null || $statusRaw !== null),
            'nameservers' => $nameservers,
            'expiry' => $expiry,
            'expiry_raw' => $expiryRaw,
            'status' => $this->normalizeStatus($statusRaw, $expiry),
            'status_raw' => $statusRaw,
        ];
    }

    /** @return bool|null true = available, false = taken, null = could not tell. */
    public function checkAvailability(string $domain): ?bool
    {
        $domain = strtolower($domain);
        $page = (string) $this->client->requireMap('availability_page');
        if ((bool) $this->client->map('availability_requires_login', true)) {
            $this->client->ensureLogin();
        }
        $vars = $this->vars($domain);
        $formSel = $this->client->map('availability_form_selector');
        if (MapLoader::isFilled($formSel)) {
            $fields = $this->buildFields('availability_fields', $vars);
            $resp = $this->client->submitForm($page, (string) $formSel, $fields, $vars, 'availability');
        } else {
            $resp = $this->client->get($page, $vars, 'availability');
        }
        $text = PknicClient::visibleText($resp->body);
        // "Taken" first: "not available" also contains "available".
        if (PknicClient::matchAny($text, MapLoader::textList($this->client->mapAll(), 'availability_taken_text')) !== null) {
            return false;
        }
        if (PknicClient::matchAny($text, MapLoader::textList($this->client->mapAll(), 'availability_available_text')) !== null) {
            return true;
        }
        return null;
    }

    public function isInAccount(string $domain): bool
    {
        $list = $this->client->map('domain_list_page');
        if (MapLoader::isFilled($list)) {
            $resp = $this->client->get((string) $list, $this->vars($domain), 'domain_list');
            return self::containsDomain($resp->body, $domain);
        }
        return $this->getDomainInfo($domain)['found'];
    }

    public function findDomainId(string $domain): string
    {
        if (isset($this->idCache[$domain])) {
            return $this->idCache[$domain];
        }
        $regex = (string) $this->client->requireMap('domain_id_regex');
        $resp = $this->client->get((string) $this->client->requireMap('domain_list_page'), $this->vars($domain), 'domain_list');
        $pattern = str_replace('{domain}', preg_quote($domain), $regex);
        $ok = @preg_match($pattern, $resp->body, $m);
        if ($ok === false) {
            throw new MapException('pknic_map.php: domain_id_regex is not a valid regular expression');
        }
        if (!$ok || !isset($m[1]) || $m[1] === '') {
            throw new PknicException("Could not find $domain in the PKNIC domain list (domain_id_regex did not match)");
        }
        return $this->idCache[$domain] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Submit an action whose nameservers are chosen from saved sets. If no set matches and
     * ns_set_add_page is configured, create the set and submit again (nothing was posted yet).
     */
    private function submitWithNameserverSet(string $action, string $domain, array $fields, array $nameservers): Response
    {
        try {
            return $this->submitAction($action, $domain, $fields);
        } catch (NoMatchingOptionException $e) {
            if ($e->choice->mode !== 'nameservers' || !MapLoader::has('ns_set_add_page', $this->client->mapAll())) {
                throw $e;
            }
            $this->addNameserverSet($domain, $nameservers);
            return $this->submitAction($action, $domain, $fields);
        }
    }

    public function addNameserverSet(string $domain, array $nameservers): void
    {
        $nameservers = Validator::normalizeNameservers($nameservers);
        $data = [
            'domain' => $domain,
            'nameservers' => $nameservers,
            'set_name' => 'whmcs-' . substr(sha1(implode(',', $nameservers)), 0, 8),
        ];
        foreach ([1, 2, 3, 4, 5] as $i) {
            $data['ns' . $i] = $nameservers[$i - 1] ?? '';
        }
        $resp = $this->client->submitForm(
            (string) $this->client->requireMap('ns_set_add_page'),
            (string) ($this->client->map('ns_set_add_form_selector') ?: 'form'),
            $this->buildFields('ns_set_add_fields', $data),
            $this->vars($domain),
            'ns_set_add'
        );
        $this->assertNoError($resp, 'ns_set_add');
    }

    private function submitAction(string $action, string $domain, array $fields): Response
    {
        $pageKey = $action . '_page';
        $resp = $this->client->submitForm(
            (string) $this->client->requireMap($pageKey),
            (string) ($this->client->map($action . '_form_selector') ?: 'form'),
            $fields,
            $this->vars($domain, $pageKey),
            $action
        );

        $confirm = $this->client->map($action . '_confirm_form_selector');
        if (MapLoader::isFilled($confirm)) {
            $this->assertNoError($resp, $action);
            if ($this->client->extractForm($resp->body, $resp->url, (string) $confirm) === null) {
                throw new PknicException(sprintf(
                    'Expected the confirmation page after submitting the %s form, but got: %s',
                    $action,
                    mb_substr(PknicClient::visibleText($resp->body), 0, 400)
                ));
            }
            $resp = $this->client->submitLoadedForm(
                $resp,
                (string) $confirm,
                (array) $this->client->map($action . '_confirm_static_fields', []),
                $action . ':confirm'
            );
        }
        $this->assertNoError($resp, $action);
        return $resp;
    }

    private function assertNoError(Response $resp, string $action): void
    {
        if ($resp->status >= 400) {
            throw new PknicException("PKNIC returned HTTP {$resp->status} for $action ({$resp->url})");
        }
        $text = PknicClient::visibleText($resp->body);
        $balance = PknicClient::matchAny($text, MapLoader::textList($this->client->mapAll(), 'insufficient_balance_text'));
        if ($balance !== null) {
            throw new InsufficientBalanceException("PKNIC account balance too low for the $action (\"$balance\")");
        }
        $needles = MapLoader::textList($this->client->mapAll(), $action . '_error_text', 'error_text');
        $hit = PknicClient::matchAny($text, $needles);
        if ($hit === null) {
            return;
        }
        $message = $hit;
        $sel = $this->client->map('error_message_selector');
        if (MapLoader::isFilled($sel)) {
            foreach ($this->client->findTexts($resp->body, (string) $sel) as $text) {
                if (stripos($text, $hit) !== false) {
                    $message = str_replace("\n", ' ', $text);
                    break;
                }
            }
        }
        throw new PknicException("PKNIC rejected the $action: $message");
    }

    private function successText(Response $resp, string $action): ?string
    {
        $needles = MapLoader::textList($this->client->mapAll(), $action . '_success_text');
        if ($needles === []) {
            $needles = MapLoader::textList($this->client->mapAll(), 'success_text');
        }
        return PknicClient::matchAny(PknicClient::visibleText($resp->body), $needles);
    }

    /**
     * Turn a "whmcs key => pknic field" mapping into POST fields. A spec may be a field name or
     * ['field' => name, 'default' => v, 'transform' => fn ($value, $data) => ...].
     */
    private function buildFields(string $mapKey, array $data, bool $required = true): array
    {
        $mapping = ($required || !empty($this->client->map($mapKey)))
            ? (array) $this->client->requireMap($mapKey)
            : [];
        $fields = [];
        foreach ($mapping as $source => $spec) {
            $field = is_array($spec) ? ($spec['field'] ?? null) : $spec;
            if (!is_string($field) || $field === '') {
                continue;
            }
            $value = self::lookup($data, (string) $source);
            if (is_array($spec)) {
                if (($value === null || $value === '') && array_key_exists('default', $spec)) {
                    $value = $spec['default'];
                }
                if (isset($spec['transform']) && is_callable($spec['transform'])) {
                    $value = ($spec['transform'])($value, $data);
                }
                if (!empty($spec['select'])) {
                    $mode = $spec['select'] === 'nameservers' ? 'nameservers' : 'equal';
                    $wanted = (array) $value;
                    foreach ((array) ($spec['also'] ?? []) as $extra) {
                        $wanted[] = self::lookup($data, (string) $extra);
                    }
                    $wanted = array_values(array_filter(array_map('strval', array_filter($wanted, 'is_scalar')), 'strlen'));
                    if ($wanted !== []) {
                        $fields[$field] = new OptionChoice($wanted, $mode, (bool) ($spec['required'] ?? true));
                    }
                    continue;
                }
            }
            $fields[$field] = is_array($value) ? array_map('strval', $value) : (string) ($value ?? '');
        }
        $staticKey = preg_replace('/_fields$/', '_static_fields', $mapKey);
        foreach ((array) $this->client->map((string) $staticKey, []) as $k => $v) {
            $fields[(string) $k] = $v;
        }
        return $fields;
    }

    private static function lookup(array $data, string $path): mixed
    {
        if (array_key_exists($path, $data)) {
            return $data[$path];
        }
        $cur = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    private function vars(string $domain, string ...$templateKeys): array
    {
        [$sld, $tld] = array_pad(explode('.', $domain, 2), 2, '');
        $vars = ['domain' => $domain, 'sld' => $sld, 'tld' => $tld];
        foreach ($templateKeys as $key) {
            if (str_contains((string) $this->client->map($key, ''), '{id}')) {
                $vars['id'] = $this->findDomainId($domain);
                break;
            }
        }
        return $vars;
    }

    private function normalizeStatus(?string $raw, ?string $expiry): string
    {
        if ($raw !== null && $raw !== '') {
            $map = $this->client->mapAll();
            if (PknicClient::matchAny($raw, MapLoader::textList($map, 'status_expired_text')) !== null) {
                return 'expired';
            }
            if (PknicClient::matchAny($raw, MapLoader::textList($map, 'status_active_text')) !== null) {
                return 'active';
            }
            return 'unknown';
        }
        if ($expiry === null) {
            return 'unknown';
        }
        return $expiry < date('Y-m-d') ? 'expired' : 'active';
    }

    /** @return string[] */
    public static function extractHostnames(array $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            preg_match_all('/(?<![a-z0-9.-])((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62})\.?(?![a-z0-9-])/i', $text, $m);
            foreach ($m[1] as $host) {
                $out[] = strtolower($host);
            }
        }
        return array_values(array_unique($out));
    }

    public static function sameSet(array $a, array $b): bool
    {
        $a = Validator::normalizeNameservers($a);
        $b = Validator::normalizeNameservers($b);
        sort($a);
        sort($b);
        return $a === $b;
    }

    public static function containsDomain(string $html, string $domain): bool
    {
        $pattern = '/(?<![a-z0-9.-])' . preg_quote(strtolower($domain), '/') . '(?![a-z0-9-]|\.[a-z0-9])/i';
        return preg_match($pattern, html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')) === 1;
    }

    /** Returns Y-m-d or null. Tries date_format first, then common formats inside the text. */
    public static function parseDate(string $raw, string $format = ''): ?string
    {
        $raw = trim((string) preg_replace('/\s+/', ' ', $raw));
        if ($raw === '') {
            return null;
        }
        $candidates = [$raw];
        if (preg_match_all('/\d{1,4}[-\/.]\d{1,2}[-\/.]\d{1,4}|\d{1,2}[- ][A-Za-z]{3,9}[- ,]+\d{4}|[A-Za-z]{3,9} \d{1,2},? \d{4}/', $raw, $m)) {
            $candidates = array_merge($m[0], $candidates);
        }
        foreach ($candidates as $c) {
            if ($format !== '' && MapLoader::isFilled($format)) {
                $dt = \DateTime::createFromFormat('!' . $format, $c);
                $errors = \DateTime::getLastErrors();
                if ($dt !== false && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $dt->format('Y-m-d');
                }
            }
        }
        foreach ($candidates as $c) {
            $ts = strtotime($c);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }
        return null;
    }
}
