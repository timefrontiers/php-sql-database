# TimeFrontiers PHP SQL Database

A small SQL database manager supporting MySQLi and PDO through a compatible
prepared-query and transaction API.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## Features

- Backward-compatible MySQLi and PDO query APIs.
- Prepared statements with positional parameters.
- Manual and callback transactions.
- Nested transaction scopes implemented with generated savepoints.
- Rollback-only protection after ignored statement failures.
- Affected-row reporting for prepared INSERT, UPDATE, and DELETE statements.
- Driver error code and SQLSTATE accessors.
- Explicit transaction methods on the `SQLDatabase` facade.

## Requirements

- PHP 8.4 or higher.
- `ext-mysqli`.
- `ext-pdo` and the appropriate PDO driver when using PDO.

## Installation

```bash
composer require timefrontiers/php-sql-database
```

## Connections

MySQLi is the default facade backend:

```php
use TimeFrontiers\SQLDatabase;

$database = new SQLDatabase(
  server: '127.0.0.1',
  user: 'app',
  pass: 'secret',
  database_or_class: 'app',
  port: '3306',
);
```

Use the additive PDO factory when the database name must be supplied:

```php
$database = SQLDatabase::pdo(
  driver: 'mysql',
  host: '127.0.0.1',
  port: 3306,
  database: 'app',
  user: 'app',
  password: 'secret',
  options: [],
);
```

An existing concrete driver can also be wrapped without replacing it:

```php
use TimeFrontiers\PDODatabase;
use TimeFrontiers\SQLDatabase;

$connection = new PDODatabase(
  driver: 'mysql',
  host: '127.0.0.1',
  port: 3306,
  database: 'app',
  username: 'app',
  password: 'secret',
);

$database = SQLDatabase::fromConnection($connection);
```

The existing constructor and class-name construction paths remain available
for compatibility. The class-name PDO path has no database-name parameter, so
new PDO callers should use `SQLDatabase::pdo()` or `fromConnection()`.

## Prepared queries

```php
$users = $database->fetchAll(
  'SELECT * FROM users WHERE status = ?',
  ['active'],
);

$user = $database->fetchOne(
  'SELECT * FROM users WHERE id = ?',
  [5],
);

$database->execute(
  'UPDATE users SET name = ? WHERE id = ?',
  ['John', 5],
);
```

The legacy `query()`, `multiQuery()`, `confirmQuery()`, result helpers, and
magic facade forwarding remain available.

## Manual transactions

```php
if (!$database->beginTransaction()) {
  throw new RuntimeException('Transaction could not be started.');
}

try {
  if (!$database->execute(
    'UPDATE accounts SET balance = balance - ? WHERE id = ?',
    [100, 10],
  )) {
    throw new RuntimeException('Debit failed.');
  }

  if (!$database->execute(
    'UPDATE accounts SET balance = balance + ? WHERE id = ?',
    [100, 20],
  )) {
    throw new RuntimeException('Credit failed.');
  }

  if (!$database->commit()) {
    throw new RuntimeException('Transaction was not committed.');
  }
} catch (Throwable $exception) {
  if ($database->inTransaction()) {
    $database->rollBack();
  }
  throw $exception;
}
```

`transactionDepth()` reports the managed scope count. `inTransaction()`
reports managed state and verifies it against PDO's native state when PDO can
do so.

## Callback transactions

```php
use TimeFrontiers\SQLDatabase;

$invoiceId = $database->transaction(
  function (SQLDatabase $database): int {
    $database->execute(
      'INSERT INTO invoices (account_id, status) VALUES (?, ?)',
      [42, 'draft'],
    );

    return (int)$database->insertId();
  },
);
```

The callback receives the concrete driver when called on a concrete driver,
or the facade when called on `SQLDatabase`. Its original return value is
returned unchanged; `false`, `0`, `null`, and an empty array are normal values
and are committed.

