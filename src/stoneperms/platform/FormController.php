<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use imperazim\form\builder\FormBuilder;
use imperazim\form\custom\response\CustomResponse;
use imperazim\form\FormResult;
use imperazim\form\long\elements\Button;
use imperazim\form\long\elements\ButtonCollection;
use imperazim\form\long\elements\ButtonTexture;
use imperazim\form\long\PaginatedLongForm;
use imperazim\form\long\response\ButtonResponse;
use pocketmine\player\Player;
use stoneperms\domain\GroupRecord;
use stoneperms\domain\Node;
use stoneperms\domain\NodeType;
use stoneperms\domain\SubjectRef;
use stoneperms\domain\TrackRecord;
use stoneperms\StonePermsPlugin;
use Throwable;

/**
* The in-game administration UI, built on LibForm.
*
* It covers the operations that are awkward to type: browsing groups and their
* nodes, moving a player along a track, and inspecting why a permission
* resolves the way it does. Anything the forms can do, the commands can do too.
*/
final class FormController {

  private const BACK = "\0back";

  public function __construct(private readonly StonePermsPlugin $plugin) {}

  public function openMain(Player $player): void {
    FormBuilder::long('§lStonePerms')
      ->content('Manage permissions, groups and tracks.')
      ->button("§lPlayers\n§7Inspect and move players", fn(Player $viewer): FormResult => $this->openPlayers($viewer))
      ->button("§lGroups\n§7Weights, nodes and parents", fn(Player $viewer): FormResult => $this->openGroups($viewer))
      ->button("§lTracks\n§7Promotion ladders", fn(Player $viewer): FormResult => $this->openTracks($viewer))
      ->button("§lDashboard\n§7Pairing and status", fn(Player $viewer): FormResult => $this->openWeb($viewer))
      ->send($player);
  }

  private function openPlayers(Player $player): FormResult {
    $entries = [];
    foreach ($this->plugin->getServer()->getOnlinePlayers() as $online) {
      $entries[$online->getName()] = $online->getName();
    }
    if ($entries === []) {
      $player->sendMessage('§7No players are online.');
      return $this->back($player);
    }
    $this->paginate(
      $player,
      'Players',
      'Select an online player.',
      $entries,
      fn(Player $viewer, string $name): FormResult => $this->openPlayer($viewer, $name)
    );
    return FormResult::CLOSE;
  }

  private function openPlayer(Player $player, string $name): FormResult {
    return $this->guard($player, function () use ($player, $name): void {
      $service = $this->plugin->api();
      $subject = $this->plugin->manager()->userSubject($name);
      $groups = implode(', ', $service->getGroups($name)) ?: 'none';
      $tracks = $service->getUserTracks($name);

      $lines = [
        '§7Primary group: §f' . $service->getPrimaryGroup($name),
        '§7Groups: §f' . $groups,
        '§7Prefix: §f' . ($service->getPrefix($name) ?? '§8none'),
        '§7Suffix: §f' . ($service->getSuffix($name) ?? '§8none'),
        '§7Nodes: §f' . count($this->plugin->manager()->nodesFor($subject))
      ];

      $builder = FormBuilder::long('§l' . $name)->content(implode("\n", $lines) . "\n");
      foreach ($this->plugin->manager()->listTracks() as $track) {
        if (count($track->groups) < 2) {
          continue;
        }
        $position = $tracks[$track->name][0] ?? '§8not on track';
        $trackName = $track->name;
        $builder->button(
          "§lPromote on $trackName\n§7Currently: $position",
          fn(Player $viewer): FormResult => $this->move($viewer, $name, $trackName, 'promote')
        );
        $builder->button(
          "§lDemote on $trackName\n§7Currently: $position",
          fn(Player $viewer): FormResult => $this->move($viewer, $name, $trackName, 'demote')
        );
      }
      $builder->button(
        "§lCheck a permission\n§7Explain how it resolves",
        fn(Player $viewer): FormResult => $this->openCheck($viewer, $name)
      );
      $builder->button('§7Back', fn(Player $viewer): FormResult => $this->openPlayers($viewer));
      $builder->send($player);
    });
  }

