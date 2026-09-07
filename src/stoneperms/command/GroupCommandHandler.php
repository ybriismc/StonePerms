<?php

declare(strict_types = 1);

namespace stoneperms\command;

use InvalidArgumentException;
use pocketmine\command\CommandSender;
use stoneperms\domain\NodeType;
use stoneperms\domain\SubjectRef;
use stoneperms\StonePermsPlugin;

/**
* `/stoneperms group ...` — everything that operates on a group.
*/
final class GroupCommandHandler {

  public function __construct(private readonly StonePermsPlugin $plugin) {}

  /** @param list<string> $tokens tokens after `group` */
  public function handle(CommandSender $sender, array $tokens): void {
    $action = strtolower($tokens[0] ?? '');
    if ($action === '' || $action === 'list') {
      $this->list($sender);
      return;
    }

    if ($action === 'create') {
      $this->create($sender, $tokens);
      return;
    }

    $name = $tokens[1] ?? '';
    if ($name === '') {
      CommandSupport::error($sender, 'Usage: /stoneperms group <list|create|info|setweight|permission|parent|meta|prefix|suffix> ...');
      return;
    }

    match ($action) {
      'info' => $this->info($sender, $name),
      'setweight' => $this->setWeight($sender, $name, $tokens),
      'permission' => $this->permission($sender, $name, CommandSupport::slice($tokens, 2)),
      'parent' => $this->parent($sender, $name, CommandSupport::slice($tokens, 2)),
      'meta' => $this->meta($sender, $name, CommandSupport::slice($tokens, 2)),
      'prefix', 'suffix' => $this->affix($sender, $name, $action, CommandSupport::slice($tokens, 2)),
      default => CommandSupport::error($sender, "Unknown group action '$action'.")
    };
  }

  private function list(CommandSender $sender): void {
    $groups = $this->plugin->manager()->listGroups();
    if ($groups === []) {
      CommandSupport::send($sender, '§7No groups are defined.');
      return;
    }
    CommandSupport::send($sender, '§7Groups (§f' . count($groups) . '§7):');
    foreach ($groups as $group) {
      $sender->sendMessage('  §f' . $group->name . ' §8| §7weight §f' . $group->weight);
    }
  }

  /** @param list<string> $tokens */
  private function create(CommandSender $sender, array $tokens): void {
    $name = $tokens[1] ?? '';
    if ($name === '') {
      CommandSupport::error($sender, 'Usage: /stoneperms group create <group> [weight]');
      return;
    }
    $weight = isset($tokens[2]) ? CommandSupport::integer($tokens[2], 'Weight') : 0;
    $created = $this->plugin->manager()->createGroup($name, null, $weight, CommandSupport::actor($sender));
    if (!$created) {
      CommandSupport::error($sender, "Group '$name' already exists.");
      return;
    }
    $this->plugin->refreshEveryone();
    CommandSupport::send($sender, "§7Created group §f$name§7 with weight §f$weight§7.");
  }

  private function info(CommandSender $sender, string $name): void {
    $group = $this->plugin->manager()->getGroup($name);
    $nodes = $this->plugin->manager()->nodesFor(SubjectRef::group($group->name));
    CommandSupport::send(
      $sender,
      '§7Group §f' . $group->name . ' §8| §7display §f' . $group->displayName
      . ' §8| §7weight §f' . $group->weight
    );
    if ($nodes === []) {
      $sender->sendMessage('  §8No nodes.');
      return;
    }
    foreach ($nodes as $node) {
      $sender->sendMessage('  ' . CommandSupport::renderNode($node));
    }
  }

  /** @param list<string> $tokens */
  private function setWeight(CommandSender $sender, string $name, array $tokens): void {
    if (!isset($tokens[2])) {
      CommandSupport::error($sender, 'Usage: /stoneperms group setweight <group> <weight>');
      return;
    }
    $weight = CommandSupport::integer($tokens[2], 'Weight');
    $this->plugin->manager()->setGroupWeight($name, $weight, CommandSupport::actor($sender));
    $this->plugin->refreshEveryone();
    CommandSupport::send($sender, "§7Weight of §f$name§7 is now §f$weight§7.");
  }

