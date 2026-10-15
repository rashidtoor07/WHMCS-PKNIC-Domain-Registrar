<?php

declare(strict_types=1);

namespace PknicWeb;

/**
 * Logged-in HTTP session against the PKNIC web panel.
 * Everything panel-specific (URLs, field names, marker texts) comes from pknic_map.php.
 */
final class PknicClient
{
    public const MODULE = 'pknicweb';
    private const LOG_LIMIT = 3000;
    private const DEBUG_KEEP = 50;
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    private HttpTransport $transport;

    /** @var callable(string, string, string, string, string[]): void */
    private $logger;

    private bool $loginVerified = false;
    private ?Response $lastResponse = null;

    public function __construct(
        private readonly Config $config,
        private readonly array $map,
        ?HttpTransport $transport = null,
        ?callable $logger = null
    ) {
        if (!preg_match('#^https?://[^/]+#i', $config->baseUrl)) {
            throw new MapException('PKNIC Panel Base URL is not configured (expected e.g. https://panel.example)');
        }
        if ($config->username === '' || $config->password === '') {
            throw new LoginException('PKNIC username/password are not configured');
        }
        $this->transport = $transport ?? new CurlTransport($this->cookieFile(), 30, self::USER_AGENT);
        $this->logger = $logger ?? [self::class, 'logToWhmcs'];
    }

