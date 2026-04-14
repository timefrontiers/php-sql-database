<?php

declare(strict_types=1);

namespace TimeFrontiers;

/**
 * SQL Database Factory.
 *
 * Returns either MySQLiDatabase (default) or PDODatabase based on parameters.
 */
class SQLDatabase {
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
      // Use provided instance directly
      $this->_instance = $database_or_class;
    } elseif (
      \is_string($database_or_class) &&
      \class_exists($database_or_class) &&
      \is_subclass_of($database_or_class, PDODatabase::class)
    ) {
      // Instantiate PDO class with remaining parameters
      $this->_instance = new $database_or_class(
        $driver,
        $server,
        (int)$port,
        $database_or_class instanceof PDODatabase ? '' : '', // not used when class name
        $user,
        $pass,
        $options
      );
    } elseif (
      \is_string($database_or_class) &&
      \class_exists($database_or_class) &&
      \is_subclass_of($database_or_class, MySQLiDatabase::class)
    ) {
      // Instantiate MySQLi class
      $this->_instance = new $database_or_class(
        $server,
        $user,
        $pass,
        '', // database name will be set via changeDB or passed differently
        $new_conn,
        $port
      );
    } else {
      // Default: MySQLiDatabase with database name as string
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

  // Proxy all method calls to the underlying instance
  public function __call(string $method, array $arguments): mixed {
    return $this->_instance->$method(...$arguments);
  }

  // Expose the underlying instance if needed
  public function getInstance(): MySQLiDatabase|PDODatabase  {
    return $this->_instance;
  }

  // Delegate error retrieval
  public function getErrors(): array  {
    return $this->_instance->getErrors();
  }
}