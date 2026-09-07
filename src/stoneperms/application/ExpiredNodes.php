<?php

declare(strict_types = 1);

namespace stoneperms\application;

use stoneperms\domain\SubjectRef;

/**
* What one expiry sweep removed.
*/
final class ExpiredNodes {

  /** @param list<SubjectRef> $subjects */
  public function __construct(
    public readonly int $count,
    public readonly array $subjects
  ) {}
}
