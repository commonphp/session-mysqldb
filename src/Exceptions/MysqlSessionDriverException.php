<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\MySQL\Exceptions;

use CommonPHP\Session\Exceptions\SessionDriverException;
use Throwable;

class MysqlSessionDriverException extends SessionDriverException
{
    public static function invalidOption(string $option, string $message): self
    {
        return new self('Invalid MySQL session option "' . $option . '": ' . $message);
    }

    public static function forRandomId(Throwable $previous): self
    {
        return self::forRandomBytes('generating a MySQL session id', $previous);
    }

    public static function forRandomBytes(string $operation, Throwable $previous): self
    {
        return new self(
            'Unable to read secure random data while ' . $operation . '.',
            previous: $previous,
        );
    }
}
