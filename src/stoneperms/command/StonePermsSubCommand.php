<?php

declare(strict_types = 1);

namespace stoneperms\command;

use imperazim\command\result\CommandFailure;
use imperazim\command\result\CommandResult;
use imperazim\command\SubCommand;
use pocketmine\command\CommandSender;
use stoneperms\StonePermsPlugin;
use Throwable;

/**
* Base for the branches whose shape LibCommand's argument parser cannot express.
*
* `user <name> permission set <node> <value>` puts a variable in the middle of
* the tree, and the parser assigns tokens to declared arguments positionally,
* so these branches read the raw tokens instead. They still declare arguments,
* which is what drives the client's command UI and autocomplete, and they still
* run the constraints LibCommand attached to them.
*/
abstract class StonePermsSubCommand extends SubCommand {

  public function __construct(private readonly StonePermsPlugin $stonePerms, object $parent) {
    parent::__construct($parent);
  }

  public function plugin(): StonePermsPlugin {
    return $this->stonePerms;
  }

  public function execute(CommandSender $sender, string $label, array $rawArgs): void {
    foreach ($this->getConstraints() as $constraint) {
      if (!$constraint->isSatisfiedBy($sender)) {
        $constraint->onFailure($sender);
        return;
      }
    }

    try {
      $this->run($sender, array_values($rawArgs));
    } catch (Throwable $throwable) {
      CommandSupport::error($sender, $throwable->getMessage());
    }
  }

  /** @param list<string> $tokens the tokens after this subcommand's own name */
  abstract protected function run(CommandSender $sender, array $tokens): void;

  public function onExecute(CommandResult $result): void {
    // Unused: execute() is overridden so the raw tokens survive.
  }

  public function onFailure(CommandFailure $failure): void {
    $message = $failure->getMessage();
    CommandSupport::error($failure->getSender(), $message !== '' ? $message : 'That command could not run.');
  }
}
