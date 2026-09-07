<?php

declare(strict_types = 1);

namespace stoneperms\command;

use imperazim\command\enum\CommandEnumManager;
use pocketmine\network\mcpe\protocol\types\command\CommandSoftEnum;
use stoneperms\application\StonePermsManager;
use Throwable;

/**
* Live command suggestions for group and track names.
*
* Group and track names are registered as soft enums and pushed to clients, so
* the in-game command UI can offer what actually exists on this server rather
* than a list baked in at build time.
*
* Each enum is synchronised independently and add-or-update is idempotent, so
* one failing broadcast never leaves the other enum unregistered and never
* stops later refreshes from trying again.
*/
final class CommandEnums {

  public const GROUPS = 'stoneperms.groups';
  public const TRACKS = 'stoneperms.tracks';

  public static function register(StonePermsManager $manager): void {
    self::sync(self::GROUPS, self::groupNames($manager));
    self::sync(self::TRACKS, self::trackNames($manager));
  }

  public static function refresh(StonePermsManager $manager): void {
    self::register($manager);
  }

  /** @param list<string> $values */
  private static function sync(string $name, array $values): void {
    try {
      if (CommandEnumManager::getEnumByName($name) === null) {
        CommandEnumManager::addEnum(new CommandSoftEnum($name, $values));
        return;
      }
      CommandEnumManager::updateEnum($name, $values);
    } catch (Throwable) {
      // Suggestions are a convenience. A permission change must never fail
      // because the client could not be told about a new group name.
    }
  }

  /** @return list<string> */
  private static function groupNames(StonePermsManager $manager): array {
    return array_map(static fn(object $group): string => $group->name, $manager->listGroups());
  }

  /** @return list<string> */
  private static function trackNames(StonePermsManager $manager): array {
    return array_map(static fn(object $track): string => $track->name, $manager->listTracks());
  }
}
