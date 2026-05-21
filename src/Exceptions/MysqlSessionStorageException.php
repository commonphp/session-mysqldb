<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\MySQL\Exceptions;

use CommonPHP\Session\Exceptions\SessionStorageException;
use Throwable;

class MysqlSessionStorageException extends SessionStorageException
{
    public static function forSessionOperation(
        string $operation,
        ?string $sessionId = null,
        ?Throwable $previous = null,
    ): self {
        $target = $sessionId === null ? '' : ' for session "' . $sessionId . '"';

        return new self(
            'MySQL session operation "' . $operation . '" failed' . $target . '.',
            previous: $previous,
        );
    }

    public static function forQuery(string $operation, string $query, ?Throwable $previous = null): self
    {
        return new self(
            'MySQL session query operation "' . $operation . '" failed for query: ' . self::summarize($query),
            previous: $previous,
        );
    }

    public static function forPrepareFailure(string $query): self
    {
        return new self('MySQL session query could not be prepared: ' . self::summarize($query));
    }

    public static function forBinding(string|int $parameter, string $query): self
    {
        return new self(
            'MySQL session query could not bind parameter "' . $parameter . '" for query: ' . self::summarize($query),
        );
    }

    public static function forInvalidParameter(string|int $parameter, string $message): self
    {
        return new self('Invalid MySQL session query parameter "' . $parameter . '": ' . $message);
    }

    public static function forCorruptPayload(?Throwable $previous = null): self
    {
        return new self('MySQL session payload could not be decoded.', previous: $previous);
    }

    public static function forUnexpectedPayload(mixed $payload): self
    {
        return new self('MySQL session payload decoded to ' . get_debug_type($payload) . ' instead of array.');
    }

    private static function summarize(string $query): string
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? $query);

        return strlen($query) > 160 ? substr($query, 0, 157) . '...' : $query;
    }
}