  private function openCheck(Player $player, string $name): FormResult {
    FormBuilder::custom('Check permission')
      ->label("§7Resolve a node for §f$name§7.")
      ->input('node', 'Permission node', 'example.command.kick')
      ->onSubmit(function (Player $viewer, CustomResponse $response) use ($name): FormResult {
        return $this->guard($viewer, function () use ($viewer, $response, $name): void {
          $node = trim($response->getInput('node'));
          if ($node === '') {
            $viewer->sendMessage('§cEnter a permission node.');
            return;
          }
          $decision = $this->plugin->api()->checkPermission($name, $node);
          $value = $decision->value === null ? '§8undefined' : ($decision->value ? '§atrue' : '§cfalse');
          $viewer->sendMessage("§7$node for §f$name§7: $value");
          if ($decision->selected !== null) {
            $viewer->sendMessage(
              '§7Chosen from §f' . $decision->selected->origin->key()
              . ' §7(' . count($decision->candidates) . ' candidate(s))'
            );
          }
        });
      })
      ->send($player);
    return FormResult::CLOSE;
  }

  private function move(Player $player, string $name, string $track, string $direction): FormResult {
    return $this->guard($player, function () use ($player, $name, $track, $direction): void {
      $actor = 'player:' . $player->getName();
      $result = $direction === 'promote'
        ? $this->plugin->api()->promote($name, $track, $actor)
        : $this->plugin->api()->demote($name, $track, $actor);
      $player->sendMessage(
        '§7' . ucfirst($direction) . " on §f$track§7: §f" . $result->status->value
        . ($result->groupTo !== null ? ' §7-> §f' . $result->groupTo : '')
      );
    });
  }

  private function openGroups(Player $player): FormResult {
    $entries = ['§l+ Create a group' => ''];
    foreach ($this->plugin->manager()->listGroups() as $group) {
      $entries["§l{$group->name}\n§7weight {$group->weight}"] = $group->name;
    }
    $this->paginate(
      $player,
      'Groups',
      'Select a group.',
      $entries,
      fn(Player $viewer, string $name): FormResult
        => $name === '' ? $this->openCreateGroup($viewer) : $this->openGroup($viewer, $name)
    );
    return FormResult::CLOSE;
  }

  private function openGroup(Player $player, string $name): FormResult {
    return $this->guard($player, function () use ($player, $name): void {
      $group = $this->plugin->manager()->getGroup($name);
      $nodes = $this->plugin->manager()->nodesFor(SubjectRef::group($group->name));
      FormBuilder::long('§l' . $group->name)
        ->content(self::describeGroup($group, $nodes))
        ->button(
          "§lSet weight",
          fn(Player $viewer): FormResult => $this->openGroupWeight($viewer, $group->name, $group->weight)
        )
        ->button(
          "§lSet a permission",
          fn(Player $viewer): FormResult => $this->openGroupPermission($viewer, $group->name)
        )
        ->button('§7Back', fn(Player $viewer): FormResult => $this->openGroups($viewer))
        ->send($player);
    });
  }

  private function openGroupWeight(Player $player, string $name, int $weight): FormResult {
    FormBuilder::custom("Weight of $name")
      ->input('weight', 'Weight', '0', (string) $weight)
      ->onSubmit(function (Player $viewer, CustomResponse $response) use ($name): FormResult {
        return $this->guard($viewer, function () use ($viewer, $response, $name): void {
          $value = trim($response->getInput('weight'));
          if (!is_numeric($value)) {
            $viewer->sendMessage('§cWeight must be a whole number.');
            return;
          }
          $this->plugin->manager()->setGroupWeight($name, (int) $value, 'player:' . $viewer->getName());
          $this->plugin->refreshEveryone();
          $viewer->sendMessage("§7Weight of §f$name§7 is now §f" . (int) $value);
        });
      })
      ->send($player);
    return FormResult::CLOSE;
  }

  private function openGroupPermission(Player $player, string $name): FormResult {
    FormBuilder::custom("Permission for $name")
      ->input('node', 'Permission node', 'example.command.kick')
      ->toggle('value', 'Grant (off denies)', true)
      ->onSubmit(function (Player $viewer, CustomResponse $response) use ($name): FormResult {
        return $this->guard($viewer, function () use ($viewer, $response, $name): void {
          $node = trim($response->getInput('node'));
          if ($node === '') {
            $viewer->sendMessage('§cEnter a permission node.');
            return;
          }
          $this->plugin->manager()->setPermission(
            SubjectRef::group($name),
            $node,
            $response->getToggle('value'),
            'player:' . $viewer->getName()
          );
          $this->plugin->refreshEveryone();
          $viewer->sendMessage("§7Set §f$node§7 on §f$name§7.");
        });
      })
      ->send($player);
    return FormResult::CLOSE;
  }

