<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use pocketmine\player\Player;
use stoneperms\domain\SubjectRef;
use stoneperms\domain\UserRecord;

/**
* How a PocketMine player maps onto a StonePerms subject.
*
* The UUID is canonical, matching the Endstone build so one dashboard shows the
* same player id for both. On servers running with `xbox-auth` disabled the
* UUID is derived from the name and is therefore not stable across renames;
* the startup report warns about exactly that.
*/
final class PlayerIdentity {

  public static function uniqueId(Player $player): string {
    return $player->getUniqueId()->toString();
  }

  public static function subject(Player $player): SubjectRef {
    return SubjectRef::user(self::uniqueId($player));
  }

  public static function record(Player $player): UserRecord {
    $xuid = trim($player->getXuid());
    return new UserRecord(self::uniqueId($player), $player->getName(), $xuid === '' ? null : $xuid);
  }
}
