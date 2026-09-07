<?php

declare(strict_types = 1);

namespace stoneperms;

use imperazim\components\plugin\PluginToolkit;
use imperazim\components\scheduler\TaskSchedulerAPI;
use pocketmine\player\Player;
use stoneperms\application\EditorProtocol;
use stoneperms\application\StonePermsManager;
use stoneperms\command\CommandEnums;
use stoneperms\command\StonePermsCommand;
use stoneperms\infrastructure\PdoPermissionRepository;
use stoneperms\infrastructure\SqliteDialect;
use stoneperms\platform\AttachmentManager;
use stoneperms\platform\ConfigurationService;
use stoneperms\platform\ContextCalculator;
use stoneperms\platform\DisplayService;
use stoneperms\platform\DisplaySettings;
use stoneperms\platform\FormController;
use stoneperms\platform\PlaceholderBridge;
use stoneperms\platform\Settings;
use stoneperms\platform\StartupReport;
use stoneperms\platform\StonePermsEvents;
use stoneperms\platform\StonePermsListener;
use stoneperms\platform\StonePermsService;
use stoneperms\web\WebConnector;
use Throwable;

/**
* StonePerms for PocketMine-MP.
*
* Permission data lives in this plugin's own SQLite database and is applied
* through PocketMine's native attachment API, so checks keep working when the
* web stack is offline or was never set up. The optional bridge speaks the same
* protocol as the Endstone build, which is why both pair with the same public
* API and dashboard.
*/
final class StonePermsPlugin extends PluginToolkit {

  private const EXPIRY_TASK = 'stoneperms.maintenance.expiry';
  private const CATALOG_TASK = 'stoneperms.maintenance.catalog';

  private Settings $settings;
  private PdoPermissionRepository $repository;
  private StonePermsManager $manager;
  private ContextCalculator $contextCalculator;
  private AttachmentManager $attachmentManager;
  private DisplayService $displayService;
  private ConfigurationService $configurationService;
  private EditorProtocol $editorProtocol;
  private StonePermsService $service;
  private WebConnector $webConnector;
  private FormController $formController;
  private PlaceholderBridge $placeholderBridge;
  private int $suggestionRevision = -1;

