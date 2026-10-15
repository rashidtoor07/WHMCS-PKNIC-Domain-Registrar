<?php

declare(strict_types=1);

namespace PknicWeb;

use WHMCS\Database\Capsule;

/** Key/value flags in mod_pknicweb_settings: pause state and the login-failure counter. */
final class Settings
{
    public const LOGIN_FAILURE_LIMIT = 2;

    public function __construct()
    {
        JobQueue::ensureSchema();
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = Capsule::table(JobQueue::SETTINGS)->where('setting', $key)->value('value');
        return $value === null ? $default : (string) $value;
    }

    public function set(string $key, ?string $value): void
    {
        Capsule::table(JobQueue::SETTINGS)->updateOrInsert(
            ['setting' => $key],
            ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    public function isPaused(): bool
    {
        return $this->get('paused', '0') === '1';
    }

    public function pauseReason(): string
    {
        return (string) $this->get('paused_reason', '');
    }

    /** @return bool true if this call paused (false if it was already paused). */
    public function pause(string $reason): bool
    {
        if ($this->isPaused()) {
            return false;
        }
        $this->set('paused', '1');
        $this->set('paused_reason', $reason);
        $this->set('paused_at', date('Y-m-d H:i:s'));
        return true;
    }

    public function resume(): void
    {
        $this->set('paused', '0');
        $this->set('paused_reason', '');
        $this->set('login_failures', '0');
    }

    public function recordLoginSuccess(): void
    {
        if ($this->get('login_failures', '0') !== '0') {
            $this->set('login_failures', '0');
        }
    }

    /** Count a failed login; pause and alert once the limit is reached. */
    public function recordLoginFailure(string $message, Notifier $notifier): void
    {
        $count = (int) $this->get('login_failures', '0') + 1;
        $this->set('login_failures', (string) $count);
        if (function_exists('logActivity')) {
            logActivity("PKNIC: login failed ($count in a row): $message");
        }
        if ($count >= self::LOGIN_FAILURE_LIMIT) {
            $reason = "PKNIC login failed $count times in a row. Last error: $message";
            if ($this->pause($reason)) {
                $notifier->alertAdmin(
                    'PKNIC automation paused: login failing',
                    $reason . "\n\nProcessing is paused to avoid locking the account. Check the username/password in "
                    . "Setup > Domain Registrars > PKNIC, then click \"Resume processing\" in Addons > PKNIC Jobs."
                );
            }
        }
    }
}
