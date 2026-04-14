# TimeFrontiers PHP SQL Database

A flexible SQL database manager supporting MySQLi (default) and PDO with a unified interface.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## Features

- **Backward compatible** – drop-in replacement for the legacy `MySQLDatabase` class.
- **Dual backend support** – MySQLi (default) or PDO via factory pattern.
- **Prepared statements** – both `MySQLiDatabase` and `PDODatabase` support secure parameterized queries.
- **Unified API** – same method names across both drivers.
- **Error collection** – consistent with other TimeFrontiers packages.

## Installation

```bash
composer require timefrontiers/php-sql-database
```

## Requirements

- PHP 8.1 or higher
- `ext-mysqli` (always required)
- `ext-pdo` + driver (optional, for PDO support)

## Basic Usage

### Default: MySQLiDatabase

```php
use TimeFrontiers\SQLDatabase;

// This uses MySQLiDatabase internally (backward compatible)
$db = new SQLDatabase('localhost', 'root', 'secret', 'my_database');
```

### Using PDO Instead

```php
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\PDODatabase;

// Pass the PDO class name as the fourth parameter
$db = new SQLDatabase('localhost', 'root', 'secret', PDODatabase::class, driver: 'mysql');
```

### Executing Queries (Legacy Style)

```php
$result = $db->query("SELECT * FROM users WHERE id = 1");
while ($row = $db->fetchAssocArray($result)) {
  // ...
}
```

### Using Prepared Statements (Recommended)

```php
// Fetch all rows
$users = $db->fetchAll("SELECT * FROM users WHERE status = ?", ['active']);

// Fetch single row
$user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [5]);

// Execute INSERT/UPDATE
$db->execute("UPDATE users SET name = ? WHERE id = ?", ['John', 5]);
$newId = $db->insertId();
```

## License

MIT License. See [LICENSE](LICENSE) for details.
```