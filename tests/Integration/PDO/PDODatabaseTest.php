<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Integration\PDO;

use TimeFrontiers\MySQLiDatabase;
use TimeFrontiers\PDODatabase;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Tests\Support\AbstractDatabaseBehaviorTestCase;
use TimeFrontiers\Tests\Support\IntegrationConfig;

final class PDODatabaseTest extends AbstractDatabaseBehaviorTestCase
{
  protected function newDatabase(
    IntegrationConfig $config
  ): MySQLiDatabase|PDODatabase|SQLDatabase {
    return new PDODatabase(
      'mysql',
      $config->host,
      $config->port,
      $config->database,
      $config->user,
      $config->password
    );
  }
}
