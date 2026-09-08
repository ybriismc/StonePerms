<?php

declare(strict_types = 1);

/**
* Checks the storage layer against a real MySQL, without a server.
*
*     php tools/storage-check.php --host=127.0.0.1 --database=stoneperms_check \
*         --username=stoneperms --password=secret
*
* It runs three things:
*
*   1. The same script on SQLite and on MySQL, comparing every answer. The two
*      engines have to behave identically or the shared store is not the same
*      product as the local one.
*   2. Two repositories on one database, which is what two servers are: a write
*      on one has to reach the other through the revision it polls, and a stale
*      editor batch from the second has to be refused rather than applied over
*      the first.
*   3. A migration of a SQLite file into the database: additive, repeatable,
*      and scoped so a merge does not change what a node means.
*
* The database must be empty. The tool creates the schema, uses it, and drops
* what it created, so point it at a scratch database rather than a live one.
*/

namespace imperazim\db {
  // The plugin opens SQLite through EasyLibrary. Outside a server the library
  // is not there, so this stands in for it; the MySQL driver already falls
  // back to PDO on its own when the library is missing.
  if (!class_exists(Sqlite3::class, false)) {
    class Sqlite3 {
      public function __construct(private \PDO $pdo) {}
      public function getPdo(): \PDO { return $this->pdo; }
      public function close(): void {}
    }
  }
  if (!class_exists(DBManager::class, false)) {
    class DBManager {
      public static function connect(string $driver, array $config): Sqlite3 {
        if ($driver !== 'sqlite') {
          throw new \RuntimeException("this check only opens sqlite through the library");
        }
        return new Sqlite3(new \PDO('sqlite:' . $config['database'], null, null, [
          \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
          \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]));
      }
    }
  }
}

namespace {

use stoneperms\application\EditorSubjectChange;
use stoneperms\application\RevisionConflictException;
use stoneperms\application\StonePermsManager;
use stoneperms\application\StorageMigrator;
use stoneperms\domain\ContextSet;
use stoneperms\domain\GroupRecord;
use stoneperms\domain\Node;
use stoneperms\domain\NodeType;
use stoneperms\domain\PermissionResolver;
use stoneperms\domain\PlayerProfile;
use stoneperms\domain\SubjectRef;
use stoneperms\domain\TrackRecord;
use stoneperms\domain\UserRecord;
use stoneperms\infrastructure\MysqlDialect;
use stoneperms\infrastructure\PdoPermissionRepository;
use stoneperms\infrastructure\ServerScope;
use stoneperms\infrastructure\SqlDialect;
use stoneperms\infrastructure\SqliteDialect;

const UUID = '11111111-1111-4111-8111-111111111111';
const OTHER_UUID = '22222222-2222-4222-8222-222222222222';

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
  if (!str_starts_with($class, 'stoneperms\\')) {
    return;
  }
  $file = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
  if (is_file($file)) {
    require $file;
  }
});

$options = getopt('', ['host::', 'port::', 'database::', 'username::', 'password::', 'help']);
if (isset($options['help'])) {
  fwrite(STDOUT, "php tools/storage-check.php --host=127.0.0.1 --port=3306 --database=stoneperms_check"
    . " --username=stoneperms --password=secret\n");
  exit(0);
}

$host = (string) ($options['host'] ?? '127.0.0.1');
$port = (int) ($options['port'] ?? 3306);
$database = (string) ($options['database'] ?? 'stoneperms_check');
$username = (string) ($options['username'] ?? 'stoneperms');
$password = (string) ($options['password'] ?? '');

foreach (['pdo_sqlite', 'pdo_mysql'] as $extension) {
  if (!extension_loaded($extension)) {
    fwrite(STDERR, "This check needs the $extension PHP extension.\n");
    exit(2);
  }
}

$dialect = static fn(): MysqlDialect => new MysqlDialect($host, $port, $database, $username, $password);

