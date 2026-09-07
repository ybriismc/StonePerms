# Changelog

All notable changes to StonePerms for PocketMine-MP are recorded here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

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
