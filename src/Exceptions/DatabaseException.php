<?php

declare(strict_types=1);

namespace TimeFrontiers\Exceptions;

class DatabaseException extends \RuntimeException
{
  public function __construct(
    string $message,
    private readonly int|string|null $driverCode = null,
    private readonly ?string $sqlState = null,
    ?\Throwable $previous = null
  ) {
    parent::__construct(
      $message,
      \is_int($driverCode) ? $driverCode : 0,
      $previous
    );
  }

  public function driverCode(): int|string|null
  {
    return $this->driverCode;
  }

  public function sqlState(): ?string
  {
    return $this->sqlState;
  }
}
