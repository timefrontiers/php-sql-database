<?php

declare(strict_types=1);

namespace TimeFrontiers;

/**
 * MySQLi Database manager.
 *
 * Maintains backward compatibility with the legacy MySQLDatabase class
 * while adding modern prepared statement support.
 */
class MySQLiDatabase {
  protected \mysqli|null $_connection = null;
  protected string $_db_server;
  protected string $_db_server_port = '3306';
  protected string $_db_user;
  protected string $_db_pass;
  protected string $_db_name;
  protected string|null $_last_query = null;
  protected array $_errors = [];

  /**
   * @param string $db_server
   * @param string $db_user
   * @param string $db_pass
   * @param string $db_name
   * @param bool   $new_conn
   * @param string|null $port
   */
  public function __construct(
    string $db_server,
    string $db_user,
    string $db_pass,
    string $db_name = '',
    bool $new_conn = false,
    ?string $port = '3306'
  ) {
    if (!$new_conn && $this->_connection) {
      $this->closeConnection();
    }
    $this->_db_server = $db_server;
    $this->_db_user   = $db_user;
    $this->_db_pass   = $db_pass;
    $this->_db_name   = $db_name;
    if (!empty($port)) {
      $this->_db_server_port = $port;
    }
    $this->openConnection();
  }

  // -------------------------------------------------------------------------
  // Connection Management
  // -------------------------------------------------------------------------

  /**
   * Opens database connection.
   */
  public function openConnection(): bool
  {
    try {
      $this->_connection = !empty($this->_db_name)
        ? new \mysqli(
          $this->_db_server,
          $this->_db_user,
          $this->_db_pass,
          $this->_db_name,
          (int)$this->_db_server_port
        )
        : new \mysqli(
          $this->_db_server,
          $this->_db_user,
          $this->_db_pass,
          null,
          (int)$this->_db_server_port
        );

      if ($this->_connection->connect_error) {
        $this->_addError('openConnection', 256, 'Failed to connect to database.', __FILE__, __LINE__);
        $this->_addError('openConnection', $this->_connection->connect_errno, $this->_connection->connect_error, __FILE__, __LINE__);
        return false;
      }

      unset($this->_errors['openConnection']);
      return true;
    } catch (\Throwable $th) {
      $this->_addError('openConnection', 256, "Db Connection Error: {$th->getMessage()}", __FILE__, __LINE__);
      return false;
    }
  }

  /**
   * Closes database connection.
   */
  public function closeConnection(): void
  {
    if ($this->_connection) {
      $this->_connection->close();
      $this->_connection = null;
    }
  }

  /**
   * Checks if connection is active.
   */
  public function checkConnection(): bool
  {
    return $this->_connection instanceof \mysqli;
  }

  /**
   * @deprecated Use checkConnection() instead.
   */
  public function connected(): bool
  {
    return $this->checkConnection();
  }

  /**
   * Returns the current database name.
   */
  public function getDatabase(): string
  {
    return $this->_db_name;
  }

  /**
   * @deprecated Use getDatabase() instead.
   */
  public function dbName(): string
  {
    return $this->getDatabase();
  }

  /**
   * Returns the server host.
   */
  public function getServer(): string
  {
    return $this->_db_server;
  }

  /**
   * Returns the username.
   */
  public function getUser(): string
  {
    return $this->_db_user;
  }

  // -------------------------------------------------------------------------
  // Query Execution (Legacy)
  // -------------------------------------------------------------------------

  /**
   * Executes a raw SQL query.
   *
   * @param string $sql
   * @return \mysqli_result|bool
   */
  public function query(string $sql): \mysqli_result|bool
  {
    if (!$this->checkConnection()) {
      $this->_addError('query', 256, 'No active Database connection.', __FILE__, __LINE__);
      return false;
    }

    $this->_last_query = $sql;

    try {
      $result = $this->_connection->query($sql);
    } catch (\Throwable $th) {
      $this->_addError('query', 256, $th->getMessage(), __FILE__, __LINE__);
      return false;
    }

    return $this->confirmQuery($result) ? $result : false;
  }

  /**
   * Executes multiple SQL statements.
   *
   * @param string $sql
   * @return bool
   */
  public function multiQuery(string $sql): bool
  {
    if (!$this->checkConnection()) {
      $this->_addError('multiQuery', 256, 'No active Database connection.', __FILE__, __LINE__);
      return false;
    }

    $this->_last_query = $sql;

    try {
      $result = $this->_connection->multi_query($sql);
      if ($result) {
        return true;
      }
    } catch (\Throwable $th) {
      $this->_addError('multiQuery', 256, "Multi-Query Error: {$th->getMessage()}", __FILE__, __LINE__);
    }

    $this->_addError('multiQuery', 256, 'Multi-Query failed!', __FILE__, __LINE__);
    if ($this->_connection->errno) {
      $this->_addError('multiQuery', 256, $this->_connection->error, __FILE__, __LINE__);
    }
    return false;
  }

