<?php

declare(strict_types = 1);

namespace stoneperms\domain;

use InvalidArgumentException;

/**
* Identifies whatever owns a node: one user, or one group.
*
* Group identifiers are normalised on construction so `SubjectRef::group('VIP')`
* and `SubjectRef::group('vip')` are the same subject.
*/
final class SubjectRef {

  private function __construct(
    public readonly SubjectType $type,
    public readonly string $identifier
  ) {}

  public static function user(string $uniqueId): self {
    $identifier = trim($uniqueId);
    if ($identifier === '') {
      throw new InvalidArgumentException('A user subject id is required');
    }
    return new self(SubjectType::USER, $identifier);
  }

  public static function group(string $name): self {
    return new self(SubjectType::GROUP, Validation::groupName($name));
  }

  public static function of(SubjectType $type, string $identifier): self {
    return $type === SubjectType::GROUP ? self::group($identifier) : self::user($identifier);
  }

  public function equals(self $other): bool {
    return $this->type === $other->type && $this->identifier === $other->identifier;
  }

  /**
  * Stable key for array indexing, since PHP has no value-object hashing.
  */
  public function key(): string {
    return $this->type->value . ':' . $this->identifier;
  }

  public function __toString(): string {
    return $this->key();
  }
}