  protected function onEnable(): void {
    TaskSchedulerAPI::init($this);

    try {
      $this->saveDefaultConfig();
      $this->settings = Settings::load($this->getConfig()->getAll());
    } catch (Throwable $throwable) {
      $this->getLogger()->critical('StonePerms could not read its configuration: ' . $throwable->getMessage());
      $this->getServer()->getPluginManager()->disablePlugin($this);
      return;
    }

    // PocketMine-MP does not require PDO, so say plainly what is missing
    // rather than surfacing a driver error from inside the storage layer.
    if (!extension_loaded('pdo_sqlite')) {
      $this->getLogger()->critical(
        'StonePerms needs the pdo_sqlite PHP extension, which this server does not have. '
        . 'Install it, or run a PocketMine-MP build that includes it.'
      );
      $this->getServer()->getPluginManager()->disablePlugin($this);
      return;
    }

    try {
      $this->repository = new PdoPermissionRepository(
        new SqliteDialect($this->getDataFolder() . $this->settings->databaseFile)
      );
      $this->repository->initialize($this->settings->defaultGroup);
    } catch (Throwable $throwable) {
      $this->getLogger()->critical('StonePerms could not open its database: ' . $throwable->getMessage());
      $this->getServer()->getPluginManager()->disablePlugin($this);
      return;
    }

    $this->manager = new StonePermsManager($this->repository, $this->settings->defaultGroup);
    $this->contextCalculator = new ContextCalculator($this->settings);
    $this->attachmentManager = new AttachmentManager(
      $this,
      $this->manager,
      $this->contextCalculator,
      function (Player $player): void {
        $this->displayService->applyNameTag($player);
      }
    );
    $this->displayService = new DisplayService(
      $this,
      $this->manager,
      $this->contextCalculator,
      $this->settings->display
    );
    $this->editorProtocol = new EditorProtocol(
      $this->manager,
      Version::VERSION,
      fn(): array => $this->attachmentManager->permissionCatalog()
    );
    $this->configurationService = new ConfigurationService(
      $this->manager,
      $this->contextCalculator,
      $this->settings,
      function (Settings $updated): void {
        $this->persistSettings($updated);
      },
      function (Settings $active): void {
        $this->settings = $active;
      }
    );
    $this->service = new StonePermsService(
      $this->manager,
      $this->contextCalculator,
      $this->editorProtocol,
      Version::VERSION,
      fn(string $uniqueId): mixed => $this->refreshUser($uniqueId),
      fn(): mixed => $this->refreshEveryone(),
      $this->displayService,
      $this->configurationService
    );
    $this->webConnector = new WebConnector(
      $this,
      $this->service,
      $this->settings->webEnabled,
      $this->settings->webApiUrl,
      $this->settings->webServerId,
      $this->settings->webServerToken,
      $this->settings->webServerName,
      function (string $apiUrl, string $serverId, string $serverToken, string $serverName): void {
        $this->persistWebCredential($apiUrl, $serverId, $serverToken, $serverName);
      },
      function (DisplaySettings $display): void {
        $this->persistDisplay($display);
      }
    );
    $this->formController = new FormController($this);
    $this->placeholderBridge = new PlaceholderBridge($this, $this->service);

    $this->initComponents($this, self::LISTENER_COMPONENT, new StonePermsListener($this));
    $this->initComponents($this, self::COMMAND_COMPONENT, new StonePermsCommand($this));

    $this->placeholderBridge->register();
    CommandEnums::register($this->manager);
    $this->scheduleMaintenance();
    $this->attachmentManager->refreshPermissionCatalog();
    $this->attachmentManager->refreshAll();

    $this->webConnector->start();
    StartupReport::log($this, $this->manager, $this->settings);
  }

  protected function onDisable(): void {
    TaskSchedulerAPI::cancel(self::EXPIRY_TASK);
    TaskSchedulerAPI::cancel(self::CATALOG_TASK);

    // onEnable can bail out part-way through, so each teardown is guarded
    // rather than assuming every collaborator was constructed.
    if (isset($this->placeholderBridge)) {
      $this->placeholderBridge->unregister();
    }
    if (isset($this->webConnector)) {
      $this->webConnector->shutdown();
    }
    if (isset($this->editorProtocol)) {
      $this->editorProtocol->close();
    }
    if (isset($this->displayService)) {
      $this->displayService->close();
    }
    if (isset($this->attachmentManager)) {
      $this->attachmentManager->close();
    }
    if (isset($this->repository)) {
      $this->repository->close();
    }
  }

  // ------------------------------------------------------------- accessors

  public function settings(): Settings {
    return $this->settings;
  }

  public function manager(): StonePermsManager {
    return $this->manager;
  }

  public function contexts(): ContextCalculator {
    return $this->contextCalculator;
  }

  public function attachments(): AttachmentManager {
    return $this->attachmentManager;
  }

  public function display(): DisplayService {
    return $this->displayService;
  }

  public function web(): WebConnector {
    return $this->webConnector;
  }

  public function forms(): FormController {
    return $this->formController;
  }

  public function placeholders(): PlaceholderBridge {
    return $this->placeholderBridge;
  }

  /**
  * The entry point for other plugins:
  *
  * ```php
  * $stoneperms = $server->getPluginManager()->getPlugin('StonePerms');
  * if ($stoneperms instanceof StonePermsPlugin && $stoneperms->api()->hasPermission($player, 'example.use')) {
  *     ...
  * }
  * ```
  */
  public function api(): StonePermsService {
    return $this->service;
  }

  // --------------------------------------------------------------- helpers

