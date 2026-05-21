<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\MySQL;

use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionConnectionException;
use PDO;

final readonly class MysqlSessionConnectionOptions
{
    public const string DEFAULT_USERNAME = 'root';

    public const string DEFAULT_PASSWORD = '';

    public const string DEFAULT_HOST = '127.0.0.1';

    public const int DEFAULT_PORT = 3306;

    public const string DEFAULT_CHARSET = 'utf8mb4';

    /**
     * @param array<int, mixed> $attributes
     */
    public function __construct(
        private string $username = self::DEFAULT_USERNAME,
        private string $password = self::DEFAULT_PASSWORD,
        private string $host = self::DEFAULT_HOST,
        private string $database = '',
        private int $port = self::DEFAULT_PORT,
        private string $charset = self::DEFAULT_CHARSET,
        private ?string $unixSocket = null,
        private ?int $timeout = null,
        private bool $persistent = false,
        private array $attributes = [],
    ) {
        $this->assertValid();
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $attributes = self::option($options, 'attributes', 'driverOptions') ?? [];

        if (!is_array($attributes)) {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session PDO attributes must be an array.',
            );
        }

        return new self(
            username: self::stringOption($options, self::DEFAULT_USERNAME, 'username'),
            password: self::stringOption($options, self::DEFAULT_PASSWORD, 'password'),
            host: self::stringOption($options, self::DEFAULT_HOST, 'host', 'Host', 'server', 'Server'),
            database: self::stringOption($options, '', 'database', 'Database', 'dbname'),
            port: self::intOption($options, self::DEFAULT_PORT, 'port', 'Port'),
            charset: self::stringOption($options, self::DEFAULT_CHARSET, 'charset', 'CharacterSet'),
            unixSocket: self::nullableStringOption($options, 'unixSocket', 'unix_socket'),
            timeout: self::nullableIntOption($options, 'timeout', 'connectTimeout'),
            persistent: self::boolOption($options, false, 'persistent'),
            attributes: $attributes,
        );
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function charset(): string
    {
        return $this->charset;
    }

    public function unixSocket(): ?string
    {
        return $this->unixSocket;
    }

    public function timeout(): ?int
    {
        return $this->timeout;
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function endpoint(): string
    {
        if ($this->unixSocket !== null) {
            return 'unix_socket:' . $this->unixSocket;
        }

        return $this->host . ':' . $this->port;
    }

    /**
     * @return array<int, mixed>
     */
    public function pdoAttributes(): array
    {
        $attributes = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        if ($this->timeout !== null) {
            $attributes[PDO::ATTR_TIMEOUT] = $this->timeout;
        }

        if ($this->persistent) {
            $attributes[PDO::ATTR_PERSISTENT] = true;
        }

        return $this->attributes + $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password,
            'host' => $this->host,
            'database' => $this->database,
            'port' => $this->port,
            'charset' => $this->charset,
            'unixSocket' => $this->unixSocket,
            'timeout' => $this->timeout,
            'persistent' => $this->persistent,
            'attributes' => $this->attributes,
        ];
    }

    private function assertValid(): void
    {
        if (trim($this->username) === '') {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session username must be a non-empty string.',
            );
        }

        if (trim($this->database) === '') {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session database name must be a non-empty string.',
            );
        }

        if ($this->unixSocket === null && trim($this->host) === '') {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session host must be a non-empty string.',
            );
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session port must be between 1 and 65535.',
            );
        }

        if (trim($this->charset) === '') {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session charset must be a non-empty string.',
            );
        }

        if ($this->unixSocket !== null && trim($this->unixSocket) === '') {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session unix socket must be a non-empty string.',
            );
        }

        if ($this->timeout !== null && $this->timeout < 1) {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session timeout must be greater than zero.',
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function stringOption(array $options, string $default, string ...$keys): string
    {
        $value = self::option($options, ...$keys) ?? $default;

        if (!is_string($value)) {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session option "' . $keys[0] . '" must be a string.',
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function nullableStringOption(array $options, string ...$keys): ?string
    {
        $value = self::option($options, ...$keys);

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session option "' . $keys[0] . '" must be a string.',
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function intOption(array $options, int $default, string ...$keys): int
    {
        $value = self::option($options, ...$keys);

        if ($value === null) {
            return $default;
        }

        if (!is_int($value)) {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session option "' . $keys[0] . '" must be an integer.',
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function nullableIntOption(array $options, string ...$keys): ?int
    {
        $value = self::option($options, ...$keys);

        if ($value === null) {
            return null;
        }

        if (!is_int($value)) {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session option "' . $keys[0] . '" must be an integer.',
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function boolOption(array $options, bool $default, string ...$keys): bool
    {
        $value = self::option($options, ...$keys);

        if ($value === null) {
            return $default;
        }

        if (!is_bool($value)) {
            throw MysqlSessionConnectionException::forInvalidOptions(
                'MySQL session option "' . $keys[0] . '" must be a boolean.',
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function option(array $options, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $options)) {
                return $options[$key];
            }
        }

        return null;
    }
}
