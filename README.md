<p align="center">
  <img src="assets/stoneperms-logo.png" alt="StonePerms" width="168">
</p>

<h1 align="center">StonePerms</h1>

<p align="center">
  Permissions, groups, tracks, chat formatting and a web editor for PocketMine-MP.
</p>

<p align="center">
  <img alt="StonePerms 1.0.0" src="https://img.shields.io/badge/StonePerms-1.0.0-d8d58d?style=flat-square">
  <img alt="PocketMine-MP 5" src="https://img.shields.io/badge/PocketMine--MP-5.0.0%2B-d8d58d?style=flat-square">
  <img alt="PHP 8.2 or newer" src="https://img.shields.io/badge/PHP-8.2%2B-68737a?style=flat-square">
  <a href="LICENSE"><img alt="MIT license" src="https://img.shields.io/badge/License-MIT-68737a?style=flat-square"></a>
</p>

<p align="center">
  <a href="CHANGELOG.md">Changelog</a> &nbsp;·&nbsp;
  <a href="CREDITS.md">Credits</a> &nbsp;·&nbsp;
  <a href="LICENSE">License</a> &nbsp;·&nbsp;
  <a href="https://github.com/ybriismc/StonePerms/releases/latest">Download</a>
</p>

---

StonePerms stores and resolves permissions for PocketMine-MP and applies the result through the
server's native `PermissionAttachment`, so checks keep working when the web stack is offline or was
never set up. Permission data lives in the plugin's own SQLite database.

The web dashboard is optional. When you pair a server, the plugin connects to the StonePerms API
that already exists — there is nothing extra to host.

## Features

- **Permissions** — users, groups, recursive inheritance, positive and negative nodes, temporary
  assignments, weighted conflict resolution and a SQLite audit history.
- **Contexts** — `server`, `world`, `dimension` and `gamemode`, plus contexts registered by other
  plugins.
- **Display** — inherited metadata, weighted prefixes and suffixes, placeholders, and optional chat
  and nametag formatting.
- **Administration** — commands, in-game forms, promotion tracks, and permission explanations with
  `/stoneperms user ... check`.
- **Web** — pair with the dashboard to manage players, groups, tracks, settings and the audit log.

## Requirements

- PocketMine-MP API 5.0.0 or newer
- PHP 8.2 or newer
- **EasyLibrary 2.0.0** — required

| EasyLibrary | |
| --- | --- |
| Download | https://gitlab.com/ImperaZim/EasyLibrary/-/releases |
| Repository | https://gitlab.com/ImperaZim/EasyLibrary |

## Installation

1. Download EasyLibrary from the link above and drop the phar into `plugins/`.
2. Download the StonePerms phar from the [releases](../../releases) and drop it
   into `plugins/`.
3. Start the server. `plugin_data/StonePerms/stoneperms.db` is created on first enable.

The zip on a release holds the same files inside a `StonePerms/` folder, for
running the plugin unpacked instead.

## First steps

From the console:

```text
stoneperms group create moderator 100
stoneperms group permission moderator set example.command.kick true
stoneperms user Steve parent add moderator
stoneperms user Steve check example.command.kick
```

Players must have joined once before they can be addressed by name. UUID is the canonical identity;
XUID and name are lookup aliases.

## Dashboard

Sign in to the dashboard, open **Servers**, choose **Pair a server**, and run the code it shows:

```text
stoneperms web pair ABCD-EFGH-JKLM-NPQR "My Server"
stoneperms web status
```

Self-hosted installations pass the address first:

```text
stoneperms web pair https://permissions.example.com ABCD-EFGH-JKLM-NPQR "My Server"
```

On a server with no owner yet, `stoneperms web login` claims it and returns a sign-in code.
`stoneperms web unpair` revokes the credential and removes the local copy.

## Commands

The root command is `/stoneperms`; aliases are `/sp` and `/perms`. Everything is gated behind
`stoneperms.command.admin`, which defaults to operators.

<details>
<summary>Show the complete command reference</summary>

<br>

