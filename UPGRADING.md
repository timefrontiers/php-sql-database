# Upgrading

## Upgrading from 1.x to 2.0.0

This transaction-safety upgrade is intended for the `2.0.0` release. No version
or tag is created by the source change itself.

## PHP requirement

Version 2.0.0 requires PHP 8.4 or later. Applications that must remain on an
older PHP runtime should continue using a compatible 1.x release until their
runtime and dependency graph are ready to upgrade.

## Preserved APIs

The following remain available with their existing signatures and general
return conventions:

- `SQLDatabase::__construct()`, `__call()`, and `getInstance()`;
- both concrete driver constructors;
- `query()`, `multiQuery()`, `confirmQuery()`, and `prepare()`;
- `execute()`, `fetchOne()`, and `fetchAll()`;
- `insertId()`, `affectedRows()`, and connection/database accessors; and
- the five-element entry shape returned by `getErrors()`.

Callers that do not use transactions do not need source changes.

## New transaction API

`MySQLiDatabase`, `PDODatabase`, and `SQLDatabase` now expose:

```php
beginTransaction(): bool
commit(): bool
rollBack(): bool
inTransaction(): bool
transactionDepth(): int
transaction(callable $callback): mixed
```

The concrete drivers implement
`TimeFrontiers\Contracts\TransactionalConnectionInterface`. The facade declares
the same methods explicitly while preserving the concrete union returned by
`getInstance()`.

The facade callback receives `SQLDatabase`; a direct-driver callback receives
that concrete driver.

## Callback exceptions

Manual transaction methods retain the package convention of recording an error
and returning `false`. The callback API throws infrastructure exceptions:

```text
DatabaseException
└── TransactionException
    ├── TransactionBeginException
    ├── TransactionCommitException
    └── TransactionRollbackException
```

If the application callback throws and rollback succeeds, its original
exception is rethrown unchanged. If rollback fails,
`TransactionRollbackException` preserves the application exception as
`previous`.

A `TransactionCommitException` represents a potentially uncertain database
outcome. Consumers must reconcile state before deciding whether any business
operation can be repeated.

## Nested scopes and failed statements

An outer scope uses the driver's native transaction API. Each nested scope uses
a generated savepoint. Inner rollback discards only inner work when the native
database successfully rolls back to and releases the savepoint.

Failed package-managed statements mark only their current scope rollback-only.
Committing that scope rolls it back and returns `false`; the callback form
throws `TransactionException`. A parent that failed remains rollback-only even
if later child work succeeds.

Use the package's `execute()` method when rollback-only observation is needed.
Execution performed directly on a native statement returned by `prepare()` is
outside the package and cannot update managed state.

## Affected rows

PDO `affectedRows()` no longer always returns zero. Both drivers now retain the
count from the latest completed statement. Portable expectations apply to
INSERT, UPDATE, and DELETE, including guarded updates that legitimately affect
zero rows. SELECT row-count behavior remains driver-dependent.

Rollback restores persisted data but does not rewrite the count reported by
the completed statement.

## Diagnostics

Both drivers and the facade add:

```php
lastErrorCode(): int|string|null
lastSqlState(): ?string
```

These values are also available on the new database exceptions. New prepared
statement error messages use safe operational text and do not copy bound values
from native errors. The historical `getErrors()` array shape is unchanged.

## PDO facade construction

The previous README class-name example could not supply a PDO database name.
Use the new factory:

```php
$database = SQLDatabase::pdo(
  driver: 'mysql',
  host: '127.0.0.1',
  port: 3306,
  database: 'app',
  user: 'app',
  password: 'secret',
);
```

`SQLDatabase::fromConnection()` can wrap an already-created concrete driver.
The old constructor is retained.

## Database support and operational limits

- MySQLi and PDO-MySQL have equivalent real-database behavioral suites.
- PDO savepoint SQL is compatible with the package's documented MySQL,
  PostgreSQL, and SQLite targets, but release acceptance requires the supplied
  MySQL/MariaDB parity suites.
- Savepoint availability is checked at runtime; nesting never silently falls
  back to the outer transaction.
- MySQLi has no portable native transaction-state inspector, so its
  `inTransaction()` result is managed state. PDO state is checked against
  `PDO::inTransaction()`.
- Native statements executed outside the package and raw transaction-control
  SQL can bypass managed-state observation.
- DDL and implicit-commit statements must not run in managed business
  transactions.
- There is no automatic retry.
- External side effects must remain outside transaction callbacks.

## Recommended versioning

After the change is accepted, `2.0.0` is the recommended next version. Do not
update consumer constraints to a mutable branch; use the accepted commit during
controlled development and the `^2.0` constraint after release.