  /** @param list<string> $tokens tokens after the group name */
  private function permission(CommandSender $sender, string $name, array $tokens): void {
    $subject = SubjectRef::group($this->plugin->manager()->getGroup($name)->name);
    $action = strtolower($tokens[0] ?? '');
    $node = $tokens[1] ?? '';
    if ($node === '') {
      CommandSupport::error($sender, 'Usage: /stoneperms group permission <group> <set|settemp|unset|unsettemp> <node> ...');
      return;
    }

    switch ($action) {
      case 'set':
        $value = CommandSupport::boolean($tokens[2] ?? '');
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 3));
        $this->plugin->manager()->setPermission($subject, $node, $value, CommandSupport::actor($sender), $contexts);
        $this->plugin->refreshEveryone();
        CommandSupport::send(
          $sender,
          "§7Set §f$node§7 to " . ($value ? '§atrue' : '§cfalse') . " §7on §f$name" . CommandSupport::renderContexts($contexts)
        );
        return;
      case 'settemp':
        $value = CommandSupport::boolean($tokens[2] ?? '');
        $expiry = CommandSupport::expiry($tokens[3] ?? '');
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 4));
        $this->plugin->manager()->setPermission($subject, $node, $value, CommandSupport::actor($sender), $contexts, $expiry);
        $this->plugin->refreshEveryone();
        CommandSupport::send($sender, "§7Set temporary §f$node§7 on §f$name" . CommandSupport::renderContexts($contexts));
        return;
      case 'unset':
      case 'unsettemp':
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 2));
        $removed = $this->plugin->manager()->unsetPermission(
          $subject,
          $node,
          CommandSupport::actor($sender),
          $contexts,
          $action === 'unsettemp'
        );
        $this->plugin->refreshEveryone();
        CommandSupport::send($sender, "§7Removed §f$removed§7 node(s) from §f$name" . CommandSupport::renderContexts($contexts));
        return;
      default:
        CommandSupport::error($sender, "Unknown permission action '$action'.");
    }
  }

  /** @param list<string> $tokens */
  private function parent(CommandSender $sender, string $name, array $tokens): void {
    $subject = SubjectRef::group($this->plugin->manager()->getGroup($name)->name);
    $action = strtolower($tokens[0] ?? '');
    $parent = $tokens[1] ?? '';
    if ($parent === '') {
      CommandSupport::error($sender, 'Usage: /stoneperms group parent <group> <add|addtemp|remove|removetemp> <parent> ...');
      return;
    }

    switch ($action) {
      case 'add':
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 2));
        $this->plugin->manager()->addParent($subject, $parent, CommandSupport::actor($sender), $contexts);
        break;
      case 'addtemp':
        $expiry = CommandSupport::expiry($tokens[2] ?? '');
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 3));
        $this->plugin->manager()->addParent($subject, $parent, CommandSupport::actor($sender), $contexts, $expiry);
        break;
      case 'remove':
      case 'removetemp':
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 2));
        $removed = $this->plugin->manager()->removeParent(
          $subject,
          $parent,
          CommandSupport::actor($sender),
          $contexts,
          $action === 'removetemp'
        );
        $this->plugin->refreshEveryone();
        CommandSupport::send($sender, "§7Removed §f$removed§7 parent node(s) from §f$name§7.");
        return;
      default:
        CommandSupport::error($sender, "Unknown parent action '$action'.");
        return;
    }

    $this->plugin->refreshEveryone();
    CommandSupport::send($sender, "§7Added parent §f$parent§7 to §f$name§7.");
  }

  /** @param list<string> $tokens */
  private function meta(CommandSender $sender, string $name, array $tokens): void {
    $subject = SubjectRef::group($this->plugin->manager()->getGroup($name)->name);
    $action = strtolower($tokens[0] ?? '');
    $key = $tokens[1] ?? '';
    if ($key === '') {
      CommandSupport::error($sender, 'Usage: /stoneperms group meta <group> <set|settemp|unset|unsettemp> <key> ...');
      return;
    }

    switch ($action) {
      case 'set':
        $value = $tokens[2] ?? '';
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 3));
        $this->plugin->manager()->setMeta($subject, $key, $value, CommandSupport::actor($sender), $contexts);
        break;
      case 'settemp':
        $value = $tokens[2] ?? '';
        $expiry = CommandSupport::expiry($tokens[3] ?? '');
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 4));
        $this->plugin->manager()->setMeta($subject, $key, $value, CommandSupport::actor($sender), $contexts, $expiry);
        break;
      case 'unset':
      case 'unsettemp':
        $contexts = CommandSupport::contexts(CommandSupport::slice($tokens, 2));
        $removed = $this->plugin->manager()->unsetMeta(
          $subject,
          $key,
          CommandSupport::actor($sender),
          $contexts,
          $action === 'unsettemp'
        );
        $this->plugin->refreshEveryone();
        CommandSupport::send($sender, "§7Removed §f$removed§7 metadata node(s) from §f$name§7.");
        return;
      default:
        CommandSupport::error($sender, "Unknown meta action '$action'.");
        return;
    }

    $this->plugin->refreshEveryone();
    CommandSupport::send($sender, "§7Set metadata §f$key§7 on §f$name§7.");
  }

  /** @param list<string> $tokens */
  private function affix(CommandSender $sender, string $name, string $kind, array $tokens): void {
    $subject = SubjectRef::group($this->plugin->manager()->getGroup($name)->name);
    $type = $kind === 'prefix' ? NodeType::PREFIX : NodeType::SUFFIX;
    $action = strtolower($tokens[0] ?? '');
    if (!isset($tokens[1])) {
      CommandSupport::error($sender, "Usage: /stoneperms group $kind <group> <set|settemp|unset|unsettemp> <priority> ...");
      return;
    }
    $priority = CommandSupport::integer($tokens[1], 'Priority');
    $manager = $this->plugin->manager();
    $actor = CommandSupport::actor($sender);

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
        $this->plugin->refreshEveryone();
        CommandSupport::send($sender, "§7Removed §f$removed§7 $kind node(s) from §f$name§7.");
        return;
      default:
        CommandSupport::error($sender, "Unknown $kind action '$action'.");
        return;
    }

    $this->plugin->refreshEveryone();
    CommandSupport::send($sender, "§7Set $kind on §f$name§7 at priority §f$priority§7.");
  }
}
