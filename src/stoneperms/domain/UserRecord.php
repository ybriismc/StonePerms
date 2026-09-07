<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* The identity triple StonePerms keeps for every player it has seen.
*
* The unique id is canonical; xuid and name are lookup aliases only.
*/
final class UserRecord {

  public function __construct(
    public readonly string $uniqueId,
    public readonly string $lastName,
    public readonly ?string $xuid = null
  ) {}
}
