<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\MySQL;

use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionConnectionException;
use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionDriverException;
use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionStorageException;
use CommonPHP\Session\Contracts\AbstractSessionDriver;
use CommonPHP\Session\Enums\SessionStatus;
use PDO;
use Throwable;

final class MysqlSessionDriver extends AbstractSessionDriver
{
    /**
     * @var array<string, mixed>
     */
    private array $payload = [];

    private bool $active = false;

    private string $sessionId = '';

    private string $sessionName;

    private MysqlSessionConnection $connection;

    private MysqlSessionOptions $options;

    private SessionPayloadSerializer $serializer;

    private MysqlSessionGarbageCollector $garbageCollector;

    /**
     * @param array<string, mixed>|MysqlSessionConnectionOptions|MysqlSessionConnection|PDO|null $connection
     * @param array<string, mixed>|MysqlSessionOptions|null $options
     */
    public function __construct(
        array|MysqlSessionConnectionOptions|MysqlSessionConnection|PDO|null $connection = null,
        array|MysqlSessionOptions|null $options = null,
        ?SessionPayloadSerializer $serializer = null,
        ?MysqlSessionGarbageCollector $garbageCollector = null,
        ?string $sessionId = null,
        ?string $sessionName = null,
    ) {
        $this->connection = $connection instanceof MysqlSessionConnection
            ? $connection
            : new MysqlSessionConnection($connection);

        $this->options = is_array($options)
            ? MysqlSessionOptions::fromArray($options)
            : ($options ?? new MysqlSessionOptions());

        $this->serializer = $serializer ?? new SessionPayloadSerializer();
        $this->garbageCollector = $garbageCollector ?? new MysqlSessionGarbageCollector(
            $this->connection,
            $this->options,
        );

        if ($sessionId === '') {
            throw MysqlSessionStorageException::forSessionOperation('configure session id: value cannot be empty');
        }

        $this->sessionId = $sessionId ?? '';
        $this->sessionName = $this->normalizeSessionName($sessionName ?? $this->options->sessionName);
    }

    public function getName(): string
    {
        return 'mysql-session';
    }

    public function connection(): MysqlSessionConnection
    {
        return $this->connection;
    }

    public function options(): MysqlSessionOptions
    {
        return $this->options;
    }

    public function serializer(): SessionPayloadSerializer
    {
        return $this->serializer;
    }

    public function garbageCollector(): MysqlSessionGarbageCollector
    {
        return $this->garbageCollector;
    }

    public function start(): void
    {
        $this->assertSessionSupport();

        if ($this->active) {
            return;
        }

        if ($this->sessionId === '') {
            $this->sessionId = $this->generateId();
        }

        $this->maybeCollectGarbage();

        $record = $this->readRecord($this->sessionId);

        if ($record !== null && $record->isExpired(time(), $this->options->lifetimeSeconds)) {
            $this->deleteRecord($this->sessionId);
            $record = null;
        }

        $this->payload = $record === null ? [] : $record->data($this->serializer);
        $this->active = true;
    }

    public function save(): void
    {
        $this->assertCanAccessData('save session data');

        $this->writeRecord(
            MysqlSessionRecord::fromPayload(
                $this->sessionId,
                $this->sessionName,
                $this->payload,
                time(),
                $this->serializer,
            ),
        );

        $this->active = false;
    }

    public function invalidate(): void
    {
        $this->assertCanAccessData('invalidate the session');

        $this->deleteRecord($this->sessionId);
        $this->payload = [];
        $this->active = false;
    }

    public function regenerateId(bool $deleteOldSession = true): string
    {
        $this->assertCanAccessData('regenerate the session id');

        $oldId = $this->sessionId;
        $newId = $this->generateId();

        if ($deleteOldSession) {
            $this->deleteRecord($oldId);
        }

        $this->sessionId = $newId;

        return $this->sessionId;
    }

    public function status(): SessionStatus
    {
        return $this->active ? SessionStatus::Active : SessionStatus::None;
    }

    public function id(): string
    {
        return $this->sessionId;
    }

    public function setId(string $id): void
    {
        $this->assertCanConfigure('set the session id');
        $this->sessionId = $this->normalizeSessionId($id);
    }

    public function name(): string
    {
        return $this->sessionName;
    }

    public function setName(string $name): void
    {
        $this->assertCanConfigure('set the session name');
        $this->sessionName = $this->normalizeSessionName($name);
    }

    /**
     * @return array<string, mixed>
     */
    public function &data(): array
    {
        $this->assertCanAccessData('access session data');

        return $this->payload;
    }

    private function readRecord(string $sessionId): ?MysqlSessionRecord
    {
        try {
            $row = $this->connection->fetchOne($this->options->selectSql(), $this->identityParameters($sessionId));
        } catch (MysqlSessionConnectionException | MysqlSessionStorageException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw MysqlSessionStorageException::forSessionOperation('read', $sessionId, $throwable);
        }

        if ($row === false) {
            return null;
        }

        return MysqlSessionRecord::fromRow($row, $this->options);
    }

    private function writeRecord(MysqlSessionRecord $record): void
    {
        $exists = $this->readRecord($record->id) !== null;
        $sql = $exists ? $this->options->updateSql() : $this->options->insertSql();
        $operation = $exists ? 'write update' : 'write insert';

        $this->execute($sql, $record->parameters(), $operation, $record->id);
    }

    private function deleteRecord(string $sessionId): void
    {
        $this->execute($this->options->deleteSql(), $this->identityParameters($sessionId), 'delete', $sessionId);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function execute(string $sql, array $parameters, string $operation, string $sessionId): void
    {
        try {
            $this->connection->execute($sql, $parameters);
        } catch (MysqlSessionConnectionException | MysqlSessionStorageException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw MysqlSessionStorageException::forSessionOperation($operation, $sessionId, $throwable);
        }
    }

    /**
     * @return array{id: string, name: string}
     */
    private function identityParameters(string $sessionId): array
    {
        return [
            'id' => $sessionId,
            'name' => $this->sessionName,
        ];
    }

    private function maybeCollectGarbage(): void
    {
        if ($this->options->gcProbability === 0) {
            return;
        }

        try {
            $shouldCollect = random_int(1, $this->options->gcDivisor) <= $this->options->gcProbability;
        } catch (Throwable $throwable) {
            throw MysqlSessionDriverException::forRandomBytes(
                'checking MySQL session garbage collection probability',
                $throwable,
            );
        }

        if ($shouldCollect) {
            $this->garbageCollector->collect();
        }
    }

    private function generateId(): string
    {
        try {
            return bin2hex(random_bytes($this->options->idBytes));
        } catch (Throwable $throwable) {
            throw MysqlSessionDriverException::forRandomId($throwable);
        }
    }

    private function assertCanConfigure(string $operation): void
    {
        if ($this->active) {
            throw MysqlSessionStorageException::forSessionOperation($operation, $this->sessionId);
        }
    }

    private function normalizeSessionId(string $id): string
    {
        $id = trim($id);

        if ($id === '') {
            throw MysqlSessionStorageException::forSessionOperation('set the session id: value cannot be empty');
        }

        return $id;
    }

    private function normalizeSessionName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw MysqlSessionStorageException::forSessionOperation('set the session name: value cannot be empty');
        }

        return $name;
    }
}
