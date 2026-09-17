<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use InvalidArgumentException;
use stoneperms\domain\Validation;

/**
* Where the permission data lives.
*
* `sqlite` is the default and needs nothing configured: one file in the
* plugin's data folder, used by this server alone.
*
* `mysql` points several servers at one database — the same database the
* Endstone build opens, so a network can run both. Nothing is copied and
* nothing is synchronised: there is a single set of rows that every server
* reads and writes, and each server polls a revision to learn that another
* changed something.
*
* Groups, tracks and everything hanging off a group are shared. Players are
* not: each server keeps its own, identified by `server_id`, so a player who
* is VIP on the lobby arrives at a minigame with whatever that server gives
* them.
*/
final class StorageSettings {

  public const SQLITE = 'sqlite';
  public const MYSQL = 'mysql';

  public function __construct(
    public readonly string $backend = self::SQLITE,
    public readonly string $serverId = '',
    public readonly int $syncTicks = 20,
    public readonly string $host = '127.0.0.1',
    public readonly int $port = 3306,
    public readonly string $database = 'stoneperms',
    public readonly string $username = 'stoneperms',
    public readonly string $password = '',
    public readonly int $connectTimeout = 5,
    public readonly int $readTimeout = 10,
    public readonly string $sslCa = ''
  ) {}

  /** @param array<string, mixed> $storage */
  public static function load(array $storage): self {
    $backend = strtolower(trim((string) ($storage['backend'] ?? self::SQLITE)));
    if ($backend === '') {
      $backend = self::SQLITE;
    }
    if ($backend !== self::SQLITE && $backend !== self::MYSQL) {
      throw new InvalidArgumentException('storage.backend must be sqlite or mysql');
    }

    $mysql = $storage['mysql'] ?? [];
    if (!is_array($mysql)) {
      throw new InvalidArgumentException('storage.mysql must be a table');
    }

    $serverId = trim((string) ($storage['server_id'] ?? ''));
    if ($backend === self::MYSQL && $serverId === '') {
      throw new InvalidArgumentException('storage.server_id must identify this server when using MySQL');
    }
    if ($serverId !== '') {
      $serverId = Validation::contextValue($serverId);
    }

    $settings = new self(
      $backend,
      $serverId,
      self::bounded($storage['sync_ticks'] ?? null, 20, 20, 1200),
      trim((string) ($mysql['host'] ?? '127.0.0.1')),
      self::bounded($mysql['port'] ?? null, 3306, 1, 65535),
      trim((string) ($mysql['database'] ?? 'stoneperms')),
      trim((string) ($mysql['username'] ?? 'stoneperms')),
      (string) ($mysql['password'] ?? ''),
      self::bounded($mysql['connect_timeout'] ?? null, 5, 1, 60),
      self::bounded($mysql['read_timeout'] ?? null, 10, 1, 120),
      trim((string) ($mysql['ssl_ca'] ?? ''))
    );

    if ($settings->isShared() && ($settings->host === '' || $settings->database === '' || $settings->username === '')) {
      throw new InvalidArgumentException('storage.mysql needs a host, a database and a username');
    }

    return $settings;
  }

  public function isShared(): bool {
    return $this->backend === self::MYSQL;
  }

  private static function bounded(mixed $value, int $default, int $minimum, int $maximum): int {
    if (is_bool($value) || $value === null || !is_numeric($value)) {
      return $default;
    }
    return min($maximum, max($minimum, (int) $value));
  }
}
