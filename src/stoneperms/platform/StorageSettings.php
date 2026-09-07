<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use InvalidArgumentException;

/**
* Where the permission data lives.
*
* `sqlite` is the default and needs nothing configured: one file in the
* plugin's data folder, used by this server alone.
*
* `mysql` points several servers at one database. Nothing is copied and
* nothing is synchronised — there is a single set of data that every server
* reads and writes. What each server polls for is the revision, which is how
* it learns that another server changed something.
*/
final class StorageSettings {

  public const SQLITE = 'sqlite';
  public const MYSQL = 'mysql';

  public function __construct(
    public readonly string $driver = self::SQLITE,
    public readonly string $host = '127.0.0.1',
    public readonly int $port = 3306,
    public readonly string $database = 'stoneperms',
    public readonly string $username = 'stoneperms',
    public readonly string $password = '',
    public readonly string $charset = 'utf8mb4',
    public readonly int $syncCheckTicks = 20
  ) {}

  /** @param array<string, mixed> $storage */
  public static function load(array $storage): self {
    $driver = strtolower(trim((string) ($storage['driver'] ?? self::SQLITE)));
    if ($driver === '') {
      $driver = self::SQLITE;
    }
    if ($driver !== self::SQLITE && $driver !== self::MYSQL) {
      throw new InvalidArgumentException("storage.driver must be 'sqlite' or 'mysql', not '$driver'");
    }

    $mysql = $storage['mysql'] ?? [];
    if (!is_array($mysql)) {
      throw new InvalidArgumentException('storage.mysql must be a table');
    }

    $settings = new self(
      $driver,
      trim((string) ($mysql['host'] ?? '127.0.0.1')),
      (int) ($mysql['port'] ?? 3306),
      trim((string) ($mysql['database'] ?? 'stoneperms')),
      trim((string) ($mysql['username'] ?? 'stoneperms')),
      (string) ($mysql['password'] ?? ''),
      trim((string) ($mysql['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
      self::boundedTicks($mysql['sync_check_ticks'] ?? null)
    );

    // A misspelled host is worth catching now rather than as a connection
    // error after the server has already started coming up.
    if ($settings->isShared()) {
      if ($settings->host === '' || $settings->database === '') {
        throw new InvalidArgumentException('storage.mysql needs a host and a database name');
      }
      if ($settings->port < 1 || $settings->port > 65535) {
        throw new InvalidArgumentException('storage.mysql.port must be a port number');
      }
    }

    return $settings;
  }

  public function isShared(): bool {
    return $this->driver === self::MYSQL;
  }

  private static function boundedTicks(mixed $value): int {
    if (is_bool($value) || $value === null || !is_numeric($value)) {
      return 20;
    }
    return min(1200, max(5, (int) $value));
  }
}
