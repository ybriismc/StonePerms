<?php

declare(strict_types = 1);

namespace stoneperms\application;

use Closure;
use JsonException;
use stoneperms\domain\ContextSet;
use stoneperms\domain\Node;
use stoneperms\domain\NodeType;
use stoneperms\domain\SubjectRef;
use stoneperms\domain\SubjectType;
use stoneperms\domain\TrackRecord;
use stoneperms\domain\Validation;
use Throwable;

/**
* Editor protocol v1: the contract the Vue dashboard speaks.
*
* A session captures the exact node lists it hands out. The changeset that
* comes back must describe the same base revision and the same before-state,
* or the apply is rejected rather than silently overwriting someone else's
* work. Every field is checked; unknown fields are a rejection, not a shrug.
*/
final class EditorProtocol {

  public const PROTOCOL_VERSION = 1;
  public const SESSION_SCHEMA = 'stoneperms.editor/session';
  public const CHANGES_SCHEMA = 'stoneperms.editor/changes';
  public const RESULT_SCHEMA = 'stoneperms.editor/result';

  public const MAX_PAYLOAD_BYTES = 1048576;
  public const MAX_SUBJECTS = 500;
  public const MAX_TRACKS = 200;
  public const MAX_NODES = 5000;
  public const MAX_NODES_PER_SUBJECT = 2000;
  public const MAX_CONTEXTS_PER_NODE = 32;
  public const MAX_TRACK_GROUPS = 500;

  /** @var array<string, EditorSession> */
  private array $sessions = [];
  private bool $enabled = true;
  private readonly int $ttl;
  private readonly int $maxSessions;

  /** @var Closure(): list<string> */
  private Closure $knownPermissions;

  /**
  * @param (callable(): list<string>)|null $knownPermissions the server's registered permission list
  */
  public function __construct(
    private readonly StonePermsManager $manager,
    private readonly string $productVersion,
    ?callable $knownPermissions = null,
    int $sessionTtlSeconds = 900,
    int $maxSessions = 128
  ) {
    if ($sessionTtlSeconds < 60 || $sessionTtlSeconds > 3600) {
      throw new EditorProtocolException('Editor session TTL must be between 60 and 3600 seconds');
    }
    $this->ttl = $sessionTtlSeconds;
    $this->maxSessions = max(1, min(1024, $maxSessions));
    $this->knownPermissions = $knownPermissions !== null
      ? Closure::fromCallable($knownPermissions)
      : static fn(): array => [];
  }

  public function close(): void {
    $this->enabled = false;
    $this->sessions = [];
  }

  /**
  * @param list<string> $users
  * @return array<string, mixed> the session document
  */
  public function createSession(
    string $actor,
    array $users = [],
    bool $includeGroups = true,
    bool $includeTracks = true
  ): array {
    $normalizedActor = self::actor($actor);
    $this->requireEnabled();
    $this->manager->cleanupExpired();

    $requested = [];
    $seen = [];
    foreach ($users as $identifier) {
      $user = $this->manager->findUser((string) $identifier);
      if (!isset($seen[$user->uniqueId])) {
        $requested[] = SubjectRef::user($user->uniqueId);
        $seen[$user->uniqueId] = true;
      }
    }

    $scope = $this->captureScope($requested, $includeGroups, $includeTracks);
    $createdAt = time();
    return $this->storeSession($normalizedActor, $scope, $createdAt);
  }

