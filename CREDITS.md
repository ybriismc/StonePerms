# Credits

StonePerms for PocketMine-MP is a port, not a rewrite. The permission model, the
storage schema and the editor protocol are Daniel-Ric's design, carried over
unchanged — which is exactly why one dashboard manages Endstone and
PocketMine-MP servers together, and why a `stoneperms.db` written by either
build opens in the other.

## People and projects

| | |
| --- | --- |
| Original StonePerms, the Endstone plugin | **Daniel-Ric** — https://github.com/Daniel-Ric/StonePerms |
| EasyLibrary | **imperazim** — https://gitlab.com/ImperaZim/EasyLibrary |
| StonePerms for PocketMine-MP | **yBriisMC** — https://github.com/ybriismc |

## Libraries

The plugin runs on [EasyLibrary](https://gitlab.com/ImperaZim/EasyLibrary) and
uses six of its parts:

| Library | Used for |
| ------- | -------- |
| LibCommand | The command tree: subcommands, arguments, constraints, generated help and soft enums |
| LibForm | The in-game UI, including paged lists for groups and players |
| LibDB | The SQLite connection and driver |
| LibPlaceholder | The `stoneperms` expansion |
| LibHud | Per-observer nametags |
| Components | Plugin toolkit, scheduler, filesystem and event bus |

Everything else the plugin needs — the permission resolver, the storage schema
and the editor protocol — is in this repository, with no third-party PHP
dependencies to install.

## Not affiliated with LuckPerms

StonePerms is a separate project. It does not bundle, require, or derive from
LuckPerms, and it is not endorsed by or connected to that project in any way.

## Licence

MIT, for this build and for the original alike. See [LICENSE](LICENSE).
