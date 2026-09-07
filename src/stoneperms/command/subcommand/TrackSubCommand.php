<?php

declare(strict_types = 1);

namespace stoneperms\command\subcommand;

use imperazim\command\argument\EnumArgument;
use imperazim\command\argument\SoftEnumArgument;
use imperazim\command\argument\StringArgument;
use pocketmine\command\CommandSender;
use stoneperms\command\CommandEnums;
use stoneperms\command\StonePermsCommand;
use stoneperms\command\StonePermsSubCommand;
use stoneperms\command\TrackCommandHandler;
use stoneperms\StonePermsPlugin;

/**
* `/stoneperms track ...`
*/
final class TrackSubCommand extends StonePermsSubCommand {

  private TrackCommandHandler $handler;

  public function __construct(StonePermsPlugin $plugin, object $parent) {
    $this->handler = new TrackCommandHandler($plugin);
    parent::__construct($plugin, $parent);
  }

  public function onBuild(): array {
    return [
      'name' => 'track',
      'description' => 'Define and edit promotion ladders',
      'aliases' => ['t'],
      'permission' => StonePermsCommand::PERMISSION,
      'arguments' => [
        new EnumArgument('action', false, [
          'list', 'create', 'delete', 'info', 'append', 'insert', 'remove', 'clear', 'rename', 'clone'
        ]),
        new SoftEnumArgument('track', true, CommandEnums::TRACKS, 'An existing track'),
        new StringArgument('options', true, null, 'Action arguments')
      ]
    ];
  }

  protected function run(CommandSender $sender, array $tokens): void {
    $this->handler->handle($sender, $tokens);
  }
}
