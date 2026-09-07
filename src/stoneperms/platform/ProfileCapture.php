<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use pocketmine\entity\Skin;
use pocketmine\player\Player;
use stoneperms\domain\PlayerProfile;
use Throwable;

/**
* Reads everything the dashboard displays off a live player, and renders the
* vanilla face preview locally so no skin bytes ever leave the server.
*/
final class ProfileCapture {

  private const FACE_SIZE = 128;

  /** Byte length of the raw RGBA buffer for each skin geometry PocketMine accepts. */
  private const SKIN_DIMENSIONS = [
    8192 => [64, 32],
    16384 => [64, 64],
    32768 => [128, 64],
    65536 => [128, 128],
    131072 => [256, 128],
    262144 => [256, 256]
  ];

  public static function capture(
    Player $player,
    bool $online = true,
    bool $joined = false,
    bool $quit = false,
    ?int $observedAt = null
  ): PlayerProfile {
    $timestamp = $observedAt ?? time();
    $skin = self::skin($player);
    [$skinId, $skinHash, $width, $height, $rgba, $capeId] = self::captureSkin($skin);
    $xuid = trim($player->getXuid());

    return new PlayerProfile(
      PlayerIdentity::uniqueId($player),
      $player->getName(),
      $xuid === '' ? null : $xuid,
      self::text(self::call($player, 'getLocale')),
      self::deviceOs($player),
      self::text(self::call($player, 'getGameVersion')),
      self::gameMode($player),
      self::integer(self::ping($player), 0),
      self::integer(self::xp($player, 'getCurrentTotalXp'), 0),
      self::integer(self::xp($player, 'getXpLevel'), 0),
      $skinId,
      $skinHash,
      $width,
      $height,
      $rgba,
      $capeId,
      $timestamp,
      $timestamp,
      $joined ? $timestamp : null,
      $quit ? $timestamp : null,
      $skinHash !== null ? $timestamp : null,
      $online
    );
  }

  /**
  * Composes the 8x8 head with its hat overlay, scales it with nearest
  * neighbour and encodes a PNG by hand, so the plugin needs neither GD nor
  * Imagick.
  */
  public static function renderFacePng(PlayerProfile $profile, int $size = self::FACE_SIZE): ?string {
    if (str_starts_with(strtolower($profile->skinId ?? ''), 'persona-')) {
      return null;
    }
    $width = $profile->skinWidth;
    $height = $profile->skinHeight;
    $rgba = $profile->skinRgba;
    if (
      $rgba === null
      || $rgba === ''
      || $width === null
      || $height === null
      || $width < 64
      || $width % 64 !== 0
      || $height < intdiv($width, 2)
      || strlen($rgba) !== $width * $height * 4
      || $size < 16
      || $size > 256
    ) {
      return null;
    }

    $scale = intdiv($width, 64);
    $sourceSize = 8 * $scale;
    if ($height < 16 * $scale || $width < 48 * $scale) {
      return null;
    }

    $pixels = str_repeat("\0", $sourceSize * $sourceSize * 4);
    for ($y = 0; $y < $sourceSize; $y++) {
      for ($x = 0; $x < $sourceSize; $x++) {
        $base = substr($rgba, ((8 * $scale + $y) * $width + 8 * $scale + $x) * 4, 4);
        $overlay = substr($rgba, ((8 * $scale + $y) * $width + 40 * $scale + $x) * 4, 4);
        $offset = ($y * $sourceSize + $x) * 4;
        $composed = self::alphaOver($base, $overlay);
        $pixels[$offset] = $composed[0];
        $pixels[$offset + 1] = $composed[1];
        $pixels[$offset + 2] = $composed[2];
        $pixels[$offset + 3] = $composed[3];
      }
    }

    $resized = '';
    for ($y = 0; $y < $size; $y++) {
      $sourceY = intdiv($y * $sourceSize, $size);
      for ($x = 0; $x < $size; $x++) {
        $sourceX = intdiv($x * $sourceSize, $size);
        $resized .= substr($pixels, ($sourceY * $sourceSize + $sourceX) * 4, 4);
      }
    }
    return self::encodeRgbaPng($size, $size, $resized);
  }

  private static function skin(Player $player): ?Skin {
    try {
      return $player->getSkin();
    } catch (Throwable) {
      return null;
    }
  }

