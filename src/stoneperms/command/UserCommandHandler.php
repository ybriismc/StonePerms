<?php

declare(strict_types = 1);

namespace stoneperms\command;

use InvalidArgumentException;
use pocketmine\command\CommandSender;
use stoneperms\domain\NodeType;
use stoneperms\domain\SubjectRef;
use stoneperms\StonePermsPlugin;

/**
* `/stoneperms user <name|uuid|xuid> ...` — everything that operates on a
* player. The identifier comes before the action, so this branch parses its own
* tokens rather than using a fixed subcommand tree.
*/
final class UserCommandHandler {

  public function __construct(private readonly StonePermsPlugin $plugin) {}

  /** @param list<string> $tokens tokens after `user` */
  public function handle(CommandSender $sender, array $tokens): void {
    $identifier = $tokens[0] ?? '';
    $action = strtolower($tokens[1] ?? '');
    if ($identifier === '' || $action === '') {
      CommandSupport::error($sender, 'Usage: /stoneperms user <name|uuid|xuid> <info|check|permission|parent|meta|prefix|suffix|promote|demote|showtracks> ...');
      return;
    }

    $subject = $this->plugin->manager()->userSubject($identifier);
    $rest = CommandSupport::slice($tokens, 2);

    match ($action) {
      'info' => $this->info($sender, $identifier, $subject),
      'check' => $this->check($sender, $subject, $rest),
      'permission' => $this->permission($sender, $subject, $rest),
      'parent' => $this->parent($sender, $subject, $rest),
      'meta' => $this->meta($sender, $subject, $rest),
      'prefix', 'suffix' => $this->affix($sender, $subject, $action, $rest),
      'promote', 'demote' => $this->move($sender, $identifier, $action, $rest),
      'showtracks' => $this->showTracks($sender, $subject, $rest),
      default => CommandSupport::error($sender, "Unknown user action '$action'.")
    };
  }

  private function info(CommandSender $sender, string $identifier, SubjectRef $subject): void {
    $manager = $this->plugin->manager();
    $user = $manager->findUser($identifier);
    $nodes = $manager->nodesFor($subject);

    CommandSupport::send($sender, '§7Player §f' . $user->lastName . ' §8| §7uuid §f' . $user->uniqueId);
    $sender->sendMessage('  §7xuid: §f' . ($user->xuid ?? '§8none'));
    $sender->sendMessage('  §7primary group: §f' . $manager->primaryGroup($subject));
    $sender->sendMessage('  §7groups: §f' . (implode(', ', $manager->effectiveGroups($subject)) ?: '§8none'));
    $sender->sendMessage('  §7prefix: §f' . ($manager->resolvePrefix($subject)->value ?? '§8none'));
    $sender->sendMessage('  §7suffix: §f' . ($manager->resolveSuffix($subject)->value ?? '§8none'));

    $meta = $manager->metaMap($subject);
    if ($meta !== []) {
      $parts = [];
      foreach ($meta as $key => $value) {
        $parts[] = "$key=$value";
      }
      $sender->sendMessage('  §7meta: §f' . implode(' ', $parts));
    }
    if ($nodes === []) {
      $sender->sendMessage('  §8No direct nodes.');
      return;
    }
    $sender->sendMessage('  §7direct nodes:');
    foreach ($nodes as $node) {
      $sender->sendMessage('    ' . CommandSupport::renderNode($node));
    }
  }

