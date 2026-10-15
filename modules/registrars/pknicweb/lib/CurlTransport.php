<?php

declare(strict_types=1);

namespace PknicWeb;

final class CurlTransport implements HttpTransport
{
    private \CurlHandle $ch;

    /** @var array<string, string[]> */
    private array $responseHeaders = [];

    public function __construct(
        private readonly string $cookieFile,
        int $timeout,
        string $userAgent
    ) {
        if (!is_file($cookieFile)) {
            $old = umask(0077);
            touch($cookieFile);
            umask($old);
        }
        @chmod($cookieFile, 0600);

        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('Could not initialise cURL');
        }
        $this->ch = $ch;

        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
                $len = strlen($line);
                if (preg_match('#^HTTP/\S+\s+\d+#', $line)) {
                    // New response in a redirect chain: keep only the final one's headers.
                    $this->responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $this->responseHeaders[strtolower(trim($name))][] = trim($value);
                }
                return $len;
            },
        ]);
    }

    public function request(string $method, string $url, string|array|null $body = null, array $headers = []): Response
    {
        $this->responseHeaders = [];
        $headers = array_merge([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ], $headers);

        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
        if ($method === 'GET') {
            curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, null);
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method === 'POST' ? null : $method);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $body ?? '');
        }

        $result = curl_exec($this->ch);
        if ($result === false) {
            throw new TransportException(sprintf('cURL error %d: %s', curl_errno($this->ch), curl_error($this->ch)));
        }

        curl_setopt($this->ch, CURLOPT_COOKIELIST, 'FLUSH');
        @chmod($this->cookieFile, 0600);

        return new Response(
            (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE),
            (string) $result,
            (string) curl_getinfo($this->ch, CURLINFO_EFFECTIVE_URL),
            $this->responseHeaders
        );
    }

    public function cookieValues(): array
    {
        $values = [];
        foreach ((array) curl_getinfo($this->ch, CURLINFO_COOKIELIST) as $line) {
            // Netscape format: domain, flag, path, secure, expiry, name, value
            $parts = explode("\t", (string) $line);
            if (isset($parts[6]) && $parts[6] !== '') {
                $values[] = $parts[6];
            }
        }
        return $values;
    }

    public function clearCookies(): void
    {
        curl_setopt($this->ch, CURLOPT_COOKIELIST, 'ALL');
        curl_setopt($this->ch, CURLOPT_COOKIELIST, 'FLUSH');
        file_put_contents($this->cookieFile, '');
        @chmod($this->cookieFile, 0600);
    }
}
