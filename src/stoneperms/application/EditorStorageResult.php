<?php

declare(strict_types = 1);

namespace stoneperms\application;

/**
* What committing a changeset did to storage.
*/
final class EditorStorageResult {

  public function __construct(
    public readonly int $revision,
    public readonly int $changedSubjects = 0,
    public readonly int $changedTracks = 0,
    public readonly int $nodesAdded = 0,
    public readonly int $nodesRemoved = 0
  ) {}

  public function changed(): bool {
    return $this->changedSubjects > 0 || $this->changedTracks > 0;
  }
}
