<?php

declare(strict_types = 1);

namespace stoneperms\command\subcommand;

use pocketmine\command\CommandSender;
use stoneperms\command\CommandSupport;
use stoneperms\command\StonePermsCommand;
use stoneperms\command\StonePermsSubCommand;

/**
* `/stoneperms info` — what the plugin is actually doing right now.
*/
final class InfoSubCommand extends StonePermsSubCommand {

  public function onBuild(): array {
    return [
      'name' => 'info',
      'description' => 'Plugin, storage and dashboard status',
      'permission' => StonePermsCommand::PERMISSION
    ];
  }

  protected function run(CommandSender $sender, array $tokens): void {
    $plugin = $this->plugin();
    $manager = $plugin->manager();
    $status = $plugin->web()->status();

    CommandSupport::send($sender, '§7StonePerms §f' . $plugin->getDescription()->getVersion());
    $sender->sendMessage('  §7groups: §f' . count($manager->listGroups()));
    $sender->sendMessage('  §7tracks: §f' . count($manager->listTracks()));
    $sender->sendMessage('  §7players known: §f' . count($manager->listUsers()));
    $sender->sendMessage('  §7revision: §f' . $manager->repository()->revision());
    $sender->sendMessage('  §7permission catalog: §f' . count($plugin->attachments()->permissionCatalog()));
    $sender->sendMessage('  §7placeholders: §f' . ($plugin->placeholders()->isRegistered() ? 'registered' : 'unavailable'));
    $sender->sendMessage(
      '  §7dashboard: §f'
      . ($status->connected ? 'connected' : ($status->configured ? 'paired' : 'not paired'))
    );
    $sender->sendMessage('  §8PocketMine-MP port by yBriisMC. Original StonePerms by Daniel-Ric.');
    $sender->sendMessage('  §8Built on EasyLibrary by imperazim. Not affiliated with LuckPerms.');
  }
}
