<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* One permission node that could answer a check, plus why it is a contender.
*/
final class PermissionCandidate {

  public function __construct(
    public readonly Node $node,
    public readonly SubjectRef $origin,
    public readonly bool $direct,
    public readonly int $inheritanceDistance,
    public readonly int $groupWeight,
    public readonly int $matchSpecificity
  ) {}
}
