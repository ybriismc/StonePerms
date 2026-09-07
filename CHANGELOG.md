# Changelog

All notable changes to StonePerms for PocketMine-MP are recorded here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - unreleased

### Added

- **MySQL storage.** `storage.driver: mysql` points several servers at one
  database. There is a single set of rows that every server reads and writes,
  so nothing is copied between servers and nothing is reconciled. Each write
  bumps a revision inside its own transaction and each server polls that row,
  which is how it learns that another server changed something and its cached
  snapshots are stale; the players online then have the new answer applied.
- **Players belong to their server.** On a shared database the groups, tracks
  and everything hanging off a group are the network's — one definition, seen
  everywhere — while the players and what each player has been given belong to
  the server that saw them. Somebody who is VIP on the lobby arrives at a
  minigame as whatever that server gives them, usually the default group. Each
  server is identified by its own `contexts.server` name, and
  `storage.mysql.share_players: true` asks for one set of players instead.
- **`/stoneperms storage`.** `status` says where the data lives and whether
  other servers can write there. `migrate` reads this server's SQLite file into
  the shared database — writing nothing until `--apply`, keeping anything the
  target already has, and safe to run twice. `--scope-to-server` tags every
  copied node with that server's `server` context so behaviour does not change
  the moment the data is merged.
- The startup report and `/stoneperms info` say which store is in use.

### Changed

- The store is one implementation over PDO with the engine's differences behind
  a dialect, rather than a class named for SQLite. SQLite keeps its file, its
  schema and its behaviour exactly: a `stoneperms.db` written by this build
  still opens in the Endstone one.
- An editor batch checks the revision it was built against with that row locked
  inside its transaction, so two dashboards on two servers can no longer both
  pass the check and both write.
- A statement whose connection died is retried once on a fresh connection, which
  is what a database left idle overnight does to it.

### Notes

- `storage.driver` defaults to `sqlite` and nothing about an existing server
  changes. The MySQL driver needs the `pdo_mysql` PHP extension.
- While the database is unreachable, permissions already resolved keep being
  served and writes fail rather than being queued: a queued write would have to
  be reconciled later against what other servers did meanwhile, which is how
  data goes missing quietly. A player who joins during an outage gets no
  attachment rather than a wrong one.

## [1.0.0] - 2026-09-07

First stable release of the PocketMine-MP build. It is a port of the Endstone
plugin, not a rewrite: the permission model, the storage schema and the editor
protocol are unchanged, so one dashboard manages both server implementations and
a `stoneperms.db` written by either opens in the other.

### Added

**Permissions**

- Users, groups and recursive group inheritance, with cycles cut safely.
- Positive and negative nodes. A denial beats a grant on an exact tie, so a
  `false` node overrides an operator's blanket permission.
- Terminal wildcards (`namespace.*`) and the global `*` node, expanded against
  the server's own permission catalog because PocketMine does not match them.
- Temporary nodes with a combinable duration syntax (`30m`, `2h30m`, `7d`,
  `1mo2d`), removed by a timer rather than lazily at the next check.
- Weighted conflict resolution: a direct node beats an inherited one, a
  temporary beats a permanent, a longer wildcard beats a shorter one.
- A SQLite audit log of every change, whoever made it.

**Contexts**

- Built-in `server`, `world`, `dimension` and `gamemode` contexts, plus optional
  `device_os` and `locale`.
- Context providers other plugins can register and unregister, so a minigame can
  expose an arena or a region plugin a zone.

**Display**

- Inherited metadata and weighted prefixes and suffixes.
- Optional chat and nametag formatting, both off by default so an existing chat
  plugin keeps control. Chat formatting only replaces the event's formatter; it
  never cancels or rebroadcasts the message.
- A `stoneperms` placeholder expansion registered with LibPlaceholder.

**Administration**

- The full `/stoneperms` command tree, with `/sp` and `/perms` as aliases, built
  from LibCommand subcommands with their own arguments and constraints.
- Group and track names published as command soft enums, refreshed whenever the
  data changes.
- An in-game UI on LibForm, with paged lists for groups and players.
- `/stoneperms user ... check`, which explains which node won and why.
- Promotion tracks with promote and demote, preserving temporary expiry while
  moving a player between groups.

**Web**

- An optional outbound bridge to the existing StonePerms API and dashboard, so
  the server needs no open port and no public address.
- Pairing, dashboard sign-in codes and unpairing from the console.
- Editor protocol v1, including the base-revision check that refuses a changeset
  built against stale data instead of overwriting someone else's work.
- Player faces rendered locally, so skin bytes never leave the server.

### Notes

- Requires [EasyLibrary](https://gitlab.com/ImperaZim/EasyLibrary) 2.0.0 and
  PocketMine-MP API 5.0.0.
- Needs the `pdo_sqlite` PHP extension, which PocketMine-MP does not require of
  itself. The plugin says so by name on enable if it is missing.
- The dashboard bridge uses the API's HTTP long-poll transport rather than a
  WebSocket, because PocketMine ticks the server on one thread. The wire format
  is identical; dashboard actions land within the poll interval the API asks for
  rather than instantly.
- With `xbox-auth=off` the UUID is derived from the player's name and the XUID is
  empty, so permissions do not survive a rename. The startup report warns.
- The repository ships the plugin sources only. Install it as a folder plugin, or
  build a phar with your own tooling.

[1.0.0]: https://github.com/ybriismc/StonePerms/releases/tag/v1.0.0
