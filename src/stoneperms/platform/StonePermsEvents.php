<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use imperazim\components\event\EventBus;
use Throwable;

/**
* Change notifications on EasyLibrary's event bus.
*
* Another plugin can react to a permission change without registering a
* PocketMine listener or depending on StonePerms at compile time:
*
* ```php
* EventBus::on(StonePermsEvents::USER_CHANGED, function (array $data): void {
*     // $data['uniqueId'], $data['changed']
* });
* ```
*
* Emitting never throws: a badly behaved subscriber must not be able to break
* a permission write.
*/
final class StonePermsEvents {

  /** One player's permissions were recalculated. */
  public const USER_CHANGED = 'stoneperms.user.changed';

  /** Permission data changed in a way that affects everyone. */
  public const DATA_CHANGED = 'stoneperms.data.changed';

  /** Temporary nodes expired and were removed. */
  public const NODES_EXPIRED = 'stoneperms.nodes.expired';

  /** The dashboard connection changed state. */
  public const WEB_STATUS = 'stoneperms.web.status';

  /** @param array<string, mixed> $data */
  public static function emit(string $event, array $data = []): void {
    try {
      EventBus::emit($event, $data);
    } catch (Throwable) {
      // A subscriber failing is not StonePerms' problem to propagate.
    }
  }
}
