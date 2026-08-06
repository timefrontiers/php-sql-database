# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

This change set is planned for the `2.0.0` release.

### Breaking changes

- The minimum supported PHP version is now 8.4. This deliberate platform
  requirement change gives existing 1.x consumers room to upgrade separately.

### Added

- Common `TransactionalConnectionInterface` implemented by both concrete
  drivers.
- Explicit manual and callback transaction APIs on both drivers and the facade.
- Savepoint-backed nested transaction scopes with ownership validation.
- Rollback-only protection after failed package-managed statements.
- Begin-, commit-, and rollback-specific transaction exceptions carrying safe
  driver code and SQLSTATE context.
- `lastErrorCode()` and `lastSqlState()` driver diagnostics.
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
