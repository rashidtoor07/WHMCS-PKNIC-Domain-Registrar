<?php

declare(strict_types=1);

namespace PknicWeb;

interface HttpTransport
{
    /**
     * @param string|array<string, string>|null $body Encoded body, or an array for multipart.
     * @param string[] $headers Raw "Name: value" header lines.
     * @throws TransportException
     */
    public function request(string $method, string $url, string|array|null $body = null, array $headers = []): Response;

    /** @return string[] Current cookie values, used to mask them in logs. */
    public function cookieValues(): array;

    public function clearCookies(): void;
}
