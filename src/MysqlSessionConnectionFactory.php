<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\MySQL;

use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionConnectionException;
use PDO;
use Throwable;

final readonly class MysqlSessionConnectionFactory
{
    public function __construct(
        private MysqlSessionDsnBuilder $dsnBuilder = new MysqlSessionDsnBuilder(),
    ) {
    }

    /**
     * @param array<string, mixed>|MysqlSessionConnectionOptions $options
     */
    public function connect(array|MysqlSessionConnectionOptions $options): PDO
    {
        $options = is_array($options) ? MysqlSessionConnectionOptions::fromArray($options) : $options;

        try {
            return new PDO(
                $this->dsnBuilder->build($options),
                $options->username(),
                $options->password(),
                $options->pdoAttributes(),
            );
        } catch (Throwable $throwable) {
            throw MysqlSessionConnectionException::forConnection($options, $throwable);
        }
    }
}
