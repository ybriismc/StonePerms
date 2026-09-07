<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* Everything StonePerms knows about a player beyond their permissions: the
* identity aliases plus the client details the dashboard displays.
*/
final class PlayerProfile {

  public function __construct(
    public readonly string $uniqueId,
    public readonly string $lastName,
    public readonly ?string $xuid = null,
    public readonly ?string $locale = null,
    public readonly ?string $deviceOs = null,
    public readonly ?string $gameVersion = null,
    public readonly ?string $gameMode = null,
    public readonly ?int $pingMs = null,
    public readonly ?int $totalExp = null,
    public readonly ?int $expLevel = null,
    public readonly ?string $skinId = null,
    public readonly ?string $skinHash = null,
    public readonly ?int $skinWidth = null,
    public readonly ?int $skinHeight = null,
    public readonly ?string $skinRgba = null,
    public readonly ?string $capeId = null,
    public readonly int $firstSeenAt = 0,
    public readonly int $lastSeenAt = 0,
    public readonly ?int $lastJoinedAt = null,
    public readonly ?int $lastQuitAt = null,
    public readonly ?int $skinUpdatedAt = null,
    public readonly bool $online = false
  ) {}

  public function user(): UserRecord {
    return new UserRecord($this->uniqueId, $this->lastName, $this->xuid);
  }

  /**
  * The compact shape the dashboard lists on its player and server pages.
  *
  * @return array<string, mixed>
  */
  public function toSummaryWire(): array {
    return [
      'id' => $this->uniqueId,
      'name' => $this->lastName,
      'xuid' => $this->xuid,
      'online' => $this->online,
      'lastSeenAt' => $this->lastSeenAt,
      'deviceOs' => $this->deviceOs,
      'gameVersion' => $this->gameVersion,
      'skinHash' => $this->skinHash
    ];
  }

  /**
  * The detail shape shown on one player's profile card.
  *
  * @return array<string, mixed>
  */
  public function toDetailWire(): array {
    return [
      'locale' => $this->locale,
      'deviceOs' => $this->deviceOs,
      'gameVersion' => $this->gameVersion,
      'gameMode' => $this->gameMode,
      'pingMs' => $this->pingMs,
      'totalExp' => $this->totalExp,
      'expLevel' => $this->expLevel,
      'skinId' => $this->skinId,
      'skinHash' => $this->skinHash,
      'skinWidth' => $this->skinWidth,
      'skinHeight' => $this->skinHeight,
      'capeId' => $this->capeId,
      'firstSeenAt' => $this->firstSeenAt,
      'lastSeenAt' => $this->lastSeenAt,
      'lastJoinedAt' => $this->lastJoinedAt,
      'lastQuitAt' => $this->lastQuitAt,
      'skinUpdatedAt' => $this->skinUpdatedAt,
      'online' => $this->online
    ];
  }
}
