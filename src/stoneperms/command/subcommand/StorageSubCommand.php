<?php

declare(strict_types = 1);

namespace stoneperms\command\subcommand;

use imperazim\command\argument\EnumArgument;
use imperazim\command\argument\StringArgument;
use pocketmine\command\CommandSender;
use stoneperms\application\StorageMigrationReport;
use stoneperms\application\StorageMigrator;
use stoneperms\command\CommandSupport;
use stoneperms\command\StonePermsCommand;
use stoneperms\command\StonePermsSubCommand;
use stoneperms\infrastructure\PdoPermissionRepository;
use stoneperms\infrastructure\SqliteDialect;
use Throwable;

/**
* `/stoneperms storage ...` — where the data lives, and moving it there.
*
* The migration reads this server's own SQLite file and writes it into the
* store the server is running on now, which is what a server already carrying
* data does once it is pointed at a shared database. It only looks unless it
* is told to apply, and it never overwrites what the target already has.
*/
final class StorageSubCommand extends StonePermsSubCommand {

  public function onBuild(): array {
    return [
      'name' => 'storage',
      'description' => 'Show where permission data lives, and move a SQLite store into it',
      'permission' => StonePermsCommand::PERMISSION,
      'arguments' => [
        new EnumArgument('action', false, ['status', 'migrate']),
        new StringArgument('options', true, null, '--apply, --scope-to-server')
      ]
    ];
  }

  protected function run(CommandSender $sender, array $tokens): void {
    $action = strtolower(trim((string) ($tokens[0] ?? 'status')));
    match ($action) {
      'status' => $this->status($sender),
      'migrate' => $this->migrate($sender, CommandSupport::slice($tokens, 1)),
      default => CommandSupport::error($sender, "Unknown storage action '$action'.")
    };
  }

  private function status(CommandSender $sender): void {
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
    if ($storage->isShared()) {
      $scope = $storage->playerScope();
      $sender->sendMessage(
        '  §7players: §f' . ($scope === ''
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
    $sender->sendMessage('  §7groups: §f' . count($manager->listGroups())
      . ' §7tracks: §f' . count($manager->listTracks())
      . ' §7players: §f' . count($manager->listUsers()));

    if (!$storage->isShared()) {
      return;
    }
    $file = $this->sqliteFile();
    $sender->sendMessage(
      '  §7local file: §f' . (is_file($file)
        ? basename($file) . ' §7(use §f/stoneperms storage migrate§7 to bring it in)'
        : 'none')
    );
  }

  private function migrate(CommandSender $sender, array $tokens): void {
    $plugin = $this->plugin();
    $target = $plugin->storage();

    if (!$target->isShared()) {
      CommandSupport::error(
        $sender,
        'This server is already on its SQLite file. Point storage.driver at mysql first, '
        . 'restart, then run this to bring the file in.'
      );
      return;
    }

    $file = $this->sqliteFile();
    if (!is_file($file)) {
      CommandSupport::error($sender, 'There is no ' . basename($file) . ' to migrate in this data folder.');
      return;
    }

    $apply = false;
    $scope = null;
    foreach ($tokens as $token) {
      $option = strtolower(trim((string) $token));
      if ($option === '--apply') {
        $apply = true;
        continue;
      }
      if ($option === '--scope-to-server') {
        $scope = $plugin->settings()->serverContext;
        continue;
      }
      if ($option !== '') {
        CommandSupport::error($sender, "Unknown option '$option'. Use --apply or --scope-to-server.");
        return;
      }
    }

    $source = new PdoPermissionRepository(new SqliteDialect($file));
    try {
      $source->initialize($plugin->settings()->defaultGroup);
      $report = (new StorageMigrator($source, $target))->copy(
        CommandSupport::actor($sender),
        !$apply,
        $scope
      );
    } catch (Throwable $throwable) {
      CommandSupport::error($sender, 'The migration stopped: ' . $throwable->getMessage());
      return;
    } finally {
      $source->close();
    }

    $this->report($sender, $report, $scope);

    if ($apply) {
      $plugin->refreshEveryone();
    }
  }

  private function report(CommandSender $sender, StorageMigrationReport $report, ?string $scope): void {
    CommandSupport::send(
      $sender,
      $report->dryRun
        ? '§7Nothing was written. This is what it would copy:'
        : '§7Copied into ' . $this->plugin()->storage()->describe() . ':'
    );
    $sender->sendMessage('  §7groups: §f' . $report->groups . ' §7kept as they were: §f' . $report->groupsKept);
    $sender->sendMessage('  §7tracks: §f' . $report->tracks . ' §7kept as they were: §f' . $report->tracksKept);
    $sender->sendMessage('  §7players: §f' . $report->players);
    $sender->sendMessage('  §7nodes: §f' . $report->nodes);
    foreach ($report->notes as $note) {
      $sender->sendMessage('  §8' . $note);
    }

    if (!$report->dryRun) {
      return;
    }
    if ($scope === null) {
      $sender->sendMessage(
        '  §8Without --scope-to-server every node becomes global: a permission that only'
      );
      $sender->sendMessage(
        '  §8applied here because it lived in this file will apply on every server.'
      );
    }
    $sender->sendMessage('  §7Run it again with §f--apply§7 to write it.');
  }

  private function sqliteFile(): string {
    $plugin = $this->plugin();
    return $plugin->getDataFolder() . $plugin->settings()->databaseFile;
  }
}
