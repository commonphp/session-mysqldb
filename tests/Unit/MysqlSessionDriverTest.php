<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\MySQL\Tests\Unit;

use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionConnectionException;
use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionDriverException;
use CommonPHP\Drivers\Session\MySQL\Exceptions\MysqlSessionStorageException;
use CommonPHP\Drivers\Session\MySQL\MysqlSessionConnectionOptions;
use CommonPHP\Drivers\Session\MySQL\MysqlSessionDriver;
use CommonPHP\Drivers\Session\MySQL\MysqlSessionDsnBuilder;
use CommonPHP\Drivers\Session\MySQL\MysqlSessionOptions;
use CommonPHP\Drivers\Session\MySQL\SessionPayloadSerializer;
use CommonPHP\Runtime\Contracts\DriverInterface;
use CommonPHP\Session\Contracts\SessionDriverInterface;
use CommonPHP\Session\Enums\SessionStatus;
use CommonPHP\Session\SessionManager;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MysqlSessionDriverTest extends TestCase
{
    public function testConnectionOptionsAndDsnBuilderExposeMysqlDefaults(): void
    {
        $options = new MysqlSessionConnectionOptions(
            username: 'app',
            password: 'secret',
            host: 'db.internal',
            database: 'app_db',
            port: 3307,
            charset: 'utf8mb4',
            timeout: 5,
        );

        self::assertSame('app', $options->username());
        self::assertSame('secret', $options->password());
        self::assertSame('db.internal:3307', $options->endpoint());
        self::assertSame(
            'mysql:host=db.internal;port=3307;dbname=app_db;charset=utf8mb4',
            (new MysqlSessionDsnBuilder())->build($options),
        );
        self::assertSame(PDO::ERRMODE_EXCEPTION, $options->pdoAttributes()[PDO::ATTR_ERRMODE]);
        self::assertSame(5, $options->pdoAttributes()[PDO::ATTR_TIMEOUT]);
    }

    public function testConnectionOptionsRejectInvalidConfiguration(): void
    {
        $this->expectException(MysqlSessionConnectionException::class);

        new MysqlSessionConnectionOptions(database: '', port: 0);
    }

    public function testSessionOptionsBuildQuotedSqlAndRejectUnsafeIdentifiers(): void
    {
        $options = new MysqlSessionOptions(table: 'app.sessions');

        self::assertSame('`app`.`sessions`', $options->tableSql());
        self::assertSame(
            'select `id`, `name`, `payload`, `last_activity` from `app`.`sessions` where `id` = :id and `name` = :name',
            $options->selectSql(),
        );

        $this->expectException(MysqlSessionDriverException::class);

        new MysqlSessionOptions(table: 'sessions; drop table users');
    }

    public function testItLoadsMutatesAndSavesMysqlSessions(): void
    {
        $serializer = new SessionPayloadSerializer();
        $pdo = new FakeMysqlSessionPdo();
        $pdo->seed('existing', 'COMMONPHPSESSID', ['user' => 'Ada'], time(), $serializer);
        $driver = new MysqlSessionDriver(
            $pdo,
            new MysqlSessionOptions(gcProbability: 0),
            sessionId: 'existing',
        );
        $session = new SessionManager($driver);

        self::assertInstanceOf(SessionDriverInterface::class, $driver);
        self::assertInstanceOf(DriverInterface::class, $driver);
        self::assertSame(SessionStatus::None, $driver->status());

        $session->start();

        self::assertSame(SessionStatus::Active, $driver->status());
        self::assertSame('Ada', $session->get('user'));

        $session->set('theme', 'dark')->save();

        self::assertSame(SessionStatus::None, $driver->status());
        self::assertSame(
            ['user' => 'Ada', 'theme' => 'dark'],
            $pdo->payload('existing', 'COMMONPHPSESSID', $serializer),
        );
    }

    public function testItCreatesNewIdsAndInsertsNewSessions(): void
    {
        $serializer = new SessionPayloadSerializer();
        $pdo = new FakeMysqlSessionPdo();
        $driver = new MysqlSessionDriver(
            $pdo,
            new MysqlSessionOptions(
                sessionName: 'APPSESSID',
                gcProbability: 0,
                idBytes: 16,
            ),
        );
        $session = new SessionManager($driver);

        $session->start();
        $id = $session->id();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        self::assertSame('APPSESSID', $session->name());

        $session->set('cart', ['sku' => 'A-1'])->save();

        self::assertTrue($pdo->has($id, 'APPSESSID'));
        self::assertSame(['cart' => ['sku' => 'A-1']], $pdo->payload($id, 'APPSESSID', $serializer));
    }

    public function testItInvalidatesAndRegeneratesIds(): void
    {
        $serializer = new SessionPayloadSerializer();
        $pdo = new FakeMysqlSessionPdo();
        $pdo->seed('old', 'COMMONPHPSESSID', ['count' => 1], time(), $serializer);
        $driver = new MysqlSessionDriver(
            $pdo,
            new MysqlSessionOptions(gcProbability: 0, idBytes: 16),
            sessionId: 'old',
        );
        $session = new SessionManager($driver);
        $session->start();

        $newId = $session->regenerateId();

        self::assertNotSame('old', $newId);
        self::assertFalse($pdo->has('old', 'COMMONPHPSESSID'));

        $session->set('count', 2)->save();

        self::assertTrue($pdo->has($newId, 'COMMONPHPSESSID'));
        self::assertSame(['count' => 2], $pdo->payload($newId, 'COMMONPHPSESSID', $serializer));

        $session->start()->invalidate();

        self::assertFalse($pdo->has($newId, 'COMMONPHPSESSID'));
        self::assertSame(SessionStatus::None, $driver->status());
    }

    public function testExpiredSessionsStartEmptyAndGarbageCollectionRemovesOldRows(): void
    {
        $pdo = new FakeMysqlSessionPdo();
        $pdo->seed('expired', 'COMMONPHPSESSID', ['stale' => true], time() - 10);
        $pdo->seed('fresh', 'COMMONPHPSESSID', ['fresh' => true], time());
        $driver = new MysqlSessionDriver(
            $pdo,
            new MysqlSessionOptions(lifetimeSeconds: 5, gcProbability: 1, gcDivisor: 1),
            sessionId: 'fresh',
        );
        $session = new SessionManager($driver);

        $session->start();

        self::assertSame(['fresh' => true], $session->all());
        self::assertFalse($pdo->has('expired', 'COMMONPHPSESSID'));
        self::assertTrue($pdo->has('fresh', 'COMMONPHPSESSID'));

        $expiredDriver = new MysqlSessionDriver(
            $pdo,
            new MysqlSessionOptions(lifetimeSeconds: 5, gcProbability: 0),
            sessionId: 'expired',
        );
        $expiredSession = new SessionManager($expiredDriver);
        $expiredSession->start();

        self::assertSame([], $expiredSession->all());
    }

    public function testCorruptPayloadsThrowStorageExceptions(): void
    {
        $pdo = new FakeMysqlSessionPdo();
        $pdo->seedRaw('corrupt', 'COMMONPHPSESSID', 'not serialized', time());
        $driver = new MysqlSessionDriver(
            $pdo,
            new MysqlSessionOptions(gcProbability: 0),
            sessionId: 'corrupt',
        );

        $this->expectException(MysqlSessionStorageException::class);

        $driver->start();
    }
}

