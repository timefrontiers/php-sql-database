<?php

declare(strict_types=1);

namespace TimeFrontiers\Internal;

use TimeFrontiers\Exceptions\TransactionBeginException;
use TimeFrontiers\Exceptions\TransactionCommitException;
use TimeFrontiers\Exceptions\TransactionException;
use TimeFrontiers\Exceptions\TransactionRollbackException;

/**
 * Shared transaction state machine for the concrete database drivers.
 *
 * Native operations are supplied by each driver. Savepoint identifiers are
 * generated internally and never contain caller-controlled data.
 *
 * @internal
 */
trait ManagesTransactions
{
  /**
   * @var list<array{
   *   id: int,
   *   savepoint: string|null,
   *   rollbackOnly: bool,
   *   failureCode: int|string|null,
   *   sqlState: string|null
   * }>
   */
  private array $_transaction_scopes = [];
  private int $_transaction_scope_sequence = 0;
  private int|string|null $_last_error_code = null;
  private ?string $_last_sql_state = null;
  private ?string $_last_transaction_failure = null;

  abstract protected function _transactionConnectionAvailable(): bool;

  abstract protected function _nativeBeginTransaction(): bool;

  abstract protected function _nativeCommitTransaction(): bool;

  abstract protected function _nativeRollbackTransaction(): bool;

  abstract protected function _nativeCreateSavepoint(string $savepoint): bool;

  abstract protected function _nativeReleaseSavepoint(string $savepoint): bool;

  abstract protected function _nativeRollbackToSavepoint(string $savepoint): bool;

  /**
   * Returns null when the native driver cannot verify transaction state.
   */
  abstract protected function _nativeTransactionState(): ?bool;

  abstract protected function _addError(
    string $context,
    int $code,
    string $message,
    string $file,
    int $line,
    int $min_rank = 0
  ): void;

  public function beginTransaction(): bool
  {
    $this->_last_transaction_failure = null;
    $this->_clearLastDriverFailure();

    if (!$this->_transactionConnectionAvailable()) {
      $this->_last_transaction_failure = 'begin';
      $this->_addTransactionError('beginTransaction', 'Cannot begin a transaction without an active connection.');
      return false;
    }

    $scopeId = ++$this->_transaction_scope_sequence;
    $savepoint = null;

    if ($this->_transaction_scopes === []) {
      $success = $this->_nativeBeginTransaction();
    } else {
      $savepoint = "tf_tx_sp_{$scopeId}";
      $success = $this->_nativeCreateSavepoint($savepoint);
    }

    if (!$success) {
      $this->_last_transaction_failure = 'begin';
      if ($this->_transaction_scopes !== []) {
        $this->_markAllTransactionScopesRollbackOnly();
      }
      $this->_addTransactionError('beginTransaction', 'Failed to create a transaction scope.');
      return false;
    }

    $this->_transaction_scopes[] = [
      'id' => $scopeId,
      'savepoint' => $savepoint,
      'rollbackOnly' => false,
      'failureCode' => null,
      'sqlState' => null,
    ];

    return true;
  }

  public function commit(): bool
  {
    $this->_last_transaction_failure = null;

    if ($this->_transaction_scopes === []) {
      $this->_clearLastDriverFailure();
      $this->_last_transaction_failure = 'commit';
      $this->_addTransactionError('commit', 'Cannot commit without an active transaction.');
      return false;
    }

    $scope = $this->_currentTransactionScope();
    if ($scope['rollbackOnly']) {
      $failureCode = $scope['failureCode'];
      $sqlState = $scope['sqlState'];

      if (!$this->_rollbackCurrentTransactionScope(false)) {
        $this->_last_transaction_failure = 'rollback';
        return false;
      }

      $this->_setLastDriverFailure($failureCode, $sqlState);
      $this->_last_transaction_failure = 'rollback_only';
      $this->_addTransactionError(
        'commit',
        'Commit was rejected because the transaction scope is rollback-only.'
      );
      return false;
    }

    $this->_clearLastDriverFailure();
    $success = $scope['savepoint'] === null
      ? $this->_nativeCommitTransaction()
      : $this->_nativeReleaseSavepoint($scope['savepoint']);

    if (!$success) {
      $this->_last_transaction_failure = 'commit';
      $this->_markAllTransactionScopesRollbackOnly();
      $this->_addTransactionError('commit', 'Failed to commit the transaction scope.');
      $this->_reconcileFailedOuterTransactionOperation($scope['savepoint']);
      return false;
    }

    \array_pop($this->_transaction_scopes);
    return true;
  }

  public function rollBack(): bool
  {
    $this->_last_transaction_failure = null;

    if ($this->_transaction_scopes === []) {
      $this->_clearLastDriverFailure();
      $this->_last_transaction_failure = 'rollback';
      $this->_addTransactionError('rollBack', 'Cannot roll back without an active transaction.');
      return false;
    }

    return $this->_rollbackCurrentTransactionScope(true);
  }

  public function inTransaction(): bool
  {
    if ($this->_transaction_scopes === []) {
      return false;
    }

    $nativeState = $this->_nativeTransactionState();
    if ($nativeState === false) {
      $this->_addTransactionError(
        'inTransaction',
        'Managed transaction state no longer matches the native connection.'
      );
      $this->_resetTransactionState();
      return false;
    }

    return true;
  }

  public function transactionDepth(): int
  {
    if ($this->_transaction_scopes !== []) {
      $this->inTransaction();
    }

    return \count($this->_transaction_scopes);
  }

