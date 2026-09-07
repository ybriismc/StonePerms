<?php

declare(strict_types = 1);

namespace stoneperms\web;

/**
* A snapshot of the bridge, as reported by `/stoneperms web status`.
*/
final class WebConnectionStatus {

  public function __construct(
    public readonly bool $enabled,
    public readonly bool $configured,
    public readonly bool $connected,
    public readonly bool $ready,
    public readonly string $apiUrl,
    public readonly string $serverId,
    public readonly string $lastError
  ) {}
}
