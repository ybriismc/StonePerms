<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use Closure;
use InvalidArgumentException;
use pocketmine\player\Player;
use stoneperms\domain\ContextSet;
use stoneperms\domain\Validation;
use Throwable;

/**
* Builds the active context set for a player.
*
* Four contexts are built in — `server`, `world`, `dimension` and `gamemode` —
* plus optional device and locale contexts. Other plugins can contribute their
* own through `registerProvider`, which is how a minigame exposes an arena or a
* region plugin exposes a zone.
*/
final class ContextCalculator {

  /** @var array<string, array{owner: string, key: string, provider: Closure}> */
  private array $providers = [];

  public function __construct(private Settings $settings) {}

  public function calculate(Player $player): ContextSet {
    $pairs = [['server', $this->settings->serverContext]];

    $world = $player->getWorld();
    $pairs[] = ['world', self::safe($world->getFolderName())];
    $pairs[] = ['dimension', self::dimension($world)];
    $pairs[] = ['gamemode', self::safe(self::gameMode($player))];

    if ($this->settings->includeDeviceOsContext) {
      $pairs[] = ['device_os', self::safe(self::deviceOs($player))];
    }
    if ($this->settings->includeLocaleContext) {
      $pairs[] = ['locale', self::safe(self::locale($player))];
    }

    foreach ($this->providers as $registration) {
      try {
        $values = ($registration['provider'])($player);
      } catch (Throwable) {
        continue;
      }
      if ($values === null) {
        continue;
      }
      foreach (is_array($values) ? $values : [$values] as $value) {
        try {
          $pairs[] = [$registration['key'], Validation::contextValue((string) $value)];
        } catch (InvalidArgumentException) {
          continue;
        }
      }
    }

    return ContextSet::of($pairs);
  }

  public function updateSettings(Settings $settings): void {
    $this->settings = $settings;
  }

  /**
  * @param callable(Player): (string|list<string>|null) $provider
  */
  public function registerProvider(string $owner, string $key, callable $provider): void {
    $ownerName = strtolower(trim($owner));
    if ($ownerName === '') {
      throw new InvalidArgumentException('A context provider owner is required');
    }
    $normalizedKey = Validation::contextKey($key);
    $this->providers[$ownerName . "\0" . $normalizedKey] = [
      'owner' => $ownerName,
      'key' => $normalizedKey,
      'provider' => Closure::fromCallable($provider)
    ];
  }

  public function unregisterOwner(string $owner): void {
    $ownerName = strtolower(trim($owner));
    foreach ($this->providers as $identity => $registration) {
      if ($registration['owner'] === $ownerName) {
        unset($this->providers[$identity]);
      }
    }
  }

  /** @return list<string> */
  public function providerKeys(): array {
    return array_values(array_unique(array_map(
      static fn(array $registration): string => $registration['key'],
      $this->providers
    )));
  }

  /**
  * PocketMine exposes the dimension through the world provider rather than the
  * world itself, and not on every build, so this degrades to `overworld`
  * instead of guessing wrong.
  */
  private static function dimension(object $world): string {
    foreach (['getDimensionId', 'getDimension'] as $method) {
      if (!method_exists($world, $method)) {
        continue;
      }
      try {
        $value = $world->$method();
      } catch (Throwable) {
        continue;
      }
      if (is_int($value)) {
        return match ($value) {
          1 => 'nether',
          2 => 'the_end',
          default => 'overworld'
        };
      }
      if (is_string($value) && $value !== '') {
        return self::safe($value);
      }
    }
    return 'overworld';
  }

  private static function gameMode(Player $player): string {
    $mode = $player->getGamemode();
    if (is_object($mode)) {
      if (property_exists($mode, 'name')) {
        return (string) $mode->name;
      }
      if (method_exists($mode, 'name')) {
        return (string) $mode->name();
      }
      if (method_exists($mode, 'getEnglishName')) {
        return (string) $mode->getEnglishName();
      }
    }
    return (string) $mode;
  }

  private static function deviceOs(Player $player): string {
    if (!method_exists($player, 'getPlayerInfo')) {
      return 'unknown';
    }
    try {
      $info = $player->getPlayerInfo();
      $extra = method_exists($info, 'getExtraData') ? $info->getExtraData() : [];
    } catch (Throwable) {
      return 'unknown';
    }
    return is_array($extra) && isset($extra['DeviceOS']) ? (string) $extra['DeviceOS'] : 'unknown';
  }

  private static function locale(Player $player): string {
    if (!method_exists($player, 'getLocale')) {
      return 'unknown';
    }
    try {
      return (string) $player->getLocale();
    } catch (Throwable) {
      return 'unknown';
    }
  }

  /**
  * Context values are constrained; anything that does not fit becomes
  * `unknown` rather than throwing in the middle of a permission check.
  */
  private static function safe(string $value): string {
    $text = str_replace(' ', '_', strtolower(trim($value)));
    try {
      return Validation::contextValue($text);
    } catch (InvalidArgumentException) {
      return 'unknown';
    }
  }
}
