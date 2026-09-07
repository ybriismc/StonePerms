<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use InvalidArgumentException;
use stoneperms\domain\Validation;

/**
* The parsed, validated `config.yml`.
*
* Parsing is total: anything the file cannot supply falls back to a documented
* default, and anything it supplies badly raises before the plugin enables.
*/
final class Settings {

  public const PUBLIC_DASHBOARD_URL = 'https://stoneperms.spindexgfx.com';
  public const PUBLIC_API_URL = self::PUBLIC_DASHBOARD_URL;

  public function __construct(
    public readonly string $databaseFile = 'stoneperms.db',
    public readonly string $defaultGroup = 'default',
    public readonly string $serverContext = 'global',
    public readonly int $expiryCheckTicks = 20,
    public readonly int $catalogCheckTicks = 100,
    public readonly bool $includeDeviceOsContext = false,
    public readonly bool $includeLocaleContext = false,
    public readonly bool $debug = false,
    public readonly bool $webEnabled = false,
    public readonly string $webApiUrl = self::PUBLIC_API_URL,
    public readonly string $webServerId = '',
    public readonly string $webServerToken = '',
    public readonly string $webServerName = 'PocketMine-MP server',
    public readonly StartupSettings $startup = new StartupSettings(),
    public readonly DisplaySettings $display = new DisplaySettings()
  ) {}

  /** @param array<string, mixed> $raw */
  public static function load(array $raw): self {
    $storage = self::section($raw, 'storage');
    $permissions = self::section($raw, 'permissions');
    $contexts = self::section($raw, 'contexts');
    $maintenance = self::section($raw, 'maintenance');
    $startup = self::section($raw, 'startup');
    $web = self::section($raw, 'web');
    $display = self::section($raw, 'display');
    $chat = self::section($display, 'chat');
    $nametag = self::section($display, 'nametag');

    $databaseFile = trim((string) ($storage['database'] ?? 'stoneperms.db'));
    if (
      $databaseFile === ''
      || str_contains($databaseFile, '/')
      || str_contains($databaseFile, '\\')
      || $databaseFile === '.'
      || $databaseFile === '..'
    ) {
      throw new InvalidArgumentException(
        'storage.database must be a filename inside the plugin data directory'
      );
    }

    return new self(
      $databaseFile,
      Validation::groupName((string) ($permissions['default_group'] ?? 'default')),
      Validation::contextValue((string) ($contexts['server'] ?? 'global')),
      self::boundedInt($maintenance['expiry_check_ticks'] ?? null, 20, 20, 1200),
      self::boundedInt($maintenance['catalog_check_ticks'] ?? null, 100, 20, 6000),
      (bool) ($contexts['include_device_os'] ?? false),
      (bool) ($contexts['include_locale'] ?? false),
      (bool) ($raw['debug'] ?? false),
      (bool) ($web['enabled'] ?? false),
      self::boundedText($web['api_url'] ?? null, self::PUBLIC_API_URL, 512),
      self::boundedText($web['server_id'] ?? null, '', 64),
      self::boundedText($web['server_token'] ?? null, '', 128),
      self::boundedText($web['server_name'] ?? null, 'PocketMine-MP server', 80),
      new StartupSettings(
        (bool) ($startup['show_summary'] ?? true),
        (bool) ($startup['check_server_properties'] ?? true)
      ),
      new DisplaySettings(
        (bool) ($chat['enabled'] ?? false),
        self::validateFormat(
          $chat['format'] ?? DisplaySettings::DEFAULT_CHAT_FORMAT,
          DisplaySettings::CHAT_PLACEHOLDERS,
          ['name', 'message'],
          'display.chat.format'
        ),
        (bool) ($nametag['enabled'] ?? false),
        self::validateFormat(
          $nametag['format'] ?? DisplaySettings::DEFAULT_NAMETAG_FORMAT,
          DisplaySettings::NAMETAG_PLACEHOLDERS,
          ['name'],
          'display.nametag.format'
        )
      )
    );
  }

  /**
  * A display template may only use the placeholders its surface supports, must
  * include the ones that make it meaningful, and must stay on one line.
  *
  * @param list<string> $allowed
  * @param list<string> $required
  */
  public static function validateFormat(mixed $value, array $allowed, array $required, string $label): string {
    $result = $value !== null ? (string) $value : '';
    if (
      $result === ''
      || strlen($result) > 256
      || str_contains($result, "\0")
      || str_contains($result, "\n")
      || str_contains($result, "\r")
    ) {
      throw new InvalidArgumentException("$label must contain 1-256 characters on one line");
    }

    $fields = [];
    $matches = [];
    preg_match_all('/\{([^{}]*)\}/', $result, $matches);
    foreach ($matches[1] as $field) {
      if (!in_array($field, $allowed, true)) {
        throw new InvalidArgumentException("$label contains unsupported placeholder {" . $field . '}');
      }
      $fields[$field] = true;
    }

    $missing = array_values(array_diff($required, array_keys($fields)));
    if ($missing !== []) {
      $names = implode(', ', array_map(static fn(string $name): string => '{' . $name . '}', $missing));
      throw new InvalidArgumentException("$label must include $names");
    }
    return $result;
  }

  public function withDisplay(DisplaySettings $display): self {
    return new self(
      $this->databaseFile,
      $this->defaultGroup,
      $this->serverContext,
      $this->expiryCheckTicks,
      $this->catalogCheckTicks,
      $this->includeDeviceOsContext,
      $this->includeLocaleContext,
      $this->debug,
      $this->webEnabled,
      $this->webApiUrl,
      $this->webServerId,
      $this->webServerToken,
      $this->webServerName,
      $this->startup,
      $display
    );
  }

  /**
  * @param array<string, mixed> $overrides
  */
  public function with(array $overrides): self {
    return new self(
      $overrides['databaseFile'] ?? $this->databaseFile,
      $overrides['defaultGroup'] ?? $this->defaultGroup,
      $overrides['serverContext'] ?? $this->serverContext,
      $overrides['expiryCheckTicks'] ?? $this->expiryCheckTicks,
      $overrides['catalogCheckTicks'] ?? $this->catalogCheckTicks,
      $overrides['includeDeviceOsContext'] ?? $this->includeDeviceOsContext,
      $overrides['includeLocaleContext'] ?? $this->includeLocaleContext,
      $overrides['debug'] ?? $this->debug,
      $overrides['webEnabled'] ?? $this->webEnabled,
      $overrides['webApiUrl'] ?? $this->webApiUrl,
      $overrides['webServerId'] ?? $this->webServerId,
      $overrides['webServerToken'] ?? $this->webServerToken,
      $overrides['webServerName'] ?? $this->webServerName,
      $overrides['startup'] ?? $this->startup,
      $overrides['display'] ?? $this->display
    );
  }

  /** @param array<string, mixed> $raw @return array<string, mixed> */
  private static function section(array $raw, string $key): array {
    $value = $raw[$key] ?? [];
    if (!is_array($value)) {
      throw new InvalidArgumentException("Configuration section '$key' must be a table");
    }
    return $value;
  }

  private static function boundedInt(mixed $value, int $default, int $minimum, int $maximum): int {
    if (is_bool($value) || $value === null || !is_numeric($value)) {
      return $default;
    }
    return min($maximum, max($minimum, (int) $value));
  }

  private static function boundedText(mixed $value, string $default, int $maximum): string {
    $result = $value !== null ? trim((string) $value) : $default;
    if (strlen($result) > $maximum || str_contains($result, "\0")) {
      throw new InvalidArgumentException("Configuration text must contain at most $maximum characters");
    }
    return $result;
  }
}
