<?php

declare(strict_types = 1);

namespace stoneperms\infrastructure;

use imperazim\db\DBManager;
use imperazim\db\Sqlite3;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use stoneperms\application\EditorStorageResult;
use stoneperms\application\EditorSubjectChange;
use stoneperms\application\EditorTrackChange;
use stoneperms\application\ExpiredNodes;
use stoneperms\application\PermissionRepository;
use stoneperms\application\RevisionConflictException;
use stoneperms\domain\ContextSet;
use stoneperms\domain\GroupRecord;
use stoneperms\domain\Node;
use stoneperms\domain\NodeType;
use stoneperms\domain\PermissionSnapshot;
use stoneperms\domain\PlayerProfile;
use stoneperms\domain\SubjectRef;
use stoneperms\domain\SubjectType;
use stoneperms\domain\TrackRecord;
use stoneperms\domain\UserRecord;
use stoneperms\domain\Validation;

/**
* The permission store, on SQLite, through LibDB.
*
* The connection is opened by `imperazim\db\DBManager` so the plugin uses the
* same driver as the rest of the EasyLibrary stack, and the raw PDO handle is
* used where LibDB's CRUD helpers cannot express a statement (upserts, ordered
* deletes, last insert id).
*/
final class SqlitePermissionRepository implements PermissionRepository {

  private ?Sqlite3 $database = null;
  private ?PDO $pdo = null;
  private int $revision = 0;

  public function __construct(private readonly string $path) {}

  public function revision(): int {
    return $this->revision;
  }

  public function initialize(string $defaultGroup): void {
    $groupName = Validation::groupName($defaultGroup);
    $directory = dirname($this->path);
    if (!is_dir($directory)) {
      mkdir($directory, 0777, true);
    }

    $connection = DBManager::connect('sqlite', ['database' => $this->path]);
    if (!$connection instanceof Sqlite3) {
      throw new RuntimeException('StonePerms requires the SQLite driver');
    }
    $this->database = $connection;
    $this->pdo = $connection->getPdo();

    $this->pdo->exec('PRAGMA foreign_keys = ON');
    $this->pdo->exec('PRAGMA journal_mode = WAL');
    $this->pdo->exec('PRAGMA synchronous = NORMAL');
    $this->pdo->exec('PRAGMA busy_timeout = 10000');

    $this->migrate();

    $timestamp = time();
    $this->pdo->prepare(
      'INSERT INTO permission_groups(name, display_name, weight, created_at, updated_at)
       VALUES (?, ?, 0, ?, ?) ON CONFLICT(name) DO NOTHING'
    )->execute([$groupName, $groupName, $timestamp, $timestamp]);
  }

  private function migrate(): void {
    $pdo = $this->requirePdo();
    $pdo->exec(
      'CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, applied_at INTEGER NOT NULL)'
    );
    $applied = [];
    foreach ($pdo->query('SELECT version FROM schema_migrations')->fetchAll() as $row) {
      $applied[(int) $row['version']] = true;
    }
    foreach (SqliteSchema::migrations() as $version => $sql) {
      if (isset($applied[$version])) {
        continue;
      }
      $pdo->exec($sql);
      $statement = $pdo->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES (?, ?)');
      $statement->execute([$version, time()]);
    }
  }

  public function close(): void {
    $this->database?->close();
    $this->database = null;
    $this->pdo = null;
  }

  private function requirePdo(): PDO {
    if ($this->pdo === null) {
      throw new RuntimeException('The StonePerms database is not open');
    }
    return $this->pdo;
  }

  /**
  * @param list<mixed> $params
  * @return list<array<string, mixed>>
  */
  private function select(string $sql, array $params = []): array {
    $statement = $this->requirePdo()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
  }

  /** @param list<mixed> $params */
  private function run(string $sql, array $params = []): int {
    $statement = $this->requirePdo()->prepare($sql);
    $statement->execute($params);
    return $statement->rowCount();
  }

