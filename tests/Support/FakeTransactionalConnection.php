<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Support;

use TimeFrontiers\Contracts\TransactionalConnectionInterface;
use TimeFrontiers\Internal\ManagesTransactions;

final class FakeTransactionalConnection implements TransactionalConnectionInterface
{
  use ManagesTransactions;

  /** @var list<string> */
  public array $operations = [];

  /** @var array<string, int> */
  private array $failures = [];
  private bool $connectionAvailable = true;
  private ?bool $nativeState = false;
  private array $errors = [];

  public function failNext(string $operation, int $count = 1): void
  {
    $this->failures[$operation] = $count;
  }

  public function failStatement(
    int|string|null $driverCode = 1062,
    ?string $sqlState = '23000'
  ): void {
    $this->_setLastDriverFailure($driverCode, $sqlState);
    $this->_addError('execute', 256, 'Prepared statement execution failed.', __FILE__, __LINE__);
    $this->_markTransactionStatementFailure();
  }

  public function succeedStatement(): void
  {
    $this->_clearLastDriverFailure();
  }

  public function disconnect(): void
  {
    $this->_closeManagedTransaction();
    $this->connectionAvailable = false;
    $this->nativeState = false;
  }

  public function forceNativeState(?bool $state): void
  {
    $this->nativeState = $state;
  }

  public function getErrors(): array
  {
    return $this->errors;
  }

  protected function _transactionConnectionAvailable(): bool
  {
    return $this->connectionAvailable;
  }

  protected function _nativeBeginTransaction(): bool
  {
    if (!$this->perform('begin')) {
      return false;
    }
    $this->nativeState = true;
    return true;
  }

  protected function _nativeCommitTransaction(): bool
  {
    if (!$this->perform('commit')) {
      return false;
    }
    $this->nativeState = false;
    return true;
  }

  protected function _nativeRollbackTransaction(): bool
  {
    if (!$this->perform('rollback')) {
      return false;
    }
    $this->nativeState = false;
    return true;
  }

  protected function _nativeCreateSavepoint(string $savepoint): bool
  {
    return $this->perform("savepoint:{$savepoint}", 'savepoint');
  }

  protected function _nativeReleaseSavepoint(string $savepoint): bool
  {
    return $this->perform("release:{$savepoint}", 'release');
  }

  protected function _nativeRollbackToSavepoint(string $savepoint): bool
  {
    return $this->perform("rollback-to:{$savepoint}", 'rollback-to');
  }

  protected function _nativeTransactionState(): ?bool
  {
    return $this->connectionAvailable ? $this->nativeState : false;
  }

  protected function _addError(
    string $context,
    int $code,
    string $message,
    string $file,
    int $line,
    int $min_rank = 0
  ): void {
    $this->errors[$context][] = [
      $min_rank,
      $code,
      $message,
      $file,
      $line,
    ];
  }

  private function perform(string $logEntry, ?string $failureKey = null): bool
  {
    $this->operations[] = $logEntry;
    $failureKey ??= $logEntry;

    if (($this->failures[$failureKey] ?? 0) < 1) {
      return true;
    }

    --$this->failures[$failureKey];
    $this->_setLastDriverFailure(9999, 'HY000');
    return false;
  }
}
