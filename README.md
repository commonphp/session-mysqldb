# CommonPHP MySQL Session Driver

Session driver for CommonPHP that reads and stores session data in MySQL.

## Requirements

- PHP `^8.5`
- `comphp/session:^0.3`
- A MySQL extension or connection library supported by the implementation

## Installation

Once this package is available through your Composer repositories, install it with:

```bash
composer require comphp/session-mysqldb
```

## Usage

```php
<?php

// TODO: Write usage
```

## Driver Notes

This driver is intended for applications that want MySQL-backed session storage without requiring the full CommonPHP Database abstraction.

Use `comphp/session-comphp-database` instead when session storage should go through a CommonPHP Database connection.

## Error Handling

Connection, read, write, destroy, garbage collection, and configuration failures should throw CommonPHP session driver exceptions instead of returning ambiguous false values.

## Documentation

- [Usage](docs/usage.md)
- [Testing](TESTING.md)
- [Contributing](CONTRIBUTING.md)
- [Security](SECURITY.md)

## License

MIT. See [LICENSE.md](LICENSE.md).
