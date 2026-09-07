<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* The answer to one permission check, with the full ordered candidate list so
* `/stoneperms user ... check` can explain itself.
*/
final class PermissionDecision {

  /** @param list<PermissionCandidate> $candidates */
  public function __construct(
    public readonly string $permission,
    public readonly ?bool $value,
    public readonly ?PermissionCandidate $selected,
    public readonly array $candidates = []
  ) {}

  public function isDefined(): bool {
    return $this->value !== null;
  }
}
