<?php

declare(strict_types = 1);

namespace stoneperms\domain;

use InvalidArgumentException;

/**
* One stored value belonging to one subject.
*
* The constructor canonicalises the key and value for the node type, so a node
* built from user input and a node loaded from storage are directly comparable.
*/
final class Node {

  public readonly string $key;
  public readonly string $value;
  public readonly ContextSet $contexts;

  public function __construct(
    public readonly SubjectRef $subject,
    public readonly NodeType $type,
    string $key,
    string $value,
    ?ContextSet $contexts = null,
    public readonly ?int $expiresAt = null,
    public readonly int $priority = 0,
    public readonly int $createdAt = 0,
    public readonly ?int $id = null
  ) {
    $key = trim($key);

    switch ($type) {
      case NodeType::PERMISSION:
        $key = Validation::permission($key);
        $value = strtolower(trim($value));
        if ($value !== 'true' && $value !== 'false') {
          throw new InvalidArgumentException('Permission node values must be true or false');
        }
        break;
      case NodeType::PARENT:
        $key = Validation::groupName($key);
        $value = 'true';
        break;
      case NodeType::META:
        $key = Validation::metaKey($key);
        break;
      case NodeType::PREFIX:
      case NodeType::SUFFIX:
        if (trim($value) === '') {
          throw new InvalidArgumentException($type->value . ' values may not be empty');
        }
        $key = $type->value;
        break;
    }

    if ($expiresAt !== null && $expiresAt <= 0) {
      throw new InvalidArgumentException('Expiry timestamps must be positive');
    }

    $this->key = $key;
    $this->value = $value;
    $this->contexts = $contexts ?? ContextSet::empty();
  }

  public function permissionValue(): bool {
    if ($this->type !== NodeType::PERMISSION) {
      throw new InvalidArgumentException('Only permission nodes have a boolean value');
    }
    return $this->value === 'true';
  }

  public function isTemporary(): bool {
    return $this->expiresAt !== null;
  }

  public function activeAt(int $timestamp): bool {
    return $this->expiresAt === null || $this->expiresAt > $timestamp;
  }

  /**
  * Identity used when comparing a stored node against a submitted one. The
  * database id and creation time are deliberately excluded.
  */
  public function identity(): string {
    return implode("\0", [
      $this->type->value,
      $this->key,
      $this->value,
      $this->contexts->toJson(),
      (string) ($this->expiresAt ?? 0),
      (string) $this->priority
    ]);
  }

  /** @return array<string, mixed> */
  public function toWire(): array {
    return [
      'type' => $this->type->value,
      'key' => $this->key,
      'value' => $this->value,
      'contexts' => $this->contexts->toWire(),
      'expiresAt' => $this->expiresAt,
      'priority' => $this->priority
    ];
  }
}