  public function transaction(callable $callback): mixed
  {
    $startingDepth = $this->transactionDepth();

    if (!$this->beginTransaction()) {
      throw new TransactionBeginException(
        'Failed to begin the managed transaction scope.',
        $this->lastErrorCode(),
        $this->lastSqlState()
      );
    }

    $ownedScope = $this->_currentTransactionScope();

    try {
      $result = $callback($this);
    } catch (\Throwable $applicationException) {
      if (!$this->_ownsTransactionScope($ownedScope['id'], $startingDepth + 1)) {
        throw new TransactionException(
          'The transaction callback changed the scope it was expected to own.',
          $this->lastErrorCode(),
          $this->lastSqlState(),
          $applicationException
        );
      }

      if (!$this->rollBack()) {
        throw new TransactionRollbackException(
          'The transaction callback failed and its scope could not be rolled back.',
          $this->lastErrorCode(),
          $this->lastSqlState(),
          $applicationException
        );
      }

      throw $applicationException;
    }

    if (!$this->_ownsTransactionScope($ownedScope['id'], $startingDepth + 1)) {
      throw new TransactionException(
        'The transaction callback changed the scope it was expected to own.',
        $this->lastErrorCode(),
        $this->lastSqlState()
      );
    }

    if ($this->commit()) {
      return $result;
    }

    if ($this->_last_transaction_failure === 'rollback') {
      throw new TransactionRollbackException(
        'The rollback-only transaction scope could not be rolled back.',
        $this->lastErrorCode(),
        $this->lastSqlState()
      );
    }

    if ($this->_last_transaction_failure === 'rollback_only') {
      throw new TransactionException(
        'The transaction scope was rolled back because a statement failed.',
        $this->lastErrorCode(),
        $this->lastSqlState()
      );
    }

    throw new TransactionCommitException(
      'The transaction scope could not be committed; its database outcome may be uncertain.',
      $this->lastErrorCode(),
      $this->lastSqlState()
    );
  }

  public function lastErrorCode(): int|string|null
  {
    return $this->_last_error_code;
  }

  public function lastSqlState(): ?string
  {
    return $this->_last_sql_state;
  }

  protected function _setLastDriverFailure(
    int|string|null $driverCode,
    ?string $sqlState
  ): void {
    $this->_last_error_code = $driverCode;
    $this->_last_sql_state = $sqlState;
  }

  protected function _clearLastDriverFailure(): void
  {
    $this->_last_error_code = null;
    $this->_last_sql_state = null;
  }

  protected function _markTransactionStatementFailure(): void
  {
    if ($this->_transaction_scopes === []) {
      return;
    }

    $index = \array_key_last($this->_transaction_scopes);
    $this->_transaction_scopes[$index]['rollbackOnly'] = true;

    if ($this->_transaction_scopes[$index]['failureCode'] === null) {
      $this->_transaction_scopes[$index]['failureCode'] = $this->_last_error_code;
      $this->_transaction_scopes[$index]['sqlState'] = $this->_last_sql_state;
    }
  }

  /**
   * Rolls back an active native transaction before a connection is discarded.
   */
  protected function _closeManagedTransaction(): void
  {
    if ($this->_transaction_scopes !== [] && $this->_transactionConnectionAvailable()) {
      if (!$this->_nativeRollbackTransaction()) {
        $this->_addTransactionError(
          'closeConnection',
          'The active transaction could not be explicitly rolled back before closing.'
        );
      }
    }

    $this->_resetTransactionState();
  }

  protected function _resetTransactionState(): void
  {
    $this->_transaction_scopes = [];
    $this->_transaction_scope_sequence = 0;
    $this->_last_transaction_failure = null;
  }

  private function _rollbackCurrentTransactionScope(bool $clearDriverFailure): bool
  {
    if ($clearDriverFailure) {
      $this->_clearLastDriverFailure();
    }

    $scope = $this->_currentTransactionScope();

    if ($scope['savepoint'] === null) {
      $success = $this->_nativeRollbackTransaction();
    } else {
      $success = $this->_nativeRollbackToSavepoint($scope['savepoint']);
      if ($success) {
        $success = $this->_nativeReleaseSavepoint($scope['savepoint']);
      }
    }

    if (!$success) {
      $this->_last_transaction_failure = 'rollback';
      $this->_markAllTransactionScopesRollbackOnly();
      $this->_addTransactionError('rollBack', 'Failed to roll back the transaction scope.');
      $this->_reconcileFailedOuterTransactionOperation($scope['savepoint']);
      return false;
    }

    \array_pop($this->_transaction_scopes);
    return true;
  }

  /**
   * @return array{
   *   id: int,
   *   savepoint: string|null,
   *   rollbackOnly: bool,
   *   failureCode: int|string|null,
   *   sqlState: string|null
   * }
   */
  private function _currentTransactionScope(): array
  {
    return $this->_transaction_scopes[\array_key_last($this->_transaction_scopes)];
  }

  private function _ownsTransactionScope(int $scopeId, int $expectedDepth): bool
  {
    if (\count($this->_transaction_scopes) !== $expectedDepth) {
      return false;
    }

    return $this->_currentTransactionScope()['id'] === $scopeId;
  }

  private function _markAllTransactionScopesRollbackOnly(): void
  {
    foreach ($this->_transaction_scopes as $index => $scope) {
      $this->_transaction_scopes[$index]['rollbackOnly'] = true;

      if ($scope['failureCode'] === null) {
        $this->_transaction_scopes[$index]['failureCode'] = $this->_last_error_code;
        $this->_transaction_scopes[$index]['sqlState'] = $this->_last_sql_state;
      }
    }
  }

  private function _reconcileFailedOuterTransactionOperation(?string $savepoint): void
  {
    if ($savepoint !== null) {
      return;
    }

    if ($this->_nativeTransactionState() === false) {
      $this->_resetTransactionState();
    }
  }

  private function _addTransactionError(string $context, string $message): void
  {
    $this->_addError($context, 256, $message, __FILE__, __LINE__);
  }
}
