<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TimeFrontiers\Exceptions\TransactionBeginException;
use TimeFrontiers\Exceptions\TransactionCommitException;
use TimeFrontiers\Exceptions\TransactionException;
use TimeFrontiers\Exceptions\TransactionRollbackException;
use TimeFrontiers\Tests\Support\FakeTransactionalConnection;

final class TransactionStateTest extends TestCase
{
  public function testManualTransactionBoundaries(): void
  {
    $database = new FakeTransactionalConnection();

    self::assertFalse($database->inTransaction());
    self::assertSame(0, $database->transactionDepth());
    self::assertTrue($database->beginTransaction());
    self::assertTrue($database->inTransaction());
    self::assertSame(1, $database->transactionDepth());
    self::assertTrue($database->commit());
    self::assertFalse($database->inTransaction());
    self::assertSame(['begin', 'commit'], $database->operations);

    self::assertFalse($database->commit());
    self::assertFalse($database->rollBack());
  }

  #[DataProvider('callbackValues')]
  public function testCallbackReturnsOriginalValue(mixed $value): void
  {
    $database = new FakeTransactionalConnection();

    self::assertSame($value, $database->transaction(fn (): mixed => $value));
    self::assertSame(['begin', 'commit'], $database->operations);
  }

  public static function callbackValues(): array
  {
    return [
      'false' => [false],
      'zero' => [0],
      'null' => [null],
      'empty array' => [[]],
      'string' => ['committed'],
    ];
  }

  public function testCallbackExceptionIsRethrownUnchangedAfterRollback(): void
  {
    $database = new FakeTransactionalConnection();
    $expected = new \RuntimeException('application failure');

    try {
      $database->transaction(static fn () => throw $expected);
      self::fail('The callback exception was not thrown.');
    } catch (\RuntimeException $actual) {
      self::assertSame($expected, $actual);
    }

    self::assertSame(['begin', 'rollback'], $database->operations);
    self::assertSame(0, $database->transactionDepth());
  }

  public function testInfrastructureFailuresUseSpecificExceptions(): void
  {
    $begin = new FakeTransactionalConnection();
    $begin->failNext('begin');

    try {
      $begin->transaction(static fn () => self::fail('Callback must not run.'));
      self::fail('Begin failure was not thrown.');
    } catch (TransactionBeginException $exception) {
      self::assertSame(9999, $exception->driverCode());
      self::assertSame('HY000', $exception->sqlState());
    }

    $commit = new FakeTransactionalConnection();
    $commit->failNext('commit');
    try {
      $commit->transaction(static fn (): string => 'done');
      self::fail('Commit failure was not thrown.');
    } catch (TransactionCommitException $exception) {
      self::assertSame(9999, $exception->driverCode());
      self::assertSame(1, $commit->transactionDepth());
    }
    self::assertTrue($commit->rollBack());

    $rollback = new FakeTransactionalConnection();
    $rollback->failNext('rollback');
    $applicationException = new \LogicException('callback');
    try {
      $rollback->transaction(static fn () => throw $applicationException);
      self::fail('Rollback failure was not thrown.');
    } catch (TransactionRollbackException $exception) {
      self::assertSame($applicationException, $exception->getPrevious());
      self::assertSame(1, $rollback->transactionDepth());
    }
    self::assertTrue($rollback->rollBack());
  }

  public function testUnavailableConnectionCannotBeginOrRunCallback(): void
  {
    $database = new FakeTransactionalConnection();
    $database->disconnect();

    self::assertFalse($database->beginTransaction());

    $callbackRan = false;
    try {
      $database->transaction(function () use (&$callbackRan): void {
        $callbackRan = true;
      });
      self::fail('An unavailable connection must not run the callback.');
    } catch (TransactionBeginException) {
      self::assertFalse($callbackRan);
    }
  }

  #[Group('nested')]
  public function testNestedScopesUseUniqueSavepoints(): void
  {
    $database = new FakeTransactionalConnection();

    self::assertTrue($database->beginTransaction());
    self::assertTrue($database->beginTransaction());
    self::assertTrue($database->beginTransaction());
    self::assertSame(3, $database->transactionDepth());
    self::assertTrue($database->commit());
    self::assertTrue($database->rollBack());
    self::assertTrue($database->commit());

    self::assertSame([
      'begin',
      'savepoint:tf_tx_sp_2',
      'savepoint:tf_tx_sp_3',
      'release:tf_tx_sp_3',
      'rollback-to:tf_tx_sp_2',
      'release:tf_tx_sp_2',
      'commit',
    ], $database->operations);
  }

  #[Group('nested')]
  public function testCaughtInnerExceptionAllowsOuterContinuation(): void
  {
    $database = new FakeTransactionalConnection();

    $result = $database->transaction(function (FakeTransactionalConnection $database): string {
      try {
        $database->transaction(static fn () => throw new \RuntimeException('inner'));
      } catch (\RuntimeException) {
        $database->succeedStatement();
      }
      return 'outer';
    });

    self::assertSame('outer', $result);
    self::assertSame(0, $database->transactionDepth());
  }

  #[Group('nested')]
  public function testUncaughtInnerExceptionRollsBackOuterScope(): void
  {
    $database = new FakeTransactionalConnection();

    try {
      $database->transaction(function (FakeTransactionalConnection $database): void {
        $database->transaction(static fn () => throw new \RuntimeException('inner'));
      });
      self::fail('The inner exception was not propagated.');
    } catch (\RuntimeException $exception) {
      self::assertSame('inner', $exception->getMessage());
    }

    self::assertSame(0, $database->transactionDepth());
    self::assertSame([
      'begin',
      'savepoint:tf_tx_sp_2',
      'rollback-to:tf_tx_sp_2',
      'release:tf_tx_sp_2',
      'rollback',
    ], $database->operations);
  }

