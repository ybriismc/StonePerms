<?php

declare(strict_types = 1);

namespace stoneperms\command\subcommand;

use imperazim\command\HelpGenerator;
use imperazim\command\result\CommandFailure;
use imperazim\command\result\CommandResult;
use imperazim\command\SubCommand;
use stoneperms\command\CommandSupport;
use stoneperms\command\StonePermsCommand;

/**
* `/stoneperms help` — the command reference, generated from the registered
* subcommands so it cannot drift from what actually exists.
*/
final class HelpSubCommand extends SubCommand {

  public function onBuild(): array {
    return [
      'name' => 'help',
      'description' => 'Show the command reference',
      'aliases' => ['?'],
      'permission' => StonePermsCommand::PERMISSION
    ];
  }

  public function onExecute(CommandResult $result): void {
    $sender = $result->getSender();
    $parent = $this->parent;

    CommandSupport::send($sender, '§7Commands (§f/sp§7 and §f/perms§7 also work):');
    foreach ($parent->getSubCommands() as $subcommand) {
      $sender->sendMessage(
        '  §f/stoneperms ' . $subcommand->getName() . ' §8- §7' . $subcommand->getDescription()
      );
    }
    $sender->sendMessage('  §7Durations combine, for example §f30m§7, §f2h30m§7, §f7d§7, §f1mo2d§7.');
    $sender->sendMessage('  §7Trailing §fkey=value§7 tokens scope a change to a context.');

    if ($parent instanceof StonePermsCommand) {
      $sender->sendMessage('§8' . HelpGenerator::generate($parent, 'usage'));
    }
  }

  public function onFailure(CommandFailure $failure): void {
    CommandSupport::error($failure->getSender(), 'You may not use this command.');
  }
}
