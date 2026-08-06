<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Support;

final class IntegrationConfig
{
  private function __construct(
    public string $host,
    public int $port,
    public string $database,
    public string $user,
    public string $password
  ) {
  }

  public static function fromEnvironment(): self
  {
    $host = \getenv('TF_SQL_TEST_HOST');
    $port = \getenv('TF_SQL_TEST_PORT');
    $database = \getenv('TF_SQL_TEST_DATABASE');
    $user = \getenv('TF_SQL_TEST_USER');
    $password = \getenv('TF_SQL_TEST_PASSWORD');

    if (
      $host === false || $host === '' ||
      $port === false || $port === '' ||
      $database === false || $database === '' ||
      $user === false || $user === '' ||
      $password === false
    ) {
      throw new \RuntimeException(
        'MySQL integration tests require TF_SQL_TEST_HOST, TF_SQL_TEST_PORT, ' .
        'TF_SQL_TEST_DATABASE, TF_SQL_TEST_USER, and TF_SQL_TEST_PASSWORD.'
      );
    }

    if (!\ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
      throw new \RuntimeException('TF_SQL_TEST_PORT must be a valid numeric TCP port.');
    }

    if (\stripos($database, 'test') === false) {
      throw new \RuntimeException(
        'TF_SQL_TEST_DATABASE must name a dedicated disposable database containing "test".'
      );
    }

    return new self($host, (int)$port, $database, $user, $password);
  }
}
