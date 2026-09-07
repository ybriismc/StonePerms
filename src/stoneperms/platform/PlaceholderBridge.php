<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use imperazim\placeholder\PlaceholderAPI;
use pocketmine\plugin\Plugin;
use Throwable;

/**
* Registers the `stoneperms` expansion with LibPlaceholder, if a host is
* available. Everything else in the plugin works whether or not it is.
*/
final class PlaceholderBridge {

  public const IDENTIFIER = 'stoneperms';

  private bool $registered = false;

  public function __construct(
    private readonly Plugin $plugin,
    private readonly StonePermsService $service
  ) {}

  public function register(): bool {
    if ($this->registered || !class_exists(PlaceholderAPI::class) || !PlaceholderAPI::isAvailable()) {
      return false;
    }
    try {
      $this->registered = PlaceholderAPI::registerExpansion(
        new StonePermsExpansion($this->service),
        $this->plugin,
        true
      );
    } catch (Throwable $throwable) {
      $this->plugin->getLogger()->warning(
        'StonePerms could not register its placeholders: ' . $throwable->getMessage()
      );
      return false;
    }
    return $this->registered;
  }

  public function unregister(): void {
    if (!$this->registered) {
      return;
    }
    try {
      PlaceholderAPI::unregister(self::IDENTIFIER, $this->plugin);
    } catch (Throwable) {
      // The host may already have torn the registry down during shutdown.
    }
    $this->registered = false;
  }

  public function isRegistered(): bool {
    return $this->registered;
  }

  /** @return list<string> */
  public static function placeholders(): array {
    return [
      '%stoneperms_prefix%',
      '%stoneperms_suffix%',
      '%stoneperms_primary_group%',
      '%stoneperms_groups%',
      '%stoneperms_meta.<key>%',
      '%stoneperms_track.current.<track>%',
      '%stoneperms_track.next.<track>%',
      '%stoneperms_track.previous.<track>%',
      '%stoneperms_track.has.<track>%'
    ];
  }
}