final class FakeMysqlSessionPdo extends PDO
{
    /**
     * @var array<string, array{id: string, name: string, payload: string, last_activity: int}>
     */
    private array $rows = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeMysqlSessionStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = new FakeMysqlSessionStatement($this, $query);
        $statement->execute();

        return $statement;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function seed(
        string $id,
        string $name,
        array $payload,
        int $lastActivity,
        ?SessionPayloadSerializer $serializer = null,
    ): void {
        $serializer ??= new SessionPayloadSerializer();
        $this->seedRaw($id, $name, $serializer->encode($payload), $lastActivity);
    }

    public function seedRaw(string $id, string $name, string $payload, int $lastActivity): void
    {
        $this->rows[$this->key($id, $name)] = [
            'id' => $id,
            'name' => $name,
            'payload' => $payload,
            'last_activity' => $lastActivity,
        ];
    }

    public function has(string $id, string $name): bool
    {
        return array_key_exists($this->key($id, $name), $this->rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(string $id, string $name, SessionPayloadSerializer $serializer): array
    {
        $row = $this->rows[$this->key($id, $name)] ?? null;

        if ($row === null) {
            throw new RuntimeException('Missing fake MySQL session row.');
        }

        return $serializer->decode($row['payload']);
    }

    /**
     * @param array<string|int, mixed> $bindings
     * @return array{rows: list<array<string, mixed>>, affectedRows: int}
     */
    public function executePrepared(string $query, array $bindings): array
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? $query));

