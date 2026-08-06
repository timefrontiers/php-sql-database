<?php

declare(strict_types=1);

namespace TimeFrontiers;

use TimeFrontiers\Contracts\TransactionalConnectionInterface;
use TimeFrontiers\Internal\ManagesTransactions;

/**
 * PDO Database manager with interface compatible with MySQLiDatabase.
 */
class PDODatabase implements TransactionalConnectionInterface
{
  use ManagesTransactions;

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
  protected int $_affected_rows = 0;
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
    if ($this->_pdo !== null) {
      $this->closeConnection();
    }

    $this->_resetTransactionState();
    $this->_affected_rows = 0;
    $this->_clearLastDriverFailure();

    try {
      $dsn = $this->_buildDsn();
      $this->_pdo = new \PDO(
        $dsn,
        $this->_username,
        $this->_password,
        $this->_getDefaultOptions()
      );
      $this->_pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
      return true;
    } catch (\PDOException $e) {
      $this->_capturePDOFailure(
        'openConnection',
        'Failed to connect to the database.',
        $e
      );
      $this->_pdo = null;
      return false;
    }
  }

  public function closeConnection(): void
  {
    if ($this->_pdo === null) {
      $this->_resetTransactionState();
      return;
    }

    $this->_closeManagedTransaction();
    $this->_pdo = null;
    $this->_affected_rows = 0;
    $this->_resetTransactionState();
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
    $result = $this->execute($sql, []);
    return $result instanceof \PDOStatement ? $result : false;
  }

  public function multiQuery(string $sql): bool
  {
    $this->_last_query = $sql;
    $this->_affected_rows = 0;
    $this->_clearLastDriverFailure();
    $this->_addError('multiQuery', 256, 'multiQuery is not supported in PDO.', __FILE__, __LINE__);
    $this->_markTransactionStatementFailure();
    return false;
  }

  public function confirmQuery($result): bool
  {
    if ($result === false) {
      if ($this->lastErrorCode() === null && $this->_pdo !== null) {
        $this->_capturePDOFailure('query', 'Database query failed.');
      }
      $this->_markTransactionStatementFailure();
      return false;
    }

    $this->_clearLastDriverFailure();
    return true;
  }

  // -------------------------------------------------------------------------
  // Prepared Statements
  // -------------------------------------------------------------------------

  public function prepare(string $sql): \PDOStatement|false
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
      $statement = $this->_pdo->prepare($sql);
    } catch (\PDOException $e) {
      $this->_capturePDOFailure('prepare', 'Failed to prepare the database statement.', $e);
      $this->_markTransactionStatementFailure();
      return false;
    }

    if ($statement === false) {
      $this->_capturePDOFailure('prepare', 'Failed to prepare the database statement.');
      $this->_markTransactionStatementFailure();
      return false;
    }

    return $statement;
  }

  public function execute(string $sql, array $params = []): \PDOStatement|bool
  {
    $this->_last_query = $sql;
    $this->_last_params = $params;
    $this->_affected_rows = 0;
    $this->_clearLastDriverFailure();

    if (!$this->checkConnection()) {
      $this->_addError('execute', 256, 'No active Database connection.', __FILE__, __LINE__);
      $this->_markTransactionStatementFailure();
      return false;
    }

    try {
      $statement = $this->_pdo->prepare($sql);
      if (!$statement) {
        $this->_capturePDOFailure('execute', 'Failed to prepare the database statement.');
        $this->_markTransactionStatementFailure();
        return false;
      }

      if (!$statement->execute($params)) {
        $this->_capturePDOFailure(
          'execute',
          'Prepared statement execution failed.',
          null,
          $statement
        );
        $this->_markTransactionStatementFailure();
        return false;
      }

      $this->_affected_rows = $statement->rowCount();
      $this->_clearLastDriverFailure();
      return $statement;
    } catch (\PDOException $e) {
      $this->_capturePDOFailure(
        'execute',
        'Prepared statement execution failed.',
        $e,
        isset($statement) && $statement instanceof \PDOStatement ? $statement : null
      );
      $this->_markTransactionStatementFailure();
      return false;
    }
  }

  public function fetchAll(string $sql, array $params = []): array|false
  {
    $statement = $this->execute($sql, $params);
    if (!$statement instanceof \PDOStatement) {
      return false;
    }
    return $statement->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function fetchOne(string $sql, array $params = []): array|false
  {
    $statement = $this->execute($sql, $params);
    if (!$statement instanceof \PDOStatement) {
      return false;
    }
    $row = $statement->fetch(\PDO::FETCH_ASSOC);
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
    return $this->_affected_rows;
  }

  public function lastQuery(): ?string
  {
    return $this->_last_query;
  }

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

    if (
      $this->_driver !== 'mysql' ||
      $db_name === '' ||
      $db_name === $this->_database ||
      !$this->checkConnection()
    ) {
      return false;
    }

    $quotedDatabase = \str_replace('`', '``', $db_name);

    try {
      $result = $this->_pdo->exec("USE `{$quotedDatabase}`");
    } catch (\PDOException $e) {
      $this->_capturePDOFailure('changeDB', 'Failed to change the current database.', $e);
      return false;
    }

    if ($result === false) {
      $this->_capturePDOFailure('changeDB', 'Failed to change the current database.');
      return false;
    }

    $this->_database = $db_name;
    return true;
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
      $success = $this->_pdo->beginTransaction();
    } catch (\PDOException $e) {
      $this->_capturePDOFailure('beginTransaction', 'Native transaction begin failed.', $e);
      return false;
    }

    if (!$success) {
      $this->_capturePDOFailure('beginTransaction', 'Native transaction begin failed.');
    }
    return $success;
  }

  protected function _nativeCommitTransaction(): bool
  {
    if (!$this->checkConnection()) {
      return false;
    }

    try {
      $success = $this->_pdo->commit();
    } catch (\PDOException $e) {
      $this->_capturePDOFailure('commit', 'Native transaction commit failed.', $e);
      return false;
    }

    if (!$success) {
      $this->_capturePDOFailure('commit', 'Native transaction commit failed.');
    }
    return $success;
  }

  protected function _nativeRollbackTransaction(): bool
  {
    if (!$this->checkConnection()) {
      return false;
    }

    try {
      $success = $this->_pdo->rollBack();
    } catch (\PDOException $e) {
      $this->_capturePDOFailure('rollBack', 'Native transaction rollback failed.', $e);
      return false;
    }

    if (!$success) {
      $this->_capturePDOFailure('rollBack', 'Native transaction rollback failed.');
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
    if (!$this->checkConnection()) {
      return false;
    }

    try {
      return $this->_pdo->inTransaction();
    } catch (\PDOException $e) {
      $this->_capturePDOFailure('inTransaction', 'Failed to inspect native transaction state.', $e);
      return null;
    }
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
      $result = $this->_pdo->exec($sql);
    } catch (\PDOException $e) {
      $this->_capturePDOFailure($context, $safeMessage, $e);
      return false;
    }

    if ($result === false) {
      $this->_capturePDOFailure($context, $safeMessage);
      return false;
    }

    return true;
  }

  private function _capturePDOFailure(
    string $context,
    string $safeMessage,
    ?\PDOException $exception = null,
    ?\PDOStatement $statement = null
  ): void {
    $errorInfo = $exception?->errorInfo;

    if (!\is_array($errorInfo) && $statement !== null) {
      $errorInfo = $statement->errorInfo();
    }
    if (!\is_array($errorInfo) && $this->_pdo !== null) {
      $errorInfo = $this->_pdo->errorInfo();
    }

    $sqlState = isset($errorInfo[0]) && \is_string($errorInfo[0]) && $errorInfo[0] !== '00000'
      ? $errorInfo[0]
      : null;
    $driverCode = $errorInfo[1] ?? $exception?->getCode();
    if ($driverCode === 0 || $driverCode === '00000') {
      $driverCode = null;
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
      $initCommandAttribute = \defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
        ? \constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
        : \constant('PDO::MYSQL_ATTR_INIT_COMMAND');
      $defaults[$initCommandAttribute] = 'SET NAMES utf8mb4';
    }

    return $this->_options + $defaults;
  }
}
