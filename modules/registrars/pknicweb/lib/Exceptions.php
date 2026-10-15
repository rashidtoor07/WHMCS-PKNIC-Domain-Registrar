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