        if ($normalized === 'select 1') {
            return ['rows' => [['value' => 1]], 'affectedRows' => 0];
        }

        if ($normalized === 'select `id`, `name`, `payload`, `last_activity` from `sessions` where `id` = :id and `name` = :name') {
            $row = $this->rows[$this->key((string) $bindings[':id'], (string) $bindings[':name'])] ?? null;

            return ['rows' => $row === null ? [] : [$row], 'affectedRows' => 0];
        }

        if ($normalized === 'insert into `sessions` (`id`, `name`, `payload`, `last_activity`) values (:id, :name, :payload, :lastactivity)') {
            $this->seedRaw(
                (string) $bindings[':id'],
                (string) $bindings[':name'],
                (string) $bindings[':payload'],
                (int) $bindings[':lastActivity'],
            );

            return ['rows' => [], 'affectedRows' => 1];
        }

        if ($normalized === 'update `sessions` set `payload` = :payload, `last_activity` = :lastactivity where `id` = :id and `name` = :name') {
            $key = $this->key((string) $bindings[':id'], (string) $bindings[':name']);

            if (!array_key_exists($key, $this->rows)) {
                return ['rows' => [], 'affectedRows' => 0];
            }

            $this->rows[$key]['payload'] = (string) $bindings[':payload'];
            $this->rows[$key]['last_activity'] = (int) $bindings[':lastActivity'];

            return ['rows' => [], 'affectedRows' => 1];
        }

        if ($normalized === 'delete from `sessions` where `last_activity` <= :expiredbefore') {
            $deleted = 0;

            foreach ($this->rows as $key => $row) {
                if ($row['last_activity'] <= (int) $bindings[':expiredBefore']) {
                    unset($this->rows[$key]);
                    ++$deleted;
                }
            }

            return ['rows' => [], 'affectedRows' => $deleted];
        }

        if ($normalized === 'delete from `sessions` where `id` = :id and `name` = :name') {
            $key = $this->key((string) $bindings[':id'], (string) $bindings[':name']);
            $existed = array_key_exists($key, $this->rows);
            unset($this->rows[$key]);

            return ['rows' => [], 'affectedRows' => $existed ? 1 : 0];
        }

        throw new PDOException('Unsupported fake MySQL session query: ' . $query);
    }

    private function key(string $id, string $name): string
    {
        return $name . "\0" . $id;
    }
}

final class FakeMysqlSessionStatement extends PDOStatement
{
    /**
     * @var array<string|int, mixed>
     */
    private array $bindings = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $rows = [];

    private int $affectedRows = 0;

    public function __construct(
        private readonly FakeMysqlSessionPdo $pdo,
        private readonly string $query,
    ) {
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = $value;

        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            $this->bindings = $params;
        }

        $result = $this->pdo->executePrepared($this->query, $this->bindings);
        $this->rows = $result['rows'];
        $this->affectedRows = $result['affectedRows'];

        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        $row = array_shift($this->rows);

        return $row === null ? false : $this->mapRow($row, $mode);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return array_map(fn (array $row): mixed => $this->mapRow($row, $mode), $this->rows);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = array_shift($this->rows);

        if ($row === null) {
            return false;
        }

        return array_values($row)[$column] ?? false;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row, int $mode): mixed
    {
        return match ($mode) {
            PDO::FETCH_NUM => array_values($row),
            PDO::FETCH_BOTH => array_values($row) + $row,
            PDO::FETCH_OBJ => (object) $row,
            PDO::FETCH_COLUMN => array_values($row)[0] ?? null,
            default => $row,
        };
    }
}
