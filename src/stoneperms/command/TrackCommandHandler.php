<?php

declare(strict_types = 1);

namespace stoneperms\command;

use pocketmine\command\CommandSender;
use stoneperms\StonePermsPlugin;

/**
* `/stoneperms track ...` — defining and editing promotion ladders.
*
* Editing a track never changes anybody's groups; only promote and demote do.
*/
final class TrackCommandHandler {

  public function __construct(private readonly StonePermsPlugin $plugin) {}

  /** @param list<string> $tokens tokens after `track` */
  public function handle(CommandSender $sender, array $tokens): void {
    $action = strtolower($tokens[0] ?? '');
    if ($action === '' || $action === 'list') {
      $this->list($sender);
      return;
    }

    $name = $tokens[1] ?? '';
    if ($name === '') {
      CommandSupport::error($sender, 'Usage: /stoneperms track <list|create|delete|info|append|insert|remove|clear|rename|clone> <track> ...');
      return;
    }
    $manager = $this->plugin->manager();
    $actor = CommandSupport::actor($sender);

    switch ($action) {
      case 'create':
        $created = $manager->createTrack($name, $actor);
        CommandSupport::send(
          $sender,
          $created ? "§7Created track §f$name§7." : "§cTrack $name already exists."
        );
        return;
      case 'delete':
        $deleted = $manager->deleteTrack($name, $actor);
        CommandSupport::send(
          $sender,
          $deleted ? "§7Deleted track §f$name§7." : "§cTrack $name does not exist."
        );
        return;
      case 'info':
        $track = $manager->getTrack($name);
        CommandSupport::send($sender, '§7Track §f' . $track->name . '§7:');
        $sender->sendMessage('  §f' . ($track->groups === [] ? '§8empty' : implode(' §8-> §f', $track->groups)));
        return;
      case 'append':
        $track = $manager->appendTrackGroup($name, $tokens[2] ?? '', $actor);
        break;
      case 'insert':
        $track = $manager->insertTrackGroup(
          $name,
          $tokens[2] ?? '',
          CommandSupport::integer($tokens[3] ?? '', 'Position'),
          $actor
        );
        break;
      case 'remove':
        $track = $manager->removeTrackGroup($name, $tokens[2] ?? '', $actor);
        break;
      case 'clear':
        $track = $manager->clearTrack($name, $actor);
        break;
      case 'rename':
        $track = $manager->renameTrack($name, $tokens[2] ?? '', $actor);
        break;
      case 'clone':
        $track = $manager->cloneTrack($name, $tokens[2] ?? '', $actor);
        break;
      default:
        CommandSupport::error($sender, "Unknown track action '$action'.");
        return;
    }

    CommandSupport::send(
      $sender,
      '§7Track §f' . $track->name . '§7: §f'
      . ($track->groups === [] ? '§8empty' : implode(' §8-> §f', $track->groups))
    );
  }

  private function list(CommandSender $sender): void {
    $tracks = $this->plugin->manager()->listTracks();
    if ($tracks === []) {
      CommandSupport::send($sender, '§7No tracks are defined.');
      return;
    }
    CommandSupport::send($sender, '§7Tracks (§f' . count($tracks) . '§7):');
    foreach ($tracks as $track) {
      $sender->sendMessage(
        '  §f' . $track->name . ' §8| §7'
        . ($track->groups === [] ? '§8empty' : implode(' -> ', $track->groups))
      );
    }
  }
}
