<?php

declare(strict_types = 1);

namespace stoneperms\domain;

use InvalidArgumentException;

/**
* Turns a snapshot into answers.
*
* The resolver is deliberately free of any PocketMine dependency: give it a
* snapshot, a permission and the active contexts and it produces a decision.
* Nothing here touches the server, the database or the network.
*/
final class PermissionResolver {

  private const MAX_GROUP_WEIGHT = 2147483647;
  private const NEVER_EXPIRES = -9223372036854775807;

  public function resolve(
    PermissionSnapshot $snapshot,
    string $permission,
    ?ContextSet $contexts,
    int $now
  ): PermissionDecision {
    $requested = Validation::permission($permission);
    $active = $contexts ?? ContextSet::empty();
    $candidates = [];

    foreach ($this->subjects($snapshot, $active, $now) as $inherited) {
      foreach ($snapshot->nodesFor($inherited->subject) as $node) {
        if ($node->type !== NodeType::PERMISSION) {
          continue;
        }
        if (!$node->activeAt($now) || !$node->contexts->matches($active)) {
          continue;
        }
        $specificity = self::matchSpecificity($node->key, $requested);
        if ($specificity < 0) {
          continue;
        }
        $candidates[] = new PermissionCandidate(
          $node,
          $inherited->subject,
          $inherited->subject->equals($snapshot->user),
          $inherited->distance,
          $inherited->groupWeight,
          $specificity
        );
      }
    }

    usort(
      $candidates,
      static fn(PermissionCandidate $a, PermissionCandidate $b): int
        => self::permissionPriority($b) <=> self::permissionPriority($a)
    );

    $selected = $candidates[0] ?? null;
    return new PermissionDecision(
      $requested,
      $selected?->node->permissionValue(),
      $selected,
      $candidates
    );
  }

  /**
  * Resolves many permissions against one snapshot in a single pass over the
  * inheritance order. This is what the attachment layer uses on every join.
  *
  * @param iterable<string> $permissions
  * @return array<string, bool> only the permissions that resolved to a value
  */
  public function resolveMany(
    PermissionSnapshot $snapshot,
    iterable $permissions,
    ?ContextSet $contexts,
    int $now
  ): array {
    $result = [];
    foreach ($permissions as $permission) {
      try {
        $decision = $this->resolve($snapshot, (string) $permission, $contexts, $now);
      } catch (InvalidArgumentException) {
        continue;
      }
      if ($decision->value !== null) {
        $result[$decision->permission] = $decision->value;
      }
    }
    return $result;
  }

  /** @return list<string> */
  public function effectiveGroups(PermissionSnapshot $snapshot, ?ContextSet $contexts, int $now): array {
    $groups = [];
    foreach ($this->subjects($snapshot, $contexts ?? ContextSet::empty(), $now) as $inherited) {
      if ($inherited->subject->type === SubjectType::GROUP) {
        $groups[] = $inherited->subject->identifier;
      }
    }
    return $groups;
  }

  public function primaryGroup(PermissionSnapshot $snapshot, ?ContextSet $contexts, int $now): string {
    $groups = $this->effectiveGroups($snapshot, $contexts, $now);
    if ($groups === []) {
      return $snapshot->defaultGroup;
    }
    $best = null;
    $bestKey = null;
    foreach ($groups as $group) {
      $key = [$snapshot->weightOf($group), $group];
      if ($bestKey === null || $key > $bestKey) {
        $bestKey = $key;
        $best = $group;
      }
    }
    return $best ?? $snapshot->defaultGroup;
  }

  public function resolveMeta(
    PermissionSnapshot $snapshot,
    string $key,
    ?ContextSet $contexts,
    int $now
  ): MetaDecision {
    return $this->resolveMetaNode(
      $snapshot,
      NodeType::META,
      Validation::metaKey($key),
      $contexts ?? ContextSet::empty(),
      $now
    );
  }

  public function resolvePrefix(PermissionSnapshot $snapshot, ?ContextSet $contexts, int $now): MetaDecision {
    return $this->resolveMetaNode(
      $snapshot,
      NodeType::PREFIX,
      NodeType::PREFIX->value,
      $contexts ?? ContextSet::empty(),
      $now
    );
  }

  public function resolveSuffix(PermissionSnapshot $snapshot, ?ContextSet $contexts, int $now): MetaDecision {
    return $this->resolveMetaNode(
      $snapshot,
      NodeType::SUFFIX,
      NodeType::SUFFIX->value,
      $contexts ?? ContextSet::empty(),
      $now
    );
  }

  /** @return array<string, string> */
  public function resolveMetaMap(PermissionSnapshot $snapshot, ?ContextSet $contexts, int $now): array {
    $active = $contexts ?? ContextSet::empty();
    $keys = [];
    foreach ($this->subjects($snapshot, $active, $now) as $inherited) {
      foreach ($snapshot->nodesFor($inherited->subject) as $node) {
        if ($node->type === NodeType::META && $node->activeAt($now) && $node->contexts->matches($active)) {
          $keys[$node->key] = true;
        }
      }
    }
    ksort($keys);

    $values = [];
    foreach (array_keys($keys) as $key) {
      $decision = $this->resolveMetaNode($snapshot, NodeType::META, $key, $active, $now);
      if ($decision->value !== null) {
        $values[$key] = $decision->value;
      }
    }
    return $values;
  }

