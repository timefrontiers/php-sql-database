<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Integration\MySQLi;

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
    return SQLDatabase::fromConnection(new MySQLiDatabase(
      $config->host,
      $config->user,
      $config->password,
      $config->database,
      true,
      (string)$config->port
    ));
  }
}
