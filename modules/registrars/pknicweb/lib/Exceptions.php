<?php

declare(strict_types=1);

namespace PknicWeb;

/** Generic, retryable failure while talking to the PKNIC panel. */
class PknicException extends \RuntimeException
{
}

/** Login to the panel failed (bad credentials, marker missing, form changed). */
class LoginException extends PknicException
{
}

/** Network / cURL level failure. */
class TransportException extends PknicException
{
}

/** pknic_map.php is incomplete or invalid; retrying will not help until it is fixed. */
class MapException extends PknicException
{
}

/** The outcome is unsafe to retry automatically (e.g. a renewal that may have gone through). */
class NonRetryableException extends PknicException
{
}

/** The action was submitted; PKNIC has not reflected it yet. Re-check later without re-submitting. */
class AwaitingConfirmationException extends PknicException
{
}

/** PKNIC refused because the account balance is too low. Affects every paid action, not just this job. */
class InsufficientBalanceException extends PknicException
{
}

/** A <select> had no option matching the wanted value. Raised before anything is posted. */
class NoMatchingOptionException extends PknicException
{
    public function __construct(
        public readonly string $field,
        public readonly OptionChoice $choice,
        public readonly array $options
    ) {
        parent::__construct(sprintf(
            'No option in select "%s" matches %s. Available: %s',
            $field,
            implode(', ', $choice->wanted),
            implode(' | ', array_map(static fn ($v, $t) => "$t [$v]", array_keys($options), $options)) ?: '(none)'
        ));
    }
}
