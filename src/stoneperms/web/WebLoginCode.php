<?php

declare(strict_types = 1);

namespace stoneperms\web;

/**
* A short-lived code that signs an operator into the dashboard from in game.
*/
final class WebLoginCode {

  public function __construct(
    public readonly string $code,
    public readonly int $expiresAt,
    public readonly ?string $username,
    public readonly string $dashboardUrl,
    public readonly bool $claimedServer
  ) {}
}
