<?php

declare(strict_types = 1);

namespace stoneperms\domain;

use InvalidArgumentException;

/**
* An ordered promotion ladder. Defining a track never changes inheritance by
* itself; only promote and demote touch a user's parents.
*/
final class TrackRecord {

  public readonly string $name;
  /** @var list<string> */
  public readonly array $groups;

  /** @param list<string> $groups */
  public function __construct(string $name, array $groups = []) {
    $this->name = Validation::trackName($name);
    $normalized = array_map(static fn(string $group): string => Validation::groupName($group), $groups);
    if (count($normalized) !== count(array_unique($normalized))) {
      throw new InvalidArgumentException('A group may only appear once in a track');
    }
    $this->groups = array_values($normalized);
  }

  public function indexOf(string $group): ?int {
    $index = array_search(Validation::groupName($group), $this->groups, true);
    return $index === false ? null : $index;
  }

  public function contains(string $group): bool {
    return $this->indexOf($group) !== null;
  }
}
