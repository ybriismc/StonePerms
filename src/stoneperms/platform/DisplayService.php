<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use imperazim\hud\nametag\NameTagManager;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use stoneperms\application\StonePermsManager;
use Throwable;

/**
* Renders the resolved prefix and suffix into chat and above players.
*
* Both formatters ship disabled. Chat formatting only replaces the event's
* format; it never cancels or rebroadcasts the message, so other chat plugins
* keep working. Nametags go through LibHud's manager, which sends per-observer
* packets rather than mutating the entity, so disabling the feature restores
* whatever the player had before.
*/
final class DisplayService {

  /** @var array<string, string> */
  private array $appliedNameTags = [];

  private bool $chatFormatterWarned = false;

  public function __construct(
    private readonly Plugin $plugin,
    private readonly StonePermsManager $manager,
    private readonly ContextCalculator $contexts,
    private DisplaySettings $settings
  ) {}

  public function settings(): DisplaySettings {
    return $this->settings;
  }

  /** @return array<string, mixed> */
  public function webSettings(): array {
    return [
      'chat' => [
        'enabled' => $this->settings->chatEnabled,
        'format' => $this->settings->chatFormat,
        'placeholders' => DisplaySettings::CHAT_PLACEHOLDERS
      ],
      'nametag' => [
        'enabled' => $this->settings->nametagEnabled,
        'format' => $this->settings->nametagFormat,
        'placeholders' => DisplaySettings::NAMETAG_PLACEHOLDERS
      ]
    ];
  }

  /**
  * @param callable(DisplaySettings): void $persist
  * @return array<string, mixed>
  */
  public function updateSettings(
    bool $chatEnabled,
    string $chatFormat,
    bool $nametagEnabled,
    string $nametagFormat,
    callable $persist
  ): array {
    $updated = new DisplaySettings(
      $chatEnabled,
      Settings::validateFormat($chatFormat, DisplaySettings::CHAT_PLACEHOLDERS, ['name', 'message'], 'chatFormat'),
      $nametagEnabled,
      Settings::validateFormat($nametagFormat, DisplaySettings::NAMETAG_PLACEHOLDERS, ['name'], 'nametagFormat')
    );
    $previous = $this->settings;
    $this->settings = $updated;
    $persist($updated);

    if ($previous->nametagEnabled && !$updated->nametagEnabled) {
      $this->restoreAllNameTags();
    } elseif ($updated->nametagEnabled) {
      $this->refreshAllNameTags();
    }
    return $this->webSettings();
  }

  public function applyChatFormat(PlayerChatEvent $event): void {
    if (!$this->settings->chatEnabled) {
      return;
    }
    $values = $this->playerValues($event->getPlayer());
    $rendered = self::render($this->settings->chatFormat, $values, '{%1}');

    // PocketMine 5 replaced the plain format string with a ChatFormatter; the
    // legacy setter is kept as the fallback for older API 5 builds.
    try {
      $formatter = 'pocketmine\\player\\chat\\LegacyRawChatFormatter';
      if (method_exists($event, 'setFormatter') && class_exists($formatter)) {
        $event->setFormatter(new $formatter($rendered));
        return;
      }
      if (method_exists($event, 'setFormat')) {
        $event->setFormat($rendered);
        return;
      }
    } catch (Throwable $throwable) {
      $this->warnChatFormatter($throwable->getMessage());
      return;
    }
    $this->warnChatFormatter('this PocketMine build exposes no chat format setter');
  }

  public function applyNameTag(Player $player): bool {
    if (!$this->settings->nametagEnabled) {
      return $this->restoreNameTag($player);
    }
    $identity = PlayerIdentity::uniqueId($player);
    $rendered = self::render($this->settings->nametagFormat, $this->playerValues($player), '', true);
    if (($this->appliedNameTags[$identity] ?? null) === $rendered) {
      return false;
    }
    NameTagManager::set($player, $rendered);
    $this->appliedNameTags[$identity] = $rendered;
    return true;
  }

  public function restoreNameTag(Player $player): bool {
    $identity = PlayerIdentity::uniqueId($player);
    if (!isset($this->appliedNameTags[$identity])) {
      return false;
    }
    unset($this->appliedNameTags[$identity]);
    NameTagManager::reset($player);
    return true;
  }

  public function forgetPlayer(Player $player): void {
    unset($this->appliedNameTags[PlayerIdentity::uniqueId($player)]);
    NameTagManager::cleanup($player);
  }

  public function refreshAllNameTags(): int {
    $changed = 0;
    foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
      $changed += $this->applyNameTag($player) ? 1 : 0;
    }
    return $changed;
  }

  public function restoreAllNameTags(): int {
    $changed = 0;
    foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
      $changed += $this->restoreNameTag($player) ? 1 : 0;
    }
    return $changed;
  }

  public function close(): void {
    $this->restoreAllNameTags();
    $this->appliedNameTags = [];
  }

  /** @return array<string, string> */
  private function playerValues(Player $player): array {
    $subject = PlayerIdentity::subject($player);
    $contexts = $this->contexts->calculate($player);
    return [
      // The legacy formatter substitutes {%0} and {%1} after this runs, so a
      // prefix that happens to contain one must not become a second slot.
      'prefix' => self::sanitise($this->manager->resolvePrefix($subject, $contexts)->value ?? ''),
      'name' => self::sanitise($player->getName()),
      'suffix' => self::sanitise($this->manager->resolveSuffix($subject, $contexts)->value ?? '')
    ];
  }

  /**
  * Replaces the supported placeholders and nothing else.
  *
  * `$keepName` leaves `{name}` in place for LibHud's nametag manager, which
  * substitutes it per observer along with its own tokens.
  *
  * @param array<string, string> $values
  */
  private static function render(
    string $template,
    array $values,
    string $messageMarker = '',
    bool $keepName = false
  ): string {
    return preg_replace_callback(
      '/\{([a-z]+)\}/',
      static function (array $matches) use ($values, $messageMarker, $keepName): string {
        if ($matches[1] === 'message') {
          return $messageMarker;
        }
        if ($keepName && $matches[1] === 'name') {
          return '{name}';
        }
        return $values[$matches[1]] ?? $matches[0];
      },
      $template
    ) ?? $template;
  }

  private static function sanitise(string $value): string {
    return str_replace(['{%0}', '{%1}'], '', $value);
  }

  private function warnChatFormatter(string $reason): void {
    if ($this->chatFormatterWarned) {
      return;
    }
    $this->chatFormatterWarned = true;
    $this->plugin->getLogger()->warning(
      "StonePerms chat formatting is enabled but could not be applied: $reason"
    );
  }
}
