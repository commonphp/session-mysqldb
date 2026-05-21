# Testing

## Required dev dependencies

This package uses PHPUnit 13 for its test suite. `composer.json` already lists:

- `phpunit/phpunit:^13.1`

If PHPUnit is missing from a clone, install it with:

```bash
composer require --dev phpunit/phpunit:^13.1
```

## Running tests

Install dependencies for this repository, then run PHPUnit from this repository root:

```bash
composer install
vendor/bin/phpunit
```

On Windows, use `vendor\bin\phpunit.bat`.

## Notes

The unit suite uses a fake PDO implementation, so it verifies SQL generation, serialization, session lifecycle behavior, ID regeneration, invalidation, and garbage collection without requiring a live MySQL server.
