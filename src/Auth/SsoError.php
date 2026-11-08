<?php

declare(strict_types=1);

namespace Shepherd\Auth;

/**
 * A single sign-on failure. $reason is a fixed code the console shows a message for;
 * the exception message is the detail for the audit log, never shown to the user.
 */
final class SsoError extends \RuntimeException
{
    public const REASONS = ['sso_unavailable', 'sso_expired', 'sso_failed', 'sso_denied', 'no_role', 'disabled', 'groups_overage'];

    public function __construct(public readonly string $reason, string $detail)
    {
        parent::__construct($detail);
    }
}
