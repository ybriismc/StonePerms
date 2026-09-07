<?php

declare(strict_types = 1);

namespace stoneperms\command\subcommand;

use imperazim\command\argument\EnumArgument;
use imperazim\command\argument\StringArgument;
use pocketmine\command\CommandSender;
use stoneperms\command\StonePermsCommand;
use stoneperms\command\StonePermsSubCommand;
use stoneperms\command\WebCommandHandler;
use stoneperms\StonePermsPlugin;

/**
* `/stoneperms web ...` — pairing this server with the dashboard.
*/
final class WebSubCommand extends StonePermsSubCommand {

  private WebCommandHandler $handler;

  public function __construct(StonePermsPlugin $plugin, object $parent) {
    $this->handler = new WebCommandHandler($plugin);
    parent::__construct($plugin, $parent);
  }

  public function onBuild(): array {
    return [
      'name' => 'web',
      'description' => 'Pair this server with the dashboard',
      'permission' => StonePermsCommand::PERMISSION,
      'arguments' => [
        new EnumArgument('action', true, ['status', 'dashboard', 'pair', 'login', 'unpair']),
        new StringArgument('options', true, null, 'Pairing code, API address or server name')
      ]
    ];
  }

  protected function run(CommandSender $sender, array $tokens): void {
    $this->handler->handle($sender, $tokens);
  }
}
