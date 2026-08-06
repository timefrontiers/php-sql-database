<?php

declare(strict_types=1);

namespace TimeFrontiers;

use TimeFrontiers\Contracts\TransactionalConnectionInterface;
use TimeFrontiers\Internal\ManagesTransactions;

/**
 * MySQLi Database manager.
 *
 * Maintains backward compatibility with the legacy MySQLDatabase class
 * while adding modern prepared statement and transaction support.
 */
class MySQLiDatabase implements TransactionalConnectionInterface
{
  use ManagesTransactions;

  protected \mysqli|null $_connection = null;
  protected string $_db_server;
  protected string $_db_server_port = '3306';
  protected string $_db_user;
  protected string $_db_pass;
  protected string $_db_name;
  protected string|null $_last_query = null;
  protected int $_affected_rows = 0;
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
    if ($this->_connection !== null) {
      $this->closeConnection();
    }

    $this->_resetTransactionState();
    $this->_affected_rows = 0;
    $this->_clearLastDriverFailure();

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
        $this->_captureMySQLiFailure(
          'openConnection',
          'Failed to connect to the database.'
        );
        $this->_connection = null;
        return false;
      }

      unset($this->_errors['openConnection']);
      return true;
    } catch (\mysqli_sql_exception $e) {
      $this->_captureMySQLiFailure(
        'openConnection',
        'Failed to connect to the database.',
        $e
      );
      $this->_connection = null;
      return false;
    } catch (\Throwable $e) {
      $this->_captureMySQLiFailure(
        'openConnection',
        'Failed to initialize the database connection.',
        $e
      );
      $this->_connection = null;
      return false;
    }
  }

  /**
   * Closes database connection, abandoning any active transaction.
   */
  public function closeConnection(): void
  {
    if ($this->_connection === null) {
      $this->_resetTransactionState();
      return;
    }

    $this->_closeManagedTransaction();

    try {
      $this->_connection->close();
    } catch (\Throwable $e) {
      $this->_captureMySQLiFailure(
        'closeConnection',
        'Failed to close the database connection cleanly.',
        $e
      );
    } finally {
      $this->_connection = null;
      $this->_affected_rows = 0;
      $this->_resetTransactionState();
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
    $this->_last_query = $sql;
    $this->_affected_rows = 0;
    $this->_clearLastDriverFailure();

    if (!$this->checkConnection()) {
      $this->_addError('query', 256, 'No active Database connection.', __FILE__, __LINE__);
      $this->_markTransactionStatementFailure();
      return false;
    }

    try {
      $result = $this->_connection->query($sql);
    } catch (\mysqli_sql_exception $e) {
      $this->_captureMySQLiFailure('query', 'Database query failed.', $e);
      $this->_markTransactionStatementFailure();
      return false;
    } catch (\Throwable $e) {
      $this->_captureMySQLiFailure('query', 'Database query failed.', $e);
      $this->_markTransactionStatementFailure();
      return false;
    }

    if ($result === false) {
      $this->_captureMySQLiFailure('query', 'Database query failed.');
      $this->_markTransactionStatementFailure();
      return false;
    }

    $this->_affected_rows = $this->_connection->affected_rows;
    unset($this->_errors['query']);
    return $result;
  }

  /**
   * Executes multiple SQL statements.
   */
  public function multiQuery(string $sql): bool
  {
    $this->_last_query = $sql;
    $this->_affected_rows = 0;
    $this->_clearLastDriverFailure();

    if (!$this->checkConnection()) {
      $this->_addError('multiQuery', 256, 'No active Database connection.', __FILE__, __LINE__);
      $this->_markTransactionStatementFailure();
      return false;
    }

    try {
      $result = $this->_connection->multi_query($sql);
    } catch (\mysqli_sql_exception $e) {
      $this->_captureMySQLiFailure('multiQuery', 'Multi-query execution failed.', $e);
      $this->_markTransactionStatementFailure();
      return false;
    } catch (\Throwable $e) {
      $this->_captureMySQLiFailure('multiQuery', 'Multi-query execution failed.', $e);
      $this->_markTransactionStatementFailure();
      return false;
    }

    if (!$result) {
      $this->_captureMySQLiFailure('multiQuery', 'Multi-query execution failed.');
      $this->_markTransactionStatementFailure();
      return false;
    }

    $this->_affected_rows = $this->_connection->affected_rows;
    return true;
  }

  /**
   * Confirms a query was successful.
   *
   * @param mixed $result
   */
  public function confirmQuery($result): bool
  {
    if (!$result) {
      $this->_captureMySQLiFailure('query', 'Database query failed.');
      $this->_markTransactionStatementFailure();
      return false;
    }

    $this->_clearLastDriverFailure();
    unset($this->_errors['query']);
    return true;
  }

  // -------------------------------------------------------------------------
  // Prepared Statements
  // -------------------------------------------------------------------------

  /**
   * Prepares a SQL statement for execution.
   *
   * @return \mysqli_stmt|false
   */
  public function prepare(string $sql): \mysqli_stmt|false
  {
    $this->_last_query = $sql;
    $this->_affected_rows = 0;
    $this->_clearLastDriverFailure();

    if (!$this->checkConnection()) {
      $this->_addError('prepare', 256, 'No active Database connection.', __FILE__, __LINE__);
      $this->_markTransactionStatementFailure();
      return false;
    }

    try {
      $statement = $this->_connection->prepare($sql);
    } catch (\mysqli_sql_exception $e) {
      $this->_captureMySQLiFailure('prepare', 'Failed to prepare the database statement.', $e);
      $this->_markTransactionStatementFailure();
      return false;
    } catch (\Throwable $e) {
      $this->_captureMySQLiFailure('prepare', 'Failed to prepare the database statement.', $e);
      $this->_markTransactionStatementFailure();
      return false;
    }

    if ($statement === false) {
      $this->_captureMySQLiFailure('prepare', 'Failed to prepare the database statement.');
      $this->_markTransactionStatementFailure();
      return false;
    }

    return $statement;
  }

  /**
   * Executes a prepared statement with parameters.
   *
   * @param array $params Parameters to bind
   * @return \mysqli_result|bool
   */
  public function execute(string $sql, array $params = []): \mysqli_result|bool
  {
    $this->_affected_rows = 0;
    $statement = $this->prepare($sql);
    if (!$statement) {
      return false;
    }

    try {
      if ($params !== []) {
        $types = '';
        foreach ($params as $param) {
          $types .= $this->_getParamType($param);
        }

        if (!$statement->bind_param($types, ...$params)) {
          $this->_captureMySQLiFailure(
            'execute',
            'Failed to bind the prepared statement parameters.',
            null,
            $statement
          );
          $this->_markTransactionStatementFailure();
          return false;
        }
      }

      if (!$statement->execute()) {
        $this->_captureMySQLiFailure(
          'execute',
          'Prepared statement execution failed.',
          null,
          $statement
        );
        $this->_markTransactionStatementFailure();
        return false;
      }

      $this->_affected_rows = $statement->affected_rows;
      $result = $statement->get_result();
      $this->_clearLastDriverFailure();
      return $result !== false ? $result : true;
    } catch (\mysqli_sql_exception $e) {
      $this->_captureMySQLiFailure(
        'execute',
        'Prepared statement execution failed.',
        $e,
        $statement
      );
      $this->_markTransactionStatementFailure();
      return false;
    } catch (\Throwable $e) {
      $this->_captureMySQLiFailure(
        'execute',
        'Prepared statement execution failed.',
        $e,
        $statement
      );
      $this->_markTransactionStatementFailure();
      return false;
    } finally {
      $statement->close();
    }
  }

  /**
   * Fetches all rows from a prepared query.
   *
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
   * @return array|false
   */
  public function fetchOne(string $sql, array $params = []): array|false
  {
    $result = $this->execute($sql, $params);
    if ($result instanceof \mysqli_result) {
      $row = $result->fetch_assoc();
      $result->free();
      return $row ?? false;
    }
    return false;
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
    return $this->_affected_rows;
  }

  public function lastQuery(): ?string
  {
    return $this->_last_query;
  }

  /**
   * Changes the current database unless a managed transaction is active.
   */
  public function changeDB(string $db_name): bool
  {
    $this->_affected_rows = 0;
    $this->_clearLastDriverFailure();

    if ($this->inTransaction()) {
      $this->_addError(
        'changeDB',
        256,
        'Cannot change the database during an active transaction.',
        __FILE__,
        __LINE__
      );
      return false;
    }

    if (!$db_name || $db_name === $this->_db_name || !$this->checkConnection()) {
      return false;
    }

    try {
      $success = $this->_connection->select_db($db_name);
    } catch (\mysqli_sql_exception $e) {
      $this->_captureMySQLiFailure('changeDB', 'Failed to change the current database.', $e);
      return false;
    } catch (\Throwable $e) {
      $this->_captureMySQLiFailure('changeDB', 'Failed to change the current database.', $e);
      return false;
    }

    if (!$success) {
      $this->_captureMySQLiFailure('changeDB', 'Failed to change the current database.');
      return false;
    }

    $this->_db_name = $db_name;
    return true;
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
  // Native Transaction Adapter
  // -------------------------------------------------------------------------

  protected function _transactionConnectionAvailable(): bool
  {
    return $this->checkConnection();
  }

  protected function _nativeBeginTransaction(): bool
  {
    if (!$this->checkConnection()) {
      return false;
    }

    try {
      $success = $this->_connection->begin_transaction();
    } catch (\mysqli_sql_exception $e) {
      $this->_captureMySQLiFailure('beginTransaction', 'Native transaction begin failed.', $e);
      return false;
    } catch (\Throwable $e) {
      $this->_captureMySQLiFailure('beginTransaction', 'Native transaction begin failed.', $e);
      return false;
    }

    if (!$success) {
      $this->_captureMySQLiFailure('beginTransaction', 'Native transaction begin failed.');
    }
    return $success;
  }

  protected function _nativeCommitTransaction(): bool
  {
    if (!$this->checkConnection()) {
      return false;
    }

    try {
      $success = $this->_connection->commit();
    } catch (\mysqli_sql_exception $e) {
      $this->_captureMySQLiFailure('commit', 'Native transaction commit failed.', $e);
      return false;
    } catch (\Throwable $e) {
      $this->_captureMySQLiFailure('commit', 'Native transaction commit failed.', $e);
      return false;
    }

    if (!$success) {
      $this->_captureMySQLiFailure('commit', 'Native transaction commit failed.');
    }
    return $success;
  }

  protected function _nativeRollbackTransaction(): bool
  {
    if (!$this->checkConnection()) {
      return false;
    }

    try {
      $success = $this->_connection->rollback();
    } catch (\mysqli_sql_exception $e) {
      $this->_captureMySQLiFailure('rollBack', 'Native transaction rollback failed.', $e);
      return false;
    } catch (\Throwable $e) {
      $this->_captureMySQLiFailure('rollBack', 'Native transaction rollback failed.', $e);
      return false;
    }

    if (!$success) {
      $this->_captureMySQLiFailure('rollBack', 'Native transaction rollback failed.');
    }
    return $success;
  }

  protected function _nativeCreateSavepoint(string $savepoint): bool
  {
    return $this->_executeTransactionControl(
      "SAVEPOINT {$savepoint}",
      'beginTransaction',
      'Failed to create the transaction savepoint.'
    );
  }

  protected function _nativeReleaseSavepoint(string $savepoint): bool
  {
    return $this->_executeTransactionControl(
      "RELEASE SAVEPOINT {$savepoint}",
      'commit',
      'Failed to release the transaction savepoint.'
    );
  }

  protected function _nativeRollbackToSavepoint(string $savepoint): bool
  {
    return $this->_executeTransactionControl(
      "ROLLBACK TO SAVEPOINT {$savepoint}",
      'rollBack',
      'Failed to roll back to the transaction savepoint.'
    );
  }

  protected function _nativeTransactionState(): ?bool
  {
    // MySQLi does not expose a portable native in-transaction state API.
    return null;
  }

  // -------------------------------------------------------------------------
  // Private Helpers
  // -------------------------------------------------------------------------

  private function _executeTransactionControl(
    string $sql,
    string $context,
    string $safeMessage
  ): bool {
    if (!$this->checkConnection()) {
      return false;
    }

    try {
      $success = $this->_connection->query($sql);
    } catch (\mysqli_sql_exception $e) {
      $this->_captureMySQLiFailure($context, $safeMessage, $e);
      return false;
    } catch (\Throwable $e) {
      $this->_captureMySQLiFailure($context, $safeMessage, $e);
      return false;
    }

    if ($success === false) {
      $this->_captureMySQLiFailure($context, $safeMessage);
      return false;
    }

    return true;
  }

  private function _captureMySQLiFailure(
    string $context,
    string $safeMessage,
    ?\Throwable $exception = null,
    ?\mysqli_stmt $statement = null
  ): void {
    $driverCode = null;
    $sqlState = null;

    if ($exception !== null) {
      $driverCode = $exception->getCode() !== 0 ? $exception->getCode() : null;
      if (\method_exists($exception, 'getSqlState')) {
        $sqlState = $exception->getSqlState();
      }
    }

    if ($statement !== null) {
      $driverCode ??= $statement->errno !== 0 ? $statement->errno : null;
      $sqlState ??= $statement->sqlstate ?: null;
    }

    if ($this->_connection !== null) {
      $driverCode ??= $this->_connection->errno !== 0 ? $this->_connection->errno : null;
      $sqlState ??= $this->_connection->sqlstate ?: null;
    }

    $this->_setLastDriverFailure($driverCode, $sqlState);
    $this->_addError(
      $context,
      \is_int($driverCode) && $driverCode !== 0 ? $driverCode : 256,
      $safeMessage,
      __FILE__,
      __LINE__
    );
  }

  private function _getParamType(mixed $value): string
  {
    return match (true) {
      \is_int($value) => 'i',
      \is_float($value) => 'd',
      default => 's',
    };
  }
}