    public function cookieFile(): string
    {
        $dir = $this->config->storageDir !== '' ? $this->config->storageDir : Config::resolveStorageDir();
        return rtrim($dir, '/') . '/cookies_' . substr(sha1(strtolower($this->config->username)), 0, 16) . '.txt';
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function map(string $key, mixed $default = null): mixed
    {
        return $this->map[$key] ?? $default;
    }

    public function mapAll(): array
    {
        return $this->map;
    }

    public function requireMap(string $key): mixed
    {
        $value = $this->map[$key] ?? null;
        if (!MapLoader::isFilled($value)) {
            throw new MapException("pknic_map.php: '$key' is not filled in yet (empty or still TODO)");
        }
        return $value;
    }

    public function lastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    // ------------------------------------------------------------------ session

    public function ensureLogin(bool $force = false): void
    {
        if ($this->loginVerified && !$force) {
            return;
        }
        if (!$force) {
            $check = $this->send('GET', $this->url((string) $this->requireMap('logged_in_check_page')), [], 'login:check');
            if ($this->isLoggedIn($check)) {
                $this->loginVerified = true;
                return;
            }
        }
        $this->login();
    }

    public function login(): void
    {
        $this->loginVerified = false;
        $fieldsMap = (array) $this->requireMap('login_fields');
        $userField = (string) ($fieldsMap['username'] ?? '');
        $passField = (string) ($fieldsMap['password'] ?? '');
        if ($userField === '' || $passField === '') {
            throw new MapException("pknic_map.php: 'login_fields' needs 'username' and 'password' keys");
        }

        $page = $this->send('GET', $this->url((string) $this->requireMap('login_page')), [], 'login:form');
        $form = $this->extractForm($page->body, $page->url, (string) $this->map('login_form_selector', 'form'));
        if ($form === null) {
            if ($this->isLoggedIn($page) && !$this->looksLikeLoginPage($page)) {
                $this->loginVerified = true;
                return;
            }
            throw new LoginException('Login form not found on ' . $page->url . ' (check login_form_selector)');
        }

        $fields = array_merge($form['fields'], (array) $this->map('login_extra_fields', []), [
            $userField => $this->config->username,
            $passField => $this->config->password,
        ]);
        $resp = $this->send($form['method'], $form['action'], $fields, 'login:submit', $page->url, $form['multipart']);

        if ($this->isLoggedIn($resp) && !$this->looksLikeLoginPage($resp)) {
            $this->loginVerified = true;
            return;
        }

        $failure = self::matchAny(self::visibleText($resp->body), MapLoader::textList($this->map, 'login_failed_text'));
        if ($failure !== null) {
            throw new LoginException("PKNIC login failed: \"$failure\"");
        }

        // Some panels answer the POST with an interstitial; confirm with the logged-in-only page.
        $check = $this->send('GET', $this->url((string) $this->requireMap('logged_in_check_page')), [], 'login:verify');
        if (!$this->isLoggedIn($check) || $this->looksLikeLoginPage($check)) {
            throw new LoginException(sprintf(
                'PKNIC login failed: logged-in marker "%s" not found after submitting the login form',
                (string) $this->map('logged_in_marker')
            ));
        }
        $this->loginVerified = true;
    }

    public function clearSession(): void
    {
        $this->transport->clearCookies();
        $this->loginVerified = false;
    }

    public function isLoggedIn(Response $resp): bool
    {
        $marker = (string) $this->requireMap('logged_in_marker');
        return stripos($resp->body, $marker) !== false;
    }

    public function looksLikeLoginPage(Response $resp): bool
    {
        $marker = $this->map('login_page_marker');
        if (MapLoader::isFilled($marker)) {
            return stripos($resp->body, (string) $marker) !== false && !$this->isLoggedIn($resp);
        }
        return !$this->isLoggedIn($resp)
            && preg_match('/<input[^>]+type\s*=\s*["\']?password/i', $resp->body) === 1;
    }

    // ------------------------------------------------------------------ requests

    /** GET a panel page; re-logs in once if the session turns out to have expired. */
    public function get(string $path, array $vars = [], string $label = 'GET'): Response
    {
        $url = $this->url($path, $vars);
        $resp = $this->send('GET', $url, [], $label);
        if ($this->looksLikeLoginPage($resp)) {
            $this->login();
            $resp = $this->send('GET', $url, [], $label . ' (after re-login)');
            if ($this->looksLikeLoginPage($resp)) {
                throw new LoginException("Still on the login page after re-login while loading $url");
            }
        }
        return $resp;
    }

    /**
     * GET $pageUrl, read the form matched by $formSelector (hidden inputs + current values),
     * overlay $fields and submit it to the form's action. Re-logs in once if the POST bounced to login.
     *
     * @param array<string, string|string[]|OptionChoice|null> $fields null removes a field.
     */
    public function submitForm(string $pageUrl, string $formSelector, array $fields, array $vars = [], string $label = 'submit'): Response
    {
        $this->ensureLogin();
        for ($try = 0; $try < 2; $try++) {
            $page = $this->get($pageUrl, $vars, $label . ':load');
            $resp = $this->submitLoadedForm($page, $formSelector, $fields, $label);
            if (!$this->looksLikeLoginPage($resp)) {
                return $resp;
            }
            $this->login();
        }
        throw new LoginException("Submitting '$label' keeps landing on the login page");
    }

    public function submitLoadedForm(Response $page, string $formSelector, array $fields, string $label = 'submit'): Response
    {
        $form = $this->extractForm($page->body, $page->url, $formSelector);
        if ($form === null) {
            throw new PknicException("Form '$formSelector' not found on {$page->url}");
        }
        $data = $form['fields'];
        foreach ($fields as $name => $value) {
            if ($value instanceof OptionChoice) {
                if (!isset($form['options'][$name])) {
                    throw new MapException("Select field '$name' not found in form '$formSelector' on {$page->url}");
                }
                $value = $value->resolve($form['options'][$name]);
                if ($value === null) {
                    if ($fields[$name]->required) {
                        throw new NoMatchingOptionException((string) $name, $fields[$name], $form['options'][$name]);
                    }
                    continue; // Keep the form's default selection.
                }
            }
            if ($value === null) {
                unset($data[$name]);
            } else {
                $data[$name] = $value;
            }
        }
        return $this->send($form['method'], $form['action'], $data, $label, $page->url, $form['multipart']);
    }

    public function send(
        string $method,
        string $url,
        array $fields = [],
        string $label = 'request',
        ?string $referer = null,
        bool $multipart = false
    ): Response {
        $method = strtoupper($method);
        $body = null;
        if ($method === 'GET') {
            if ($fields) {
                $url = preg_replace('/#.*$/', '', $url) ?? $url;
                $url .= (str_contains($url, '?') ? '&' : '?') . self::encodeFields($fields);
            }
        } else {
            $body = $multipart ? self::flattenMultipart($fields) : self::encodeFields($fields);
        }
        $headers = $referer !== null ? ['Referer: ' . $referer] : [];
        if ($body !== null && !$multipart) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        try {
            $resp = $this->transport->request($method, $url, $body, $headers);
        } catch (TransportException $e) {
            $this->log($label, $method, $url, $fields, null, $e->getMessage());
            throw $e;
        }
        $this->lastResponse = $resp;
        $this->log($label, $method, $url, $fields, $resp);
        $this->debugDump($label, $resp);
        return $resp;
    }

    public function url(string $path, array $vars = []): string
    {
        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{' . $k . '}'] = rawurlencode((string) $v);
        }
        $path = strtr($path, $replace);
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return rtrim($this->config->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    // ------------------------------------------------------------------ parsing

    /**
     * @return array{action: string, method: string, multipart: bool, fields: array<string, string|string[]>,
     *               options: array<string, array<string, string>>}|null
     */
    public function extractForm(string $html, string $pageUrl, string $selector): ?array
    {
        $doc = Selector::loadHtml($html);
        $form = null;
        foreach (Selector::query($doc, $selector) as $node) {
            for ($n = $node; $n !== null; $n = $n->parentNode) {
                if ($n instanceof \DOMElement && strtolower($n->nodeName) === 'form') {
                    $form = $n;
                    break 2;
                }
            }
        }
        if ($form === null) {
            return null;
        }

        $base = $pageUrl;
        foreach ($doc->getElementsByTagName('base') as $b) {
            if ($b->getAttribute('href') !== '') {
                $base = self::resolveUrl($pageUrl, $b->getAttribute('href'));
            }
            break;
        }

        $action = trim($form->getAttribute('action'));
        $fields = [];
        $options = [];
        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('.//input | .//select | .//textarea', $form) ?: [] as $el) {
            /** @var \DOMElement $el */
            $name = $el->getAttribute('name');
            if ($name === '' || $el->hasAttribute('disabled')) {
                continue;
            }
            $tag = strtolower($el->nodeName);
            if ($tag === 'input') {
                $type = strtolower($el->getAttribute('type') ?: 'text');
                if (in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) {
                    continue;
                }
                if (in_array($type, ['checkbox', 'radio'], true) && !$el->hasAttribute('checked')) {
                    continue;
                }
                $value = $el->hasAttribute('value') ? $el->getAttribute('value') : ($type === 'checkbox' ? 'on' : '');
                self::addField($fields, $name, $value);
            } elseif ($tag === 'textarea') {
                self::addField($fields, $name, $el->textContent);
            } else {
                $selected = [];
                $first = null;
                foreach ($el->getElementsByTagName('option') as $opt) {
                    $val = $opt->hasAttribute('value') ? $opt->getAttribute('value') : trim($opt->textContent);
                    $first ??= $val;
                    $options[$name][$val] = trim((string) preg_replace('/\s+/', ' ', $opt->textContent));
                    if ($opt->hasAttribute('selected')) {
                        $selected[] = $val;
                    }
                }
                if (!$selected && $first !== null && !$el->hasAttribute('multiple')) {
                    $selected[] = $first;
                }
                foreach ($selected as $val) {
                    self::addField($fields, $name, $val);
                }
            }
        }

        return [
            'action' => self::resolveUrl($base, $action),
            'method' => strtoupper($form->getAttribute('method') ?: 'GET'),
            'multipart' => stripos($form->getAttribute('enctype'), 'multipart') !== false,
            'fields' => $fields,
            'options' => $options,
        ];
    }

    /** @return string[] */
    public function findTexts(string $html, string $selector): array
    {
        return Selector::texts($html, $selector);
    }

    /** Page text as a user sees it (scripts/styles removed, whitespace collapsed). */
    public static function visibleText(string $html): string
    {
        $doc = Selector::loadHtml($html);
        $body = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
        if ($body === null) {
            return '';
        }
        return trim((string) preg_replace('/\s+/u', ' ', Selector::innerText($body)));
    }

    /** First needle found (case-insensitive) in $haystack, or null. */
    public static function matchAny(string $haystack, array $needles): ?string
    {
        foreach ($needles as $needle) {
            if (is_string($needle) && $needle !== '' && stripos($haystack, $needle) !== false) {
                return $needle;
            }
        }
        return null;
    }

    public static function resolveUrl(string $base, string $rel): string
    {
        $rel = trim($rel);
        $base = preg_replace('/#.*$/', '', $base) ?? $base;
        if ($rel === '') {
            return $base;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $rel)) {
            return $rel;
        }
        $b = parse_url($base);
        $scheme = $b['scheme'] ?? 'https';
        $authority = ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($rel, '//')) {
            return $scheme . ':' . $rel;
        }
        $basePath = $b['path'] ?? '/';
        if ($rel[0] === '#') {
            return $base;
        }
        if ($rel[0] === '?') {
            return "$scheme://$authority$basePath$rel";
        }

