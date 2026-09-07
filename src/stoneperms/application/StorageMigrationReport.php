<?php

declare(strict_types = 1);

namespace stoneperms\application;

/**
* What a migration did, or what it would do when it was only asked to look.
*/
final class StorageMigrationReport {

  /** @param list<string> $notes */
  public function __construct(
    public readonly bool $dryRun,
    public readonly int $groups = 0,
    public readonly int $groupsKept = 0,
    public readonly int $tracks = 0,
    public readonly int $tracksKept = 0,
    public readonly int $players = 0,
    public readonly int $nodes = 0,
    public readonly array $notes = []
  ) {}

  public function copied(): int {
    return $this->groups + $this->tracks + $this->players + $this->nodes;
  }
}
