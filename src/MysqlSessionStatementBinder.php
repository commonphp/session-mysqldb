<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\MySQL;

use BackedEnum;
use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionStorageException;
use DateTimeInterface;
use PDO;
use PDOStatement;
use Stringable;
use UnitEnum;

final class MysqlSessionStatementBinder
{
    /**
     * @param array<string|int, mixed> $parameters
     */
    public function bind(PDOStatement $statement, array $parameters, string $query = ''): void
    {
        $isList = array_is_list($parameters);

        foreach ($parameters as $key => $value) {
            $parameter = is_int($key)
                ? $this->positionalParameter($key, $isList)
                : $this->namedParameter($key);

            if (!$statement->bindValue($parameter, $this->normalizeValue($value), $this->parameterType($value))) {
                throw MysqlSessionStorageException::forBinding($parameter, $query);
            }
        }
    }

    public function parameterType(mixed $value): int
    {
        $value = $this->normalizeValue($value);

        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            is_resource($value) => PDO::PARAM_LOB,
            default => PDO::PARAM_STR,
        };
    }

    public function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return $value;
    }

    private function namedParameter(string $key): string
    {
        $key = trim($key);

        if ($key === '' || $key === ':') {
            throw MysqlSessionStorageException::forInvalidParameter($key, 'named parameters cannot be empty.');
        }

        return str_starts_with($key, ':') ? $key : ':' . $key;
    }

    private function positionalParameter(int $key, bool $isList): int
    {
        if ($key < 0) {
            throw MysqlSessionStorageException::forInvalidParameter(
                $key,
                'positional parameters cannot be negative.',
            );
        }

        return $isList ? $key + 1 : max(1, $key);
    }
}
