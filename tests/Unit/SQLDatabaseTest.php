<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Tests\Support\FacadeConnectionStub;

final class SQLDatabaseTest extends TestCase
{
  public function testFromConnectionPreservesConcreteInstance(): void
  {
    $connection = new FacadeConnectionStub();
    $database = SQLDatabase::fromConnection($connection);

    self::assertSame($connection, $database->getInstance());
  }

  public function testFacadeExplicitlyDelegatesTransactionAndDiagnostics(): void
  {
    $database = SQLDatabase::fromConnection(new FacadeConnectionStub());

    self::assertTrue($database->beginTransaction());
    self::assertTrue($database->inTransaction());
    self::assertSame(1, $database->transactionDepth());
    self::assertTrue($database->rollBack());
    self::assertSame(1205, $database->lastErrorCode());
    self::assertSame('HY000', $database->lastSqlState());
    self::assertSame(7, $database->affectedRows());
    self::assertArrayHasKey('stub', $database->getErrors());
  }

  public function testFacadeCallbackReceivesFacadeRatherThanConcreteDriver(): void
  {
    $database = SQLDatabase::fromConnection(new FacadeConnectionStub());

    $result = $database->transaction(function (SQLDatabase $callbackDatabase) use ($database): string {
      self::assertSame($database, $callbackDatabase);
      self::assertSame(1, $callbackDatabase->transactionDepth());
      return 'value';
    });

    self::assertSame('value', $result);
    self::assertSame(0, $database->transactionDepth());
  }

  public function testUnsupportedInjectedObjectFailsClearly(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    new SQLDatabase('', '', '', new \stdClass());
  }
}