```text
/stoneperms help
/stoneperms info
/stoneperms log [limit]
/stoneperms form
/stoneperms web <status|dashboard|pair|login|unpair>

/stoneperms track list
/stoneperms track create <track>
/stoneperms track delete <track>
/stoneperms track info <track>
/stoneperms track append <track> <group>
/stoneperms track insert <track> <group> <position>
/stoneperms track remove <track> <group>
/stoneperms track clear <track>
/stoneperms track rename <track> <new-track>
/stoneperms track clone <track> <new-track>

/stoneperms group list
/stoneperms group create <group> [weight]
/stoneperms group info <group>
/stoneperms group setweight <group> <weight>
/stoneperms group permission <group> <set|settemp|unset|unsettemp> <node> ...
/stoneperms group parent <group> <add|addtemp|remove|removetemp> <parent> ...
/stoneperms group meta <group> <set|settemp|unset|unsettemp> <key> ...
/stoneperms group prefix <group> <set|settemp|unset|unsettemp> <priority> ...
/stoneperms group suffix <group> <set|settemp|unset|unsettemp> <priority> ...

/stoneperms user <name|uuid|xuid> info
/stoneperms user <name|uuid|xuid> check <node> [key=value ...]
/stoneperms user <name|uuid|xuid> permission <set|settemp|unset|unsettemp> <node> ...
/stoneperms user <name|uuid|xuid> parent <add|addtemp|remove|removetemp> <group> ...
/stoneperms user <name|uuid|xuid> meta <get|set|settemp|unset|unsettemp> <key> ...
/stoneperms user <name|uuid|xuid> prefix <get|set|settemp|unset|unsettemp> [priority] ...
/stoneperms user <name|uuid|xuid> suffix <get|set|settemp|unset|unsettemp> [priority] ...
/stoneperms user <name|uuid|xuid> promote <track> [--dont-add-to-first] [key=value ...]
/stoneperms user <name|uuid|xuid> demote <track> [--dont-remove-from-first] [key=value ...]
/stoneperms user <name|uuid|xuid> showtracks [key=value ...]
```

Durations combine: `30m`, `2h30m`, `7d`, `1mo2d`. Trailing `key=value` tokens scope a change to a
context. Quote values containing spaces, for example
`/stoneperms group prefix vip set 100 "[VIP] "`.

</details>

## Contexts

A context is a `key=value` pair. Every node — a permission, a parent, a meta value, a prefix or a
suffix — can carry a set of them, and the plugin builds a set for the player at the moment of the
check. The two are compared by one rule:

> A node applies when **every key it names** is present in the player's active set with **at least
> one matching value**. Keys the node does not name are ignored.

So a node with no contexts at all applies everywhere; repeating a key means *or*; naming different
keys means *and*.

```text
world=lobby                      only in the lobby world
world=lobby world=arena          in either world
world=lobby gamemode=survival    in the lobby, and only in survival
(none)                           everywhere
```

### The keys the plugin provides

| Key | Value | Notes |
| --- | ----- | ----- |
| `server` | `contexts.server` from the config | Defaults to `global`; see below |
| `world` | The world's folder name | |
| `dimension` | `overworld`, `nether`, `the_end` | Falls back to `overworld` when the build does not expose it |
| `gamemode` | The player's current gamemode | |
| `device_os` | The device reported at login | Off by default: `include_device_os` |
| `locale` | The client's locale | Off by default: `include_locale` |

Keys and values are lowercased and trimmed, and must match `[a-z0-9][a-z0-9_.-]{0,63}` and
`[a-z0-9][a-z0-9_.:/-]{0,127}`. A value that does not fit becomes `unknown` rather than failing a
permission check mid-flight.

### When the active set is rebuilt

On join, on a gamemode change, and on a teleport that crosses worlds — the three things that can
change a built-in key. The result is compared with what is already on the player's attachment and
only the difference is written.

A check made for an offline player or from the console has no player to build a set from, so it
resolves against an **empty** set: nodes scoped to a context do not apply there. That is why
`/stoneperms user ... check` can disagree with what the player sees in game unless you pass the same
contexts, which that command accepts as trailing `key=value` tokens.

### Contexts break ties

Contexts are not only a filter. When several nodes answer the same permission, the winner is decided
in this order:

1. Direct over inherited
2. Temporary over permanent
3. Node specificity — `a.b.c` beats `a.*` beats `*`
4. **Context specificity — the node constraining more keys wins**
5. Group weight
6. Shorter inheritance distance
7. The expiry that comes first
8. `false` over `true`

Step 4 is what makes the common pattern work: grant broadly, then deny in one place.

```text
/stoneperms group permission ceo set fly.use true                 grant everywhere
/stoneperms group permission ceo set fly.use false world=lobby2   wins in lobby2
```

The same group can also look different per world, since a prefix is a node like any other, and a
player can even hold a group in one world only:

```text
/stoneperms group prefix ceo set 100 "§cCEO " world=lobby
/stoneperms group prefix ceo set 100 "§6CEO " world=lobby2
/stoneperms user Steve parent add ceo world=lobby
```

