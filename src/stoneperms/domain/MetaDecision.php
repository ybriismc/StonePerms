<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* The winning metadata, prefix or suffix value for one key.
*/
final class MetaDecision {

  /** @param list<MetaCandidate> $candidates */
  public function __construct(
    public readonly NodeType $type,
    public readonly string $key,
    public readonly ?string $value,
    public readonly ?MetaCandidate $selected,
    public readonly array $candidates = []
  ) {}

  public function isDefined(): bool {
    return $this->value !== null;
  }
}
