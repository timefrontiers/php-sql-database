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
}
