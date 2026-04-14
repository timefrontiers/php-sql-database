<?php

declare(strict_types=1);

namespace TimeFrontiers;

/**
 * PDO Database manager with interface compatible with MySQLiDatabase.
 */
class PDODatabase {
  protected \PDO|null $_pdo = null;
  protected string $_driver;
  protected string $_host;
  protected int $_port;
  protected string $_database;
  protected string $_username;
  protected string $_password;
  protected array $_options;
  protected string|null $_last_query = null;
  protected array $_last_params = [];
  protected array $_errors = [];

  /**
   * @param string $driver   PDO driver (mysql, pgsql, sqlite)
   * @param string $host     Database host
   * @param int    $port     Port number
   * @param string $database Database name
   * @param string $username Username
   * @param string $password Password
   * @param array  $options  Additional PDO options
   */
  public function __construct(
    string $driver,
    string $host,
    int $port,
    string $database,
    string $username,
    string $password,
    array $options = []
  ) {
    $this->_driver = \strtolower($driver);
    $this->_host = $host;
    $this->_port = $port;
    $this->_database = $database;
    $this->_username = $username;
    $this->_password = $password;
    $this->_options = $options;

    $this->openConnection();
  }

  // -------------------------------------------------------------------------
  // Connection Management (Compatible Interface)
  // -------------------------------------------------------------------------

  public function openConnection(): bool
  {
    try {
      $dsn = $this->_buildDsn();
      $this->_pdo = new \PDO($dsn, $this->_username, $this->_password, $this->_getDefaultOptions());
      $this->_pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
      return true;
    } catch (\PDOException $e) {
      $this->_addError('openConnection', 256, $e->getMessage(), __FILE__, __LINE__);
      return false;
    }
  }

  public function closeConnection(): void
  {
    $this->_pdo = null;
  }

  public function checkConnection(): bool
  {
    return $this->_pdo !== null;
  }

  public function connected(): bool
  {
    return $this->checkConnection();
  }

  public function getDatabase(): string
  {
    return $this->_database;
  }

  public function dbName(): string
  {
    return $this->getDatabase();
  }

  public function getServer(): string
  {
    return $this->_host;
  }

  public function getUser(): string
  {
    return $this->_username;
  }

  // -------------------------------------------------------------------------
  // Query Execution
  // -------------------------------------------------------------------------

  public function query(string $sql): \PDOStatement|false
  {
    return $this->execute($sql, []);
  }

  public function multiQuery(string $sql): bool
  {
    $this->_addError('multiQuery', 256, 'multiQuery is not supported in PDO.', __FILE__, __LINE__);
    return false;
  }

  public function confirmQuery($result): bool
  {
    return $result !== false;
  }

  // -------------------------------------------------------------------------
  // Prepared Statements
  // -------------------------------------------------------------------------

  public function prepare(string $sql): \PDOStatement|false
  {
    if (!$this->checkConnection()) {
      $this->_addError('prepare', 256, 'No active Database connection.', __FILE__, __LINE__);
      return false;
    }

    $this->_last_query = $sql;

    try {
      return $this->_pdo->prepare($sql);
    } catch (\PDOException $e) {
      $this->_addError('prepare', 256, $e->getMessage(), __FILE__, __LINE__);
      return false;
    }
  }

  public function execute(string $sql, array $params = []): \PDOStatement|bool
  {
    if (!$this->checkConnection()) {
      $this->_addError('execute', 256, 'No active Database connection.', __FILE__, __LINE__);
      return false;
    }

    $this->_last_query = $sql;
    $this->_last_params = $params;

    try {
      $stmt = $this->_pdo->prepare($sql);
      if (!$stmt) {
        $this->_addError('execute', 256, 'Failed to prepare statement.', __FILE__, __LINE__);
        return false;
      }
      $stmt->execute($params);
      return $stmt;
    } catch (\PDOException $e) {
      $this->_addError('execute', 256, $e->getMessage(), __FILE__, __LINE__);
      return false;
    }
  }

  public function fetchAll(string $sql, array $params = []): array|false
  {
    $stmt = $this->execute($sql, $params);
    if (!$stmt) {
      return false;
    }
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function fetchOne(string $sql, array $params = []): array|false
  {
    $stmt = $this->execute($sql, $params);
    if (!$stmt) {
      return false;
    }
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row !== false ? $row : false;
  }

  // -------------------------------------------------------------------------
  // Legacy Result Methods (Not fully supported)
  // -------------------------------------------------------------------------

  public function fetchArray($result_set)
  {
    return $result_set->fetch();
  }

  public function fetchAssocArray($result_set)
  {
    return $result_set->fetch(\PDO::FETCH_ASSOC);
  }

  public function fetchAllLegacy($result_set)
  {
    return $result_set->fetchAll();
  }

  public function numRows($result_set): int
  {
    return $result_set->rowCount();
  }

  // -------------------------------------------------------------------------
  // Database Info
  // -------------------------------------------------------------------------

  public function insertId(): int|string
  {
    return $this->_pdo->lastInsertId();
  }

  public function affectedRows(): int
  {
    // PDO doesn't track this globally; use the statement's rowCount()
    return 0;
  }

  public function lastQuery(): ?string
  {
    return $this->_last_query;
  }

  public function changeDB(string $db_name): bool
  {
    if ($this->_driver === 'mysql') {
      $result = $this->execute("USE `{$db_name}`");
      if ($result) {
        $this->_database = $db_name;
        return true;
      }
    }
    return false;
  }

  // -------------------------------------------------------------------------
  // Escaping (Deprecated)
  // -------------------------------------------------------------------------

  public function escapeValue(string $value): string
  {
    \trigger_error('escapeValue() is deprecated. Use prepared statements instead.', \E_USER_DEPRECATED);
    return $this->_pdo->quote($value);
  }

  // -------------------------------------------------------------------------
  // Error Handling
  // -------------------------------------------------------------------------

  protected function _addError(
    string $context,
    int $code,
    string $message,
    string $file,
    int $line,
    int $min_rank = 0
  ): void {
    $this->_errors[$context][] = [
      $min_rank,
      $code,
      $message,
      $file,
      $line,
    ];
  }

  public function getErrors(): array
  {
    return $this->_errors;
  }

  // -------------------------------------------------------------------------
  // Private Helpers
  // -------------------------------------------------------------------------

  private function _buildDsn(): string
  {
    return match ($this->_driver) {
      'mysql' => "mysql:host={$this->_host};port={$this->_port};dbname={$this->_database};charset=utf8mb4",
      'pgsql' => "pgsql:host={$this->_host};port={$this->_port};dbname={$this->_database}",
      'sqlite' => "sqlite:{$this->_database}",
      default => throw new \InvalidArgumentException("Unsupported driver: {$this->_driver}")
    };
  }

  private function _getDefaultOptions(): array
  {
    $defaults = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if ($this->_driver === 'mysql') {
      $defaults[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
    }

    return $this->_options + $defaults;
  }
}