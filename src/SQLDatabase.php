<?php

declare(strict_types=1);

namespace TimeFrontiers;

use TimeFrontiers\Contracts\TransactionalConnectionInterface;

/**
 * SQL Database Factory.
 *
 * Returns either MySQLiDatabase (default) or PDODatabase based on parameters.
 */
class SQLDatabase implements TransactionalConnectionInterface
{
  private MySQLiDatabase|PDODatabase $_instance;

  /**
   * @param string               $server            Database server host
   * @param string               $user              Database username
   * @param string               $pass              Database password
   * @param string|object|string $database_or_class Database name, class name, or instance
   * @param bool                 $new_conn          For MySQLi: force new connection
   * @param string|null          $port              For MySQLi: port number
   * @param string               $driver            For PDO: driver name (mysql, pgsql, etc.)
   * @param array                $options           For PDO: additional options
   */
  public function __construct(
    string $server,
    string $user,
    string $pass,
    string|object $database_or_class = '',
    bool $new_conn = false,
    ?string $port = '3306',
    string $driver = 'mysql',
    array $options = []
  ) {
    if (\is_object($database_or_class)) {
      if (!$database_or_class instanceof MySQLiDatabase && !$database_or_class instanceof PDODatabase) {
        throw new \InvalidArgumentException(
          'The provided database instance must be MySQLiDatabase or PDODatabase.'
        );
      }
      $this->_instance = $database_or_class;
    } elseif (
      \is_string($database_or_class) &&
      \class_exists($database_or_class) &&
      \is_a($database_or_class, PDODatabase::class, true)
    ) {
      $this->_instance = new $database_or_class(
        $driver,
        $server,
        (int)$port,
        '',
        $user,
        $pass,
        $options
      );
    } elseif (
      \is_string($database_or_class) &&
      \class_exists($database_or_class) &&
      \is_a($database_or_class, MySQLiDatabase::class, true)
    ) {
      $this->_instance = new $database_or_class(
        $server,
        $user,
        $pass,
        '', // database name will be set via changeDB or passed differently
        $new_conn,
        $port
      );
    } else {
      $db_name = \is_string($database_or_class) ? $database_or_class : '';
      $this->_instance = new MySQLiDatabase(
        $server,
        $user,
        $pass,
        $db_name,
        $new_conn,
        $port
      );
    }
  }

  /**
   * Creates a facade backed by PDO with an explicit database name.
   */
  public static function pdo(
    string $driver,
    string $host,
    int $port,
    string $database,
    string $user,
    string $password,
    array $options = []
  ): self {
    return self::fromConnection(new PDODatabase(
      $driver,
      $host,
      $port,
      $database,
      $user,
      $password,
      $options
    ));
  }

  /**
   * Creates a facade around an existing supported connection.
   */
  public static function fromConnection(MySQLiDatabase|PDODatabase $connection): self
  {
    return new self('', '', '', $connection);
  }

  public function beginTransaction(): bool
  {
    return $this->_instance->beginTransaction();
  }

  public function commit(): bool
  {
    return $this->_instance->commit();
  }

  public function rollBack(): bool
  {
    return $this->_instance->rollBack();
  }

  public function inTransaction(): bool
  {
    return $this->_instance->inTransaction();
  }

  public function transactionDepth(): int
  {
    return $this->_instance->transactionDepth();
  }

  public function transaction(callable $callback): mixed
  {
    return $this->_instance->transaction(
      function (MySQLiDatabase|PDODatabase $connection) use ($callback): mixed {
        return $callback($this);
      }
    );
  }

  public function lastErrorCode(): int|string|null
  {
    return $this->_instance->lastErrorCode();
  }

  public function lastSqlState(): ?string
  {
    return $this->_instance->lastSqlState();
  }

  public function affectedRows(): int
  {
    return $this->_instance->affectedRows();
  }

  public function __call(string $method, array $arguments): mixed
  {
    return $this->_instance->$method(...$arguments);
  }

  public function getInstance(): MySQLiDatabase|PDODatabase
  {
    return $this->_instance;
  }

  public function getErrors(): array
  {
    return $this->_instance->getErrors();
  }
}
