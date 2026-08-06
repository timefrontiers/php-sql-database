<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Support;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TimeFrontiers\MySQLiDatabase;
use TimeFrontiers\PDODatabase;
use TimeFrontiers\SQLDatabase;

abstract class AbstractDatabaseBehaviorTestCase extends TestCase
{
  protected IntegrationConfig $config;
  protected MySQLiDatabase|PDODatabase|SQLDatabase $database;
  protected string $table;

  abstract protected function newDatabase(
    IntegrationConfig $config
  ): MySQLiDatabase|PDODatabase|SQLDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    try {
      $this->config = IntegrationConfig::fromEnvironment();
    } catch (\RuntimeException $exception) {
      self::markTestSkipped($exception->getMessage());
    }

    $this->database = $this->newDatabase($this->config);
    if (!$this->database->checkConnection()) {
      self::fail('The configured disposable MySQL database could not be opened.');
    }

    $this->table = 'tf_sql_test_' . \bin2hex(\random_bytes(6));
    $created = $this->database->query(
      "CREATE TABLE {$this->table} (" .
      'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ' .
      'business_key VARCHAR(191) NOT NULL UNIQUE, ' .
      'status VARCHAR(32) NOT NULL, ' .
      'version INT NOT NULL DEFAULT 1, ' .
      'amount INT NOT NULL DEFAULT 0' .
      ') ENGINE=InnoDB'
    );
    self::assertNotFalse($created);

