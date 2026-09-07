<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use Closure;
use InvalidArgumentException;
use pocketmine\permission\PermissionAttachment;
use pocketmine\permission\PermissionManager;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use stoneperms\application\StonePermsManager;
use stoneperms\domain\Validation;
use Throwable;

/**
* Pushes resolved permissions onto PocketMine's native attachment API.
*
* PocketMine has no wildcard matching of its own: `hasPermission('a.b')` never
* consults an `a.*` node. StonePerms therefore keeps a catalog of every
* registered permission, resolves each one for the player, and writes the
* concrete results onto one attachment. Permissions that resolve to nothing are
* deliberately left off the attachment so whatever default the owning plugin
* registered stays in force.
*/
final class AttachmentManager {

  /** @var array<string, PermissionAttachment> */
  private array $attachments = [];

  /** @var array<string, array<string, bool>> */
  private array $applied = [];

  /** @var array<string, true> */
  private array $catalog = [];

  private ?Closure $onPlayerApplied;

  /** @param (callable(Player): void)|null $onPlayerApplied */
  public function __construct(
    private readonly Plugin $plugin,
    private readonly StonePermsManager $manager,
    private readonly ContextCalculator $contexts,
    ?callable $onPlayerApplied = null
  ) {
    $this->onPlayerApplied = $onPlayerApplied !== null ? Closure::fromCallable($onPlayerApplied) : null;
  }

  public function registerPlayer(Player $player, bool $joined = false): void {
    $this->manager->observePlayer(ProfileCapture::capture($player, true, $joined));
  }

  public function updatePlayerProfile(Player $player): void {
    $this->manager->observePlayer(ProfileCapture::capture($player));
  }

  public function recordPlayerQuit(Player $player): void {
    $this->manager->observePlayer(ProfileCapture::capture($player, false, false, true));
  }

  /** @return list<string> */
  public function permissionCatalog(): array {
    return array_keys($this->catalog);
  }

  /**
  * Recomputes the player's permissions and writes only what changed.
  */
  public function applyPlayer(Player $player): bool {
    $this->registerPlayer($player);
    $identity = PlayerIdentity::uniqueId($player);
    $subject = PlayerIdentity::subject($player);

    $names = $this->catalog;
    foreach ($this->manager->resolvablePermissionKeys($subject) as $key) {
      $names[$key] = true;
    }

    $desired = $this->manager->resolvePermissions(
      $subject,
      array_keys($names),
      $this->contexts->calculate($player)
    );
    ksort($desired);

    $previous = $this->applied[$identity] ?? [];
    if ($desired === $previous) {
      $this->notify($player);
      return false;
    }

    $attachment = $this->attachments[$identity] ?? null;
    if ($attachment === null) {
      $attachment = $player->addAttachment($this->plugin);
      $this->attachments[$identity] = $attachment;
    }

    foreach (array_keys(array_diff_key($previous, $desired)) as $permission) {
      $attachment->unsetPermission($permission);
    }
    foreach ($desired as $permission => $value) {
      if (($previous[$permission] ?? null) !== $value) {
        $attachment->setPermission($permission, $value);
      }
    }

    $this->recalculate($player);
    $this->applied[$identity] = $desired;
    $this->notify($player);
    return true;
  }

  public function applyByUniqueId(string $uniqueId): bool {
    foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
      if (PlayerIdentity::uniqueId($player) === $uniqueId) {
        return $this->applyPlayer($player);
      }
    }
    return false;
  }

  public function refreshAll(): int {
    $changed = 0;
    foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
      $changed += $this->applyPlayer($player) ? 1 : 0;
    }
    return $changed;
  }

  /**
  * Rebuilds the list of permissions the server knows about. Plugins register
  * theirs as they enable, so this is polled rather than assumed to be static.
  */
  public function refreshPermissionCatalog(): bool {
    $catalog = [];
    foreach (PermissionManager::getInstance()->getPermissions() as $permission) {
      try {
        $catalog[Validation::permission($permission->getName())] = true;
      } catch (InvalidArgumentException | Throwable) {
        continue;
      }
    }
    ksort($catalog);
    if ($catalog === $this->catalog) {
      return false;
    }
    $this->catalog = $catalog;
    $this->refreshAll();
    return true;
  }

  public function removePlayer(Player $player): void {
    $identity = PlayerIdentity::uniqueId($player);
    $attachment = $this->attachments[$identity] ?? null;
    unset($this->attachments[$identity], $this->applied[$identity]);
    if ($attachment !== null) {
      $this->detach($player, $attachment);
    }
  }

  public function close(): void {
    // Attachments are owned by the permissible, so they are detached through
    // the player rather than the attachment itself. A player who has already
    // left took their permissible with them and needs no cleanup.
    foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
      $attachment = $this->attachments[PlayerIdentity::uniqueId($player)] ?? null;
      if ($attachment !== null) {
        $this->detach($player, $attachment);
      }
    }
    $this->attachments = [];
    $this->applied = [];
    $this->catalog = [];
  }

  private function detach(Player $player, PermissionAttachment $attachment): void {
    try {
      $player->removeAttachment($attachment);
    } catch (Throwable $throwable) {
      $this->plugin->getLogger()->warning(
        'Could not remove the permission attachment for player ' . $player->getName()
        . ': ' . $throwable->getMessage()
      );
    }
  }

  /**
  * Attachment writes already mark the permissible dirty on current builds; the
  * explicit recalculation and command resync keep older ones in step.
  */
  private function recalculate(Player $player): void {
    try {
      if (method_exists($player, 'recalculatePermissions')) {
        $player->recalculatePermissions();
      }
      $session = method_exists($player, 'getNetworkSession') ? $player->getNetworkSession() : null;
      if ($session !== null && method_exists($session, 'syncAvailableCommands')) {
        $session->syncAvailableCommands();
      }
    } catch (Throwable $throwable) {
      $this->plugin->getLogger()->debug(
        'StonePerms could not resynchronise permissions: ' . $throwable->getMessage()
      );
    }
  }

  private function notify(Player $player): void {
    if ($this->onPlayerApplied !== null) {
      ($this->onPlayerApplied)($player);
    }
  }
}
