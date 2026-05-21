# CommonPHP MySQL Session Driver

Session driver for CommonPHP that reads and stores session data in MySQL.

## Requirements

- PHP `^8.5`
- `ext-pdo`
- `ext-pdo_mysql`
- `comphp/session:^0.3`
- A MySQL database with a writable session table

## Installation

Once this package is available through your Composer repositories, install it with:

```bash
composer require comphp/session-mysqldb
```

## Usage

```php
<?php

use CommonPHP\Drivers\Session\MySQL\MysqlSessionConnectionOptions;
use CommonPHP\Drivers\Session\MySQL\MysqlSessionDriver;
use CommonPHP\Drivers\Session\MySQL\MysqlSessionOptions;
use CommonPHP\Session\SessionManager;

$driver = new MysqlSessionDriver(
    new MysqlSessionConnectionOptions(
        username: 'app',
        password: 'secret',
        host: '127.0.0.1',
        database: 'app',
    ),
    new MysqlSessionOptions(
        table: 'sessions',
        lifetimeSeconds: 3600,
    ),
);

$session = new SessionManager($driver);
$session->start();
$session->set('user_id', 123);
$session->save();
```

The default table shape is:

```sql
create table sessions (
    id varchar(128) not null,
    name varchar(128) not null,
    payload longblob not null,
    last_activity integer not null,
    primary key (id, name),
    index sessions_last_activity_index (last_activity)
);
```

## Driver Notes

This driver is intended for applications that want MySQL-backed session storage without requiring the full CommonPHP Database abstraction.

Use `comphp/session-comphp-database` instead when session storage should go through a CommonPHP Database connection.

## Error Handling

Connection, read, write, destroy, garbage collection, and configuration failures throw CommonPHP session driver exceptions instead of returning ambiguous false values.

## Documentation

- [Usage](docs/usage.md)
- [Testing](TESTING.md)
- [Contributing](CONTRIBUTING.md)
- [Security](SECURITY.md)

## License

MIT. See [LICENSE.md](LICENSE.md).