  /**
  * Explains a permission rather than just answering it: which node won, where
  * it came from, and how many others were in the running.
  *
  * @param list<string> $tokens
  */
  private function check(CommandSender $sender, SubjectRef $subject, array $tokens): void {
    $permission = $tokens[0] ?? '';
    if ($permission === '') {
      CommandSupport::error($sender, 'Usage: /stoneperms user <user> check <node> [key=value ...]');
      return;
    }
    $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 1));
    $decision = $this->plugin->manager()->checkPermission($subject, $permission, $contexts);

    $value = $decision->value === null ? '§8undefined' : ($decision->value ? '§atrue' : '§cfalse');
    CommandSupport::send($sender, "§7$permission§7: $value" . CommandSupport::renderContexts($contexts));
    if ($decision->selected === null) {
      $sender->sendMessage('  §8No node applies; the registered default stays in force.');
      return;
    }
    $selected = $decision->selected;
    $sender->sendMessage(
      '  §7from §f' . $selected->origin->key()
      . ' §8| §7distance §f' . $selected->inheritanceDistance
      . ' §8| §7weight §f' . $selected->groupWeight
    );
    $sender->sendMessage('  §7candidates: §f' . count($decision->candidates));
  }

  /** @param list<string> $tokens */
  private function permission(CommandSender $sender, SubjectRef $subject, array $tokens): void {
    $action = strtolower($tokens[0] ?? '');
    $node = $tokens[1] ?? '';
    if ($node === '') {
      CommandSupport::error($sender, 'Usage: /stoneperms user <user> permission <set|settemp|unset|unsettemp> <node> ...');
      return;
    }
    $manager = $this->plugin->manager();
    $actor = CommandSupport::actor($sender);

    switch ($action) {
      case 'set':
        $value = CommandSupport::boolean($tokens[2] ?? '');
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 3));
        $manager->setPermission($subject, $node, $value, $actor, $contexts);
        break;
      case 'settemp':
        $value = CommandSupport::boolean($tokens[2] ?? '');
        $expiry = CommandSupport::expiry($tokens[3] ?? '');
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 4));
        $manager->setPermission($subject, $node, $value, $actor, $contexts, $expiry);
        break;
      case 'unset':
      case 'unsettemp':
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 2));
        $removed = $manager->unsetPermission($subject, $node, $actor, $contexts, $action === 'unsettemp');
        $this->plugin->refreshUser($subject->identifier);
        CommandSupport::send($sender, "§7Removed §f$removed§7 node(s).");
        return;
      default:
        CommandSupport::error($sender, "Unknown permission action '$action'.");
        return;
    }

    $this->plugin->refreshUser($subject->identifier);
    CommandSupport::send($sender, "§7Set §f$node§7." . CommandSupport::renderContexts($contexts));
  }

  /** @param list<string> $tokens */
  private function parent(CommandSender $sender, SubjectRef $subject, array $tokens): void {
    $action = strtolower($tokens[0] ?? '');
    $parent = $tokens[1] ?? '';
    if ($parent === '') {
      CommandSupport::error($sender, 'Usage: /stoneperms user <user> parent <add|addtemp|remove|removetemp> <group> ...');
      return;
    }
    $manager = $this->plugin->manager();
    $actor = CommandSupport::actor($sender);

    switch ($action) {
      case 'add':
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 2));
        $manager->addParent($subject, $parent, $actor, $contexts);
        break;
      case 'addtemp':
        $expiry = CommandSupport::expiry($tokens[2] ?? '');
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 3));
        $manager->addParent($subject, $parent, $actor, $contexts, $expiry);
        break;
      case 'remove':
      case 'removetemp':
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 2));
        $removed = $manager->removeParent($subject, $parent, $actor, $contexts, $action === 'removetemp');
        $this->plugin->refreshUser($subject->identifier);
        CommandSupport::send($sender, "§7Removed §f$removed§7 parent node(s).");
        return;
      default:
        CommandSupport::error($sender, "Unknown parent action '$action'.");
        return;
    }

    $this->plugin->refreshUser($subject->identifier);
    CommandSupport::send($sender, "§7Added parent §f$parent§7." . CommandSupport::renderContexts($contexts));
  }

  /** @param list<string> $tokens */
  private function meta(CommandSender $sender, SubjectRef $subject, array $tokens): void {
    $action = strtolower($tokens[0] ?? '');
    $key = $tokens[1] ?? '';
    if ($key === '') {
      CommandSupport::error($sender, 'Usage: /stoneperms user <user> meta <get|set|settemp|unset|unsettemp> <key> ...');
      return;
    }
    $manager = $this->plugin->manager();
    $actor = CommandSupport::actor($sender);

    switch ($action) {
      case 'get':
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 2));
        $decision = $manager->resolveMeta($subject, $key, $contexts);
        CommandSupport::send(
          $sender,
          "§7$key§7: §f" . ($decision->value ?? '§8undefined') . CommandSupport::renderContexts($contexts)
        );
        return;
      case 'set':
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 3));
        $manager->setMeta($subject, $key, $tokens[2] ?? '', $actor, $contexts);
        break;
      case 'settemp':
        $expiry = CommandSupport::expiry($tokens[3] ?? '');
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 4));
        $manager->setMeta($subject, $key, $tokens[2] ?? '', $actor, $contexts, $expiry);
        break;
      case 'unset':
      case 'unsettemp':
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 2));
        $removed = $manager->unsetMeta($subject, $key, $actor, $contexts, $action === 'unsettemp');
        $this->plugin->refreshUser($subject->identifier);
        CommandSupport::send($sender, "§7Removed §f$removed§7 metadata node(s).");
        return;
      default:
        CommandSupport::error($sender, "Unknown meta action '$action'.");
        return;
    }

    $this->plugin->refreshUser($subject->identifier);
    CommandSupport::send($sender, "§7Set metadata §f$key§7.");
  }

  /** @param list<string> $tokens */
  private function affix(CommandSender $sender, SubjectRef $subject, string $kind, array $tokens): void {
    $type = $kind === 'prefix' ? NodeType::PREFIX : NodeType::SUFFIX;
    $action = strtolower($tokens[0] ?? '');
    $manager = $this->plugin->manager();
    $actor = CommandSupport::actor($sender);

    if ($action === 'get') {
      $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 1));
      $decision = $type === NodeType::PREFIX
        ? $manager->resolvePrefix($subject, $contexts)
        : $manager->resolveSuffix($subject, $contexts);
      CommandSupport::send($sender, "§7$kind§7: §f" . ($decision->value ?? '§8undefined'));
      return;
    }

    if (!isset($tokens[1])) {
      CommandSupport::error($sender, "Usage: /stoneperms user <user> $kind <get|set|settemp|unset|unsettemp> <priority> ...");
      return;
    }
    $priority = CommandSupport::integer($tokens[1], 'Priority');

    switch ($action) {
      case 'set':
        $value = $tokens[2] ?? '';
        if ($value === '') {
          throw new InvalidArgumentException("$kind values may not be empty");
        }
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 3));
        $type === NodeType::PREFIX
          ? $manager->setPrefix($subject, $priority, $value, $actor, $contexts)
          : $manager->setSuffix($subject, $priority, $value, $actor, $contexts);
        break;
      case 'settemp':
        $value = $tokens[2] ?? '';
        $expiry = CommandSupport::expiry($tokens[3] ?? '');
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 4));
        $type === NodeType::PREFIX
          ? $manager->setPrefix($subject, $priority, $value, $actor, $contexts, $expiry)
          : $manager->setSuffix($subject, $priority, $value, $actor, $contexts, $expiry);
        break;
      case 'unset':
      case 'unsettemp':
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 2));
        $temporary = $action === 'unsettemp';
        $removed = $type === NodeType::PREFIX
          ? $manager->unsetPrefix($subject, $priority, $actor, $contexts, $temporary)
          : $manager->unsetSuffix($subject, $priority, $actor, $contexts, $temporary);
        $this->plugin->refreshUser($subject->identifier);
        CommandSupport::send($sender, "§7Removed §f$removed§7 $kind node(s).");
        return;
      default:
        CommandSupport::error($sender, "Unknown $kind action '$action'.");
        return;
    }

    $this->plugin->refreshUser($subject->identifier);
    CommandSupport::send($sender, "§7Set $kind at priority §f$priority§7.");
  }

  /** @param list<string> $tokens */
  private function move(CommandSender $sender, string $identifier, string $action, array $tokens): void {
    $track = $tokens[0] ?? '';
    if ($track === '') {
      CommandSupport::error($sender, "Usage: /stoneperms user <user> $action <track> [key=value ...]");
      return;
    }
    $flag = $action === 'promote' ? '--dont-add-to-first' : '--dont-remove-from-first';
    $rest = array_values(array_filter(
      CommandSupport::slice($tokens, 1),
      static fn(string $token): bool => $token !== $flag
    ));
    $crossBoundary = !in_array($flag, $tokens, true);
    $contexts = CommandSupport::contexts($rest);

    $result = $action === 'promote'
      ? $this->plugin->api()->promote($identifier, $track, CommandSupport::actor($sender), $contexts, $crossBoundary)
      : $this->plugin->api()->demote($identifier, $track, CommandSupport::actor($sender), $contexts, $crossBoundary);

    $summary = '§7' . ucfirst($action) . " on §f$track§7: §f" . $result->status->value;
    if ($result->groupFrom !== null) {
      $summary .= ' §8| §7from §f' . $result->groupFrom;
    }
    if ($result->groupTo !== null) {
      $summary .= ' §8| §7to §f' . $result->groupTo;
    }
    CommandSupport::send($sender, $summary);
  }

  /** @param list<string> $tokens */
  private function showTracks(CommandSender $sender, SubjectRef $subject, array $tokens): void {
    $contexts = $tokens === [] ? null : CommandSupport::contexts($tokens);
    $positions = $this->plugin->manager()->userTracks($subject, $contexts);
    if ($positions === []) {
      CommandSupport::send($sender, '§7This player is not on any track.');
      return;
    }
    CommandSupport::send($sender, '§7Track positions:');
    foreach ($positions as $track => $groups) {
      $sender->sendMessage('  §f' . $track . ' §8| §7' . implode(', ', $groups));
    }
  }
}
