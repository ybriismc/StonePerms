<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use imperazim\placeholder\PlaceholderContext;
use imperazim\placeholder\PlaceholderExpansion;
use Stringable;
use Throwable;

/**
* The `stoneperms` placeholder expansion.
*
* A real expansion rather than a closure, so `/papi info stoneperms` reports an
* author and a version alongside the identifier. Unknown parameters return null,
* which leaves the raw placeholder in the text instead of blanking it — a typo
* stays visible rather than silently producing an empty string.
*/
final class StonePermsExpansion extends PlaceholderExpansion {

  public function __construct(private readonly StonePermsService $service) {}

  public function getIdentifier(): string {
    return 'stoneperms';
  }

  public function getAuthor(): string {
    return 'StonePerms';
  }

  public function getVersion(): string {
    return $this->service->productVersion();
  }

  public function onRequest(PlaceholderContext $context): string|int|float|bool|Stringable|null {
    $player = $context->getPlayer();
    if ($player === null) {
      return null;
    }
    $parameters = strtolower(trim($context->getParameters()));

    try {
      return match (true) {
        $parameters === 'prefix' => $this->service->getPrefix($player) ?? '',
        $parameters === 'suffix' => $this->service->getSuffix($player) ?? '',
        $parameters === 'primary_group' => $this->service->getPrimaryGroup($player),
        $parameters === 'groups' => implode(', ', $this->service->getGroups($player)),
        str_starts_with($parameters, 'meta.') => $this->service->getMeta($player, substr($parameters, 5)) ?? '',
        str_starts_with($parameters, 'track.') => $this->track($player, substr($parameters, 6)),
        default => null
      };
    } catch (Throwable) {
      return null;
    }
  }

  private function track(object $player, string $parameters): ?string {
    $separator = strpos($parameters, '.');
    if ($separator === false) {
      return null;
    }
    $operation = substr($parameters, 0, $separator);
    $trackName = substr($parameters, $separator + 1);
    if ($trackName === '') {
      return null;
    }

    $current = $this->service->getUserTracks($player)[$trackName][0] ?? null;
    if ($operation === 'has') {
      return $current !== null ? 'true' : 'false';
    }
    if ($operation === 'current') {
      return $current ?? '';
    }
    if ($current === null) {
      return '';
    }

    $track = $this->service->manager()->getTrack($trackName);
    $index = $track->indexOf($current);
    if ($index === null) {
      return '';
    }
    return match ($operation) {
      'next' => $track->groups[$index + 1] ?? '',
      'previous' => $index > 0 ? $track->groups[$index - 1] : '',
      default => null
    };
  }
}