A thrown callback exception rolls back exactly the callback's scope and is
rethrown unchanged when rollback succeeds. Begin, commit, and rollback
infrastructure failures use the public exception hierarchy under
`TimeFrontiers\Exceptions`.

## Nested scopes and rollback-only behavior

Nested `beginTransaction()` or `transaction()` calls create library-generated
savepoints. An inner rollback discards only the inner work and leaves a clean
parent usable:

```php
$database->transaction(function ($database): void {
  $database->execute('UPDATE orders SET status = ? WHERE id = ?', ['open', 1]);

  try {
    $database->transaction(function ($database): void {
      $database->execute(
        'INSERT INTO optional_events (order_id, type) VALUES (?, ?)',
        [1, 'opened'],
      );
      throw new RuntimeException('Discard the optional event.');
    });
  } catch (RuntimeException) {
    // The outer transaction may deliberately continue.
  }
});
```

If a package-managed statement fails inside a transaction, its current scope
becomes rollback-only. A later `commit()` rolls that scope back, records a
transaction error, and returns `false`. The callback API throws
`TransactionException`. Empty SELECT results and successful DML affecting zero
rows do not mark a scope rollback-only.

Calling `execute()` lets the package observe failures and affected rows. If a
caller obtains a native statement from `prepare()` and executes that native
object directly, the package cannot observe a later native execution failure.

## Locking and affected rows

Use database row locks inside a managed transaction:

```php
$updated = $database->transaction(function ($database): bool {
  $invoice = $database->fetchOne(
    'SELECT id, status, version FROM invoices WHERE id = ? FOR UPDATE',
    [123],
  );

  if (!$invoice) {
    return false;
  }

  $database->execute(
    'UPDATE invoices SET status = ?, version = version + 1 ' .
    'WHERE id = ? AND status = ? AND version = ?',
    ['paid', $invoice['id'], $invoice['status'], $invoice['version']],
  );

  return $database->affectedRows() === 1;
});
```

`affectedRows()` stores the result from the most recently completed statement.
Portable guarantees apply to INSERT, UPDATE, and DELETE; SELECT row counts vary
by driver. Rolling back does not change what the statement itself reported as
affected, although persisted state is restored.

## Driver diagnostics

```php
if (!$database->execute($sql, $params)) {
  $driverCode = $database->lastErrorCode();
  $sqlState = $database->lastSqlState();
}
```

These accessors allow callers to classify duplicate keys, deadlocks, and lock
timeouts without parsing localized messages. A later successful operation
clears the latest driver identity. `getErrors()` retains its existing
five-element entry format and historical collection behavior. Prepared bound
values are not included in new failure messages.

## Important transaction rules

- There is no automatic retry. Retry only at a higher layer after determining
  that the entire database-only operation is safe to repeat.
- A commit failure is an uncertain outcome. Do not rerun the business operation
  automatically; reconcile database state first.
- Keep HTTP, payment-provider, email, queue, and other external side effects
  outside transaction callbacks.
- Do not run DDL or database-specific implicit-commit statements inside managed
  business transactions. MySQL DDL cannot be made transactional by this API.
- `changeDB()` fails while a managed transaction is active.
- Closing a connection abandons an active transaction and clears managed state.
- Transaction state is local to one connection and cannot span connections.

## Tests

Unit tests do not require a database:

```bash
composer test-unit
```

Integration tests require a dedicated disposable MySQL or MariaDB database:

```text
TF_SQL_TEST_HOST
TF_SQL_TEST_PORT
TF_SQL_TEST_DATABASE
TF_SQL_TEST_USER
TF_SQL_TEST_PASSWORD
```

For safety, `TF_SQL_TEST_DATABASE` must contain `test`. The suite creates and
drops uniquely named InnoDB tables but never creates or drops the database.

```bash
composer test-mysqli
composer test-pdo
composer test-integration
composer test
```

Focused suites are also available through `composer test-nested`,
`composer test-rollback-only`, and `composer test-concurrency`.

## License

MIT License. See [LICENSE](LICENSE) for details.
