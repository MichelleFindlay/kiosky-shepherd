<?php

declare(strict_types=1);

namespace Shepherd\Http;

/** Thrown anywhere in a handler to end the request with a JSON error. */
final class HttpError extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, 'bad_request', $message, $details);
    }

    public static function validation(array $fieldErrors): self
    {
        return new self(422, 'validation_failed', 'Some fields are not valid.', ['fields' => $fieldErrors]);
    }

    public static function unauthorized(string $message = 'Sign in first.'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbidden(string $message = 'Your role does not allow this.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $what = 'Resource'): self
    {
        return new self(404, 'not_found', "$what not found.");
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'conflict', $message);
    }
}
