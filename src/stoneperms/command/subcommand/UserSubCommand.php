<?php

declare(strict_types = 1);

namespace stoneperms\command\subcommand;

use imperazim\command\argument\EnumArgument;
use imperazim\command\argument\StringArgument;
use pocketmine\command\CommandSender;
use stoneperms\command\StonePermsCommand;
use stoneperms\command\StonePermsSubCommand;
use stoneperms\command\UserCommandHandler;
use stoneperms\StonePermsPlugin;

/**
* `/stoneperms user <name|uuid|xuid> ...`
*
* The player comes before the action, which is exactly the shape a positional
* argument list cannot describe, so this branch reads the raw tokens.
*/
final class UserSubCommand extends StonePermsSubCommand {

  private UserCommandHandler $handler;

  public function __construct(StonePermsPlugin $plugin, object $parent) {
    $this->handler = new UserCommandHandler($plugin);
    parent::__construct($plugin, $parent);
  }

  public function onBuild(): array {
    return [
      'name' => 'user',
      'description' => 'Inspect and edit one player',
      'aliases' => ['u', 'player'],
      'permission' => StonePermsCommand::PERMISSION,
      'arguments' => [
        new StringArgument('player', false, null, 'Name, UUID or XUID'),
        new EnumArgument('action', false, [
          'info', 'check', 'permission', 'parent', 'meta', 'prefix', 'suffix',
          'promote', 'demote', 'showtracks'
        ]),
        new StringArgument('options', true, null, 'Action arguments, then optional key=value contexts')
      ]
    ];
  }

  protected function run(CommandSender $sender, array $tokens): void {
    $this->handler->handle($sender, $tokens);
  }
}
