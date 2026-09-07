<?php

declare(strict_types = 1);

namespace stoneperms\command\subcommand;

use imperazim\command\argument\EnumArgument;
use imperazim\command\argument\SoftEnumArgument;
use imperazim\command\argument\StringArgument;
use pocketmine\command\CommandSender;
use stoneperms\command\CommandEnums;
use stoneperms\command\GroupCommandHandler;
use stoneperms\command\StonePermsCommand;
use stoneperms\command\StonePermsSubCommand;
use stoneperms\StonePermsPlugin;

/**
* `/stoneperms group ...`
*/
final class GroupSubCommand extends StonePermsSubCommand {

  private GroupCommandHandler $handler;

  public function __construct(StonePermsPlugin $plugin, object $parent) {
    $this->handler = new GroupCommandHandler($plugin);
    parent::__construct($plugin, $parent);
  }

  public function onBuild(): array {
    return [
      'name' => 'group',
      'description' => 'Create and edit groups, their nodes and their parents',
      'aliases' => ['g'],
      'permission' => StonePermsCommand::PERMISSION,
      'arguments' => [
        new EnumArgument('action', false, [
          'list', 'create', 'info', 'setweight', 'permission', 'parent', 'meta', 'prefix', 'suffix'
        ]),
        new SoftEnumArgument('group', true, CommandEnums::GROUPS, 'An existing group'),
        new StringArgument('options', true, null, 'Action arguments, then optional key=value contexts')
      ]
    ];
  }

  protected function run(CommandSender $sender, array $tokens): void {
    $this->handler->handle($sender, $tokens);
  }
}
