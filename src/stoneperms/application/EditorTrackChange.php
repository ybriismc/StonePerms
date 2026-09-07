<?php

declare(strict_types = 1);

namespace stoneperms\application;

/**
* The complete before and after group order for one track in a changeset.
*/
final class EditorTrackChange {

  /**
  * @param list<string> $before
  * @param list<string> $after
  */
  public function __construct(
    public readonly string $name,
    public readonly array $before,
    public readonly array $after
  ) {}
}
