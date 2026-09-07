<?php

declare(strict_types = 1);

namespace stoneperms\domain;

use InvalidArgumentException;

/**
* Canonical forms for every identifier StonePerms stores.
*
* Normalisation happens once, on the way in. Everything downstream may then
* compare identifiers with a plain string comparison.
*/
final class Validation {

  private const GROUP_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
  private const TRACK_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
  private const PERMISSION_SEGMENT_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';
  private const CONTEXT_KEY_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';
  private const CONTEXT_VALUE_PATTERN = '/^[a-z0-9][a-z0-9_.:\/-]{0,127}$/';
  private const META_KEY_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

  public static function groupName(string $value): string {
    $name = self::fold($value);
    if (preg_match(self::GROUP_PATTERN, $name) !== 1) {
      throw new InvalidArgumentException(
        'Group names must contain 1-64 lowercase letters, numbers, underscores, or dashes'
      );
    }
    return $name;
  }

  public static function trackName(string $value): string {
    $name = self::fold($value);
    if (preg_match(self::TRACK_PATTERN, $name) !== 1) {
      throw new InvalidArgumentException(
        'Track names must contain 1-64 lowercase letters, numbers, underscores, or dashes'
      );
    }
    return $name;
  }

  /**
  * Accepts an exact node, a terminal wildcard such as `namespace.*`, and the
  * global `*` node. A wildcard anywhere but the final segment is rejected.
  */
  public static function permission(string $value): string {
    $permission = self::fold($value);
    if ($permission === '*') {
      return $permission;
    }
    $segments = explode('.', $permission);
    if (count($segments) < 2) {
      throw new InvalidArgumentException('Permission nodes must contain at least one namespace separator');
    }
    $last = count($segments) - 1;
    foreach ($segments as $index => $segment) {
      if ($segment === '') {
        throw new InvalidArgumentException('Permission nodes must contain at least one namespace separator');
      }
      if ($segment === '*') {
        if ($index !== $last) {
          throw new InvalidArgumentException('A wildcard is only allowed as the final permission segment');
        }
        continue;
      }
      if (preg_match(self::PERMISSION_SEGMENT_PATTERN, $segment) !== 1) {
        throw new InvalidArgumentException("Invalid permission segment: '$segment'");
      }
    }
    return $permission;
  }

  public static function contextKey(string $value): string {
    $key = self::fold($value);
    if (preg_match(self::CONTEXT_KEY_PATTERN, $key) !== 1) {
      throw new InvalidArgumentException("Invalid context key: '$value'");
    }
    return $key;
  }

  public static function contextValue(string $value): string {
    $normalized = self::fold($value);
    if (preg_match(self::CONTEXT_VALUE_PATTERN, $normalized) !== 1) {
      throw new InvalidArgumentException("Invalid context value: '$value'");
    }
    return $normalized;
  }

  public static function metaKey(string $value): string {
    $key = self::fold($value);
    if (preg_match(self::META_KEY_PATTERN, $key) !== 1) {
      throw new InvalidArgumentException("Invalid metadata key: '$value'");
    }
    return $key;
  }

  private static function fold(string $value): string {
    return strtolower(trim($value));
  }
}
