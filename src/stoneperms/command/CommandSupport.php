<?php

declare(strict_types = 1);

namespace stoneperms\command;

use InvalidArgumentException;
use pocketmine\command\CommandSender;
use stoneperms\domain\ContextSet;
use stoneperms\domain\Duration;
use stoneperms\domain\Node;
use stoneperms\domain\NodeType;

/**
* Parsing and formatting shared by every StonePerms subcommand.
*/
final class CommandSupport {

  public const PREFIX = '§8[§eStonePerms§8]§r';

  /**
  * Trailing `key=value` tokens become the context set a node is scoped to.
  *
  * @param list<string> $tokens
  */
  public static function contexts(array $tokens): ContextSet {
    $expanded = [];
    foreach ($tokens as $token) {
      foreach (preg_split('/\s+/', trim((string) $token), -1, PREG_SPLIT_NO_EMPTY) as $piece) {
        $expanded[] = $piece;
      }
    }
    return ContextSet::parse($expanded);
  }

  public static function boolean(string $value): bool {
    $normalized = strtolower(trim($value));
    if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
      return true;
    }
    if (in_array($normalized, ['false', '0', 'no', 'off'], true)) {
      return false;
    }
    throw new InvalidArgumentException("Expected true or false, got '$value'");
  }

  public static function integer(string $value, string $label): int {
    if (!preg_match('/^-?\d+$/', trim($value))) {
      throw new InvalidArgumentException("$label must be a whole number");
    }
    return (int) trim($value);
  }

  /** Converts a duration such as `2h30m` into an absolute expiry timestamp. */
  public static function expiry(string $duration): int {
    return time() + Duration::parse($duration);
  }

  public static function renderContexts(ContextSet $contexts): string {
    if ($contexts->isEmpty()) {
      return '';
    }
    $parts = [];
    foreach ($contexts->pairs() as [$key, $value]) {
      $parts[] = "$key=$value";
    }
    return ' §8| §7contexts: §f' . implode(' ', $parts);
  }

  public static function renderNode(Node $node): string {
    $value = match ($node->type) {
      NodeType::PERMISSION => $node->value === 'true' ? '§atrue' : '§cfalse',
      NodeType::PARENT => '§f' . $node->key,
      default => '§f' . $node->value
    };
    $label = $node->type === NodeType::PARENT ? '' : '§f' . $node->key . ' §8= ';
    $expiry = $node->expiresAt === null
      ? ''
      : ' §8| §7expires in §f' . Duration::format(max(0, $node->expiresAt - time()));
    $priority = ($node->type === NodeType::PREFIX || $node->type === NodeType::SUFFIX)
      ? ' §8| §7priority §f' . $node->priority
      : '';
    return '§7' . $node->type->value . ': ' . $label . $value . $priority . $expiry
      . self::renderContexts($node->contexts);
  }

  public static function send(CommandSender $sender, string $message): void {
    $sender->sendMessage(self::PREFIX . ' ' . $message);
  }

  public static function error(CommandSender $sender, string $message): void {
    $sender->sendMessage(self::PREFIX . ' §c' . $message);
  }

  /**
  * A stable identity for the audit log. Console changes and in-game changes
  * are distinguishable in the dashboard's history.
  */
  public static function actor(CommandSender $sender): string {
    return $sender->getName();
  }

  /**
  * @param list<string> $tokens
  * @return list<string>
  */
  public static function slice(array $tokens, int $offset): array {
    return array_values(array_slice($tokens, $offset));
  }
}
