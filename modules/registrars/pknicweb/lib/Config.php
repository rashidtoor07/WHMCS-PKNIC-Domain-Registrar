<?php

declare(strict_types=1);

namespace PknicWeb;

final class Config
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $username,
        public readonly string $password,
        public readonly int $ticketDeptId = 0,
        public readonly string $alertEmail = '',
        public readonly int $maxRetries = 3,
        public readonly bool $debug = false,
        public readonly string $storageDir = ''
    ) {
    }

    /** Build from WHMCS registrar params / getRegistrarConfigOptions() output. */
    public static function fromParams(array $params): self
    {
        $retries = (int) ($params['MaxRetries'] ?? 3);

        return new self(
            rtrim(trim((string) ($params['BaseUrl'] ?? '')), '/'),
            trim((string) ($params['Username'] ?? '')),
            (string) ($params['Password'] ?? ''),
            (int) ($params['TicketDeptId'] ?? 0),
            trim((string) ($params['AlertEmail'] ?? '')),
            $retries > 0 ? $retries : 3,
            in_array(strtolower((string) ($params['Debug'] ?? '')), ['on', 'yes', '1', 'true'], true),
            self::resolveStorageDir(trim((string) ($params['StoragePath'] ?? '')))
        );
    }

    /**
     * Storage for the cookie jar and debug dumps. Prefers the configured path, then the WHMCS
     * attachments directory (normally outside / protected from the web root).
     */
    public static function resolveStorageDir(string $configured = ''): string
    {
        $candidates = [];
        if ($configured !== '') {
            $candidates[] = $configured;
        }
        try {
            if (class_exists('\\App') && method_exists('\\App', 'getApplicationConfig')) {
                $appConfig = \App::getApplicationConfig();
                $dir = $appConfig['attachments_dir'] ?? null;
                if (is_string($dir) && $dir !== '') {
                    $candidates[] = $dir;
                }
            }
        } catch (\Throwable) {
            // Fall through to the other candidates.
        }
        if (!empty($GLOBALS['attachments_dir']) && is_string($GLOBALS['attachments_dir'])) {
            $candidates[] = $GLOBALS['attachments_dir'];
        }
        if (defined('ROOTDIR')) {
            $candidates[] = ROOTDIR . '/attachments';
        }
        $candidates[] = sys_get_temp_dir();

        foreach ($candidates as $base) {
            $base = rtrim($base, '/\\');
            if (!is_dir($base) || !is_writable($base)) {
                continue;
            }
            $dir = basename($base) === 'pknicweb' ? $base : $base . '/pknicweb';
            if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
                continue;
            }
            @chmod($dir, 0700);
            if (!is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            }
            if (!is_file($dir . '/index.html')) {
                @file_put_contents($dir . '/index.html', '');
            }
            return $dir;
        }

        throw new PknicException('No writable storage directory found for the PKNIC cookie jar');
    }
}
