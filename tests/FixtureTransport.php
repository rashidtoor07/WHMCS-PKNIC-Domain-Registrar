<?php

declare(strict_types=1);

use PknicWeb\HttpTransport;
use PknicWeb\Response;

/**
 * Serves saved HTML instead of hitting PKNIC. Routes are "METHOD /path" (query ignored) =>
 * fixture name, list of names (served in order, last one repeats) or callable(array $request): string.
 */
final class FixtureTransport implements HttpTransport
{
    /** @var array<int, array{method: string, url: string, body: string|array|null}> */
    public array $requests = [];

    /** @var array<string, int> */
    private array $cursor = [];

    public function __construct(private array $routes, private array $cookies = ['SESS-abcdef1234567890'])
    {
    }

    public function request(string $method, string $url, string|array|null $body = null, array $headers = []): Response
    {
        $request = ['method' => $method, 'url' => $url, 'body' => $body];
        $this->requests[] = $request;
        $key = $method . ' ' . (parse_url($url, PHP_URL_PATH) ?: '/');
        if (!array_key_exists($key, $this->routes)) {
            return new Response(404, "<html><body>No fixture for $key</body></html>", $url);
        }
        $route = $this->routes[$key];
        if (is_callable($route)) {
            $name = $route($request);
        } elseif (is_array($route)) {
            $i = $this->cursor[$key] ?? 0;
            $name = $route[min($i, count($route) - 1)];
            $this->cursor[$key] = $i + 1;
        } else {
            $name = $route;
        }
        $html = file_get_contents(__DIR__ . '/fixtures/' . $name);
        return new Response(200, (string) $html, $url);
    }

    public function cookieValues(): array
    {
        return $this->cookies;
    }

    public function clearCookies(): void
    {
        $this->cookies = [];
    }

    public function posts(): array
    {
        return array_values(array_filter($this->requests, static fn ($r) => $r['method'] === 'POST'));
    }

    public static function parseBody(string $body): array
    {
        $out = [];
        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $k = urldecode($k);
            $v = urldecode($v);
            if (array_key_exists($k, $out)) {
                $out[$k] = array_merge((array) $out[$k], [$v]);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
