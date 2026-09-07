<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* A named group with a weight used to break conflicts between inherited nodes.
*/
final class GroupRecord {

  public readonly string $name;
  public readonly string $displayName;

  public function __construct(string $name, string $displayName = '', public readonly int $weight = 0) {
    $this->name = Validation::groupName($name);
    $this->displayName = trim($displayName) === '' ? $this->name : $displayName;
  }
}