  /**
  * Captures groups, tracks and nodes at one revision, retrying if storage
  * moved underneath the read.
  *
  * @param list<SubjectRef> $users
  * @return array<string, mixed>
  */
  private function captureScope(array $users, bool $includeGroups, bool $includeTracks): array {
    for ($attempt = 0; $attempt < 3; $attempt++) {
      $baseRevision = $this->manager->repository()->revision();
      $groups = $this->manager->listGroups();
      $tracks = $includeTracks ? $this->manager->listTracks() : [];

      $subjectRefs = [];
      if ($includeGroups) {
        foreach ($groups as $group) {
          $subjectRefs[] = SubjectRef::group($group->name);
        }
      }
      foreach ($users as $user) {
        $subjectRefs[] = $user;
      }

      if (count($subjectRefs) > self::MAX_SUBJECTS) {
        throw new EditorProtocolException('Editor scope exceeds ' . self::MAX_SUBJECTS . ' subjects');
      }
      if (count($tracks) > self::MAX_TRACKS) {
        throw new EditorProtocolException('Editor scope exceeds ' . self::MAX_TRACKS . ' tracks');
      }

      $subjects = [];
      $total = 0;
      foreach ($subjectRefs as $subject) {
        $nodes = $this->manager->repository()->nodesFor($subject, true);
        $subjects[$subject->key()] = $nodes;
        $total += count($nodes);
      }
      if ($total > self::MAX_NODES) {
        throw new EditorProtocolException('Editor scope exceeds ' . self::MAX_NODES . ' nodes');
      }

      if ($this->manager->repository()->revision() === $baseRevision) {
        return [
          'baseRevision' => $baseRevision,
          'users' => $users,
          'includeGroups' => $includeGroups,
          'includeTracks' => $includeTracks,
          'groups' => $groups,
          'tracks' => $tracks,
          'subjects' => $subjects,
          'refs' => $subjectRefs
        ];
      }
    }
    throw new EditorProtocolException('Permissions changed repeatedly while creating the session');
  }

  /**
  * @param array<string, mixed> $scope
  * @return array<string, mixed>
  */
  private function storeSession(string $actor, array $scope, int $createdAt): array {
    $this->requireEnabled();
    $this->removeExpired($createdAt);

    $active = 0;
    foreach ($this->sessions as $session) {
      if ($session->status !== 'consumed') {
        $active++;
      }
    }
    if ($active >= $this->maxSessions) {
      throw new EditorProtocolException('Too many editor sessions are currently open');
    }
    $this->trimConsumed();

    $sessionId = $this->newSessionId();
    $expiresAt = $createdAt + $this->ttl;
    $document = $this->buildDocument($sessionId, $createdAt, $expiresAt, $scope);

    $tracks = [];
    foreach ($scope['tracks'] as $track) {
      $tracks[$track->name] = $track->groups;
    }

    $this->sessions[$sessionId] = new EditorSession(
      $sessionId,
      $actor,
      $scope['baseRevision'],
      $createdAt,
      $expiresAt,
      $scope['subjects'],
      $tracks,
      $document
    );
    return $document;
  }

  /**
  * @param string|array<string, mixed> $payload
  * @return array<string, mixed> the result document
  */
  public function applyChanges(string $actor, string|array $payload): array {
    $normalizedActor = self::actor($actor);
    $raw = self::parsePayload($payload);
    $sessionId = self::requiredText($raw['sessionId'] ?? null, 'sessionId', 128);
    $now = time();

    $this->requireEnabled();
    $session = $this->sessions[$sessionId] ?? null;
    if ($session === null) {
      throw new EditorSessionNotFoundException('Unknown editor session');
    }
    if ($session->expiresAt <= $now) {
      unset($this->sessions[$sessionId]);
      throw new EditorSessionExpiredException('Editor session has expired');
    }
    if ($session->actor !== $normalizedActor) {
      throw new EditorActorMismatchException('Editor session belongs to a different actor');
    }
    if ($session->status === 'consumed') {
      throw new EditorSessionConsumedException('Editor session has already been applied');
    }
    if ($session->status === 'applying') {
      throw new EditorSessionBusyException('Editor session is already being applied');
    }
    $session->status = 'applying';

    try {
      [$subjectChanges, $trackChanges] = $this->decodeChanges($raw, $session, $now);
      $storage = $this->manager->applyEditorBatch(
        $session->baseRevision,
        $subjectChanges,
        $trackChanges,
        $normalizedActor,
        $session->sessionId
      );
    } catch (Throwable $throwable) {
      if ($session->status === 'applying') {
        $session->status = 'open';
      }
      throw $throwable;
    }

    $session->status = 'consumed';
    return [
      'schema' => self::RESULT_SCHEMA,
      'version' => self::PROTOCOL_VERSION,
      'sessionId' => $session->sessionId,
      'baseRevision' => $session->baseRevision,
      'revision' => $storage->revision,
      'changed' => $storage->changed(),
      'changedSubjects' => $storage->changedSubjects,
      'changedTracks' => $storage->changedTracks,
      'nodesAdded' => $storage->nodesAdded,
      'nodesRemoved' => $storage->nodesRemoved
    ];
  }