  /**
  * @return array{0: ?string, 1: ?string, 2: ?int, 3: ?int, 4: ?string, 5: ?string}
  */
  private static function captureSkin(?Skin $skin): array {
    if ($skin === null) {
      return [null, null, null, null, null, null];
    }
    $skinId = self::text($skin->getSkinId());
    $capeData = $skin->getCapeData();
    $capeId = $capeData === '' ? null : substr(hash('sha256', $capeData), 0, 32);

    $data = $skin->getSkinData();
    $dimensions = self::SKIN_DIMENSIONS[strlen($data)] ?? null;
    if ($dimensions === null) {
      return [$skinId, null, null, null, null, $capeId];
    }
    [$width, $height] = $dimensions;
    return [$skinId, hash('sha256', $data), $width, $height, $data, $capeId];
  }

  /**
  * Latency lives on the network session, not on the player.
  */
  private static function ping(Player $player): ?int {
    $session = self::call($player, 'getNetworkSession');
    return $session === null ? null : self::call($session, 'getPing');
  }

  private static function deviceOs(Player $player): ?string {
    $info = self::call($player, 'getPlayerInfo');
    if ($info === null) {
      return null;
    }
    $extra = self::call($info, 'getExtraData');
    if (is_array($extra) && isset($extra['DeviceOS'])) {
      return self::text((string) $extra['DeviceOS']);
    }
    return null;
  }

  private static function gameMode(Player $player): ?string {
    $mode = self::call($player, 'getGamemode');
    if ($mode === null) {
      return null;
    }
    if (is_object($mode)) {
      if (property_exists($mode, 'name')) {
        return strtolower((string) $mode->name);
      }
      if (method_exists($mode, 'name')) {
        return strtolower((string) $mode->name());
      }
      if (method_exists($mode, 'getEnglishName')) {
        return strtolower((string) $mode->getEnglishName());
      }
    }
    return self::text((string) $mode);
  }

  private static function xp(Player $player, string $method): ?int {
    $manager = self::call($player, 'getXpManager');
    if ($manager === null) {
      return null;
    }
    $value = self::call($manager, $method);
    return is_numeric($value) ? (int) $value : null;
  }

  private static function call(object $target, string $method): mixed {
    if (!method_exists($target, $method)) {
      return null;
    }
    try {
      return $target->$method();
    } catch (Throwable) {
      return null;
    }
  }

  private static function text(mixed $value): ?string {
    if ($value === null) {
      return null;
    }
    $text = trim((string) $value);
    return $text === '' ? null : substr($text, 0, 128);
  }

  private static function integer(mixed $value, int $minimum): ?int {
    if (!is_numeric($value)) {
      return null;
    }
    $number = (int) $value;
    return $number >= $minimum ? $number : null;
  }

  /** @return array{0: string, 1: string, 2: string, 3: string} */
  private static function alphaOver(string $base, string $overlay): array {
    if (strlen($base) < 4 || strlen($overlay) < 4) {
      return ["\0", "\0", "\0", "\0"];
    }
    $baseAlpha = ord($base[3]);
    $overlayAlpha = ord($overlay[3]);
    $outAlpha = $overlayAlpha + intdiv($baseAlpha * (255 - $overlayAlpha) + 127, 255);
    if ($outAlpha === 0) {
      return ["\0", "\0", "\0", "\0"];
    }
    $channels = [];
    for ($index = 0; $index < 3; $index++) {
      $numerator = ord($overlay[$index]) * $overlayAlpha * 255
        + ord($base[$index]) * $baseAlpha * (255 - $overlayAlpha);
      $channels[] = chr(intdiv($numerator + $outAlpha * 127, $outAlpha * 255));
    }
    $channels[] = chr($outAlpha);
    return $channels;
  }

  private static function encodeRgbaPng(int $width, int $height, string $rgba): string {
    $rows = '';
    for ($row = 0; $row < $height; $row++) {
      $rows .= "\0" . substr($rgba, $row * $width * 4, $width * 4);
    }
    $signature = "\x89PNG\r\n\x1a\n";
    $header = self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0));
    $data = self::pngChunk('IDAT', gzcompress($rows, 9));
    return $signature . $header . $data . self::pngChunk('IEND', '');
  }

  private static function pngChunk(string $kind, string $payload): string {
    return pack('N', strlen($payload)) . $kind . $payload . pack('N', crc32($kind . $payload));
  }
}