        $query = '';
        if (($q = strpos($rel, '?')) !== false) {
            $query = substr($rel, $q);
            $rel = substr($rel, 0, $q);
        }
        $rel = preg_replace('/#.*$/', '', $rel) ?? $rel;
        $path = $rel[0] === '/' ? $rel : substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $rel;

        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($seg !== '.') {
                $out[] = $seg;
            }
        }
        $path = implode('/', $out);
        if (preg_match('#(^|/)\.\.?$#', $rel)) {
            $path = rtrim($path, '/') . '/';
        }
        return "$scheme://$authority" . ($path === '' || $path[0] !== '/' ? '/' : '') . $path . $query;
    }

    public static function encodeFields(array $fields): string
    {
        $parts = [];
        foreach ($fields as $name => $value) {
            if ($value === null) {
                continue;
            }
            foreach ((array) $value as $v) {
                $parts[] = urlencode((string) $name) . '=' . urlencode((string) $v);
            }
        }
        return implode('&', $parts);
    }

    private static function flattenMultipart(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $value) {
            $out[(string) $name] = is_array($value) ? (string) end($value) : (string) $value;
        }
        return $out;
    }

    private static function addField(array &$fields, string $name, string $value): void
    {
        if (!array_key_exists($name, $fields)) {
            $fields[$name] = $value;
            return;
        }
        $fields[$name] = array_merge((array) $fields[$name], [$value]);
    }

    // ------------------------------------------------------------------ logging

    /** @return string[] */
    private function secrets(): array
    {
        $secrets = [];
        $pass = $this->config->password;
        if ($pass !== '') {
            array_push($secrets, $pass, urlencode($pass), rawurlencode($pass), htmlspecialchars($pass, ENT_QUOTES));
        }
        foreach ($this->transport->cookieValues() as $value) {
            if (strlen($value) >= 6) {
                $secrets[] = $value;
                $secrets[] = urlencode($value);
            }
        }
        $secrets = array_values(array_unique(array_filter($secrets, 'strlen')));
        // Longest first so a secret containing another is masked whole.
        usort($secrets, static fn ($a, $b) => strlen($b) <=> strlen($a));
        return $secrets;
    }

    public function mask(string $text): string
    {
        $secrets = $this->secrets();
        return $secrets ? str_replace($secrets, '********', $text) : $text;
    }

    private function log(string $label, string $method, string $url, array $fields, ?Response $resp, ?string $error = null): void
    {
        $passField = (string) (($this->map['login_fields'] ?? [])['password'] ?? '');
        if ($passField !== '' && array_key_exists($passField, $fields)) {
            $fields[$passField] = '********';
        }
        $request = $method . ' ' . $url;
        if ($fields) {
            $request .= "\n" . json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $response = $error !== null
            ? 'ERROR: ' . $error
            : sprintf("HTTP %d  %s\n\n%s", $resp->status, $resp->url, self::truncate($resp->body));

        try {
            ($this->logger)($label, $this->mask($request), $this->mask($response), '', $this->secrets());
        } catch (\Throwable) {
            // Logging must never break a request.
        }
    }

    public static function truncate(string $text, int $limit = self::LOG_LIMIT): string
    {
        return strlen($text) > $limit ? substr($text, 0, $limit) . "\n... [truncated, " . strlen($text) . ' bytes]' : $text;
    }

    public static function logToWhmcs(string $action, string $request, string $response, string $processed, array $replace): void
    {
        if (function_exists('logModuleCall')) {
            logModuleCall(self::MODULE, $action, $request, $response, $processed, $replace);
        }
    }

    /** With Debug on, keep the full (masked) HTML of recent responses for filling in pknic_map.php. */
    private function debugDump(string $label, Response $resp): void
    {
        if (!$this->config->debug || $this->config->storageDir === '') {
            return;
        }
        $dir = $this->config->storageDir . '/debug';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return;
        }
        $name = sprintf('%s-%s-%s.html', date('Ymd-His'), substr(md5(uniqid('', true)), 0, 6), preg_replace('/[^a-z0-9]+/i', '_', $label));
        $file = $dir . '/' . $name;
        if (@file_put_contents($file, "<!-- {$resp->status} {$resp->url} -->\n" . $this->mask($resp->body)) !== false) {
            @chmod($file, 0600);
        }
        $files = glob($dir . '/*.html') ?: [];
        if (count($files) > self::DEBUG_KEEP) {
            sort($files);
            foreach (array_slice($files, 0, count($files) - self::DEBUG_KEEP) as $old) {
                @unlink($old);
            }
        }
    }
}
