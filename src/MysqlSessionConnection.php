<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\MySQL;

use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionConnectionException;
use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionStorageException;
use PDO;
use PDOStatement;
use Throwable;

final class MysqlSessionConnection
{
    private ?PDO $pdo = null;

    private ?MysqlSessionConnectionOptions $options = null;

    /**
     * @param array<string, mixed>|MysqlSessionConnectionOptions|PDO|null $connection
     */
    public function __construct(
        array|MysqlSessionConnectionOptions|PDO|null $connection = null,
        private readonly MysqlSessionConnectionFactory $connectionFactory = new MysqlSessionConnectionFactory(),
        private readonly MysqlSessionStatementBinder $statementBinder = new MysqlSessionStatementBinder(),
    ) {
        if ($connection instanceof PDO) {
            $this->pdo = $connection;

            return;
        }

        $this->options = is_array($connection)
            ? MysqlSessionConnectionOptions::fromArray($connection)
            : ($connection ?? new MysqlSessionConnectionOptions());
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if ($this->options === null) {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session connection options are not configured.',
            );
        }

        return $this->pdo = $this->connectionFactory->connect($this->options);
    }

    /**
     * @param array<string|int, mixed> $parameters
     */
    public function execute(string $query, array $parameters = []): int
    {
        return $this->runStatement('execute', $query, $parameters)->rowCount();
    }

    /**
     * @param array<string|int, mixed> $parameters
     * @return array<string|int, mixed>|false
     */
    public function fetchOne(string $query, array $parameters = []): array|false
    {
        $row = $this->runStatement('fetch one', $query, $parameters)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }

    public function ping(): bool
    {
        try {
            $this->pdo()->query('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string|int, mixed> $parameters
     */
    private function runStatement(string $operation, string $query, array $parameters = []): PDOStatement
    {
        try {
            $statement = $this->prepareStatement($query);
            $this->statementBinder->bind($statement, $parameters, $query);
            $statement->execute();

            return $statement;
        } catch (MysqlSessionConnectionException | MysqlSessionStorageException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw MysqlSessionStorageException::forQuery($operation, $query, $throwable);
        }
    }

    private function prepareStatement(string $query): PDOStatement
    {
        $statement = $this->pdo()->prepare($query);

        if (!$statement instanceof PDOStatement) {
            throw MysqlSessionStorageException::forPrepareFailure($query);
        }

        return $statement;
    }
}
