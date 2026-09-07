<?php

declare(strict_types = 1);

namespace stoneperms\infrastructure;

/**
* The migration steps that build the permission database.
*
* The schema is byte-for-byte the schema the Endstone build uses, so a
* `stoneperms.db` produced by either plugin opens in the other.
*/
final class SqliteSchema {

  public const VERSION = 3;

  public const MIGRATION_1 = <<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
    version INTEGER PRIMARY KEY,
    applied_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    unique_id TEXT PRIMARY KEY,
    xuid TEXT,
    last_name TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS users_xuid_unique
    ON users(xuid) WHERE xuid IS NOT NULL AND xuid <> '';
CREATE INDEX IF NOT EXISTS users_last_name_lookup ON users(last_name COLLATE NOCASE);

CREATE TABLE IF NOT EXISTS permission_groups (
    name TEXT PRIMARY KEY,
    display_name TEXT NOT NULL,
    weight INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS nodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subject_type TEXT NOT NULL CHECK(subject_type IN ('user', 'group')),
    subject_id TEXT NOT NULL,
    node_type TEXT NOT NULL CHECK(node_type IN ('permission', 'parent', 'meta', 'prefix', 'suffix')),
    node_key TEXT NOT NULL,
    node_value TEXT NOT NULL,
    contexts_json TEXT NOT NULL DEFAULT '[]',
    expires_at INTEGER,
    priority INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS nodes_subject_lookup
    ON nodes(subject_type, subject_id);
CREATE INDEX IF NOT EXISTS nodes_expiry_lookup
    ON nodes(expires_at) WHERE expires_at IS NOT NULL;
CREATE INDEX IF NOT EXISTS nodes_permission_lookup
    ON nodes(node_type, node_key);

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at INTEGER NOT NULL,
    actor TEXT NOT NULL,
    action TEXT NOT NULL,
    subject_type TEXT,
    subject_id TEXT,
    details_json TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS audit_created_lookup ON audit_log(created_at DESC, id DESC);
SQL;

  public const MIGRATION_2 = <<<'SQL'
CREATE TABLE IF NOT EXISTS tracks (
    name TEXT PRIMARY KEY,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS track_groups (
    track_name TEXT NOT NULL REFERENCES tracks(name) ON DELETE CASCADE ON UPDATE CASCADE,
    group_name TEXT NOT NULL REFERENCES permission_groups(name) ON DELETE RESTRICT,
    position INTEGER NOT NULL CHECK(position >= 0),
    PRIMARY KEY(track_name, group_name),
    UNIQUE(track_name, position)
);
CREATE INDEX IF NOT EXISTS track_groups_position_lookup
    ON track_groups(track_name, position);
SQL;

  public const MIGRATION_3 = <<<'SQL'
ALTER TABLE users ADD COLUMN locale TEXT;
ALTER TABLE users ADD COLUMN device_os TEXT;
ALTER TABLE users ADD COLUMN game_version TEXT;
ALTER TABLE users ADD COLUMN game_mode TEXT;
ALTER TABLE users ADD COLUMN ping_ms INTEGER;
ALTER TABLE users ADD COLUMN total_exp INTEGER;
ALTER TABLE users ADD COLUMN exp_level INTEGER;
ALTER TABLE users ADD COLUMN skin_id TEXT;
ALTER TABLE users ADD COLUMN skin_hash TEXT;
ALTER TABLE users ADD COLUMN skin_width INTEGER;
ALTER TABLE users ADD COLUMN skin_height INTEGER;
ALTER TABLE users ADD COLUMN skin_rgba BLOB;
ALTER TABLE users ADD COLUMN cape_id TEXT;
ALTER TABLE users ADD COLUMN first_seen_at INTEGER;
ALTER TABLE users ADD COLUMN last_seen_at INTEGER;
ALTER TABLE users ADD COLUMN last_joined_at INTEGER;
ALTER TABLE users ADD COLUMN last_quit_at INTEGER;
ALTER TABLE users ADD COLUMN skin_updated_at INTEGER;
ALTER TABLE users ADD COLUMN online INTEGER NOT NULL DEFAULT 0 CHECK(online IN (0, 1));

UPDATE users
SET first_seen_at = created_at,
    last_seen_at = updated_at
WHERE first_seen_at IS NULL OR last_seen_at IS NULL;

CREATE INDEX IF NOT EXISTS users_last_seen_lookup ON users(last_seen_at DESC);
CREATE INDEX IF NOT EXISTS users_online_lookup ON users(online, last_seen_at DESC);
SQL;

  public const PROFILE_COLUMNS = 'unique_id, last_name, xuid, locale, device_os, game_version, game_mode, '
    . 'ping_ms, total_exp, exp_level, skin_id, skin_hash, skin_width, skin_height, '
    . 'skin_rgba, cape_id, first_seen_at, last_seen_at, last_joined_at, '
    . 'last_quit_at, skin_updated_at, online';

  /** @return array<int, string> */
  public static function migrations(): array {
    return [
      1 => self::MIGRATION_1,
      2 => self::MIGRATION_2,
      3 => self::MIGRATION_3
    ];
  }
}