  /**
   * Confirms a query was successful.
   *
   * @param mixed $result
   * @return bool
   */
  public function confirmQuery($result): bool
  {
    if (!$result) {
      $this->_addError('query', 256, 'Database query failed.', __FILE__, __LINE__);
      $this->_addError('query', 256, "Error: {$this->_connection->error}", __FILE__, __LINE__);
      $this->_addError('query', 256, "Last query: {$this->_last_query}", __FILE__, __LINE__);
      return false;
    }
    unset($this->_errors['query']);
    return true;
  }

  // -------------------------------------------------------------------------
  // Prepared Statements (New)
  // -------------------------------------------------------------------------

  /**
   * Prepares a SQL statement for execution.
   *
   * @param string $sql
   * @return \mysqli_stmt|false
   */
  public function prepare(string $sql): \mysqli_stmt|false
  {
    if (!$this->checkConnection()) {
      $this->_addError('prepare', 256, 'No active Database connection.', __FILE__, __LINE__);
      return false;
    }

    $this->_last_query = $sql;

    try {
      return $this->_connection->prepare($sql);
    } catch (\Throwable $th) {
      $this->_addError('prepare', 256, $th->getMessage(), __FILE__, __LINE__);
      return false;
    }
  }

  /**
   * Executes a prepared statement with parameters.
   *
   * @param string $sql    SQL with placeholders (?)
   * @param array  $params Parameters to bind
   * @return \mysqli_result|bool
   */
  public function execute(string $sql, array $params = []): \mysqli_result|bool
  {
    $stmt = $this->prepare($sql);
    if (!$stmt) {
      return false;
    }

    if (!empty($params)) {
      $types = '';
      foreach ($params as $param) {
        $types .= $this->_getParamType($param);
      }
      $stmt->bind_param($types, ...$params);
    }

    try {
      $stmt->execute();
      $result = $stmt->get_result();
      $stmt->close();
      return $result !== false ? $result : true;
    } catch (\Throwable $th) {
      $this->_addError('execute', 256, $th->getMessage(), __FILE__, __LINE__);
      return false;
    }
  }

  /**
   * Fetches all rows from a prepared query.
   *
   * @param string $sql
   * @param array  $params
   * @return array|false
   */
  public function fetchAll(string $sql, array $params = []): array|false
  {
    $result = $this->execute($sql, $params);
    if ($result instanceof \mysqli_result) {
      $rows = $result->fetch_all(MYSQLI_ASSOC);
      $result->free();
      return $rows;
    }
    return $result;
  }

  /**
   * Fetches a single row from a prepared query.
   *
   * @param string $sql
   * @param array  $params
   * @return array|false
   */
  public function fetchOne(string $sql, array $params = []): array|false
  {
    $result = $this->execute($sql, $params);
    if ($result instanceof \mysqli_result) {
      $row = $result->fetch_assoc();
      $result->free();
      return $row;
    }
    return $result;
  }

  // -------------------------------------------------------------------------
  // Result Set Helpers (Legacy)
  // -------------------------------------------------------------------------

  public function useResult()
  {
    return $this->_connection->use_result();
  }

  public function nextResult()
  {
    return $this->_connection->next_result();
  }

  public function moreResults(): bool
  {
    return $this->_connection->more_results();
  }

  public function fetchArray($result_set)
  {
    return $result_set->fetch_array();
  }

  public function fetchAssocArray($result_set)
  {
    return $result_set->fetch_assoc();
  }

  public function fetchAllLegacy($result_set)
  {
    return $result_set->fetch_all();
  }

  public function numRows($result_set): int|false
  {
    if ($result_set) {
      try {
        return $result_set->num_rows;
      } catch (\Exception $e) {
        $this->_addError('numRows', 256, $e->getMessage(), __FILE__, __LINE__);
      }
    }
    return false;
  }

  // -------------------------------------------------------------------------
  // Database Info
  // -------------------------------------------------------------------------

  public function insertId(): int|string
  {
    return $this->_connection->insert_id;
  }

  public function affectedRows(): int
  {
    return $this->_connection->affected_rows;
  }

  public function lastQuery(): ?string
  {
    return $this->_last_query;
  }

  /**
   * Changes the current database.
   *
   * @param string $db_name
   * @return bool
   */
  public function changeDB(string $db_name): bool
  {
    if ($db_name && $db_name !== $this->_db_name) {
      if (!$this->_connection->select_db($db_name)) {
        $err_arr = \error_get_last();
        if ($err_arr) {
          $this->_addError('changeDB', $err_arr['type'], $err_arr['message'], $err_arr['file'], $err_arr['line']);
        }
        return false;
      }
      $this->_db_name = $db_name;
      return true;
    }
    return false;
  }

  // -------------------------------------------------------------------------
  // Escaping (Deprecated - Use Prepared Statements)
  // -------------------------------------------------------------------------

  /**
   * @deprecated Use prepared statements instead.
   */
  public function escapeValue(string $value): string
  {
    \trigger_error('escapeValue() is deprecated. Use prepared statements instead.', \E_USER_DEPRECATED);
    return $this->_connection
      ? \mysqli_real_escape_string($this->_connection, $value)
      : \addslashes($value);
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

  private function _getParamType(mixed $value): string
  {
    return match (true) {
      \is_int($value) => 'i',
      \is_float($value) => 'd',
      default => 's',
    };
  }
}