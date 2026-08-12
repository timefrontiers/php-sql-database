<?php

declare(strict_types=1);

namespace TimeFrontiers\Contracts;

interface TransactionalConnectionInterface
{
  public function beginTransaction(): bool;

  public function commit(): bool;

  public function rollBack(): bool;

  public function inTransaction(): bool;

  public function transactionDepth(): int;

  public function transaction(callable $callback): mixed;

  /**
   * Driver error code for the most recent failure, or null after success.
   *
   * Part of the contract so that consumers holding this interface can classify
   * duplicate keys, deadlocks, and lock timeouts without parsing localized
   * driver messages.
   */
  public function lastErrorCode(): int|string|null;

  /** SQLSTATE for the most recent failure, where the driver provides one. */
  public function lastSqlState(): ?string;
}
