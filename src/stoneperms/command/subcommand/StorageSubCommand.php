<?php

declare(strict_types = 1);

namespace stoneperms\command\subcommand;

use imperazim\command\argument\EnumArgument;
use pocketmine\command\CommandSender;
use stoneperms\command\CommandSupport;
use stoneperms\command\StonePermsCommand;
use stoneperms\command\StonePermsSubCommand;

/**
* `/stoneperms storage` — where the permission data lives, and who shares it.
*/
final class StorageSubCommand extends StonePermsSubCommand {

  public function onBuild(): array {
    return [
      'name' => 'storage',
      'description' => 'Show where permission data lives and what is shared',
      'permission' => StonePermsCommand::PERMISSION,
      'arguments' => [
        new EnumArgument('action', true, ['status'])
      ]
    ];
  }

  protected function run(CommandSender $sender, array $tokens): void {
    $action = strtolower(trim((string) ($tokens[0] ?? 'status')));
    if ($action !== '' && $action !== 'status') {
      CommandSupport::error($sender, "Unknown storage action '$action'.");
      return;
    }

    $plugin = $this->plugin();
    $storage = $plugin->storage();
    $manager = $plugin->manager();

    CommandSupport::send($sender, '§7Storage');
    $sender->sendMessage('  §7driver: §f' . $storage->driver());
    $sender->sendMessage('  §7where: §f' . $storage->describe());
    $sender->sendMessage(
      '  §7shared: §f' . ($storage->isShared()
        ? 'yes, other servers may write here'
        : 'no, this server only')
    );
    $sender->sendMessage('  §7revision: §f' . $storage->revision());
    $sender->sendMessage('  §7groups: §f' . count($manager->listGroups())
      . ' §7tracks: §f' . count($manager->listTracks())
      . ' §7players: §f' . count($manager->listUsers()));

    if (!$storage->isShared()) {
      return;
    }

    $scope = $storage->playerScope();
    $sender->sendMessage(
      '  §7players here: §f' . ($scope === ''
        ? 'shared with every server'
        : "this server's own (" . $scope . ')')
    );
    $sender->sendMessage('  §8groups, tracks and their nodes are always shared');

    $unowned = $storage->unownedPlayers();
    if ($unowned > 0) {
      $sender->sendMessage(
        '  §e' . $unowned . ' player row(s) belong to no server and are not visible here'
      );
    }
  }
}
