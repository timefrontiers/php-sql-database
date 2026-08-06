<?php

declare(strict_types=1);

namespace TimeFrontiers\Exceptions;

/**
 * Signals a failed or ambiguous commit operation.
 *
 * Consumers must reconcile database state before retrying the surrounding
 * business operation. A failed commit does not prove that no data persisted.
 */
class TransactionCommitException extends TransactionException
{
}
