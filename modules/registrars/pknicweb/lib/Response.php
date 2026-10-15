<?php

declare(strict_types=1);

namespace PknicWeb;

final class Response
{
    /**
     * @param string $url Effective URL after redirects.
     * @param array<string, string[]> $headers Lower-cased header name => values.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $url,
        public readonly array $headers = []
    ) {
    }
}
