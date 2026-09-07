<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* Everything the resolver needs for one user, captured at one revision.
*/
final class PermissionSnapshot {

  /**
  * @param array<string, GroupRecord> $groups keyed by group name
  * @param array<string, list<Node>> $nodes keyed by SubjectRef::key()
  */
  public function __construct(
    public readonly SubjectRef $user,
    public readonly array $groups,
    public readonly array $nodes,
    public readonly string $defaultGroup = 'default'
  ) {}

  /** @return list<Node> */
  public function nodesFor(SubjectRef $subject): array {
    return $this->nodes[$subject->key()] ?? [];
  }

  public function weightOf(string $group): int {
    return isset($this->groups[$group]) ? $this->groups[$group]->weight : 0;
  }
}
