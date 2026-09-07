<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use pocketmine\Server;
use stoneperms\application\StonePermsManager;
use stoneperms\StonePermsPlugin;
use Throwable;

/**
* The status block printed once the plugin is wired up.
*
* It states what is actually active rather than what is configured, and flags
* the server settings that quietly weaken StonePerms.
*/
final class StartupReport {

  public static function log(StonePermsPlugin $plugin, StonePermsManager $manager, Settings $settings): void {
    $logger = $plugin->getLogger();
    if (!$settings->startup->showSummary) {
      foreach (self::warnings($plugin->getServer(), $settings) as $warning) {
        $logger->warning($warning);
      }
      return;
    }

    $groups = $manager->listGroups();
    $tracks = $manager->listTracks();
    $web = $plugin->web()->status();

    $logger->info('Storage: ' . $plugin->storage()->describe() . ($settings->storage->isShared()
      ? ', shared with every server pointed at it'
      : ' in plugin_data/StonePerms'));
    $logger->info(
      'Permissions: default group "' . $settings->defaultGroup . '", '
      . count($groups) . ' group(s), ' . count($tracks) . ' track(s)'
    );
    $logger->info(
      'Contexts: server=' . $settings->serverContext
      . ', device_os=' . self::flag($settings->includeDeviceOsContext)
      . ', locale=' . self::flag($settings->includeLocaleContext)
      . ', providers=' . count($plugin->contexts()->providerKeys())
    );
    $logger->info(
      'Display: chat ' . self::flag($settings->display->chatEnabled)
      . ', nametags ' . self::flag($settings->display->nametagEnabled)
      . ', placeholders ' . self::flag($plugin->placeholders()->isRegistered())
    );

    if (!$web->enabled) {
      $logger->info('Dashboard: disabled. Run "/stoneperms web pair <code>" to connect this server.');
    } elseif (!$web->configured) {
      $logger->info('Dashboard: enabled but not paired. Run "/stoneperms web pair <code>".');
    } else {
      $logger->info('Dashboard: paired with ' . $web->apiUrl . ' as server ' . $web->serverId);
    }

    foreach (self::warnings($plugin->getServer(), $settings) as $warning) {
      $logger->warning($warning);
    }
  }

  /**
  * Server settings that change what StonePerms can guarantee.
  *
  * @return list<string>
  */
  private static function warnings(Server $server, Settings $settings): array {
    $warnings = [];
    if (!$settings->startup->checkServerProperties) {
      return $warnings;
    }

    if (self::xboxAuthDisabled($server)) {
      $warnings[] = 'server.properties has xbox-auth=off. Player UUIDs are derived from names and '
        . 'XUIDs are empty, so permissions do not survive a name change and are not portable '
        . 'between servers.';
    }
    if ($settings->display->chatEnabled) {
      $warnings[] = 'Chat formatting is enabled. If another plugin also formats chat, the plugin '
        . 'that handles PlayerChatEvent last wins.';
    }
    return $warnings;
  }

  private static function xboxAuthDisabled(Server $server): bool {
    try {
      if (!method_exists($server, 'getConfigGroup')) {
        return false;
      }
      $config = $server->getConfigGroup();
      if (!method_exists($config, 'getConfigBool')) {
        return false;
      }
      return $config->getConfigBool('xbox-auth', true) === false;
    } catch (Throwable) {
      return false;
    }
  }

  private static function flag(bool $value): string {
    return $value ? 'on' : 'off';
  }
}