  public function refreshUser(string $uniqueId): bool {
    $changed = $this->attachmentManager->applyByUniqueId($uniqueId);
    foreach ($this->getServer()->getOnlinePlayers() as $player) {
      if ($player->getUniqueId()->toString() === $uniqueId) {
        $this->displayService->applyNameTag($player);
        break;
      }
    }
    StonePermsEvents::emit(StonePermsEvents::USER_CHANGED, [
      'uniqueId' => $uniqueId,
      'changed' => $changed
    ]);
    return $changed;
  }

  public function refreshEveryone(): int {
    $changed = $this->attachmentManager->refreshAll();
    $this->displayService->refreshAllNameTags();
    StonePermsEvents::emit(StonePermsEvents::DATA_CHANGED, [
      'players' => $changed,
      'revision' => $this->repository->revision()
    ]);
    return $changed;
  }

  /**
  * Temporary nodes expire on a timer rather than lazily, so a player loses a
  * timed permission when it runs out instead of at their next check.
  */
  private function scheduleMaintenance(): void {
    TaskSchedulerAPI::repeat($this->settings->expiryCheckTicks, function (): void {
      $expired = $this->manager->cleanupExpired();
      if ($expired->count > 0) {
        StonePermsEvents::emit(StonePermsEvents::NODES_EXPIRED, [
          'count' => $expired->count,
          'subjects' => count($expired->subjects)
        ]);
        $this->refreshEveryone();
      }
    }, self::EXPIRY_TASK);

    TaskSchedulerAPI::repeat($this->settings->catalogCheckTicks, function (): void {
      $this->attachmentManager->refreshPermissionCatalog();

      // Watching the revision keeps the command suggestions correct no matter
      // which surface changed the data: a command, a form, or the dashboard.
      $revision = $this->repository->revision();
      if ($revision !== $this->suggestionRevision) {
        $this->suggestionRevision = $revision;
        CommandEnums::refresh($this->manager);
      }
    }, self::CATALOG_TASK);
  }

  private function persistSettings(Settings $updated): void {
    $config = $this->getConfig();
    $permissions = $config->get('permissions', []);
    $permissions['default_group'] = $updated->defaultGroup;
    $config->set('permissions', $permissions);

    $contexts = $config->get('contexts', []);
    $contexts['server'] = $updated->serverContext;
    $contexts['include_device_os'] = $updated->includeDeviceOsContext;
    $contexts['include_locale'] = $updated->includeLocaleContext;
    $config->set('contexts', $contexts);

    $maintenance = $config->get('maintenance', []);
    $maintenance['expiry_check_ticks'] = $updated->expiryCheckTicks;
    $maintenance['catalog_check_ticks'] = $updated->catalogCheckTicks;
    $config->set('maintenance', $maintenance);

    $config->set('debug', $updated->debug);
    $config->save();
  }

  private function persistDisplay(DisplaySettings $display): void {
    $config = $this->getConfig();
    $config->set('display', [
      'chat' => ['enabled' => $display->chatEnabled, 'format' => $display->chatFormat],
      'nametag' => ['enabled' => $display->nametagEnabled, 'format' => $display->nametagFormat]
    ]);
    $config->save();
    $this->settings = $this->settings->withDisplay($display);
  }

  private function persistWebCredential(
    string $apiUrl,
    string $serverId,
    string $serverToken,
    string $serverName
  ): void {
    $config = $this->getConfig();
    $web = $config->get('web', []);
    $web['enabled'] = $serverId !== '' && $serverToken !== '';
    $web['api_url'] = $apiUrl;
    $web['server_id'] = $serverId;
    $web['server_token'] = $serverToken;
    $web['server_name'] = $serverName;
    $config->set('web', $web);
    $config->save();

    $this->settings = $this->settings->with([
      'webEnabled' => $web['enabled'],
      'webApiUrl' => $apiUrl,
      'webServerId' => $serverId,
      'webServerToken' => $serverToken,
      'webServerName' => $serverName
    ]);
  }
}
