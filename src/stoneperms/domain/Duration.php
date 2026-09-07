<?php

declare(strict_types = 1);

namespace stoneperms\domain;

use InvalidArgumentException;

/**
* Parser for the compact duration syntax used by temporary nodes, for example
* `30m`, `2h30m`, `7d` or `1mo2d`. Units may be combined but not repeated out
* of order, and the whole string must be consumed.
*/
final class Duration {

  public const MAX_SECONDS = 10 * 365 * 24 * 60 * 60;

  private const SECONDS_PER_UNIT = [
    's' => 1,
    'm' => 60,
    'h' => 3600,
    'd' => 86400,
    'w' => 604800,
    'mo' => 2592000
  ];

  public static function parse(string $value): int {
    $text = strtolower(trim($value));
    if ($text === '') {
      throw new InvalidArgumentException('A duration is required');
    }

    $position = 0;
    $seconds = 0;
    $matches = [];
    preg_match_all('/([1-9][0-9]*)(mo|[smhdw])/', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

    foreach ($matches as $match) {
      if ($match[0][1] !== $position) {
        throw new InvalidArgumentException("Invalid duration: '$value'");
      }
      $position = $match[0][1] + strlen($match[0][0]);
      $seconds += ((int) $match[1][0]) * self::SECONDS_PER_UNIT[$match[2][0]];
      if ($seconds > self::MAX_SECONDS) {
        throw new InvalidArgumentException('Duration may not exceed 10 years');
      }
    }

    if ($position !== strlen($text) || $seconds <= 0) {
      throw new InvalidArgumentException("Invalid duration: '$value'");
    }
    return $seconds;
  }

  /**
  * Renders a number of seconds back into the compact syntax.
  */
  public static function format(int $seconds): string {
    if ($seconds <= 0) {
      return '0s';
    }
    $parts = [];
    foreach (['mo' => 2592000, 'w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1] as $unit => $size) {
      $amount = intdiv($seconds, $size);
      if ($amount > 0) {
        $parts[] = $amount . $unit;
        $seconds -= $amount * $size;
      }
    }
    return implode('', $parts);
  }
}
