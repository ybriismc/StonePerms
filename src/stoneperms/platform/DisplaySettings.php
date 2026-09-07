<?php

declare(strict_types = 1);

namespace stoneperms\platform;

/**
* Chat and nametag formatting, both off by default so an existing chat plugin
* keeps full control until an operator opts in.
*/
final class DisplaySettings {

  public const DEFAULT_CHAT_FORMAT = '{prefix}§f{name}{suffix} §8» §f{message}';
  public const DEFAULT_NAMETAG_FORMAT = '{prefix}§f{name}{suffix}';

  public const CHAT_PLACEHOLDERS = ['prefix', 'name', 'suffix', 'message'];
  public const NAMETAG_PLACEHOLDERS = ['prefix', 'name', 'suffix'];

  public function __construct(
    public readonly bool $chatEnabled = false,
    public readonly string $chatFormat = self::DEFAULT_CHAT_FORMAT,
    public readonly bool $nametagEnabled = false,
    public readonly string $nametagFormat = self::DEFAULT_NAMETAG_FORMAT
  ) {}
}
