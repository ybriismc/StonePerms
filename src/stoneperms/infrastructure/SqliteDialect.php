<?php

declare(strict_types = 1);

namespace stoneperms\infrastructure;

use imperazim\db\DBManager;
use imperazim\db\Sqlite3;
use PDO;
use RuntimeException;
use Throwable;

/**
* SQLite, one file in the plugin's data folder.
*
* This is the default and it is unchanged: the schema is still byte-for-byte
* the one the Endstone build writes, so a `stoneperms.db` produced by either
* plugin still opens in the other. A file is used by one server, so the
* revision stays a counter in memory.
*/
final class SqliteDialect implements SqlDialect {

  private ?Sqlite3 $database = null;

  public function __construct(private readonly string $path) {}

  public function name(): string {
    return 'sqlite';
  }

  public function describe(): string {
    return 'SQLite (' . basename($this->path) . ')';
  }

  public function isShared(): bool {
    return false;
  }

  public function open(): PDO {
    $directory = dirname($this->path);
    if (!is_dir($directory)) {
      mkdir($directory, 0777, true);
    }

    $connection = DBManager::connect('sqlite', ['database' => $this->path]);
    if (!$connection instanceof Sqlite3) {
      throw new RuntimeException('StonePerms requires the SQLite driver');
    }
    $this->database = $connection;
    $pdo = $connection->getPdo();

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA busy_timeout = 10000');

    return $pdo;
  }

  public function close(): void {
    $this->database?->close();
    $this->database = null;
  }

  public function migrations(): array {
    $migrations = [];
    foreach (SqliteSchema::migrations() as $version => $sql) {
      $migrations[$version] = [$sql];
    }
    return $migrations;
  }

  public function insertGroupIfMissing(): string {
    return 'INSERT INTO permission_groups(name, display_name, weight, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?) ON CONFLICT(name) DO NOTHING';
  }

  public function insertTrackIfMissing(): string {
    return 'INSERT INTO tracks(name, created_at, updated_at) VALUES (?, ?, ?) ON CONFLICT(name) DO NOTHING';
  }

  public function upsertUser(): string {
    return 'INSERT INTO users(unique_id, xuid, last_name, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(unique_id) DO UPDATE SET
              xuid = excluded.xuid,
              last_name = excluded.last_name,
              updated_at = excluded.updated_at';
  }

  public function upsertProfile(): string {
    return 'INSERT INTO users(
              unique_id, xuid, last_name, created_at, updated_at,
              locale, device_os, game_version, game_mode, ping_ms, total_exp, exp_level,
              skin_id, skin_hash, skin_width, skin_height, skin_rgba, cape_id,
              first_seen_at, last_seen_at, last_joined_at, last_quit_at,
              skin_updated_at, online
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(unique_id) DO UPDATE SET
              xuid = COALESCE(excluded.xuid, users.xuid),
              last_name = excluded.last_name,
              updated_at = excluded.updated_at,
              locale = COALESCE(excluded.locale, users.locale),
              device_os = COALESCE(excluded.device_os, users.device_os),
              game_version = COALESCE(excluded.game_version, users.game_version),
              game_mode = COALESCE(excluded.game_mode, users.game_mode),
              ping_ms = COALESCE(excluded.ping_ms, users.ping_ms),
              total_exp = COALESCE(excluded.total_exp, users.total_exp),
              exp_level = COALESCE(excluded.exp_level, users.exp_level),
              skin_id = CASE WHEN excluded.skin_hash IS NOT NULL THEN excluded.skin_id ELSE users.skin_id END,
              skin_hash = COALESCE(excluded.skin_hash, users.skin_hash),
              skin_width = CASE WHEN excluded.skin_hash IS NOT NULL THEN excluded.skin_width ELSE users.skin_width END,
              skin_height = CASE WHEN excluded.skin_hash IS NOT NULL THEN excluded.skin_height ELSE users.skin_height END,
              skin_rgba = CASE WHEN excluded.skin_hash IS NOT NULL THEN excluded.skin_rgba ELSE users.skin_rgba END,
              cape_id = CASE WHEN excluded.skin_hash IS NOT NULL THEN excluded.cape_id ELSE users.cape_id END,
              first_seen_at = COALESCE(users.first_seen_at, excluded.first_seen_at),
              last_seen_at = excluded.last_seen_at,
              last_joined_at = COALESCE(excluded.last_joined_at, users.last_joined_at),
              last_quit_at = COALESCE(excluded.last_quit_at, users.last_quit_at),
              skin_updated_at = CASE
                WHEN excluded.skin_hash IS NOT NULL
                     AND (users.skin_hash IS NULL OR excluded.skin_hash <> users.skin_hash)
                THEN excluded.skin_updated_at
                ELSE users.skin_updated_at
              END,
              online = excluded.online';
  }

  public function caseInsensitive(): string {
    return ' COLLATE NOCASE';
  }

  public function bumpRevision(PDO $pdo, int $current): int {
    return $current + 1;
  }

  public function readRevision(PDO $pdo, int $current): int {
    return $current;
  }

  public function isLostConnection(Throwable $error): bool {
    return false;
  }
}
