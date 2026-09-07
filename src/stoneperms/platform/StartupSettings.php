<?php

declare(strict_types = 1);

namespace stoneperms\platform;

/**
* Controls the status block StonePerms prints when the plugin enables.
*/
final class StartupSettings {

  public function __construct(
    public readonly bool $showSummary = true,
    public readonly bool $checkServerProperties = true
  ) {}
}
