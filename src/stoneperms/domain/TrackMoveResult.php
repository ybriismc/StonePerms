<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* What a promote or demote actually did.
*/
final class TrackMoveResult {

  public function __construct(
    public readonly TrackMoveAction $action,
    public readonly TrackMoveStatus $status,
    public readonly string $track,
    public readonly ?string $groupFrom = null,
    public readonly ?string $groupTo = null,
    public readonly bool $changed = false
  ) {}

  /** @return array<string, mixed> */
  public function toWire(): array {
    return [
      'action' => $this->action->value,
      'status' => $this->status->value,
      'track' => $this->track,
      'groupFrom' => $this->groupFrom,
      'groupTo' => $this->groupTo,
      'changed' => $this->changed
    ];
  }
}
