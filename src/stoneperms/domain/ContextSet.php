<?php

declare(strict_types = 1);

namespace stoneperms\domain;

use InvalidArgumentException;
use JsonException;

/**
* An immutable, normalised, de-duplicated and sorted set of context pairs.
*
* Sorting is what makes two context sets comparable by their JSON encoding,
* which the storage layer and the editor protocol both rely on.
*/
final class ContextSet {

  /** @var list<array{0: string, 1: string}> */
  private array $pairs;

  /** @param list<array{0: string, 1: string}> $pairs */
  private function __construct(array $pairs) {
    $this->pairs = $pairs;
  }

  public static function empty(): self {
    return new self([]);
  }

  /**
  * @param iterable<array{0: string, 1: string}> $pairs
  */
  public static function of(iterable $pairs): self {
    $seen = [];
    foreach ($pairs as $pair) {
      $key = Validation::contextKey((string) $pair[0]);
      $value = Validation::contextValue((string) $pair[1]);
      $seen[$key . "\0" . $value] = [$key, $value];
    }
    $normalized = array_values($seen);
    usort($normalized, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    return new self($normalized);
  }

  /**
  * Parses the `key=value` command syntax, either from one string of
  * whitespace-separated tokens or from a list of tokens.
  *
  * @param string|list<string>|null $value
  */
  public static function parse(string|array|null $value): self {
    if ($value === null) {
      return self::empty();
    }
    $tokens = is_array($value) ? $value : preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
    $pairs = [];
    foreach ($tokens as $token) {
      if (!str_contains($token, '=')) {
        throw new InvalidArgumentException("Context must use key=value syntax: '$token'");
      }
      [$key, $item] = explode('=', $token, 2);
      $pairs[] = [$key, $item];
    }
    return self::of($pairs);
  }

  public static function fromJson(string $value): self {
    try {
      $raw = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
      throw new InvalidArgumentException('Stored contexts are not valid JSON', 0, $exception);
    }
    if (!is_array($raw)) {
      throw new InvalidArgumentException('Stored contexts must be a list');
    }
    $pairs = [];
    foreach ($raw as $item) {
      if (!is_array($item) || count($item) !== 2) {
        throw new InvalidArgumentException('Stored contexts must be key/value pairs');
      }
      $pairs[] = [(string) $item[0], (string) $item[1]];
    }
    return self::of($pairs);
  }

  public function toJson(): string {
    return json_encode($this->pairs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
  }

  /** @return list<array{0: string, 1: string}> */
  public function pairs(): array {
    return $this->pairs;
  }

  public function isEmpty(): bool {
    return $this->pairs === [];
  }

  public function equals(self $other): bool {
    return $this->pairs === $other->pairs;
  }

  /** @return array<string, list<string>> */
  public function grouped(): array {
    $values = [];
    foreach ($this->pairs as [$key, $value]) {
      $values[$key][] = $value;
    }
    return $values;
  }

  /**
  * A node applies when every key it requires is present in the active set with
  * at least one matching value. Keys the node does not mention are ignored.
  */
  public function matches(self $active): bool {
    $current = $active->grouped();
    foreach ($this->grouped() as $key => $values) {
      if (!isset($current[$key]) || array_intersect($values, $current[$key]) === []) {
        return false;
      }
    }
    return true;
  }

  /**
  * How many distinct keys this set constrains. More specific wins.
  */
  public function specificity(): int {
    return count($this->grouped());
  }

  /** @return list<array{key: string, value: string}> */
  public function toWire(): array {
    return array_map(
      static fn(array $pair): array => ['key' => $pair[0], 'value' => $pair[1]],
      $this->pairs
    );
  }
}
