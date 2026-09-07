<?php

declare(strict_types = 1);

namespace stoneperms\command;

use imperazim\command\Command;
use imperazim\command\result\CommandFailure;
use imperazim\command\result\CommandResult;
use stoneperms\command\subcommand\FormSubCommand;
use stoneperms\command\subcommand\GroupSubCommand;
use stoneperms\command\subcommand\HelpSubCommand;
use stoneperms\command\subcommand\InfoSubCommand;
use stoneperms\command\subcommand\LogSubCommand;
use stoneperms\command\subcommand\TrackSubCommand;
use stoneperms\command\subcommand\UserSubCommand;
use stoneperms\command\subcommand\WebSubCommand;
use stoneperms\StonePermsPlugin;

/**
* `/stoneperms`, with `/sp` and `/perms` as aliases.
*
* The tree is registered as LibCommand subcommands, so each branch carries its
* own name, aliases, description, permission and arguments, and the help output
* is generated from what is actually registered.
*/
final class StonePermsCommand extends Command {

  public const PERMISSION = 'stoneperms.command.admin';

  private StonePermsPlugin $stonePerms;

  public function __construct(StonePermsPlugin $plugin) {
    $this->stonePerms = $plugin;
    parent::__construct($plugin);
  }

  public function onBuild(): array {
    return [
      'name' => 'stoneperms',
      'description' => 'Inspect and manage players, groups, permissions, tracks, metadata and web access',
      'aliases' => ['sp', 'perms'],
      'permission' => self::PERMISSION,
      'subcommands' => [
        new HelpSubCommand($this),
        new InfoSubCommand($this->stonePerms, $this),
        new LogSubCommand($this->stonePerms, $this),
        new FormSubCommand($this->stonePerms, $this),
        new UserSubCommand($this->stonePerms, $this),
        new GroupSubCommand($this->stonePerms, $this),
        new TrackSubCommand($this->stonePerms, $this),
        new WebSubCommand($this->stonePerms, $this)
      ]
    ];
  }

  /**
  * Reached when no subcommand matched: either nothing was typed, or the first
  * token is not a branch we know.
  */
  public function onExecute(CommandResult $result): void {
    $sender = $result->getSender();
    $tokens = $result->getRawArguments();

    if ($tokens !== []) {
      CommandSupport::error($sender, "Unknown action '{$tokens[0]}'. Try /stoneperms help.");
      return;
    }
    $help = $this->getSubCommand('help');
    if ($help !== null) {
      $help->execute($sender, 'stoneperms help', []);
    }
  }

  public function onFailure(CommandFailure $failure): void {
    $message = $failure->getMessage();
    CommandSupport::error(
      $failure->getSender(),
      $message !== '' ? $message : 'You may not use this command.'
    );
  }
}