### `server` is an ordinary key, and `global` is an ordinary value

Nothing in the plugin treats the word `global` specially — it is just the default value of
`contexts.server`. `server=global` therefore matches only while that setting still says `global`;
change it and every node scoped that way silently stops applying.

To mean *everywhere*, give the node **no context at all**. Scope with `server=` only when one set of
data serves more than one server — several servers on the same dashboard, or the same database
reused — where each server names itself:

```yaml
contexts:
  server: lobby      # and 'survival' on the other server
```

On a single server with its own database, `world`, `dimension` and `gamemode` are the useful keys and
`server` is best left alone.

### Contexts from other plugins

A plugin can contribute its own key, which is how a minigame exposes an arena or a region plugin
exposes a zone. See [Plugin API](#plugin-api) for `registerContextProvider`. The callback may return
a string, a list of strings, or `null` to contribute nothing; if it throws or returns a value that
does not validate, it is skipped rather than breaking the check.

## Plugin API

```php
use stoneperms\StonePermsPlugin;

$stoneperms = $server->getPluginManager()->getPlugin('StonePerms');
if (!$stoneperms instanceof StonePermsPlugin) {
    return;
}

$api = $stoneperms->api();

$api->hasPermission($player, 'example.feature.use');
$api->getPrimaryGroup($player);
$api->getGroups($player);
$api->getPrefix($player);
$api->getMeta($player, 'chat-color');
$api->promote($player, 'staff', 'MyPlugin');

$api->registerContextProvider('my-minigame', 'arena', fn($player) => currentArena($player));
$api->unregisterContextProviders('my-minigame');
```

Changes are also published on EasyLibrary's event bus, so a plugin can react
without registering a listener or depending on StonePerms at compile time:

```php
use imperazim\components\event\EventBus;
use stoneperms\platform\StonePermsEvents;

EventBus::on(StonePermsEvents::USER_CHANGED, function (array $data): void {
    // $data['uniqueId'], $data['changed']
});
```

`USER_CHANGED`, `DATA_CHANGED`, `NODES_EXPIRED` and `WEB_STATUS` are emitted.

## Placeholders

Registered automatically when LibPlaceholder is available.

```text
%stoneperms_prefix%             %stoneperms_meta.chat-color%
%stoneperms_suffix%             %stoneperms_track.current.staff%
%stoneperms_primary_group%      %stoneperms_track.next.staff%
%stoneperms_groups%             %stoneperms_track.has.staff%
```

## Notes for PocketMine-MP

- **Wildcards.** PocketMine does not match `a.*` against `a.b`, so StonePerms resolves nodes itself
  against the server's permission catalog and writes concrete results. A node that resolves to
  nothing is left off the attachment, keeping the owning plugin's default; a `false` node is written
  explicitly, so a denial beats an operator's blanket grant.
- **Chat and nametags** are off by default so an existing chat plugin keeps control. Chat formatting
  only replaces the event's formatter — it never cancels or rebroadcasts the message.
- **Identity.** With `xbox-auth=off` the UUID is derived from the player's name and the XUID is
  empty, so permissions do not survive a rename. The startup report warns about this.

## Built on EasyLibrary

| Library | Used for |
| ------- | -------- |
| LibCommand | The command tree: subcommands, arguments, constraints, generated help, and soft enums that keep group and track suggestions in sync with the data |
| LibForm | The in-game UI, including paged lists for groups and players |
| LibDB | The SQLite connection and driver |
| LibPlaceholder | The `stoneperms` expansion |
| LibHud | Per-observer nametags |
| Components | Plugin toolkit, scheduler, filesystem and event bus |

The libraries that do not apply to a permissions plugin — LibWorld, LibPacket,
LibWindow, LibCustom, LibEnchantment, LibSerializer, LibTrigger and the Agent
bridge — are deliberately left alone rather than pulled in for the sake of it.

## Credits

StonePerms for PocketMine-MP is a port of **Daniel-Ric's** Endstone plugin, built
on **imperazim's** EasyLibrary. The permission model, the storage schema and the
editor protocol come from that plugin unchanged, which is why one dashboard
manages both server implementations. Full attributions are in [CREDITS.md](CREDITS.md).

**No affiliation with LuckPerms.** StonePerms is a separate project. It does not
bundle, require, or derive from LuckPerms, and it is not endorsed by or
connected to that project in any way.

## License

MIT. See [LICENSE](LICENSE).