// Refuse a database that already holds data: this is a check, not a migration.
try {
  $probe = $dialect()->open();
  $tables = $probe->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
  if ($tables !== []) {
    $rows = in_array('nodes', $tables, true)
      ? (int) $probe->query('SELECT COUNT(*) FROM nodes')->fetchColumn()
      : -1;
    if ($rows !== 0) {
      fwrite(STDERR, "The database '$database' is not empty. Point this at a scratch database:\n"
        . "  CREATE DATABASE stoneperms_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n");
      exit(2);
    }
  }
} catch (Throwable $error) {
  fwrite(STDERR, "Could not reach MySQL: {$error->getMessage()}\n");
  exit(2);
}

$failures = 0;
$check = static function (string $label, $got, $want) use (&$failures): void {
  $ok = (string) $got === (string) $want;
  if (!$ok) {
    $failures++;
  }
  printf("  %-54s %-14s %s\n", $label, self_short((string) $got), $ok ? 'ok' : "EXPECTED $want");
};

function self_short(string $value): string {
  return strlen($value) > 14 ? substr($value, 0, 11) . '...' : $value;
}

function fresh(PDO $pdo): void {
  $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
  foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
  }
  $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/** Everything the store can be asked, in an order that leaves a known shape behind. */
function scenario(SqlDialect $dialect, ?ServerScope $scope = null): array {
  $out = [];
  $repo = new PdoPermissionRepository($dialect, $scope);
  $repo->initialize('default');
  $now = time();

  $out['group.create'] = $repo->createGroup(new GroupRecord('vip', 'VIP', 10), 'check') ? 'created' : 'exists';
  $out['group.create.again'] = $repo->createGroup(new GroupRecord('vip', 'VIP', 10), 'check') ? 'created' : 'exists';
  $repo->setGroupWeight('vip', 25, 'check');
  $out['group.weight'] = $repo->getGroup('vip')?->weight;

  $repo->upsertUser(new UserRecord(UUID, 'Steve', 'xuid-1'));
  $repo->upsertUser(new UserRecord(OTHER_UUID, 'Alex', ''));
  $out['find.uppercase'] = $repo->findUser('STEVE')?->lastName;
  $out['find.by.xuid'] = $repo->findUser('xuid-1')?->lastName;
  $out['users'] = implode(',', array_map(static fn($u) => $u->lastName, $repo->listUsers()));

  $user = SubjectRef::user(UUID);
  $repo->saveNode(new Node($user, NodeType::PARENT, 'vip', 'true'), 'check', 'user.parent.add');
  $repo->saveNode(
    new Node(SubjectRef::group('vip'), NodeType::PERMISSION, 'fly.use', 'true', ContextSet::parse('world=lobby')),
    'check',
    'group.permission.set'
  );
  $repo->saveNode(
    new Node(SubjectRef::group('vip'), NodeType::PREFIX, 'prefix', '[VIP]', null, null, 100),
    'check',
    'group.prefix.set'
  );

  $resolver = new PermissionResolver();
  $snapshot = $repo->loadSnapshot($user, 'default');
  $out['resolve.in.lobby'] = var_export($resolver->resolve($snapshot, 'fly.use', ContextSet::parse('world=lobby'), $now)->value, true);
  $out['resolve.elsewhere'] = var_export($resolver->resolve($snapshot, 'fly.use', ContextSet::parse('world=arena'), $now)->value, true);
  $out['groups.effective'] = implode(',', $resolver->effectiveGroups($snapshot, null, $now));
  $out['prefix'] = $resolver->resolvePrefix($snapshot, null, $now)->value;

  $repo->saveNode(new Node($user, NodeType::PERMISSION, 'temp.node', 'true', null, $now - 60), 'check', 'user.permission.settemp');
  $out['expired'] = $repo->deleteExpired($now)->count;
  $out['removed'] = $repo->removeNodes(
    SubjectRef::group('vip'), NodeType::PERMISSION, 'fly.use',
    ContextSet::parse('world=lobby'), 'check', 'group.permission.unset'
  );

  $repo->createTrack(new TrackRecord('staff', ['vip']), 'check');
  $repo->createGroup(new GroupRecord('mod', 'Mod', 40), 'check');
  $track = $repo->setTrackGroups('staff', ['vip', 'mod'], 'check', 'track.append', ['group' => 'mod']);
  $out['track'] = implode('>', $track->groups);
  $out['track.rename'] = $repo->renameTrack('staff', 'team', 'check') ? 'renamed' : 'no';

  $repo->upsertPlayerProfile(new PlayerProfile(
    UUID, 'Steve', 'xuid-1', 'en_US', 'Android', '1.21', 'survival', 40, 100, 5,
    'skin-id', 'hash-a', 64, 64, "\x00\x01\x02\xff", null, 1000, 1000, 1000, null, 100, true
  ));
  $repo->upsertPlayerProfile(new PlayerProfile(
    UUID, 'Steve', 'xuid-1', null, null, null, null, null, null, null,
    'skin-id', 'hash-a', 64, 64, "\x00\x01\x02\xff", null, 0, 2000, null, null, 200, true
  ));
  $out['skin.same.keeps.time'] = $repo->getPlayerProfile(UUID)?->skinUpdatedAt;
  $repo->upsertPlayerProfile(new PlayerProfile(
    UUID, 'Steve', 'xuid-1', null, null, null, null, null, null, null,
    'skin-2', 'hash-b', 64, 64, "\x09\x08", null, 0, 3000, null, null, 300, true
  ));
  $profile = $repo->getPlayerProfile(UUID);
  $out['skin.new.moves.time'] = $profile?->skinUpdatedAt;
  $out['profile.first.seen'] = $profile?->firstSeenAt;
  $out['profile.locale.kept'] = $profile?->locale;
  $out['profile.binary'] = bin2hex((string) $profile?->skinRgba);

  $revision = $repo->revision();
  try {
    $repo->applyEditorBatch($revision - 1, [], [], 'check', 'stale');
    $out['editor.stale'] = 'accepted';
  } catch (RevisionConflictException) {
    $out['editor.stale'] = 'refused';
  }
  $before = $repo->nodesFor(SubjectRef::group('vip'));
  $result = $repo->applyEditorBatch(
    $revision,
    [new EditorSubjectChange(
      SubjectRef::group('vip'),
      $before,
      array_merge($before, [new Node(SubjectRef::group('vip'), NodeType::PERMISSION, 'kit.use', 'true')])
    )],
    [],
    'check',
    'session'
  );
  $out['editor.added'] = $result->nodesAdded;
  $out['editor.nodes'] = count($repo->nodesFor(SubjectRef::group('vip')));

  // Promote and demote go through this rather than a save, so it needs the
  // same exercise as everything else that writes a node.
  $parents = array_values(array_filter(
    $repo->nodesFor($user),
    static fn(Node $node): bool => $node->type === NodeType::PARENT
  ));
  $moved = $repo->replaceParentNode(
    $parents[0] ?? null,
    new Node($user, NodeType::PARENT, 'mod', 'true'),
    'check',
    'user.promote',
    ['from' => 'vip', 'to' => 'mod']
  );
  $out['promote'] = $moved?->key;
  $out['promote.parents'] = count(array_filter(
    $repo->nodesFor($user),
    static fn(Node $node): bool => $node->type === NodeType::PARENT
  ));

  $repo->recordAudit('check', 'web.settings.update', ['field' => 'chat']);
  $repo->createTrack(new TrackRecord('throwaway', ['mod']), 'check');
  $out['track.delete'] = $repo->deleteTrack('throwaway', 'check') ? 'deleted' : 'no';
  $repo->createGroup(new GroupRecord('throwaway', 'Throwaway', 1), 'check');
  $repo->saveNode(new Node($user, NodeType::PARENT, 'throwaway', 'true'), 'check', 'user.parent.add');
  $out['group.delete'] = $repo->deleteGroup('throwaway', 'check') ? 'deleted' : 'no';
  $out['group.delete.clears.parents'] = count(array_filter(
    $repo->nodesFor($user),
    static fn(Node $node): bool => $node->key === 'throwaway'
  ));

  $audit = $repo->recentAudit(5);
  $out['audit.count'] = count($audit);
  $out['audit.last'] = $audit[0]['action'] ?? '';
  $out['revision.moved'] = $repo->revision() > 0 ? 'yes' : 'no';

  $repo->close();
  return $out;
}