  /**
  * @param array<string, mixed> $raw
  * @return array{0: list<EditorSubjectChange>, 1: list<EditorTrackChange>}
  */
  private function decodeChanges(array $raw, EditorSession $session, int $now): array {
    self::exactKeys($raw, ['schema', 'version', 'sessionId', 'baseRevision', 'subjects', 'tracks'], 'changeset');
    if (($raw['schema'] ?? null) !== self::CHANGES_SCHEMA) {
      throw new EditorProtocolException('Unsupported editor schema');
    }
    if (self::integer($raw['version'], 'version', 1, 1) !== self::PROTOCOL_VERSION) {
      throw new EditorProtocolException('Unsupported editor protocol version');
    }
    $baseRevision = self::integer($raw['baseRevision'], 'baseRevision', 0, PHP_INT_MAX);
    if ($baseRevision !== $session->baseRevision) {
      throw new EditorProtocolException('Changeset baseRevision does not match the editor session');
    }

    return [
      $this->decodeSubjectChanges($raw['subjects'], $session, $now),
      self::decodeTrackChanges($raw['tracks'], $session)
    ];
  }

  /** @return list<EditorSubjectChange> */
  private function decodeSubjectChanges(mixed $value, EditorSession $session, int $now): array {
    $changes = [];
    $seen = [];
    $nodeCount = 0;

    foreach (self::listOf($value, 'subjects', self::MAX_SUBJECTS) as $item) {
      $mapping = self::mapping($item, 'subject');
      self::exactKeys($mapping, ['type', 'id', 'nodes'], 'subject');
      $subjectType = self::enumCase(SubjectType::class, $mapping['type'], 'subject type');
      $identifier = self::requiredText($mapping['id'], 'subject id', 128);
      $subject = SubjectRef::of($subjectType, $identifier);

      if ($identifier !== $subject->identifier || !isset($session->subjects[$subject->key()])) {
        throw new EditorProtocolException(
          "Subject {$subjectType->value}:$identifier is outside this editor session"
        );
      }
      if (isset($seen[$subject->key()])) {
        throw new EditorProtocolException("Duplicate subject {$subject->key()}");
      }
      $seen[$subject->key()] = true;

      $rawNodes = self::listOf($mapping['nodes'], 'nodes', self::MAX_NODES_PER_SUBJECT);
      $nodeCount += count($rawNodes);
      if ($nodeCount > self::MAX_NODES) {
        throw new EditorProtocolException('Changeset exceeds ' . self::MAX_NODES . ' nodes');
      }

      $after = [];
      foreach ($rawNodes as $rawNode) {
        $after[] = self::decodeNode($subject, $rawNode, $now);
      }
      $changes[] = new EditorSubjectChange($subject, $session->subjects[$subject->key()], $after);
    }
    return $changes;
  }

