<?php

declare(strict_types = 1);

namespace stoneperms\command\subcommand;

use imperazim\command\argument\IntegerArgument;
use imperazim\command\result\CommandFailure;
use imperazim\command\result\CommandResult;
use imperazim\command\SubCommand;
use stoneperms\command\CommandSupport;
use stoneperms\command\StonePermsCommand;
use stoneperms\StonePermsPlugin;

/**
* `/stoneperms log [limit]` — recent audit entries.
*
* The shape is fixed, so this one uses LibCommand's argument parsing directly:
* the limit is a bounded integer and the library rejects anything else before
* the handler runs.
*/
final class LogSubCommand extends SubCommand {

  public function __construct(private readonly StonePermsPlugin $stonePerms, object $parent) {
    parent::__construct($parent);
  }

  public function onBuild(): array {
    return [
      'name' => 'log',
      'description' => 'Show recent audit entries',
      'permission' => StonePermsCommand::PERMISSION,
      'arguments' => [
        new IntegerArgument('limit', true, 10, 'How many entries to show', [], null, 1, 200)
      ]
    ];
  }

  public function onExecute(CommandResult $result): void {
    $sender = $result->getSender();
    $limit = (int) ($result->getArgumentsList()->get('limit') ?? 10);
    $entries = $this->stonePerms->manager()->recentAudit(max(1, min(200, $limit)));

    if ($entries === []) {
      CommandSupport::send($sender, '§7The audit log is empty.');
      return;
    }
    CommandSupport::send($sender, '§7Recent changes:');
    foreach ($entries as $entry) {
      $subject = $entry['subject_id'] !== null
        ? ' §8| §7' . $entry['subject_type'] . ':' . $entry['subject_id']
        : '';
      $sender->sendMessage(
        '  §8' . date('H:i:s', (int) $entry['created_at'])
        . ' §f' . $entry['action'] . ' §7by §f' . $entry['actor'] . $subject
      );
    }
  }

  public function onFailure(CommandFailure $failure): void {
    $message = $failure->getMessage();
    CommandSupport::error($failure->getSender(), $message !== '' ? $message : 'Usage: /stoneperms log [limit]');
  }
}
