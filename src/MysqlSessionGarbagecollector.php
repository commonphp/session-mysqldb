<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\MySQL;

use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionStorageException;
use Throwable;

final readonly class MysqlSessionGarbageCollector
{
    public function __construct(
        private MysqlSessionConnection $connection,
        private MysqlSessionOptions $options,
    ) {
    }

    public function collect(?int $now = null): int
    {
        $now ??= time();
        $expiredBefore = $now - $this->options->lifetimeSeconds;

        try {
            return $this->connection->execute(
                $this->options->deleteExpiredSql(),
                ['expiredBefore' => $expiredBefore],
            );
        } catch (MysqlSessionStorageException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw MysqlSessionStorageException::forSessionOperation(
                'collect garbage',
                previous: $throwable,
            );
        }
    }
}