  /** @return list<EditorTrackChange> */
  private static function decodeTrackChanges(mixed $value, EditorSession $session): array {
    $changes = [];
    $seen = [];

    foreach (self::listOf($value, 'tracks', self::MAX_TRACKS) as $item) {
      $mapping = self::mapping($item, 'track');
      self::exactKeys($mapping, ['name', 'groups'], 'track');
      $name = self::requiredText($mapping['name'], 'track name', 64);
      $normalized = (new TrackRecord($name))->name;
      if ($name !== $normalized || !isset($session->tracks[$normalized])) {
        throw new EditorProtocolException("Track '$name' is outside this editor session");
      }
      if (isset($seen[$normalized])) {
        throw new EditorProtocolException("Duplicate track '$normalized'");
      }
      $seen[$normalized] = true;

      $rawGroups = self::listOf($mapping['groups'], 'track groups', self::MAX_TRACK_GROUPS);
      foreach ($rawGroups as $group) {
        if (!is_string($group)) {
          throw new EditorProtocolException('Track groups must be strings');
        }
      }
      $after = (new TrackRecord($normalized, $rawGroups))->groups;
      if ($after !== array_values($rawGroups)) {
        throw new EditorProtocolException('Track groups must use canonical lowercase names');
      }
      $changes[] = new EditorTrackChange($normalized, $session->tracks[$normalized], $after);
    }
    return $changes;
  }

  private static function decodeNode(SubjectRef $subject, mixed $raw, int $now): Node {
    $mapping = self::mapping($raw, 'node');
    self::exactKeys($mapping, ['type', 'key', 'value', 'contexts', 'expiresAt', 'priority'], 'node');

    $nodeType = self::enumCase(NodeType::class, $mapping['type'], 'node type');
    $key = self::requiredText($mapping['key'], 'node key', 255);
    $value = self::requiredText($mapping['value'], 'node value', 1024, false);

    $pairs = [];
    foreach (self::listOf($mapping['contexts'], 'node contexts', self::MAX_CONTEXTS_PER_NODE) as $item) {
      $context = self::mapping($item, 'context');
      self::exactKeys($context, ['key', 'value'], 'context');
      $pairs[] = [
        self::requiredText($context['key'], 'context key', 64),
        self::requiredText($context['value'], 'context value', 128)
      ];
    }
    $contexts = ContextSet::of($pairs);
    if (count($contexts->pairs()) !== count($pairs)) {
      throw new EditorProtocolException('Duplicate node context pair');
    }
    if ($contexts->pairs() !== $pairs) {
      throw new EditorProtocolException('Node contexts must be canonical, normalized, and sorted');
    }

    $expiresAt = $mapping['expiresAt'] === null
      ? null
      : self::integer($mapping['expiresAt'], 'expiresAt', 1, PHP_INT_MAX);
    if ($expiresAt !== null && $expiresAt <= $now) {
      throw new EditorProtocolException('Submitted editor nodes must not already be expired');
    }
    $priority = self::integer($mapping['priority'], 'priority', -2147483648, 2147483647);

    try {
      $node = new Node($subject, $nodeType, $key, $value, $contexts, $expiresAt, $priority);
    } catch (Throwable $throwable) {
      throw new EditorProtocolException($throwable->getMessage(), 0, $throwable);
    }
    if ($node->key !== $key || $node->value !== $value) {
      throw new EditorProtocolException('Node key and value must use their canonical representation');
    }
    return $node;
  }

