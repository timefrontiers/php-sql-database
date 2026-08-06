<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Integration\PDO;

use TimeFrontiers\MySQLiDatabase;
use TimeFrontiers\PDODatabase;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Tests\Support\AbstractDatabaseBehaviorTestCase;
use TimeFrontiers\Tests\Support\IntegrationConfig;

final class SQLDatabaseFacadeTest extends AbstractDatabaseBehaviorTestCase
{
  protected function newDatabase(
    IntegrationConfig $config
  ): MySQLiDatabase|PDODatabase|SQLDatabase {
    return SQLDatabase::pdo(
      driver: 'mysql',
      host: $config->host,
      port: $config->port,
      database: $config->database,
      user: $config->user,
      password: $config->password
    );
  }
}
