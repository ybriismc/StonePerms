<?php

declare(strict_types = 1);

namespace stoneperms\infrastructure;

/**
* The permission database on MySQL, as the Endstone build writes it.
*
* Every table, column, type, index and collation here matches that build
* statement for statement, because the two plugins are meant to share one
* database: a network can run both and see one set of groups and tracks.
*
* `storage_state` holds the revision a server polls to learn that another
* server changed something. `storage_servers` maps a server to its own user
* table, since a player belongs to the server that saw them.
*/
final class MysqlSchema {

  public const VERSION = 2;

  /** @return array<int, list<string>> */
  public static function migrations(): array {
    return [
      1 => self::migration1(),
      2 => self::migration2()
    ];
  }

  public const MIGRATIONS_TABLE = <<<'SQL'
  CREATE TABLE IF NOT EXISTS schema_migrations (
      version INTEGER PRIMARY KEY,
      applied_at BIGINT NOT NULL
  ) ENGINE=InnoDB
  SQL;

  /** The per-server user table, named after the server it belongs to. */
  public const USER_SCHEMA = <<<'SQL'
  CREATE TABLE IF NOT EXISTS %s (
      unique_id VARCHAR(128) PRIMARY KEY,
      xuid VARCHAR(64) UNIQUE,
      last_name VARCHAR(256) NOT NULL,
      created_at BIGINT NOT NULL,
      updated_at BIGINT NOT NULL,
      locale TEXT,
      device_os TEXT,
      game_version TEXT,
      game_mode TEXT,
      ping_ms INTEGER,
      total_exp BIGINT,
      exp_level INTEGER,
      skin_id TEXT,
      skin_hash VARCHAR(128),
      skin_width INTEGER,
      skin_height INTEGER,
      skin_rgba LONGBLOB,
      cape_id TEXT,
      first_seen_at BIGINT,
      last_seen_at BIGINT,
      last_joined_at BIGINT,
      last_quit_at BIGINT,
      skin_updated_at BIGINT,
      online INTEGER NOT NULL DEFAULT 0,
      INDEX users_last_name_lookup (last_name(128)),
      INDEX users_last_seen_lookup (last_seen_at),
      INDEX users_online_lookup (online, last_seen_at),
      CHECK (online IN (0, 1))
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
  SQL;

  /** @return list<string> */
  private static function migration1(): array {
    return [
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS storage_state (
          id INTEGER PRIMARY KEY,
          revision BIGINT NOT NULL
      ) ENGINE=InnoDB
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS storage_servers (
          server_id VARCHAR(128) PRIMARY KEY,
          users_table VARCHAR(64) NOT NULL UNIQUE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS permission_groups (
          name VARCHAR(64) PRIMARY KEY,
          display_name TEXT NOT NULL,
          weight BIGINT NOT NULL DEFAULT 0,
          created_at BIGINT NOT NULL,
          updated_at BIGINT NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS nodes (
          id BIGINT PRIMARY KEY AUTO_INCREMENT,
          subject_type VARCHAR(16) NOT NULL,
          subject_id VARCHAR(128) NOT NULL,
          node_type VARCHAR(16) NOT NULL,
          node_key TEXT NOT NULL,
          node_value LONGTEXT NOT NULL,
          contexts_json LONGTEXT NOT NULL,
          expires_at BIGINT,
          priority BIGINT NOT NULL DEFAULT 0,
          created_at BIGINT NOT NULL,
          server VARCHAR(128) NOT NULL DEFAULT '',
          INDEX nodes_subject_lookup (subject_type, subject_id),
          INDEX nodes_server_lookup (server, subject_type, subject_id),
          INDEX nodes_expiry_lookup (expires_at),
          INDEX nodes_permission_lookup (node_type, node_key(128)),
          CHECK (subject_type IN ('user', 'group')),
          CHECK (node_type IN ('permission', 'parent', 'meta', 'prefix', 'suffix'))
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS audit_log (
          id BIGINT PRIMARY KEY AUTO_INCREMENT,
          created_at BIGINT NOT NULL,
          actor VARCHAR(128) NOT NULL,
          action VARCHAR(128) NOT NULL,
          subject_type VARCHAR(16),
          subject_id VARCHAR(128),
          details_json LONGTEXT NOT NULL,
          INDEX audit_created_lookup (created_at, id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS tracks (
          name VARCHAR(64) PRIMARY KEY,
          created_at BIGINT NOT NULL,
          updated_at BIGINT NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS track_groups (
          track_name VARCHAR(64) NOT NULL,
          group_name VARCHAR(64) NOT NULL,
          position INTEGER NOT NULL,
          PRIMARY KEY (track_name, group_name),
          UNIQUE (track_name, position),
          FOREIGN KEY (track_name) REFERENCES tracks(name) ON DELETE CASCADE ON UPDATE CASCADE,
          FOREIGN KEY (group_name) REFERENCES permission_groups(name) ON DELETE RESTRICT,
          CHECK (position >= 0)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
      SQL
    ];
  }

  /**
  * A group belongs to the network and carries no server. A node attached to a
  * player belongs to the server that gave it, so the same person can be VIP on
  * one server and default on another out of one database.
  *
  * @return list<string>
  */
  private static function migration2(): array {
    return [
      "ALTER TABLE nodes ADD COLUMN server VARCHAR(128) NOT NULL DEFAULT ''",
      'ALTER TABLE nodes ADD INDEX nodes_server_lookup (server, subject_type, subject_id)'
    ];
  }
}