echo "StonePerms storage check\n";
echo "  sqlite: temporary file\n";
echo "  mysql : $username@$host:$port/$database\n\n";

// 1 -------------------------------------------------------------------------
echo "Both engines answer the same, owned rows or not\n";
$file = sys_get_temp_dir() . '/stoneperms-check-' . getmypid() . '.db';
@unlink($file);
$sqlite = scenario(new SqliteDialect($file));
@unlink($file);

fresh($dialect()->open());
$mysql = scenario($dialect());

// The same script again with the rows owned by a server. One server on its own
// must not behave differently for having its name on its players, and running
// every write path under a scope is what catches a statement whose parameters
// no longer match its placeholders.
fresh($dialect()->open());
$owned = ServerScope::of('lobby1');
$scoped = scenario(
  new MysqlDialect($host, $port, $database, $username, $password, 'utf8mb4', $owned),
  $owned
);

foreach ($sqlite as $key => $value) {
  $check($key, $mysql[$key] ?? '<missing>', (string) $value);
  $check($key . ' (owned)', $scoped[$key] ?? '<missing>', (string) $value);
}

// 2 -------------------------------------------------------------------------
echo "\nTwo servers, one database\n";
fresh($dialect()->open());
$lobby = new PdoPermissionRepository($dialect());
$lobby->initialize('default');
$minigame = new PdoPermissionRepository($dialect());
$minigame->initialize('default');
$minigameManager = new StonePermsManager($minigame, 'default');

