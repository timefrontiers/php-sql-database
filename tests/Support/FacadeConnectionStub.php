<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Support;

use TimeFrontiers\MySQLiDatabase;

final class FacadeConnectionStub extends MySQLiDatabase
{
  private int $depth = 0;

  public function __construct()
  {
  }

  public function beginTransaction(): bool
  {
    ++$this->depth;
    return true;
  }

  public function commit(): bool
  {
    if ($this->depth === 0) {
      return false;
    }
    --$this->depth;
    return true;
  }

  public function rollBack(): bool
  {
    if ($this->depth === 0) {
      return false;
    }
    --$this->depth;
    return true;
  }

  public function inTransaction(): bool
  {
    return $this->depth > 0;
  }

  public function transactionDepth(): int
  {
    return $this->depth;
  }

  public function transaction(callable $callback): mixed
  {
    $this->beginTransaction();
    try {
      $result = $callback($this);
      $this->commit();
      return $result;
    } catch (\Throwable $exception) {
      $this->rollBack();
      throw $exception;
    }
  }

  public function lastErrorCode(): int|string|null
  {
    return 1205;
  }

  public function lastSqlState(): ?string
  {
    return 'HY000';
  }

  public function affectedRows(): int
  {
    return 7;
  }

  public function getErrors(): array
  {
    return ['stub' => [[0, 256, 'stub', __FILE__, __LINE__]]];
  }
}