  /**
  * @param array<string, mixed> $scope
  * @return array<string, mixed>
  */
  private function buildDocument(string $sessionId, int $createdAt, int $expiresAt, array $scope): array {
    $groupRecords = [];
    foreach ($scope['groups'] as $group) {
      $groupRecords[$group->name] = $group;
    }

    $refs = $scope['refs'];
    usort($refs, static fn(SubjectRef $a, SubjectRef $b): int
      => [$a->type->value, $a->identifier] <=> [$b->type->value, $b->identifier]);

    $subjects = [];
    $allNodes = [];
    foreach ($refs as $subject) {
      $nodes = $scope['subjects'][$subject->key()];
      foreach ($nodes as $node) {
        $allNodes[] = $node;
      }
      $sorted = $nodes;
      usort($sorted, static fn(Node $a, Node $b): int => self::nodeSortKey($a) <=> self::nodeSortKey($b));

      $entry = [
        'type' => $subject->type->value,
        'id' => $subject->identifier,
        'nodes' => array_map(static fn(Node $node): array => $node->toWire(), $sorted)
      ];
      if ($subject->type === SubjectType::GROUP) {
        $group = $groupRecords[$subject->identifier];
        $entry['displayName'] = $group->displayName;
        $entry['weight'] = $group->weight;
      } else {
        $user = $this->manager->findUser($subject->identifier);
        $entry['name'] = $user->lastName;
        $entry['xuid'] = $user->xuid;
      }
      $subjects[] = $entry;
    }

    $knownPermissions = [];
    foreach ($allNodes as $node) {
      if ($node->type === NodeType::PERMISSION) {
        $knownPermissions[$node->key] = true;
      }
    }
    foreach (($this->knownPermissions)() as $permission) {
      try {
        $knownPermissions[Validation::permission((string) $permission)] = true;
      } catch (Throwable) {
        continue;
      }
    }
    $permissionList = array_keys($knownPermissions);
    sort($permissionList);

    $potentialContexts = [];
    foreach ($allNodes as $node) {
      foreach ($node->contexts->pairs() as [$key, $value]) {
        $potentialContexts[$key][$value] = true;
      }
    }
    ksort($potentialContexts);
    $contextList = [];
    foreach ($potentialContexts as $key => $values) {
      $sortedValues = array_keys($values);
      sort($sortedValues);
      $contextList[] = ['key' => (string) $key, 'values' => $sortedValues];
    }

    return [
      'schema' => self::SESSION_SCHEMA,
      'version' => self::PROTOCOL_VERSION,
      'sessionId' => $sessionId,
      'baseRevision' => $scope['baseRevision'],
      'createdAt' => $createdAt,
      'expiresAt' => $expiresAt,
      'scope' => [
        'users' => array_map(static fn(SubjectRef $ref): string => $ref->identifier, $scope['users']),
        'groups' => $scope['includeGroups'],
        'tracks' => $scope['includeTracks']
      ],
      'metadata' => [
        'product' => 'StonePerms',
        'productVersion' => $this->productVersion,
        'defaultGroup' => $this->manager->defaultGroup(),
        'groups' => array_map(
          static fn($group): array => [
            'name' => $group->name,
            'displayName' => $group->displayName,
            'weight' => $group->weight
          ],
          $scope['groups']
        )
      ],
      'subjects' => $subjects,
      'tracks' => array_map(
        static fn(TrackRecord $track): array => ['name' => $track->name, 'groups' => $track->groups],
        $scope['tracks']
      ),
      'knownPermissions' => $permissionList,
      'potentialContexts' => $contextList
    ];
  }

  /** @return list<mixed> */
  private static function nodeSortKey(Node $node): array {
    return [
      $node->type->value,
      $node->key,
      $node->contexts->toJson(),
      $node->expiresAt ?? 0,
      $node->priority,
      $node->value
    ];
  }

  private function newSessionId(): string {
    for ($attempt = 0; $attempt < 10; $attempt++) {
      $sessionId = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
      if (strlen($sessionId) >= 32 && strlen($sessionId) <= 128 && !isset($this->sessions[$sessionId])) {
        return $sessionId;
      }
    }
    throw new EditorProtocolException('Could not allocate a unique editor session id');
  }

  private function removeExpired(int $now): void {
    foreach ($this->sessions as $sessionId => $session) {
      if ($session->expiresAt <= $now && $session->status !== 'applying') {
        unset($this->sessions[$sessionId]);
      }
    }
  }

  private function trimConsumed(): void {
    $excess = count($this->sessions) - $this->maxSessions * 2;
    if ($excess < 0) {
      return;
    }
    $consumed = [];
    foreach ($this->sessions as $session) {
      if ($session->status === 'consumed') {
        $consumed[] = $session;
      }
    }
    usort($consumed, static fn(EditorSession $a, EditorSession $b): int => $a->createdAt <=> $b->createdAt);
    foreach (array_slice($consumed, 0, $excess + 1) as $session) {
      unset($this->sessions[$session->sessionId]);
    }
  }

