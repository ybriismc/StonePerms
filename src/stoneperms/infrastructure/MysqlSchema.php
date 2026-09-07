<?php

declare(strict_types = 1);

namespace stoneperms\infrastructure;

/**
* The permission database on MySQL.
*
* The tables carry the same names, columns and meaning as the SQLite schema, so
* the store above them is the same code. What changes is only what the engine
* requires: an indexed key needs a length, a partial index has no equivalent
* and becomes a plain one, and case-insensitive comparison comes from the
* collation instead of being spelled out per query.
*
* `shared_state` is the one table SQLite does not have. It holds the revision,
* which is how a server learns that another server changed something.
*/
final class MysqlSchema {

  public const VERSION = 1;

  /** @return array<int, list<string>> */
  public static function migrations(): array {
    return [1 => self::migration1()];
  }

  /** @return list<string> */
  private static function migration1(): array {
    return [
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS users (
          unique_id VARCHAR(64) NOT NULL,
          xuid VARCHAR(32) NULL,
          last_name VARCHAR(64) NOT NULL,
          created_at BIGINT NOT NULL,
          updated_at BIGINT NOT NULL,
          locale VARCHAR(32) NULL,
          device_os VARCHAR(32) NULL,
          game_version VARCHAR(32) NULL,
          game_mode VARCHAR(32) NULL,
          ping_ms INT NULL,
          total_exp INT NULL,
          exp_level INT NULL,
          skin_id VARCHAR(191) NULL,
          skin_hash VARCHAR(128) NULL,
          skin_width INT NULL,
          skin_height INT NULL,
          skin_rgba MEDIUMBLOB NULL,
          cape_id VARCHAR(191) NULL,
          first_seen_at BIGINT NULL,
          last_seen_at BIGINT NULL,
          last_joined_at BIGINT NULL,
          last_quit_at BIGINT NULL,
          skin_updated_at BIGINT NULL,
          online TINYINT NOT NULL DEFAULT 0,
          PRIMARY KEY (unique_id),
          UNIQUE KEY users_xuid_unique (xuid),
          KEY users_last_name_lookup (last_name),
          KEY users_last_seen_lookup (last_seen_at),
          KEY users_online_lookup (online, last_seen_at)
      ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS permission_groups (
          name VARCHAR(64) NOT NULL,
          display_name VARCHAR(64) NOT NULL,
          weight INT NOT NULL DEFAULT 0,
          created_at BIGINT NOT NULL,
          updated_at BIGINT NOT NULL,
          PRIMARY KEY (name)
      ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS nodes (
          id BIGINT NOT NULL AUTO_INCREMENT,
          subject_type VARCHAR(8) NOT NULL,
          subject_id VARCHAR(64) NOT NULL,
          node_type VARCHAR(16) NOT NULL,
          node_key VARCHAR(191) NOT NULL,
          node_value TEXT NOT NULL,
          contexts_json TEXT NOT NULL,
          expires_at BIGINT NULL,
          priority INT NOT NULL DEFAULT 0,
          created_at BIGINT NOT NULL,
          PRIMARY KEY (id),
          KEY nodes_subject_lookup (subject_type, subject_id),
          KEY nodes_expiry_lookup (expires_at),
          KEY nodes_permission_lookup (node_type, node_key),
          CONSTRAINT nodes_subject_type CHECK (subject_type IN ('user', 'group')),
          CONSTRAINT nodes_node_type CHECK (node_type IN ('permission', 'parent', 'meta', 'prefix', 'suffix'))
      ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS audit_log (
          id BIGINT NOT NULL AUTO_INCREMENT,
          created_at BIGINT NOT NULL,
          actor VARCHAR(64) NOT NULL,
          action VARCHAR(64) NOT NULL,
          subject_type VARCHAR(8) NULL,
          subject_id VARCHAR(64) NULL,
          details_json MEDIUMTEXT NOT NULL,
          PRIMARY KEY (id),
          KEY audit_created_lookup (created_at, id)
      ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS tracks (
          name VARCHAR(64) NOT NULL,
          created_at BIGINT NOT NULL,
          updated_at BIGINT NOT NULL,
          PRIMARY KEY (name)
      ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS track_groups (
          track_name VARCHAR(64) NOT NULL,
          group_name VARCHAR(64) NOT NULL,
          position INT NOT NULL,
          PRIMARY KEY (track_name, group_name),
          UNIQUE KEY track_groups_position_unique (track_name, position),
          KEY track_groups_position_lookup (track_name, position),
          CONSTRAINT track_groups_position_positive CHECK (position >= 0),
          CONSTRAINT track_groups_track FOREIGN KEY (track_name) REFERENCES tracks(name)
              ON DELETE CASCADE ON UPDATE CASCADE,
          CONSTRAINT track_groups_group FOREIGN KEY (group_name) REFERENCES permission_groups(name)
              ON DELETE RESTRICT
      ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
      SQL,
      <<<'SQL'
      CREATE TABLE IF NOT EXISTS shared_state (
          id TINYINT NOT NULL,
          revision BIGINT NOT NULL DEFAULT 0,
          PRIMARY KEY (id)
      ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
      SQL,
      'INSERT IGNORE INTO shared_state(id, revision) VALUES (1, 0)'
    ];
  }
}
