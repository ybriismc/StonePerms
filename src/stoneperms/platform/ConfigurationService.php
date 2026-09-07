<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use Closure;
use InvalidArgumentException;
use stoneperms\application\StonePermsManager;
use stoneperms\domain\Validation;

/**
* The safe subset of `config.yml` the dashboard is allowed to change.
*
* Values that only affect runtime behaviour take effect immediately. Values
* that are wired at startup — the default group and both maintenance intervals
* — are saved and then reported back as requiring a restart, rather than being
* half-applied.
*/
final class ConfigurationService {

  private Settings $configured;
  private Settings $active;
  private ?Closure $onApply;

  /**
  * @param callable(Settings): void $persist writes the values into config.yml
  * @param (callable(Settings): void)|null $onApply re-wires the runtime with the new values
  */
  public function __construct(
    private readonly StonePermsManager $manager,
    private readonly ContextCalculator $contexts,
    Settings $settings,
    private readonly Closure $persist,
    ?callable $onApply = null
  ) {
    $this->configured = $settings;
    $this->active = $settings;
    $this->onApply = $onApply !== null ? Closure::fromCallable($onApply) : null;
  }

  public function active(): Settings {
    return $this->active;
  }

  /** @return array<string, mixed> */
  public function webSettings(): array {
    return [
      'configured' => self::serialize($this->configured),
      'active' => self::serialize($this->active),
      'restartRequired' => $this->restartRequired(),
      'limits' => [
        'expiryCheckSeconds' => ['minimum' => 1, 'maximum' => 60],
        'catalogRefreshSeconds' => ['minimum' => 1, 'maximum' => 300]
      ]
    ];
  }

  /** @return array<string, mixed> */
  public function updateSettings(
    string $defaultGroup,
    string $serverContext,
    bool $includeDeviceOsContext,
    bool $includeLocaleContext,
    int $expiryCheckSeconds,
    int $catalogRefreshSeconds,
    bool $debug,
    string $actor
  ): array {
    $updated = $this->configured->with([
      'defaultGroup' => Validation::groupName($defaultGroup),
      'serverContext' => Validation::contextValue($serverContext),
      'includeDeviceOsContext' => $includeDeviceOsContext,
      'includeLocaleContext' => $includeLocaleContext,
      'expiryCheckTicks' => self::secondsToTicks($expiryCheckSeconds, 'expiryCheckSeconds', 60),
      'catalogCheckTicks' => self::secondsToTicks($catalogRefreshSeconds, 'catalogRefreshSeconds', 300),
      'debug' => $debug
    ]);

    $before = self::serialize($this->configured);
    $after = self::serialize($updated);
    $changed = [];
    foreach ($after as $key => $value) {
      if ($before[$key] !== $value) {
        $changed[] = $key;
      }
    }
    if ($changed === []) {
      return $this->webSettings();
    }

    ($this->persist)($updated);
    $this->configured = $updated;

    $this->active = $this->active->with([
      'serverContext' => $updated->serverContext,
      'includeDeviceOsContext' => $updated->includeDeviceOsContext,
      'includeLocaleContext' => $updated->includeLocaleContext,
      'debug' => $updated->debug
    ]);
    $this->contexts->updateSettings($this->active);
    if ($this->onApply !== null) {
      ($this->onApply)($this->active);
    }

    $restartRequired = $this->restartRequired();
    $this->manager->recordSettingsChange($actor, $changed, $restartRequired);
    return $this->webSettings();
  }

  /** @return list<string> */
  private function restartRequired(): array {
    $fields = [];
    if ($this->configured->defaultGroup !== $this->active->defaultGroup) {
      $fields[] = 'defaultGroup';
    }
    if ($this->configured->expiryCheckTicks !== $this->active->expiryCheckTicks) {
      $fields[] = 'expiryCheckSeconds';
    }
    if ($this->configured->catalogCheckTicks !== $this->active->catalogCheckTicks) {
      $fields[] = 'catalogRefreshSeconds';
    }
    return $fields;
  }

  /** @return array<string, mixed> */
  private static function serialize(Settings $settings): array {
    return [
      'defaultGroup' => $settings->defaultGroup,
      'serverContext' => $settings->serverContext,
      'includeDeviceOsContext' => $settings->includeDeviceOsContext,
      'includeLocaleContext' => $settings->includeLocaleContext,
      'expiryCheckSeconds' => intdiv($settings->expiryCheckTicks, 20),
      'catalogRefreshSeconds' => intdiv($settings->catalogCheckTicks, 20),
      'debug' => $settings->debug
    ];
  }

  private static function secondsToTicks(int $value, string $label, int $maximum): int {
    if ($value < 1 || $value > $maximum) {
      throw new InvalidArgumentException("$label must be between 1 and $maximum seconds");
    }
    return $value * 20;
  }
}
