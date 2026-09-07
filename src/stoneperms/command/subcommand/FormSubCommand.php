<?php

declare(strict_types = 1);

namespace stoneperms\command\subcommand;

use imperazim\command\constraint\InGameConstraint;
use imperazim\command\result\CommandFailure;
use imperazim\command\result\CommandResult;
use imperazim\command\SubCommand;
use pocketmine\player\Player;
use stoneperms\command\CommandSupport;
use stoneperms\command\StonePermsCommand;
use stoneperms\StonePermsPlugin;

/**
* `/stoneperms form` — opens the in-game UI.
*
* LibCommand's in-game constraint rejects console use before the handler runs,
* so the handler never has to check who is asking.
*/
final class FormSubCommand extends SubCommand {

  public function __construct(private readonly StonePermsPlugin $stonePerms, object $parent) {
    parent::__construct($parent);
  }

  public function onBuild(): array {
    return [
      'name' => 'form',
      'description' => 'Open the in-game administration UI',
      'permission' => StonePermsCommand::PERMISSION,
      'constraints' => [
        new InGameConstraint(CommandSupport::PREFIX . ' §cForms can only be opened in game.')
      ]
    ];
  }

  public function onExecute(CommandResult $result): void {
    $sender = $result->getSender();
    if ($sender instanceof Player) {
      $this->stonePerms->forms()->openMain($sender);
    }
  }

  public function onFailure(CommandFailure $failure): void {
    CommandSupport::error($failure->getSender(), 'The form could not be opened.');
  }
}