  private function requireEnabled(): void {
    if (!$this->enabled) {
      throw new EditorProtocolException('StonePerms editor protocol is disabled');
    }
  }

  /**
  * @param string|array<string, mixed> $payload
  * @return array<string, mixed>
  */
  private static function parsePayload(string|array $payload): array {
    if (is_string($payload)) {
      if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
        throw new EditorProtocolException('Editor payload exceeds ' . self::MAX_PAYLOAD_BYTES . ' bytes');
      }
      try {
        $raw = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
      } catch (JsonException $exception) {
        throw new EditorProtocolException('Editor payload is not valid JSON', 0, $exception);
      }
    } else {
      $raw = $payload;
    }
    return self::mapping($raw, 'changeset');
  }

  /** @return array<string, mixed> */
  private static function mapping(mixed $value, string $label): array {
    if (!is_array($value) || array_is_list($value)) {
      throw new EditorProtocolException("Editor $label must be an object");
    }
    foreach (array_keys($value) as $key) {
      if (!is_string($key)) {
        throw new EditorProtocolException("Editor $label must be an object");
      }
    }
    return $value;
  }

  /** @return list<mixed> */
  private static function listOf(mixed $value, string $label, int $maximum): array {
    if (!is_array($value) || !array_is_list($value)) {
      throw new EditorProtocolException("Editor $label must be an array");
    }
    if (count($value) > $maximum) {
      throw new EditorProtocolException("Editor $label exceeds the limit of $maximum");
    }
    return $value;
  }

  /**
  * @param array<string, mixed> $value
  * @param list<string> $expected
  */
  private static function exactKeys(array $value, array $expected, string $label): void {
    $actual = array_keys($value);
    sort($actual);
    $wanted = $expected;
    sort($wanted);
    if ($actual !== $wanted) {
      $missing = array_values(array_diff($wanted, $actual));
      $unknown = array_values(array_diff($actual, $wanted));
      $details = [];
      if ($missing !== []) {
        $details[] = 'missing ' . implode(', ', $missing);
      }
      if ($unknown !== []) {
        $details[] = 'unknown ' . implode(', ', $unknown);
      }
      throw new EditorProtocolException("Invalid editor $label: " . implode('; ', $details));
    }
  }

  private static function requiredText(mixed $value, string $label, int $maximum, bool $strip = true): string {
    if (!is_string($value)) {
      throw new EditorProtocolException("Editor $label must be text");
    }
    $result = $strip ? trim($value) : $value;
    if (trim($result) === '' || strlen($result) > $maximum) {
      throw new EditorProtocolException("Editor $label must contain 1-$maximum characters");
    }
    return $result;
  }

  private static function integer(mixed $value, string $label, int $minimum, int $maximum): int {
    if (is_bool($value) || !is_int($value)) {
      throw new EditorProtocolException("Editor $label must be an integer");
    }
    if ($value < $minimum || $value > $maximum) {
      throw new EditorProtocolException("Editor $label is outside the supported range");
    }
    return $value;
  }

  /**
  * @template T of \BackedEnum
  * @param class-string<T> $enum
  * @return T
  */
  private static function enumCase(string $enum, mixed $value, string $label): mixed {
    if (!is_string($value)) {
      throw new EditorProtocolException("Editor $label must be text");
    }
    $case = $enum::tryFrom($value);
    if ($case === null) {
      throw new EditorProtocolException("Unsupported editor $label '$value'");
    }
    return $case;
  }

  private static function actor(string $value): string {
    $actor = trim($value);
    if ($actor === '' || strlen($actor) > 128) {
      throw new EditorProtocolException('Editor actor must contain 1-128 characters');
    }
    return $actor;
  }
}