  #[Group('nested')]
  public function testScopeReplacementAtSameDepthIsDetected(): void
  {
    $database = new FakeTransactionalConnection();

    $this->expectException(TransactionException::class);
    try {
      $database->transaction(function (FakeTransactionalConnection $database): void {
        self::assertTrue($database->rollBack());
        self::assertTrue($database->beginTransaction());
      });
    } finally {
      if ($database->inTransaction()) {
        $database->rollBack();
      }
    }
  }

  #[Group('rollback-only')]
  public function testIgnoredStatementFailureRejectsCommitAndRollsBack(): void
  {
    $database = new FakeTransactionalConnection();
    $database->beginTransaction();
    $database->failStatement();

    self::assertFalse($database->commit());
    self::assertSame(0, $database->transactionDepth());
    self::assertSame(1062, $database->lastErrorCode());
    self::assertSame(['begin', 'rollback'], $database->operations);
  }

  #[Group('rollback-only')]
  public function testCallbackThrowsWhenIgnoredStatementFailureMakesScopeRollbackOnly(): void
  {
    $database = new FakeTransactionalConnection();

    try {
      $database->transaction(function (FakeTransactionalConnection $database): string {
        $database->failStatement(1062, '23000');
        return 'ignored';
      });
      self::fail('Rollback-only callback completion must throw.');
    } catch (TransactionException $exception) {
      self::assertNotInstanceOf(TransactionCommitException::class, $exception);
      self::assertSame(1062, $exception->driverCode());
      self::assertSame('23000', $exception->sqlState());
    }

    self::assertSame(0, $database->transactionDepth());
    self::assertSame(['begin', 'rollback'], $database->operations);
  }

  #[Group('rollback-only')]
  public function testNestedRollbackOnlyScopeDoesNotPoisonCleanParent(): void
  {
    $database = new FakeTransactionalConnection();
    $database->beginTransaction();
    $database->beginTransaction();
    $database->failStatement();

    self::assertFalse($database->commit());
    self::assertSame(1, $database->transactionDepth());
    self::assertTrue($database->commit());
    self::assertSame(0, $database->transactionDepth());
  }

  #[Group('rollback-only')]
  public function testParentFailureSurvivesSuccessfulChildScope(): void
  {
    $database = new FakeTransactionalConnection();
    $database->beginTransaction();
    $database->failStatement(1213, '40001');
    $database->beginTransaction();
    $database->succeedStatement();

    self::assertTrue($database->commit());
    self::assertFalse($database->commit());
    self::assertSame(1213, $database->lastErrorCode());
    self::assertSame(0, $database->transactionDepth());
  }

  #[Group('rollback-only')]
  public function testSuccessfulZeroOrEmptyResultDoesNotMarkRollbackOnly(): void
  {
    $database = new FakeTransactionalConnection();
    $database->beginTransaction();
    $database->succeedStatement();

    self::assertTrue($database->commit());
  }

  #[Group('nested')]
  public function testSavepointFailuresDoNotCorruptDepth(): void
  {
    $create = new FakeTransactionalConnection();
    $create->beginTransaction();
    $create->failNext('savepoint');
    self::assertFalse($create->beginTransaction());
    self::assertSame(1, $create->transactionDepth());
    self::assertFalse($create->commit());
    self::assertSame(0, $create->transactionDepth());

    $release = new FakeTransactionalConnection();
    $release->beginTransaction();
    $release->beginTransaction();
    $release->failNext('release');
    self::assertFalse($release->commit());
    self::assertSame(2, $release->transactionDepth());
    self::assertTrue($release->rollBack());
    self::assertFalse($release->commit());
    self::assertSame(0, $release->transactionDepth());

    $rollback = new FakeTransactionalConnection();
    $rollback->beginTransaction();
    $rollback->beginTransaction();
    $rollback->failNext('rollback-to');
    self::assertFalse($rollback->rollBack());
    self::assertSame(2, $rollback->transactionDepth());
    self::assertTrue($rollback->rollBack());
    self::assertFalse($rollback->commit());
  }

  public function testConnectionLifecycleAndNativeStateMismatchResetManagedState(): void
  {
    $closed = new FakeTransactionalConnection();
    $closed->beginTransaction();
    $closed->disconnect();
    self::assertFalse($closed->inTransaction());
    self::assertSame(0, $closed->transactionDepth());

    $mismatch = new FakeTransactionalConnection();
    $mismatch->beginTransaction();
    $mismatch->forceNativeState(false);
    self::assertFalse($mismatch->inTransaction());
    self::assertSame(0, $mismatch->transactionDepth());
  }

  public function testSuccessfulOperationClearsLatestDriverIdentityButKeepsErrors(): void
  {
    $database = new FakeTransactionalConnection();
    $database->failNext('begin');
    self::assertFalse($database->beginTransaction());
    self::assertSame(9999, $database->lastErrorCode());
    self::assertTrue($database->beginTransaction());
    self::assertNull($database->lastErrorCode());

    foreach ($database->getErrors() as $entries) {
      foreach ($entries as $entry) {
        self::assertCount(5, $entry);
      }
    }
  }
}
