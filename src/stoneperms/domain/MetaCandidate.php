<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* One metadata, prefix or suffix node competing to supply a value.
*/
final class MetaCandidate {

  public function __construct(
    public readonly Node $node,
    public readonly SubjectRef $origin,
    public readonly bool $direct,
    public readonly int $inheritanceDistance,
    public readonly int $groupWeight
  ) {}
}
