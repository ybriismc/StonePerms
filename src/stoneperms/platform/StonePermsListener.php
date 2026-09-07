<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use imperazim\components\scheduler\TaskSchedulerAPI;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerGameModeChangeEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerChangeSkinEvent;
use pocketmine\event\plugin\PluginEnableEvent;
use pocketmine\player\Player;
use stoneperms\StonePermsPlugin;

/**
* Keeps applied permissions in step with the things that can change them:
* joining, changing world, changing game mode, and other plugins registering
* new permission nodes as they enable.
*/
final class StonePermsListener implements Listener {

  public function __construct(private readonly StonePermsPlugin $plugin) {}

  /** @priority MONITOR */
  public function onJoin(PlayerJoinEvent $event): void {
    $player = $event->getPlayer();
    $this->plugin->attachments()->registerPlayer($player, true);
    $this->plugin->attachments()->applyPlayer($player);
    $this->plugin->display()->applyNameTag($player);
  }

  /** @priority MONITOR */
  public function onQuit(PlayerQuitEvent $event): void {
    $player = $event->getPlayer();
    $this->plugin->attachments()->recordPlayerQuit($player);
    $this->plugin->attachments()->removePlayer($player);
    $this->plugin->display()->forgetPlayer($player);
  }

  /**
  * Runs late so a chat plugin that wants full control can win by handling the
  * event first; StonePerms only rewrites the format it is given.
  *
  * @priority HIGH
  */
  public function onChat(PlayerChatEvent $event): void {
    if (!$event->isCancelled()) {
      $this->plugin->display()->applyChatFormat($event);
    }
  }

  /** @priority MONITOR */
  public function onGameModeChange(PlayerGameModeChangeEvent $event): void {
    if (!$event->isCancelled()) {
      $this->reapplyNextTick($event->getPlayer());
    }
  }

  /**
  * The event fires before the new skin is applied, so the profile is captured
  * on the next tick to record the skin the player actually ends up wearing.
  *
  * @priority MONITOR
  */
  public function onSkinChange(PlayerChangeSkinEvent $event): void {
    if ($event->isCancelled()) {
      return;
    }
    $identity = PlayerIdentity::uniqueId($event->getPlayer());
    TaskSchedulerAPI::once(1, function () use ($identity): void {
      foreach ($this->plugin->getServer()->getOnlinePlayers() as $online) {
        if (PlayerIdentity::uniqueId($online) === $identity) {
          $this->plugin->attachments()->updatePlayerProfile($online);
          return;
        }
      }
    });
  }

  /**
  * Cross-world teleports change the `world` and `dimension` contexts, which can
  * change which nodes apply.
  *
  * @priority MONITOR
  */
  public function onTeleport(EntityTeleportEvent $event): void {
    $entity = $event->getEntity();
    if (!$entity instanceof Player || $event->isCancelled()) {
      return;
    }
    if ($event->getFrom()->getWorld() === $event->getTo()->getWorld()) {
      return;
    }
    $this->reapplyNextTick($entity);
  }

  /**
  * A plugin enabling registers its permission nodes; the catalog has to grow
  * with it or wildcards will not cover the new nodes.
  */
  public function onPluginEnable(PluginEnableEvent $event): void {
    $this->plugin->attachments()->refreshPermissionCatalog();
  }

  /**
  * Game mode and teleport events fire before the change lands, so the
  * recalculation waits one tick for the new state.
  */
  private function reapplyNextTick(Player $player): void {
    $identity = PlayerIdentity::uniqueId($player);
    TaskSchedulerAPI::once(1, function () use ($identity): void {
      $this->plugin->attachments()->applyByUniqueId($identity);
      foreach ($this->plugin->getServer()->getOnlinePlayers() as $online) {
        if (PlayerIdentity::uniqueId($online) === $identity) {
          $this->plugin->display()->applyNameTag($online);
          return;
        }
      }
    });
  }
}