    self::assertNotFalse($this->database->execute(
      "INSERT INTO {$this->table} (business_key, status, amount) VALUES (?, ?, ?)",
      ['baseline', 'pending', 10]
    ));
  }

  protected function tearDown(): void
  {
    if (isset($this->database)) {
      while ($this->database->transactionDepth() > 0) {
        if (!$this->database->rollBack()) {
          break;
        }
      }
      $this->database->closeConnection();
    }

    if (isset($this->config, $this->table)) {
      $cleanup = $this->newDatabase($this->config);
      if ($cleanup->checkConnection()) {
        $cleanup->query("DROP TABLE IF EXISTS {$this->table}");
        $cleanup->closeConnection();
      }
    }

    parent::tearDown();
  }

  public function testManualCommitAndRollbackPersistExpectedState(): void
  {
    self::assertTrue($this->database->beginTransaction());
    self::assertSame(1, $this->database->transactionDepth());
    self::assertNotFalse($this->database->execute(
      "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
      ['committed', 'active']
    ));
    self::assertTrue($this->database->commit());

    self::assertTrue($this->database->beginTransaction());
    self::assertNotFalse($this->database->execute(
      "UPDATE {$this->table} SET status = ? WHERE business_key = ?",
      ['cancelled', 'committed']
    ));
    self::assertTrue($this->database->rollBack());

    $row = $this->database->fetchOne(
      "SELECT status FROM {$this->table} WHERE business_key = ?",
      ['committed']
    );
    self::assertSame('active', $row['status']);
    self::assertFalse($this->database->inTransaction());
  }

  public function testCallbackCommitsValuesAndRethrowsApplicationException(): void
  {
    $value = $this->database->transaction(function ($database): array {
      $database->execute(
        "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
        ['callback', 'active']
      );
      return [];
    });
    self::assertSame([], $value);

    $expected = new \RuntimeException('application failure');
    try {
      $this->database->transaction(function ($database) use ($expected): never {
        $database->execute(
          "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
          ['callback-rolled-back', 'active']
        );
        throw $expected;
      });
      self::fail('The callback exception was not rethrown.');
    } catch (\RuntimeException $actual) {
      self::assertSame($expected, $actual);
    }

    self::assertFalse($this->database->fetchOne(
      "SELECT id FROM {$this->table} WHERE business_key = ?",
      ['callback-rolled-back']
    ));
  }

  #[Group('nested')]
  public function testNestedCommitAndRollbackAreIsolatedBySavepoints(): void
  {
    $this->database->transaction(function ($database): void {
      $database->execute(
        "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
        ['outer', 'active']
      );

      $database->transaction(function ($database): void {
        $database->execute(
          "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
          ['inner-committed', 'active']
        );
      });

      try {
        $database->transaction(function ($database): never {
          $database->execute(
            "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
            ['inner-rolled-back', 'active']
          );
          throw new \RuntimeException('inner');
        });
      } catch (\RuntimeException) {
      }
    });

    self::assertNotFalse($this->database->fetchOne(
      "SELECT id FROM {$this->table} WHERE business_key = ?",
      ['outer']
    ));
    self::assertNotFalse($this->database->fetchOne(
      "SELECT id FROM {$this->table} WHERE business_key = ?",
      ['inner-committed']
    ));
    self::assertFalse($this->database->fetchOne(
      "SELECT id FROM {$this->table} WHERE business_key = ?",
      ['inner-rolled-back']
    ));
  }

  #[Group('rollback-only')]
  public function testIgnoredFailedStatementPreventsOuterCommit(): void
  {
    self::assertTrue($this->database->beginTransaction());
    self::assertNotFalse($this->database->execute(
      "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
      ['must-roll-back', 'active']
    ));
    self::assertFalse($this->database->execute(
      "INSERT INTO {$this->table} (missing_column) VALUES (?)",
      ['bound-value']
    ));
    self::assertFalse($this->database->commit());
    self::assertSame(0, $this->database->transactionDepth());
    self::assertFalse($this->database->fetchOne(
      "SELECT id FROM {$this->table} WHERE business_key = ?",
      ['must-roll-back']
    ));
  }

  #[Group('rollback-only')]
  #[Group('nested')]
  public function testNestedRollbackOnlyScopeAllowsDeliberateOuterContinuation(): void
  {
    self::assertTrue($this->database->beginTransaction());
    self::assertNotFalse($this->database->execute(
      "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
      ['outer-before', 'active']
    ));
    self::assertTrue($this->database->beginTransaction());
    self::assertNotFalse($this->database->execute(
      "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
      ['inner-discarded', 'active']
    ));
    self::assertFalse($this->database->execute(
      "UPDATE {$this->table} SET missing_column = ? WHERE id = ?",
      ['bad', 1]
    ));
    self::assertFalse($this->database->commit());
    self::assertSame(1, $this->database->transactionDepth());
    self::assertNotFalse($this->database->execute(
      "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
      ['outer-after', 'active']
    ));
    self::assertTrue($this->database->commit());

    self::assertFalse($this->database->fetchOne(
      "SELECT id FROM {$this->table} WHERE business_key = ?",
      ['inner-discarded']
    ));
    self::assertNotFalse($this->database->fetchOne(
      "SELECT id FROM {$this->table} WHERE business_key = ?",
      ['outer-after']
    ));
  }

  #[Group('rollback-only')]
  public function testEmptySelectAndZeroRowUpdateRemainCommittable(): void
  {
    self::assertTrue($this->database->beginTransaction());
    self::assertSame([], $this->database->fetchAll(
      "SELECT * FROM {$this->table} WHERE business_key = ?",
      ['missing']
    ));
    self::assertNotFalse($this->database->execute(
      "UPDATE {$this->table} SET status = ? WHERE business_key = ?",
      ['active', 'missing']
    ));
    self::assertSame(0, $this->database->affectedRows());
    self::assertTrue($this->database->commit());
  }

  #[Group('rollback-only')]
  public function testParentFailureRemainsRollbackOnlyAfterSuccessfulChild(): void
  {
    self::assertTrue($this->database->beginTransaction());
    self::assertFalse($this->database->execute(
      "INSERT INTO {$this->table} (missing_column) VALUES (?)",
      ['failure']
    ));
    self::assertTrue($this->database->beginTransaction());
    self::assertNotFalse($this->database->execute(
      "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
      ['child', 'active']
    ));
    self::assertTrue($this->database->commit());
    self::assertFalse($this->database->commit());
    self::assertFalse($this->database->fetchOne(
      "SELECT id FROM {$this->table} WHERE business_key = ?",
      ['child']
    ));
  }

  public function testAffectedRowsAreStoredForGuardedUpdatesAndSurviveRollback(): void
  {
    self::assertNotFalse($this->database->execute(
      "UPDATE {$this->table} SET status = ?, version = version + 1 " .
      'WHERE id = ? AND status = ? AND version = ?',
      ['active', 1, 'pending', 1]
    ));
    self::assertSame(1, $this->database->affectedRows());

    self::assertNotFalse($this->database->execute(
      "UPDATE {$this->table} SET status = ? WHERE id = ? AND version = ?",
      ['cancelled', 1, 1]
    ));
    self::assertSame(0, $this->database->affectedRows());

    self::assertTrue($this->database->beginTransaction());
    self::assertNotFalse($this->database->execute(
      "UPDATE {$this->table} SET amount = ? WHERE id = ?",
      [99, 1]
    ));
    self::assertSame(1, $this->database->affectedRows());
    self::assertTrue($this->database->rollBack());
    self::assertSame(1, $this->database->affectedRows());

    $row = $this->database->fetchOne("SELECT amount FROM {$this->table} WHERE id = ?", [1]);
    self::assertSame(10, (int)$row['amount']);
  }

  public function testDriverIdentityAndDiagnosticsDoNotLeakBoundValues(): void
  {
    $secret = 'secret-bound-value';
    self::assertNotFalse($this->database->execute(
      "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
      [$secret, 'active']
    ));
    self::assertFalse($this->database->execute(
      "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
      [$secret, 'active']
    ));

    self::assertSame(1062, $this->database->lastErrorCode());
    self::assertSame('23000', $this->database->lastSqlState());
    self::assertStringNotContainsString($secret, \json_encode($this->database->getErrors()));
    foreach ($this->database->getErrors() as $entries) {
      foreach ($entries as $entry) {
        self::assertCount(5, $entry);
      }
    }

    self::assertNotFalse($this->database->fetchOne(
      "SELECT id FROM {$this->table} WHERE business_key = ?",
      [$secret]
    ));
    self::assertNull($this->database->lastErrorCode());
  }

  public function testInvalidSqlExposesStructuredDriverIdentity(): void
  {
    self::assertFalse($this->database->query('THIS IS NOT VALID SQL'));
    self::assertNotNull($this->database->lastErrorCode());
    self::assertNotNull($this->database->lastSqlState());
  }

  public function testChangeDatabaseFailsDuringActiveTransaction(): void
  {
    self::assertTrue($this->database->beginTransaction());
    self::assertFalse($this->database->changeDB($this->config->database));
    self::assertSame(1, $this->database->transactionDepth());
    self::assertTrue($this->database->rollBack());
  }

  public function testCloseAndReopenAbandonsActiveTransactionAndResetsState(): void
  {
    self::assertTrue($this->database->beginTransaction());
    self::assertNotFalse($this->database->execute(
      "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
      ['abandoned', 'active']
    ));
    $this->database->closeConnection();

    self::assertSame(0, $this->database->transactionDepth());
    self::assertTrue($this->database->openConnection());
    self::assertFalse($this->database->fetchOne(
      "SELECT id FROM {$this->table} WHERE business_key = ?",
      ['abandoned']
    ));
  }

  public function testSeparateConnectionsHaveIndependentTransactionDepth(): void
  {
    $second = $this->newDatabase($this->config);
    try {
      self::assertTrue($this->database->beginTransaction());
      self::assertSame(1, $this->database->transactionDepth());
      self::assertSame(0, $second->transactionDepth());
    } finally {
      $this->database->rollBack();
      $second->closeConnection();
    }
  }

  #[Group('concurrency')]
  public function testForUpdateLockProducesStructuredTimeoutWithoutRetry(): void
  {
    $second = $this->newDatabase($this->config);

    try {
      self::assertTrue($this->database->beginTransaction());
      self::assertNotFalse($this->database->execute(
        "SELECT id FROM {$this->table} WHERE id = ? FOR UPDATE",
        [1]
      ));

      self::assertNotFalse($second->execute('SET SESSION innodb_lock_wait_timeout = 1'));
      self::assertTrue($second->beginTransaction());
      self::assertFalse($second->execute(
        "UPDATE {$this->table} SET amount = ? WHERE id = ?",
        [50, 1]
      ));
      self::assertSame(1205, $second->lastErrorCode());
      self::assertSame('HY000', $second->lastSqlState());
      self::assertFalse($second->commit());
      self::assertSame(0, $second->transactionDepth());
    } finally {
      if ($second->inTransaction()) {
        $second->rollBack();
      }
      if ($this->database->inTransaction()) {
        $this->database->rollBack();
      }
      $second->closeConnection();
    }

    $row = $this->database->fetchOne("SELECT amount FROM {$this->table} WHERE id = ?", [1]);
    self::assertSame(10, (int)$row['amount']);
  }

  public function testExistingPreparedAndRawQueryApisRemainCallable(): void
  {
    self::assertNotFalse($this->database->execute(
      "INSERT INTO {$this->table} (business_key, status) VALUES (?, ?)",
      ['legacy-regression', 'active']
    ));
    self::assertGreaterThan(0, (int)$this->database->insertId());

    $one = $this->database->fetchOne(
      "SELECT business_key FROM {$this->table} WHERE business_key = ?",
      ['legacy-regression']
    );
    self::assertSame('legacy-regression', $one['business_key']);
    self::assertNotEmpty($this->database->fetchAll(
      "SELECT business_key FROM {$this->table} WHERE status = ?",
      ['active']
    ));

    $result = $this->database->query('SELECT 1 AS value');
    self::assertNotFalse($result);
    self::assertSame('1', (string)$this->database->fetchAssocArray($result)['value']);
  }
}