$lobby->upsertUser(new UserRecord(UUID, 'Steve'));
$lobby->createGroup(new GroupRecord('ceo', 'CEO', 100), 'lobby1');
$lobby->saveNode(new Node(SubjectRef::user(UUID), NodeType::PARENT, 'ceo', 'true'), 'lobby1', 'user.parent.add');
$minigame->refreshSharedRevision();

$resolver = new PermissionResolver();
$now = time();
$check('minigame sees the group lobby1 made', implode(',', $resolver->effectiveGroups($minigameManager->snapshot(SubjectRef::user(UUID)), null, $now)), 'ceo,default');

$lobby->saveNode(
  new Node(SubjectRef::group('ceo'), NodeType::PERMISSION, 'fly.use', 'true', ContextSet::parse('server=lobby1')),
  'lobby1',
  'group.permission.set'
);
$check(
  'and serves its cache until it polls',
  var_export($resolver->resolve($minigameManager->snapshot(SubjectRef::user(UUID)), 'fly.use', null, $now)->value, true),
  'NULL'
);
$check('the poll reports the change', $minigame->refreshSharedRevision() ? 'yes' : 'no', 'yes');
$check('a second poll reports nothing', $minigame->refreshSharedRevision() ? 'yes' : 'no', 'no');
$snapshot = $minigameManager->snapshot(SubjectRef::user(UUID));
$check('now resolves under server=lobby1', var_export($resolver->resolve($snapshot, 'fly.use', ContextSet::parse('server=lobby1'), $now)->value, true), 'true');
$check('and not under server=minigame1', var_export($resolver->resolve($snapshot, 'fly.use', ContextSet::parse('server=minigame1'), $now)->value, true), 'NULL');

