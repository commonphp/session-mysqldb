<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\MySQL\Exceptions;

use CommonPHP\Drivers\Session\MySQL\MysqlSessionConnectionOptions;
use CommonPHP\Session\Exceptions\SessionStorageException;
use Throwable;

class MysqlSessionConnectionException extends SessionStorageException
{
    public static function forInvalidOptions(string $message): self
    {
        return new self($message);
    }

    public static function forConnection(MysqlSessionConnectionOptions $options, Throwable $previous): self
    {
        return new self(
            'MySQL session connection to "' . $options->database() . '" at "' . $options->endpoint() . '" failed.',
            previous: $previous,
        );
    }
}