  private function resolveMetaNode(
    PermissionSnapshot $snapshot,
    NodeType $type,
    string $key,
    ContextSet $contexts,
    int $now
  ): MetaDecision {
    if ($type !== NodeType::META && $type !== NodeType::PREFIX && $type !== NodeType::SUFFIX) {
      throw new InvalidArgumentException('Unsupported metadata node type: ' . $type->value);
    }

    $candidates = [];
    foreach ($this->subjects($snapshot, $contexts, $now) as $inherited) {
      foreach ($snapshot->nodesFor($inherited->subject) as $node) {
        if ($node->type !== $type || $node->key !== $key) {
          continue;
        }
        if (!$node->activeAt($now) || !$node->contexts->matches($contexts)) {
          continue;
        }
        $candidates[] = new MetaCandidate(
          $node,
          $inherited->subject,
          $inherited->subject->equals($snapshot->user),
          $inherited->distance,
          $inherited->groupWeight
        );
      }
    }

    $stacked = $type !== NodeType::META;
    usort(
      $candidates,
      static fn(MetaCandidate $a, MetaCandidate $b): int
        => self::metaPriority($b, $stacked) <=> self::metaPriority($a, $stacked)
    );

    $selected = $candidates[0] ?? null;
    return new MetaDecision($type, $key, $selected?->node->value, $selected, $candidates);
  }

  /**
  * Walks the inheritance graph breadth-first, starting from the default group
  * and the user's own parents. Cycles are cut by carrying the ancestry along
  * each branch, and each group keeps only its strongest reachable position.
  *
  * @return list<InheritedSubject>
  */
  public function subjects(PermissionSnapshot $snapshot, ContextSet $contexts, int $now): array {
    $result = [new InheritedSubject($snapshot->user, 0, self::MAX_GROUP_WEIGHT)];

    $roots = [$snapshot->defaultGroup => true];
    foreach (self::activeParents($snapshot->nodesFor($snapshot->user), $contexts, $now) as $parent) {
      $roots[$parent] = true;
    }
    $rootNames = array_keys($roots);
    usort(
      $rootNames,
      static fn(string $a, string $b): int
        => [$snapshot->weightOf($b), $b] <=> [$snapshot->weightOf($a), $a]
    );

    /** @var array<string, array{0: int, 1: int}> $best */
    $best = [];
    $queue = [];
    foreach ($rootNames as $group) {
      $queue[] = [$group, 1, $snapshot->weightOf($group), []];
    }

    while ($queue !== []) {
      [$group, $distance, $rootWeight, $ancestry] = array_shift($queue);
      if (isset($ancestry[$group]) || !isset($snapshot->groups[$group])) {
        continue;
      }
      $precedence = [$rootWeight, -$distance];
      if (isset($best[$group]) && $best[$group] >= $precedence) {
        continue;
      }
      $best[$group] = $precedence;
      $nextAncestry = $ancestry;
      $nextAncestry[$group] = true;
      $subject = SubjectRef::group($group);
      foreach (self::activeParents($snapshot->nodesFor($subject), $contexts, $now) as $parent) {
        $queue[] = [$parent, $distance + 1, $rootWeight, $nextAncestry];
      }
    }

    $ordered = [];
    foreach ($best as $group => $precedence) {
      $ordered[] = [$precedence[0], $precedence[1], (string) $group];
    }
    usort($ordered, static fn(array $a, array $b): int => $b <=> $a);

    foreach ($ordered as [, $negativeDistance, $group]) {
      $result[] = new InheritedSubject(
        SubjectRef::group($group),
        -$negativeDistance,
        $snapshot->groups[$group]->weight
      );
    }
    return $result;
  }

  /**
  * @param list<Node> $nodes
  * @return list<string>
  */
  private static function activeParents(array $nodes, ContextSet $contexts, int $now): array {
    $parents = [];
    foreach ($nodes as $node) {
      if ($node->type === NodeType::PARENT && $node->activeAt($now) && $node->contexts->matches($contexts)) {
        $parents[] = $node->key;
      }
    }
    return $parents;
  }

  /**
  * An exact node beats any wildcard; a longer wildcard prefix beats a shorter
  * one; the global node is the weakest match that still applies.
  */
  private static function matchSpecificity(string $granted, string $requested): int {
    if ($granted === $requested) {
      return 1000000 + strlen($granted);
    }
    if ($granted === '*') {
      return 0;
    }
    if (str_ends_with($granted, '.*') && str_starts_with($requested, substr($granted, 0, -1))) {
      return strlen($granted) - 1;
    }
    return -1;
  }

  /** @return list<int> */
  private static function permissionPriority(PermissionCandidate $candidate): array {
    $node = $candidate->node;
    return [
      $candidate->direct ? 1 : 0,
      $node->isTemporary() ? 1 : 0,
      $candidate->matchSpecificity,
      $node->contexts->specificity(),
      $candidate->groupWeight,
      -$candidate->inheritanceDistance,
      $node->expiresAt !== null ? -$node->expiresAt : self::NEVER_EXPIRES,
      $node->permissionValue() ? 0 : 1
    ];
  }

  /** @return list<int> */
  private static function metaPriority(MetaCandidate $candidate, bool $stacked): array {
    $node = $candidate->node;
    return [
      $stacked ? $node->priority : 0,
      $candidate->direct ? 1 : 0,
      $node->isTemporary() ? 1 : 0,
      $node->contexts->specificity(),
      $candidate->groupWeight,
      -$candidate->inheritanceDistance,
      $node->expiresAt !== null ? -$node->expiresAt : self::NEVER_EXPIRES,
      $node->createdAt,
      $node->id ?? 0
    ];
  }
}