$base = $lobby->revision();
$lobbyBefore = $lobby->nodesFor(SubjectRef::group('ceo'));
$lobby->applyEditorBatch(
  $base,
  [new EditorSubjectChange(SubjectRef::group('ceo'), $lobbyBefore, array_merge($lobbyBefore, [
    new Node(SubjectRef::group('ceo'), NodeType::PERMISSION, 'kit.use', 'true')
  ]))],
  [], 'lobby1', 'session-lobby'
);
$minigameBefore = $minigame->nodesFor(SubjectRef::group('ceo'));
try {
  $minigame->applyEditorBatch(
    $base,
    [new EditorSubjectChange(SubjectRef::group('ceo'), $minigameBefore, array_merge($minigameBefore, [
      new Node(SubjectRef::group('ceo'), NodeType::PERMISSION, 'gm.use', 'true')
    ]))],
    [], 'minigame1', 'session-minigame'
  );
  $check('a stale batch from the other server', 'applied', 'refused');
} catch (RevisionConflictException) {
  $check('a stale batch from the other server', 'refused', 'refused');
}
$check('the first write survived', count($lobby->nodesFor(SubjectRef::group('ceo'))), 2);
$lobby->close();
$minigame->close();

// 3 -------------------------------------------------------------------------
echo "\nMigrating a SQLite file in\n";
@unlink($file);
$source = new PdoPermissionRepository(new SqliteDialect($file));
$source->initialize('default');
$source->createGroup(new GroupRecord('ceo', 'CEO', 100), 'admin');
$source->createGroup(new GroupRecord('vip', 'VIP', 10), 'admin');
$source->createTrack(new TrackRecord('staff', ['vip', 'ceo']), 'admin');
$source->upsertUser(new UserRecord(UUID, 'Steve'));
$source->saveNode(new Node(SubjectRef::user(UUID), NodeType::PARENT, 'ceo', 'true'), 'admin', 'user.parent.add');
$source->saveNode(
  new Node(SubjectRef::group('ceo'), NodeType::PERMISSION, 'fly.use', 'true', ContextSet::parse('world=lobby')),
  'admin',
  'group.permission.set'
);
$source->close();

fresh($dialect()->open());
$target = new PdoPermissionRepository($dialect());
$target->initialize('default');
$target->createGroup(new GroupRecord('vip', 'VIP', 55), 'another-server');

$open = static function () use ($file): PdoPermissionRepository {
  $repo = new PdoPermissionRepository(new SqliteDialect($file));
  $repo->initialize('default');
  return $repo;
};

$source = $open();
$plan = (new StorageMigrator($source, $target))->copy('admin', true, 'lobby1');
$source->close();
$check('a dry run writes nothing', count($target->listGroups()), 2);
$check('it counts what it would add', $plan->groups, 1);
$check('it names the differing weight', str_contains(implode(' ', $plan->notes), 'weight 55') ? 'yes' : 'no', 'yes');

$source = $open();
(new StorageMigrator($source, $target))->copy('admin', false, 'lobby1');
$source->close();
$check('groups after applying', count($target->listGroups()), 3);
$check('the target kept its own vip', $target->getGroup('vip')?->weight, 55);
$check('the track arrived', implode('>', $target->getTrack('staff')?->groups ?? []), 'vip>ceo');
$snapshot = $target->loadSnapshot(SubjectRef::user(UUID), 'default');
$check('the copy is scoped to its server', var_export($resolver->resolve($snapshot, 'fly.use', ContextSet::parse('world=lobby server=lobby1'), $now)->value, true), 'true');
$check('and not to another', var_export($resolver->resolve($snapshot, 'fly.use', ContextSet::parse('world=lobby server=minigame1'), $now)->value, true), 'NULL');

$source = $open();
(new StorageMigrator($source, $target))->copy('admin', false, 'lobby1');
$source->close();
$check('running it twice changes nothing', count($target->nodesFor(SubjectRef::group('ceo'))), 1);
$target->close();
@unlink($file);

// 4 -------------------------------------------------------------------------
echo "\nPlayers belong to their server, definitions belong to the network\n";
fresh($dialect()->open());
$scoped = static fn(string $server): PdoPermissionRepository => new PdoPermissionRepository(
  new MysqlDialect($host, $port, $database, $username, $password, 'utf8mb4', ServerScope::of($server)),
  ServerScope::of($server)
);