  private function openCreateGroup(Player $player): FormResult {
    FormBuilder::custom('Create a group')
      ->input('name', 'Group name', 'moderator')
      ->input('weight', 'Weight', '0', '0')
      ->onSubmit(function (Player $viewer, CustomResponse $response): FormResult {
        return $this->guard($viewer, function () use ($viewer, $response): void {
          $name = trim($response->getInput('name'));
          $weight = trim($response->getInput('weight'));
          $created = $this->plugin->manager()->createGroup(
            $name,
            null,
            is_numeric($weight) ? (int) $weight : 0,
            'player:' . $viewer->getName()
          );
          $viewer->sendMessage($created ? "§7Created group §f$name§7." : "§cGroup $name already exists.");
        });
      })
      ->send($player);
    return FormResult::CLOSE;
  }

  private function openTracks(Player $player): FormResult {
    $builder = FormBuilder::long('Tracks')->content('Promotion ladders.');
    foreach ($this->plugin->manager()->listTracks() as $track) {
      $builder->button(
        '§l' . $track->name . "\n§7" . self::describeTrack($track),
        static fn(Player $viewer): FormResult => FormResult::CLOSE
      );
    }
    $builder->button('§7Back', fn(Player $viewer): FormResult => $this->back($viewer));
    $builder->send($player);
    return FormResult::CLOSE;
  }

  private function openWeb(Player $player): FormResult {
    $status = $this->plugin->web()->status();
    $lines = [
      '§7Enabled: §f' . ($status->enabled ? 'yes' : 'no'),
      '§7Paired: §f' . ($status->configured ? 'yes' : 'no'),
      '§7Connected: §f' . ($status->connected ? 'yes' : 'no'),
      '§7API: §f' . ($status->apiUrl !== '' ? $status->apiUrl : 'unset')
    ];
    if ($status->lastError !== '') {
      $lines[] = '§cLast error: §f' . $status->lastError;
    }
    FormBuilder::long('Dashboard')
      ->content(implode("\n", $lines) . "\n")
      ->button('§7Back', fn(Player $viewer): FormResult => $this->back($viewer))
      ->send($player);
    return FormResult::CLOSE;
  }

  private function back(Player $player): FormResult {
    $this->openMain($player);
    return FormResult::CLOSE;
  }

  /**
  * Group and player lists grow past what one form comfortably shows, so they
  * are paged. The last entry is always a way back to the main menu.
  *
  * @param array<string, string> $entries label => value handed to the callback
  * @param callable(Player, string): FormResult $onPick
  */
  private function paginate(
    Player $player,
    string $title,
    string $content,
    array $entries,
    callable $onPick
  ): void {
    $entries['§7Back'] = self::BACK;
    $values = array_values($entries);

    $buttons = new ButtonCollection();
    foreach (array_keys($entries) as $label) {
      $buttons->add(new Button(
        $label,
        new ButtonTexture('', ButtonTexture::PATH),
        new ButtonResponse(static fn(Player $viewer): FormResult => FormResult::CLOSE)
      ));
    }

    (new PaginatedLongForm(
      $player,
      $title,
      $content,
      $buttons,
      function (Player $viewer, Button $button, int $index) use ($values, $onPick): FormResult {
        $value = $values[$index] ?? self::BACK;
        return $value === self::BACK ? $this->back($viewer) : $onPick($viewer, $value);
      },
      fn(Player $viewer): FormResult => FormResult::CLOSE,
      8
    ))->sendTo($player);
  }

  /**
  * Form handlers run inside the network session; an uncaught throwable there
  * would drop the player, so every branch reports the failure instead.
  */
  private function guard(Player $player, callable $action): FormResult {
    try {
      $action();
    } catch (Throwable $throwable) {
      $player->sendMessage('§c' . $throwable->getMessage());
    }
    return FormResult::CLOSE;
  }

  /** @param list<Node> $nodes */
  private static function describeGroup(GroupRecord $group, array $nodes): string {
    $counts = [];
    foreach ($nodes as $node) {
      $counts[$node->type->value] = ($counts[$node->type->value] ?? 0) + 1;
    }
    $parts = [];
    foreach (NodeType::cases() as $type) {
      $parts[] = '§7' . $type->value . ': §f' . ($counts[$type->value] ?? 0);
    }
    return '§7Display name: §f' . $group->displayName . "\n"
      . '§7Weight: §f' . $group->weight . "\n"
      . implode('  ', $parts) . "\n";
  }

  private static function describeTrack(TrackRecord $track): string {
    return $track->groups === [] ? 'empty' : implode(' -> ', $track->groups);
  }
}
