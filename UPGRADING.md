# Upgrading

## Upgrading from 1.0.x to 1.1.x

This transaction-safety upgrade ships within the coordinated `1.1.x` project
generation. `2.0` is reserved for a later deliberate platform shift.

The change is additive. Callers that do not use transactions need no source
changes; the only requirement to clear is the PHP runtime.

Target `1.1.1` or later. `1.1.0` carries the same driver behaviour, but its
documentation of rollback-only propagation was incorrect and its
`TransactionalConnectionInterface` does not declare the diagnostic accessors.

## PHP requirement

`1.1.1` requires PHP 8.5 or later. The floor moved in two steps — `>=8.1` in
`1.0.x`, `>=8.4` in `1.1.0`, `>=8.5` from `1.1.1` — completing the move to the
first-party 8.5 platform baseline.

This is a platform requirement, not an API break. Composer evaluates it during
resolution, so a project on an older runtime is not broken by it — it simply
will not be offered a release it cannot satisfy, and stays on the newest version
it can. Upgrade the runtime first, then resolve `^1.1`.

## Upgrading from 1.1.0 to 1.1.1

No driver behaviour changed. Two things to be aware of:

- the PHP floor moved from `>=8.4` to `>=8.5`; and
- `TransactionalConnectionInterface` now declares `lastErrorCode()` and
  `lastSqlState()`. Every implementation shipped in this package already had
  them, so no first-party code changes — but if your application implements that
  interface itself, add both methods before upgrading.

Re-read "Nested scopes and failed statements" below. The `1.1.0` documentation
understated how far rollback-only propagates, and code written against it may
have assumed a failed nested scope was recoverable.

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

### Callbacks that change their own scope

`transaction()` validates that it still owns exactly the scope it opened,
identified by scope identity rather than depth alone. If a callback manipulates
the transaction directly and breaks that ownership, the wrapper raises
`TransactionException` instead of guessing which scope to close — and does not
roll anything back. The resulting state depends on what the callback did:

| Callback behaviour | State when the exception is thrown |
| --- | --- |
| Left an extra scope open | Still inside a transaction, at the deeper depth; nothing closed. The caller must roll back or close the connection. |
| Already committed the wrapper's scope | Depth is back to its starting value and **the work is committed and durable**. |

The second row is the important one: this `TransactionException` does not imply
the operation had no effect. Like `TransactionCommitException`, treat it as an
uncertain outcome and reconcile before retrying anything.

## Nested scopes and failed statements

An outer scope uses the driver's native transaction API. Each nested scope uses
a generated savepoint. Inner rollback discards only inner work when the native
database successfully rolls back to and releases the savepoint.

Failed package-managed **statements** mark only their current scope
rollback-only. Committing that scope rolls it back and returns `false`; the
callback form throws `TransactionException`. A parent that failed remains
rollback-only even if later child work succeeds.

Transaction **infrastructure** failures propagate further. When a begin,
savepoint, commit, release, or rollback operation itself fails, every currently
open scope is marked rollback-only, because the nesting contract can no longer
be honoured and no scope can be proven safe to commit. A failed nested
`SAVEPOINT` therefore also poisons an otherwise clean parent: the parent's
`commit()` will roll its work back and return `false`.

This is deliberate fail-closed behaviour, but it means a savepoint failure is
not recoverable by catching it and continuing the outer scope. Treat any
`false` from `beginTransaction()` inside an existing transaction as fatal to
the whole operation.

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

Both are declared on `TransactionalConnectionInterface`, so code that type-hints
the contract rather than a concrete driver can classify duplicate keys,
deadlocks, and lock timeouts without widening to the concrete union. Any
external implementation of that interface must now provide them.

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

## Consumer constraint

Require `timefrontiers/php-sql-database: ^1.1` and verify the resolved version
and reference in the consuming application after Composer resolution. Do not
point a consumer at a mutable branch, and do not edit installed lock metadata by
hand — raise the floor through a documented package release instead.

Subsequent corrections on this line ship as `1.1.x`.
