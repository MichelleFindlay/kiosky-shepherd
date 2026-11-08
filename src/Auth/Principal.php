<?php

declare(strict_types=1);

namespace Shepherd\Auth;

use Shepherd\Http\HttpError;

/** Whoever is making the request: an admin (session), an API token, or a device. */
final class Principal
{
    public const ROLES = ['viewer' => 1, 'operator' => 2, 'admin' => 3];

    public function __construct(
        public readonly string $type,      // admin | token | device
        public readonly int $id,           // admins.id, api_tokens.id or devices.id
        public readonly string $name,
        public readonly string $role,      // viewer | operator | admin | device
        public readonly string $ip,
        public readonly ?int $adminId = null, // the admin behind a session or token
        /** @var list<string>|null tags limiting which devices this user sees; null for all */
        public readonly ?array $scopeTags = null,
    ) {
    }

    /** True when this user may only see devices with one of $scopeTags. */
    public function isScoped(): bool
    {
        return $this->scopeTags !== null;
    }

    public function can(string $role): bool
    {
        return (self::ROLES[$this->role] ?? 0) >= (self::ROLES[$role] ?? PHP_INT_MAX);
    }

    public function require(string $role): void
    {
        if (!$this->can($role)) {
            throw HttpError::forbidden("This needs the $role role.");
        }
    }
}