$lobby = $scoped('lobby1');
$lobby->initialize('default');
$minigame = $scoped('minigame1');
$minigame->initialize('default');
$lobbyManager = new StonePermsManager($lobby, 'default');
$minigameManager = new StonePermsManager($minigame, 'default');

// the lobby knows this player as VIP
$lobby->createGroup(new GroupRecord('vip', 'VIP', 50), 'lobby1');
$lobby->upsertUser(new UserRecord(UUID, 'yBriisMC'));
$lobby->saveNode(new Node(SubjectRef::user(UUID), NodeType::PARENT, 'vip', 'true'), 'lobby1', 'user.parent.add');
$lobby->saveNode(
  new Node(SubjectRef::group('vip'), NodeType::PERMISSION, 'fly.use', 'true'),
  'lobby1',
  'group.permission.set'
);
$minigame->refreshSharedRevision();

$check('the group reaches the other server', $minigame->getGroup('vip')?->weight, 50);
$check('the player does not', $minigame->findUser('yBriisMC') === null ? 'unknown' : 'known', 'unknown');
$check('the lobby has them', $lobby->findUser('yBriisMC')?->lastName, 'yBriisMC');
$check(
  'VIP on the lobby',
  implode(',', $resolver->effectiveGroups($lobbyManager->snapshot(SubjectRef::user(UUID)), null, $now)),
  'vip,default'
);
$check(
  'default on the minigame',
  implode(',', $resolver->effectiveGroups($minigameManager->snapshot(SubjectRef::user(UUID)), null, $now)),
  'default'
);
$check(
  'and so the permission does not follow them',
  var_export($resolver->resolve($minigameManager->snapshot(SubjectRef::user(UUID)), 'fly.use', null, $now)->value, true),
  'NULL'
);

// the minigame sees them join and gives them something of its own
$minigame->upsertUser(new UserRecord(UUID, 'yBriisMC'));
$minigame->createGroup(new GroupRecord('duelist', 'Duelist', 20), 'minigame1');
$minigame->saveNode(new Node(SubjectRef::user(UUID), NodeType::PARENT, 'duelist', 'true'), 'minigame1', 'user.parent.add');
$lobby->refreshSharedRevision();
$check(
  'the minigame assignment stays there',
  implode(',', $resolver->effectiveGroups($minigameManager->snapshot(SubjectRef::user(UUID)), null, $now)),
  'duelist,default'
);
$check(
  'the lobby is untouched by it',
  implode(',', $resolver->effectiveGroups($lobbyManager->snapshot(SubjectRef::user(UUID)), null, $now)),
  'vip,default'
);
$check('each server sees one assignment', count($lobby->nodesFor(SubjectRef::user(UUID))), 1);
$check('and the group it made is on both', $lobby->getGroup('duelist')?->weight, 20);
$check('one player row per server', count($lobby->listUsers()) . '+' . count($minigame->listUsers()), '1+1');

// deleting a shared group has to clear the assignments it left everywhere
$minigame->deleteGroup('vip', 'minigame1');
$lobby->refreshSharedRevision();
$check(
  'deleting the group clears it on the other server too',
  implode(',', $resolver->effectiveGroups($lobbyManager->snapshot(SubjectRef::user(UUID)), null, $now)),
  'default'
);
$lobby->close();
$minigame->close();

// asking for one set of players gives the opposite behaviour
fresh($dialect()->open());
$shared1 = new PdoPermissionRepository($dialect());
$shared1->initialize('default');
$shared2 = new PdoPermissionRepository($dialect());
$shared2->initialize('default');
$shared1->upsertUser(new UserRecord(UUID, 'yBriisMC'));
$check('share_players puts them on both', $shared2->findUser('yBriisMC')?->lastName, 'yBriisMC');
$shared1->close();
$shared2->close();

// ---------------------------------------------------------------------------
fresh($dialect()->open());
echo "\n" . ($failures === 0
  ? "Everything passed. The tables this check created were dropped.\n"
  : "$failures check(s) failed. The tables this check created were dropped.\n");
exit($failures === 0 ? 0 : 1);
}