  private function transaction(callable $callback): mixed {
    $pdo = $this->requirePdo();
    $pdo->beginTransaction();
    try {
      $result = $callback();
      $pdo->commit();
      return $result;
    } catch (\Throwable $throwable) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $throwable;
    }
  }

  public function upsertUser(UserRecord $user): void {
    $timestamp = time();
    $xuid = $user->xuid !== null && trim($user->xuid) !== '' ? trim($user->xuid) : null;
    $this->run(
      'INSERT INTO users(unique_id, xuid, last_name, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?)
       ON CONFLICT(unique_id) DO UPDATE SET
         xuid = excluded.xuid,
         last_name = excluded.last_name,
         updated_at = excluded.updated_at',
      [$user->uniqueId, $xuid, $user->lastName, $timestamp, $timestamp]
    );
  }

  public function findUser(string $identifier): ?UserRecord {
    $value = trim($identifier);
    $rows = $this->select(
      'SELECT unique_id, xuid, last_name FROM users
       WHERE unique_id = ? OR xuid = ? OR last_name = ? COLLATE NOCASE
       ORDER BY CASE WHEN unique_id = ? THEN 0 WHEN xuid = ? THEN 1 ELSE 2 END
       LIMIT 1',
      [$value, $value, $value, $value, $value]
    );
    return $rows === [] ? null : self::rowToUser($rows[0]);
  }

  public function listUsers(): array {
    return array_map(
      self::rowToUser(...),
      $this->select('SELECT unique_id, xuid, last_name FROM users ORDER BY last_name COLLATE NOCASE')
    );
  }

  public function upsertPlayerProfile(PlayerProfile $profile): PlayerProfile {
    $xuid = $profile->xuid !== null && trim($profile->xuid) !== '' ? trim($profile->xuid) : null;
    $this->run(
      'INSERT INTO users(
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
         online = excluded.online',
      [
        $profile->uniqueId,
        $xuid,
        $profile->lastName,
        $profile->firstSeenAt,
        $profile->lastSeenAt,
        $profile->locale,
        $profile->deviceOs,
        $profile->gameVersion,
        $profile->gameMode,
        $profile->pingMs,
        $profile->totalExp,
        $profile->expLevel,
        $profile->skinId,
        $profile->skinHash,
        $profile->skinWidth,
        $profile->skinHeight,
        $profile->skinRgba,
        $profile->capeId,
        $profile->firstSeenAt,
        $profile->lastSeenAt,
        $profile->lastJoinedAt,
        $profile->lastQuitAt,
        $profile->skinUpdatedAt,
        $profile->online ? 1 : 0
      ]
    );

    $stored = $this->getPlayerProfile($profile->uniqueId);
    if ($stored === null) {
      throw new RuntimeException('Player profile could not be read after persistence');
    }
    return $stored;
  }

  public function getPlayerProfile(string $identifier): ?PlayerProfile {
    $value = trim($identifier);
    $rows = $this->select(
      'SELECT ' . SqliteSchema::PROFILE_COLUMNS . ' FROM users
       WHERE unique_id = ? OR xuid = ? OR last_name = ? COLLATE NOCASE
       ORDER BY CASE WHEN unique_id = ? THEN 0 WHEN xuid = ? THEN 1 ELSE 2 END
       LIMIT 1',
      [$value, $value, $value, $value, $value]
    );
    return $rows === [] ? null : self::rowToProfile($rows[0]);
  }

  public function listPlayerProfiles(): array {
    return array_map(
      self::rowToProfile(...),
      $this->select(
        'SELECT ' . SqliteSchema::PROFILE_COLUMNS . ' FROM users
         ORDER BY online DESC, last_seen_at DESC, last_name COLLATE NOCASE'
      )
    );
  }

  public function createGroup(GroupRecord $group, string $actor): bool {
    $timestamp = time();
    return (bool) $this->transaction(function () use ($group, $actor, $timestamp): bool {
      $created = $this->run(
        'INSERT INTO permission_groups(name, display_name, weight, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?) ON CONFLICT(name) DO NOTHING',
        [$group->name, $group->displayName, $group->weight, $timestamp, $timestamp]
      ) > 0;
      if ($created) {
        $this->insertAudit($actor, 'group.create', SubjectRef::group($group->name), [
          'display_name' => $group->displayName,
          'weight' => $group->weight
        ], $timestamp);
        $this->revision++;
      }
      return $created;
    });
  }

  public function getGroup(string $name): ?GroupRecord {
    $rows = $this->select(
      'SELECT name, display_name, weight FROM permission_groups WHERE name = ?',
      [Validation::groupName($name)]
    );
    return $rows === [] ? null : self::rowToGroup($rows[0]);
  }

  public function listGroups(): array {
    return array_map(
      self::rowToGroup(...),
      $this->select('SELECT name, display_name, weight FROM permission_groups ORDER BY weight DESC, name')
    );
  }

  public function setGroupWeight(string $name, int $weight, string $actor): void {
    $group = Validation::groupName($name);
    $timestamp = time();
    $this->transaction(function () use ($group, $weight, $actor, $timestamp): void {
      $updated = $this->run(
        'UPDATE permission_groups SET weight = ?, updated_at = ? WHERE name = ?',
        [$weight, $timestamp, $group]
      );
      if ($updated === 0) {
        throw new InvalidArgumentException("Unknown group '$group'");
      }
      $this->insertAudit($actor, 'group.setweight', SubjectRef::group($group), ['weight' => $weight], $timestamp);
      $this->revision++;
    });
  }

  public function deleteGroup(string $name, string $actor): bool {
    $group = Validation::groupName($name);
    $timestamp = time();
    return (bool) $this->transaction(function () use ($group, $actor, $timestamp): bool {
      $tracks = $this->select('SELECT track_name FROM track_groups WHERE group_name = ?', [$group]);
      if ($tracks !== []) {
        $names = implode(', ', array_map(static fn(array $row): string => (string) $row['track_name'], $tracks));
        throw new InvalidArgumentException("Group '$group' is still used by track(s): $names");
      }
      $removedNodes = $this->run(
        "DELETE FROM nodes WHERE (subject_type = 'group' AND subject_id = ?)
         OR (node_type = 'parent' AND node_key = ?)",
        [$group, $group]
      );
      $deleted = $this->run('DELETE FROM permission_groups WHERE name = ?', [$group]) > 0;
      if ($deleted) {
        $this->insertAudit($actor, 'group.delete', SubjectRef::group($group), [
          'removed_nodes' => $removedNodes
        ], $timestamp);
        $this->revision++;
      }
      return $deleted;
    });
  }

  public function createTrack(TrackRecord $track, string $actor, string $action = 'track.create'): bool {
    $timestamp = time();
    return (bool) $this->transaction(function () use ($track, $actor, $action, $timestamp): bool {
      $created = $this->run(
        'INSERT INTO tracks(name, created_at, updated_at) VALUES (?, ?, ?) ON CONFLICT(name) DO NOTHING',
        [$track->name, $timestamp, $timestamp]
      ) > 0;
      if (!$created) {
        return false;
      }
      $this->insertTrackGroups($track->name, $track->groups);
      $this->insertAudit($actor, $action, ['track', $track->name], ['groups' => $track->groups], $timestamp);
      $this->revision++;
      return true;
    });
  }

  public function getTrack(string $name): ?TrackRecord {
    $track = Validation::trackName($name);
    $rows = $this->select('SELECT name FROM tracks WHERE name = ?', [$track]);
    if ($rows === []) {
      return null;
    }
    return new TrackRecord($track, $this->loadTrackGroups($track));
  }

  public function listTracks(): array {
    $tracks = [];
    foreach ($this->select('SELECT name FROM tracks ORDER BY name') as $row) {
      $name = (string) $row['name'];
      $tracks[] = new TrackRecord($name, $this->loadTrackGroups($name));
    }
    return $tracks;
  }

  public function setTrackGroups(
    string $name,
    array $groups,
    string $actor,
    string $action,
    array $details
  ): TrackRecord {
    $record = new TrackRecord($name, $groups);
    $timestamp = time();
    return $this->transaction(function () use ($record, $actor, $action, $details, $timestamp): TrackRecord {
      $rows = $this->select('SELECT name FROM tracks WHERE name = ?', [$record->name]);
      if ($rows === []) {
        throw new InvalidArgumentException("Unknown track '{$record->name}'");
      }
      $this->run('DELETE FROM track_groups WHERE track_name = ?', [$record->name]);
      $this->insertTrackGroups($record->name, $record->groups);
      $this->run('UPDATE tracks SET updated_at = ? WHERE name = ?', [$timestamp, $record->name]);
      $this->insertAudit($actor, $action, ['track', $record->name], $details + [
        'groups' => $record->groups
      ], $timestamp);
      $this->revision++;
      return $record;
    });
  }

  public function renameTrack(string $name, string $newName, string $actor): bool {
    $from = Validation::trackName($name);
    $to = Validation::trackName($newName);
    $timestamp = time();
    return (bool) $this->transaction(function () use ($from, $to, $actor, $timestamp): bool {
      if ($from === $to) {
        return false;
      }
      if ($this->select('SELECT name FROM tracks WHERE name = ?', [$to]) !== []) {
        throw new InvalidArgumentException("Track '$to' already exists");
      }
      $renamed = $this->run(
        'UPDATE tracks SET name = ?, updated_at = ? WHERE name = ?',
        [$to, $timestamp, $from]
      ) > 0;
      if ($renamed) {
        $this->insertAudit($actor, 'track.rename', ['track', $to], ['from' => $from], $timestamp);
        $this->revision++;
      }
      return $renamed;
    });
  }

  public function deleteTrack(string $name, string $actor): bool {
    $track = Validation::trackName($name);
    $timestamp = time();
    return (bool) $this->transaction(function () use ($track, $actor, $timestamp): bool {
      $this->run('DELETE FROM track_groups WHERE track_name = ?', [$track]);
      $deleted = $this->run('DELETE FROM tracks WHERE name = ?', [$track]) > 0;
      if ($deleted) {
        $this->insertAudit($actor, 'track.delete', ['track', $track], [], $timestamp);
        $this->revision++;
      }
      return $deleted;
    });
  }

  public function saveNode(Node $node, string $actor, string $action): Node {
    $timestamp = time();
    $contextsJson = $node->contexts->toJson();
    return $this->transaction(function () use ($node, $actor, $action, $timestamp, $contextsJson): Node {
      [$query, $params] = self::replacementQuery($node, $contextsJson);
      $this->run($query, $params);
      $this->run(
        'INSERT INTO nodes(
           subject_type, subject_id, node_type, node_key, node_value,
           contexts_json, expires_at, priority, created_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
          $node->subject->type->value,
          $node->subject->identifier,
          $node->type->value,
          $node->key,
          $node->value,
          $contextsJson,
          $node->expiresAt,
          $node->priority,
          $timestamp
        ]
      );
      $saved = new Node(
        $node->subject,
        $node->type,
        $node->key,
        $node->value,
        $node->contexts,
        $node->expiresAt,
        $node->priority,
        $timestamp,
        (int) $this->requirePdo()->lastInsertId()
      );
      $this->insertAudit($actor, $action, $node->subject, self::nodeDetails($saved), $timestamp);
      $this->revision++;
      return $saved;
    });
  }

  public function removeNodes(
    SubjectRef $subject,
    NodeType $type,
    string $key,
    ContextSet $contexts,
    string $actor,
    string $action,
    ?bool $temporary = null,
    ?int $priority = null
  ): int {
    $query = 'DELETE FROM nodes WHERE subject_type = ? AND subject_id = ? '
      . 'AND node_type = ? AND node_key = ? AND contexts_json = ?';
    $params = [
      $subject->type->value,
      $subject->identifier,
      $type->value,
      $key,
      $contexts->toJson()
    ];
    if ($temporary === true) {
      $query .= ' AND expires_at IS NOT NULL';
    } elseif ($temporary === false) {
      $query .= ' AND expires_at IS NULL';
    }
    if ($priority !== null) {
      $query .= ' AND priority = ?';
      $params[] = $priority;
    }

    $timestamp = time();
    $removed = (int) $this->transaction(function () use (
      $query,
      $params,
      $subject,
      $type,
      $key,
      $contexts,
      $actor,
      $action,
      $temporary,
      $priority,
      $timestamp
    ): int {
      $count = $this->run($query, $params);
      if ($count > 0) {
        $this->insertAudit($actor, $action, $subject, [
          'node_type' => $type->value,
          'key' => $key,
          'contexts' => $contexts->pairs(),
          'temporary' => $temporary,
          'priority' => $priority,
          'removed' => $count
        ], $timestamp);
      }
      return $count;
    });

    if ($removed > 0) {
      $this->revision++;
    }
    return $removed;
  }

  public function replaceParentNode(
    ?Node $oldNode,
    ?Node $newNode,
    string $actor,
    string $action,
    array $details
  ): ?Node {
    if ($oldNode === null && $newNode === null) {
      throw new InvalidArgumentException('A parent replacement needs an old or a new node');
    }
    $subject = $oldNode?->subject ?? $newNode->subject;
    if ($oldNode !== null && $oldNode->type !== NodeType::PARENT) {
      throw new InvalidArgumentException('The old node must be a parent node');
    }
    if ($newNode !== null && $newNode->type !== NodeType::PARENT) {
      throw new InvalidArgumentException('The new node must be a parent node');
    }
    if ($newNode !== null && !$newNode->subject->equals($subject)) {
      throw new InvalidArgumentException('Parent replacements must stay on the same subject');
    }

    $timestamp = time();
    $saved = $this->transaction(function () use (
      $oldNode,
      $newNode,
      $subject,
      $actor,
      $action,
      $details,
      $timestamp
    ): ?Node {
      if ($oldNode !== null) {
        if ($oldNode->id === null) {
          throw new InvalidArgumentException('The old parent node must be persisted');
        }
        $removed = $this->run(
          "DELETE FROM nodes WHERE id = ? AND subject_type = ? AND subject_id = ? AND node_type = 'parent'",
          [$oldNode->id, $subject->type->value, $subject->identifier]
        );
        if ($removed !== 1) {
          throw new RuntimeException('The parent assignment changed concurrently');
        }
      }

      $stored = null;
      if ($newNode !== null) {
        $contextsJson = $newNode->contexts->toJson();
        [$query, $params] = self::replacementQuery($newNode, $contextsJson);
        $this->run($query, $params);
        $this->run(
          "INSERT INTO nodes(
             subject_type, subject_id, node_type, node_key, node_value,
             contexts_json, expires_at, priority, created_at
           ) VALUES (?, ?, 'parent', ?, 'true', ?, ?, 0, ?)",
          [
            $subject->type->value,
            $subject->identifier,
            $newNode->key,
            $contextsJson,
            $newNode->expiresAt,
            $timestamp
          ]
        );
        $stored = new Node(
          $subject,
          NodeType::PARENT,
          $newNode->key,
          'true',
          $newNode->contexts,
          $newNode->expiresAt,
          0,
          $timestamp,
          (int) $this->requirePdo()->lastInsertId()
        );
      }

      $this->insertAudit($actor, $action, $subject, $details + [
        'new_node_id' => $stored?->id
      ], $timestamp);
      return $stored;
    });

    $this->revision++;
    return $saved;
  }

  /**
  * @param list<EditorSubjectChange> $subjectChanges
  * @param list<EditorTrackChange> $trackChanges
  */
  public function applyEditorBatch(
    int $expectedRevision,
    array $subjectChanges,
    array $trackChanges,
    string $actor,
    string $sessionId
  ): EditorStorageResult {
    if ($this->revision !== $expectedRevision) {
      throw new RevisionConflictException(
        "Editor base revision $expectedRevision is stale; current revision is {$this->revision}"
      );
    }

    $timestamp = time();
    $outcome = $this->transaction(function () use (
      $expectedRevision,
      $subjectChanges,
      $trackChanges,
      $actor,
      $sessionId,
      $timestamp
    ): array {
      $currentNodes = [];
      foreach ($subjectChanges as $change) {
        $current = $this->nodesFor($change->subject, true);
        if (self::nodeCounts($current) != self::nodeCounts($change->before)) {
          throw new RevisionConflictException(
            "Editor subject {$change->subject->key()} changed since the session was created"
          );
        }
        $currentNodes[$change->subject->key()] = $current;
      }

      foreach ($trackChanges as $change) {
        $rows = $this->select('SELECT name FROM tracks WHERE name = ?', [$change->name]);
        if ($rows === [] || $this->loadTrackGroups($change->name) !== $change->before) {
          throw new RevisionConflictException(
            "Editor track '{$change->name}' changed since the session was created"
          );
        }
      }

      $added = 0;
      $removed = 0;
      $changedSubjects = 0;
      foreach ($subjectChanges as $change) {
        $beforeCounts = self::nodeCounts($change->before);
        $afterCounts = self::nodeCounts($change->after);
        $toRemove = self::nodeDifference($currentNodes[$change->subject->key()], $beforeCounts, $afterCounts);
        $toAdd = self::nodeDifference($change->after, $afterCounts, $beforeCounts);
        if ($toRemove === [] && $toAdd === []) {
          continue;
        }
        foreach ($toRemove as $node) {
          if ($node->id === null) {
            throw new RuntimeException('An editor session referenced an unpersisted node');
          }
          $deleted = $this->run(
            'DELETE FROM nodes WHERE id = ? AND subject_type = ? AND subject_id = ?',
            [$node->id, $change->subject->type->value, $change->subject->identifier]
          );
          if ($deleted !== 1) {
            throw new RevisionConflictException(
              "Editor subject {$change->subject->key()} changed during apply"
            );
          }
        }
        foreach ($toAdd as $node) {
          $this->insertEditorNode($node, $timestamp);
        }
        $added += count($toAdd);
        $removed += count($toRemove);
        $changedSubjects++;
        $this->insertAudit($actor, 'editor.subject.apply', $change->subject, [
          'session_id' => $sessionId,
          'added' => array_map(self::nodeDetails(...), $toAdd),
          'removed' => array_map(self::nodeDetails(...), $toRemove)
        ], $timestamp);
      }

      $changedTracks = 0;
      foreach ($trackChanges as $change) {
        if ($change->before === $change->after) {
          continue;
        }
        $this->run('DELETE FROM track_groups WHERE track_name = ?', [$change->name]);
        $this->insertTrackGroups($change->name, $change->after);
        $this->run('UPDATE tracks SET updated_at = ? WHERE name = ?', [$timestamp, $change->name]);
        $changedTracks++;
        $this->insertAudit($actor, 'editor.track.apply', ['track', $change->name], [
          'session_id' => $sessionId,
          'before' => $change->before,
          'after' => $change->after
        ], $timestamp);
      }

      if ($changedSubjects > 0 || $changedTracks > 0) {
        $this->insertAudit($actor, 'editor.apply', null, [
          'session_id' => $sessionId,
          'base_revision' => $expectedRevision,
          'changed_subjects' => $changedSubjects,
          'changed_tracks' => $changedTracks,
          'nodes_added' => $added,
          'nodes_removed' => $removed
        ], $timestamp);
      }

      return [$changedSubjects, $changedTracks, $added, $removed];
    });

    [$changedSubjects, $changedTracks, $added, $removed] = $outcome;
    if ($changedSubjects > 0 || $changedTracks > 0) {
      $this->revision++;
    }
    return new EditorStorageResult($this->revision, $changedSubjects, $changedTracks, $added, $removed);
  }

  public function nodesFor(SubjectRef $subject, bool $includeExpired = true): array {
    $sql = 'SELECT * FROM nodes WHERE subject_type = ? AND subject_id = ?';
    $params = [$subject->type->value, $subject->identifier];
    if (!$includeExpired) {
      $sql .= ' AND (expires_at IS NULL OR expires_at > ?)';
      $params[] = time();
    }
    $sql .= ' ORDER BY id';
    return array_map(self::rowToNode(...), $this->select($sql, $params));
  }

  public function loadSnapshot(SubjectRef $user, string $defaultGroup): PermissionSnapshot {
    if ($user->type !== SubjectType::USER) {
      throw new InvalidArgumentException('Permission snapshots require a user subject');
    }
    $groups = [];
    foreach ($this->select('SELECT name, display_name, weight FROM permission_groups') as $row) {
      $groups[(string) $row['name']] = self::rowToGroup($row);
    }
    $nodes = [];
    $rows = $this->select(
      "SELECT * FROM nodes WHERE subject_type = 'group' OR (subject_type = 'user' AND subject_id = ?) ORDER BY id",
      [$user->identifier]
    );
    foreach ($rows as $row) {
      $node = self::rowToNode($row);
      $nodes[$node->subject->key()][] = $node;
    }
    return new PermissionSnapshot($user, $groups, $nodes, Validation::groupName($defaultGroup));
  }

  public function deleteExpired(int $timestamp): ExpiredNodes {
    $result = $this->transaction(function () use ($timestamp): array {
      $rows = $this->select(
        'SELECT DISTINCT subject_type, subject_id FROM nodes WHERE expires_at IS NOT NULL AND expires_at <= ?',
        [$timestamp]
      );
      $count = $this->run('DELETE FROM nodes WHERE expires_at IS NOT NULL AND expires_at <= ?', [$timestamp]);
      $subjects = [];
      foreach ($rows as $row) {
        $subjects[] = SubjectRef::of(SubjectType::from((string) $row['subject_type']), (string) $row['subject_id']);
      }
      if ($count > 0) {
        $this->insertAudit('system', 'node.expire', null, [
          'count' => $count,
          'subjects' => count($subjects)
        ], $timestamp);
      }
      return [$count, $subjects];
    });

    [$count, $subjects] = $result;
    if ($count > 0) {
      $this->revision++;
    }
    return new ExpiredNodes($count, $subjects);
  }

  public function recentAudit(int $limit = 20): array {
    $bounded = max(1, min(200, $limit));
    $entries = [];
    $rows = $this->select(
      'SELECT id, created_at, actor, action, subject_type, subject_id, details_json
       FROM audit_log ORDER BY id DESC LIMIT ?',
      [$bounded]
    );
    foreach ($rows as $row) {
      $entries[] = [
        'id' => (int) $row['id'],
        'created_at' => (int) $row['created_at'],
        'actor' => (string) $row['actor'],
        'action' => (string) $row['action'],
        'subject_type' => $row['subject_type'],
        'subject_id' => $row['subject_id'],
        'details' => json_decode((string) $row['details_json'], true) ?? []
      ];
    }
    return $entries;
  }

  public function recordAudit(string $actor, string $action, array $details): void {
    $this->insertAudit($actor, $action, null, $details, time());
  }

  /**
  * Every write of a single node first clears the row it replaces: same subject,
  * type, key and contexts, and the same temporary-ness. Prefixes and suffixes
  * additionally key on priority so a stack keeps its separate entries.
  *
  * @return array{0: string, 1: list<mixed>}
  */
  private static function replacementQuery(Node $node, string $contextsJson): array {
    $query = 'DELETE FROM nodes WHERE subject_type = ? AND subject_id = ? AND node_type = ? '
      . 'AND node_key = ? AND contexts_json = ?';
    $params = [
      $node->subject->type->value,
      $node->subject->identifier,
      $node->type->value,
      $node->key,
      $contextsJson
    ];
    $query .= $node->isTemporary() ? ' AND expires_at IS NOT NULL' : ' AND expires_at IS NULL';
    if ($node->type === NodeType::PREFIX || $node->type === NodeType::SUFFIX) {
      $query .= ' AND priority = ?';
      $params[] = $node->priority;
    }
    return [$query, $params];
  }

  private function insertEditorNode(Node $node, int $timestamp): void {
    $this->run(
      'INSERT INTO nodes(
         subject_type, subject_id, node_type, node_key, node_value,
         contexts_json, expires_at, priority, created_at
       ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [
        $node->subject->type->value,
        $node->subject->identifier,
        $node->type->value,
        $node->key,
        $node->value,
        $node->contexts->toJson(),
        $node->expiresAt,
        $node->priority,
        $timestamp
      ]
    );
  }

  /** @param list<string> $groups */
  private function insertTrackGroups(string $trackName, array $groups): void {
    foreach ($groups as $position => $group) {
      $this->run(
        'INSERT INTO track_groups(track_name, group_name, position) VALUES (?, ?, ?)',
        [$trackName, $group, $position]
      );
    }
  }

  /** @return list<string> */
  private function loadTrackGroups(string $trackName): array {
    return array_map(
      static fn(array $row): string => (string) $row['group_name'],
      $this->select('SELECT group_name FROM track_groups WHERE track_name = ? ORDER BY position', [$trackName])
    );
  }

  /**
  * @param SubjectRef|array{0: string, 1: string}|null $subject
  * @param array<string, mixed> $details
  */
  private function insertAudit(
    string $actor,
    string $action,
    SubjectRef|array|null $subject,
    array $details,
    int $timestamp
  ): void {
    if ($subject instanceof SubjectRef) {
      $subjectType = $subject->type->value;
      $subjectId = $subject->identifier;
    } elseif (is_array($subject)) {
      [$subjectType, $subjectId] = $subject;
    } else {
      $subjectType = null;
      $subjectId = null;
    }
    ksort($details);
    $this->run(
      'INSERT INTO audit_log(created_at, actor, action, subject_type, subject_id, details_json)
       VALUES (?, ?, ?, ?, ?, ?)',
      [
        $timestamp,
        substr($actor, 0, 128),
        substr($action, 0, 128),
        $subjectType,
        $subjectId,
        // Details are an object, and an empty PHP array would store as `[]`.
        // The Endstone build writes `{}` into this column for the same rows.
        json_encode(
          $details === [] ? new \stdClass() : $details,
          JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        )
      ]
    );
  }

  /** @return array<string, mixed> */
  private static function nodeDetails(Node $node): array {
    return [
      'id' => $node->id,
      'node_type' => $node->type->value,
      'key' => $node->key,
      'value' => $node->value,
      'contexts' => $node->contexts->pairs(),
      'expires_at' => $node->expiresAt,
      'priority' => $node->priority
    ];
  }

  /**
  * @param list<Node> $nodes
  * @return array<string, int>
  */
  private static function nodeCounts(array $nodes): array {
    $counts = [];
    foreach ($nodes as $node) {
      $fingerprint = $node->identity();
      $counts[$fingerprint] = ($counts[$fingerprint] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
  }

  /**
  * The multiset difference between two node lists. Duplicates matter: two
  * identical nodes on one subject stay two nodes.
  *
  * @param list<Node> $nodes
  * @param array<string, int> $ownCounts
  * @param array<string, int> $otherCounts
  * @return list<Node>
  */
  private static function nodeDifference(array $nodes, array $ownCounts, array $otherCounts): array {
    $remaining = [];
    foreach ($ownCounts as $fingerprint => $count) {
      $remaining[$fingerprint] = max($count - ($otherCounts[$fingerprint] ?? 0), 0);
    }
    $selected = [];
    foreach ($nodes as $node) {
      $fingerprint = $node->identity();
      if (($remaining[$fingerprint] ?? 0) > 0) {
        $selected[] = $node;
        $remaining[$fingerprint]--;
      }
    }
    return $selected;
  }

  /** @param array<string, mixed> $row */
  private static function rowToUser(array $row): UserRecord {
    return new UserRecord(
      (string) $row['unique_id'],
      (string) $row['last_name'],
      $row['xuid'] !== null ? (string) $row['xuid'] : null
    );
  }

  /** @param array<string, mixed> $row */
  private static function rowToGroup(array $row): GroupRecord {
    return new GroupRecord((string) $row['name'], (string) $row['display_name'], (int) $row['weight']);
  }

  /** @param array<string, mixed> $row */
  private static function rowToNode(array $row): Node {
    return new Node(
      SubjectRef::of(SubjectType::from((string) $row['subject_type']), (string) $row['subject_id']),
      NodeType::from((string) $row['node_type']),
      (string) $row['node_key'],
      (string) $row['node_value'],
      ContextSet::fromJson((string) $row['contexts_json']),
      $row['expires_at'] !== null ? (int) $row['expires_at'] : null,
      (int) $row['priority'],
      (int) $row['created_at'],
      (int) $row['id']
    );
  }

  /** @param array<string, mixed> $row */
  private static function rowToProfile(array $row): PlayerProfile {
    $int = static fn(string $key): ?int => $row[$key] !== null ? (int) $row[$key] : null;
    $text = static fn(string $key): ?string => $row[$key] !== null ? (string) $row[$key] : null;
    return new PlayerProfile(
      (string) $row['unique_id'],
      (string) $row['last_name'],
      $text('xuid'),
      $text('locale'),
      $text('device_os'),
      $text('game_version'),
      $text('game_mode'),
      $int('ping_ms'),
      $int('total_exp'),
      $int('exp_level'),
      $text('skin_id'),
      $text('skin_hash'),
      $int('skin_width'),
      $int('skin_height'),
      $text('skin_rgba'),
      $text('cape_id'),
      (int) ($row['first_seen_at'] ?? 0),
      (int) ($row['last_seen_at'] ?? 0),
      $int('last_joined_at'),
      $int('last_quit_at'),
      $int('skin_updated_at'),
      (bool) $row['online']
    );
  }
}
