# Changelog

All notable changes to this project are documented in this file.

## [1.1.1] - 2026-08-12

Documentation corrections and one additive contract change, following a review
of the `1.1.0` transaction release. No behavioural change to the drivers.

### Platform requirement

- The minimum supported PHP version is now **8.5**, raised from the `>=8.4`
  declared by `1.1.0`, completing the move to the first-party 8.5 platform
  baseline. This is a requirement change rather than an API change: Composer
  evaluates it during resolution, so a project on an older runtime is not
  broken — it is simply not offered `1.1.1` and stays on the version it can
  satisfy. Upgrade the runtime first, then resolve `^1.1`.

### Added

- `lastErrorCode()` and `lastSqlState()` are now declared on
  `TransactionalConnectionInterface`, so consumers that type-hint the contract
  rather than a concrete driver can classify duplicate keys, deadlocks, and
  lock timeouts without widening to the concrete union.

  Every implementation shipped in this package already provided both methods, so
  no first-party code changes. Any **external** implementation of the interface
  must add them.

### Documentation

- `UPGRADING.md` and `README.md` previously stated that failed statements mark
  only their current scope rollback-only, without noting that transaction
  *infrastructure* failures mark every open scope. Both now document the
  distinction, including that a failed nested `SAVEPOINT` rolls back an
  otherwise clean parent.
- Both documents now describe the state left behind when `transaction()` raises
  `TransactionException` for scope-ownership loss: a leaked scope leaves the
  connection inside a transaction with nothing closed, while a callback that
  already committed the wrapper's scope leaves that work durable — the exception
  does not mean the operation had no effect.
- `CHANGELOG.md` and `UPGRADING.md` described this work as a `2.0.0` release.
  It ships within the coordinated `1.1.x` generation; `2.0` remains reserved for
  a later deliberate platform shift.

## [1.1.0] - 2026-08-06

The hardened transaction contract for the coordinated `1.1.x` project
generation.

The change set is additive: every public method, constructor, return
convention, and error-array shape from `1.0.x` is preserved.

### Platform requirement

- The minimum supported PHP version is `>=8.4`, raised from `>=8.1`.
  Superseded by `1.1.1`, which requires 8.5.

### Added

- Common `TransactionalConnectionInterface` implemented by both concrete
  drivers.
- Explicit manual and callback transaction APIs on both drivers and the facade.
- Savepoint-backed nested transaction scopes with ownership validation.
- Rollback-only protection after failed package-managed statements. A failed
  statement marks its own scope; a failure of the transaction machinery itself
  marks every open scope.
- Begin-, commit-, and rollback-specific transaction exceptions carrying safe
  driver code and SQLSTATE context.
- `lastErrorCode()` and `lastSqlState()` driver diagnostics on both concrete
  drivers and the facade. (Added to `TransactionalConnectionInterface` in
  `1.1.1`.)
- `SQLDatabase::pdo()` and `SQLDatabase::fromConnection()` factories.
- Unit, MySQLi, PDO-MySQL, facade, rollback-only, nesting, lifecycle, and
  concurrency test suites.

### Changed

- PDO now retains prepared DML `rowCount()` for `affectedRows()`.
- MySQLi now captures statement affected rows before closing the statement.
- MySQLi statements are closed through `finally` on all execution paths.
- Closing or reopening a connection resets managed transaction state.
- `changeDB()` now fails during an active managed transaction.
- Prepared-statement failure messages no longer include native messages that
  may reveal bound values.
- The README now uses a PDO construction path that includes the database name.

### Compatibility

- Existing constructors, query methods, facade magic forwarding,
  `getInstance()` return type, and `getErrors()` entry shape are preserved.
- No automatic transaction or deadlock retry has been added.
