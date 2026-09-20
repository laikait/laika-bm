# LBM tests

These tests run against the real application and a real MySQL database. They
never touch the application's own database.

## Run

```bash
cd vendor/laikait/laika-bm          # this package
php tools/phpunit.phar              # the whole suite
php tools/phpunit.phar --filter Overpayment
```

`tools/` is git-ignored. On a fresh checkout, fetch the runner (PHPUnit 12, PHP 8.3+) first:

```bash
mkdir -p tools && curl -L -o tools/phpunit.phar https://phar.phpunit.de/phpunit-12.phar
```

## How it isolates

- `tests/bootstrap.php` boots the app, then drops and recreates a database
  called **`lbm_test`**, using the MySQL credentials in the app's
  `lf-config/database.php`. It builds the schema with `Installer::migrate()`,
  the same way a real install does.
- It refuses to run unless the connection is actually on `lbm_test`. It also
  stops the run if `lf-config/database.php` changes while the tests run.
  laika-core's `Config::set()` writes to disk, and it once did exactly that.
- Each test runs inside a transaction that is rolled back afterwards
  (`LBM\Tests\TestCase`). The option and settings caches are flushed too,
  because a rollback doesn't reset what the process remembers.
- Set `LBM_APP_ROOT` if the app isn't the `cloud` directory next to this
  checkout.

## Writing one

Extend `LBM\Tests\TestCase`. Its helpers are `client()`, `invoice()`,
`currencyId()` and `assertMoney()`. Build only the rows the test is about,
through the models.

Tests are excluded from release zips. `bin/verify-stage.php` fails the build
if `tests/`, `tools/` or `phpunit.xml.dist` ship.
