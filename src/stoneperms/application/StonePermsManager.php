<?php

declare(strict_types = 1);

namespace stoneperms\application;

use InvalidArgumentException;
use stoneperms\domain\ContextSet;
use stoneperms\domain\GroupRecord;
use stoneperms\domain\MetaDecision;
use stoneperms\domain\Node;
use stoneperms\domain\NodeType;
use stoneperms\domain\PermissionDecision;
use stoneperms\domain\PermissionResolver;
use stoneperms\domain\PermissionSnapshot;
use stoneperms\domain\PlayerProfile;
use stoneperms\domain\SubjectRef;
use stoneperms\domain\SubjectType;
use stoneperms\domain\TrackMoveAction;
use stoneperms\domain\TrackMoveResult;
use stoneperms\domain\TrackMoveStatus;
use stoneperms\domain\TrackRecord;
use stoneperms\domain\UserRecord;
use stoneperms\domain\Validation;
use Throwable;

/**
* Every operation StonePerms can perform on permission data, in one place.
*
* Commands, forms, the service API and the dashboard bridge all funnel through
* this class, so a change made in game and a change made on the web take the
* same validation path and produce the same audit entries.
*/
final class StonePermsManager {

  private readonly PermissionResolver $resolver;
  private readonly string $defaultGroup;

  /** @var array<string, array{0: int, 1: PermissionSnapshot}> */
  private array $snapshotCache = [];

  public function __construct(
    private readonly PermissionRepository $repository,
    string $defaultGroup = 'default'
  ) {
    $this->resolver = new PermissionResolver();
    $this->defaultGroup = Validation::groupName($defaultGroup);
  }

  public function defaultGroup(): string {
    return $this->defaultGroup;
  }

  public function repository(): PermissionRepository {
    return $this->repository;
  }

  public function registerUser(string $uniqueId, string $name, ?string $xuid = null): UserRecord {
    $record = new UserRecord($uniqueId, $name, $xuid);
    $this->repository->upsertUser($record);
    return $record;
  }

  public function findUser(string $identifier): UserRecord {
    $user = $this->repository->findUser($identifier);
    if ($user === null) {
      throw new UnknownSubjectException("Unknown player '$identifier'");
    }
    return $user;
  }

  /** @return list<UserRecord> */
  public function listUsers(): array {
    return $this->repository->listUsers();
  }

  public function observePlayer(PlayerProfile $profile): PlayerProfile {
    return $this->repository->upsertPlayerProfile($profile);
  }

  public function getPlayerProfile(string $identifier): ?PlayerProfile {
    return $this->repository->getPlayerProfile($identifier);
  }

  /** @return list<PlayerProfile> */
  public function listPlayerProfiles(): array {
    return $this->repository->listPlayerProfiles();
  }

  public function userSubject(string $identifier): SubjectRef {
    return SubjectRef::user($this->findUser($identifier)->uniqueId);
  }

  public function groupSubject(string $name): SubjectRef {
    $group = Validation::groupName($name);
    if ($this->repository->getGroup($group) === null) {
      throw new UnknownSubjectException("Unknown group '$group'");
    }
    return SubjectRef::group($group);
  }

  public function createGroup(string $name, ?string $displayName, int $weight, string $actor): bool {
    $record = new GroupRecord($name, $displayName ?? '', $weight);
    $created = $this->repository->createGroup($record, $actor);
    if ($created) {
      $this->invalidate();
    }
    return $created;
  }

  /** @return list<GroupRecord> */
  public function listGroups(): array {
    return $this->repository->listGroups();
  }

  public function getGroup(string $name): GroupRecord {
    $group = $this->repository->getGroup($name);
    if ($group === null) {
      throw new UnknownSubjectException("Unknown group '" . Validation::groupName($name) . "'");
    }
    return $group;
  }

  public function setGroupWeight(string $name, int $weight, string $actor): void {
    $this->requireGroup($name);
    $this->repository->setGroupWeight($name, $weight, $actor);
    $this->invalidate();
  }

  public function deleteGroup(string $name, string $actor): bool {
    $group = Validation::groupName($name);
    if ($group === $this->defaultGroup) {
      throw new InvalidArgumentException("The default group '$group' may not be deleted");
    }
    $deleted = $this->repository->deleteGroup($group, $actor);
    if ($deleted) {
      $this->invalidate();
    }
    return $deleted;
  }

  public function createTrack(string $name, string $actor): bool {
    $created = $this->repository->createTrack(new TrackRecord($name), $actor);
    if ($created) {
      $this->invalidate();
    }
    return $created;
  }

  public function getTrack(string $name): TrackRecord {
    $track = $this->repository->getTrack($name);
    if ($track === null) {
      throw new UnknownSubjectException("Unknown track '" . Validation::trackName($name) . "'");
    }
    return $track;
  }

  /** @return list<TrackRecord> */
  public function listTracks(): array {
    return $this->repository->listTracks();
  }

  public function appendTrackGroup(string $name, string $group, string $actor): TrackRecord {
    $track = $this->getTrack($name);
    $groupName = $this->requireGroup($group);
    if ($track->contains($groupName)) {
      throw new InvalidArgumentException("Group '$groupName' is already on track '{$track->name}'");
    }
    $groups = $track->groups;
    $groups[] = $groupName;
    return $this->setTrackGroups($track->name, $groups, $actor, 'track.append', [
      'group' => $groupName,
      'position' => count($groups) - 1
    ]);
  }

  public function insertTrackGroup(string $name, string $group, int $position, string $actor): TrackRecord {
    $track = $this->getTrack($name);
    $groupName = $this->requireGroup($group);
    if ($track->contains($groupName)) {
      throw new InvalidArgumentException("Group '$groupName' is already on track '{$track->name}'");
    }
    if ($position < 0 || $position > count($track->groups)) {
      throw new InvalidArgumentException(
        'Track position must be between 0 and ' . count($track->groups)
      );
    }
    $groups = $track->groups;
    array_splice($groups, $position, 0, [$groupName]);
    return $this->setTrackGroups($track->name, $groups, $actor, 'track.insert', [
      'group' => $groupName,
      'position' => $position
    ]);
  }

  public function removeTrackGroup(string $name, string $group, string $actor): TrackRecord {
    $track = $this->getTrack($name);
    $groupName = Validation::groupName($group);
    $index = $track->indexOf($groupName);
    if ($index === null) {
      throw new InvalidArgumentException("Group '$groupName' is not on track '{$track->name}'");
    }
    $groups = $track->groups;
    array_splice($groups, $index, 1);
    return $this->setTrackGroups($track->name, $groups, $actor, 'track.remove', [
      'group' => $groupName,
      'position' => $index
    ]);
  }

  public function clearTrack(string $name, string $actor): TrackRecord {
    $track = $this->getTrack($name);
    return $this->setTrackGroups($track->name, [], $actor, 'track.clear', [
      'previous' => $track->groups
    ]);
  }

  public function renameTrack(string $name, string $newName, string $actor): TrackRecord {
    $track = $this->getTrack($name);
    $target = Validation::trackName($newName);
    if ($track->name === $target) {
      return $track;
    }
    if ($this->repository->getTrack($target) !== null) {
      throw new InvalidArgumentException("Track '$target' already exists");
    }
    $this->repository->renameTrack($track->name, $target, $actor);
    $this->invalidate();
    return $this->getTrack($target);
  }

  public function cloneTrack(string $name, string $cloneName, string $actor): TrackRecord {
    $track = $this->getTrack($name);
    $target = Validation::trackName($cloneName);
    if (!$this->repository->createTrack(new TrackRecord($target, $track->groups), $actor, 'track.clone')) {
      throw new InvalidArgumentException("Track '$target' already exists");
    }
    $this->invalidate();
    return $this->getTrack($target);
  }

  public function deleteTrack(string $name, string $actor): bool {
    $deleted = $this->repository->deleteTrack($name, $actor);
    if ($deleted) {
      $this->invalidate();
    }
    return $deleted;
  }

  public function promote(
    SubjectRef $user,
    string $trackName,
    string $actor,
    ?ContextSet $contexts = null,
    bool $addToFirst = true
  ): TrackMoveResult {
    return $this->moveOnTrack(
      $user,
      $trackName,
      TrackMoveAction::PROMOTE,
      $actor,
      $contexts ?? ContextSet::empty(),
      $addToFirst
    );
  }

  public function demote(
    SubjectRef $user,
    string $trackName,
    string $actor,
    ?ContextSet $contexts = null,
    bool $removeFromFirst = true
  ): TrackMoveResult {
    return $this->moveOnTrack(
      $user,
      $trackName,
      TrackMoveAction::DEMOTE,
      $actor,
      $contexts ?? ContextSet::empty(),
      $removeFromFirst
    );
  }

  /** @return array<string, list<string>> */
  public function userTracks(SubjectRef $user, ?ContextSet $contexts = null): array {
    $this->ensureSubject($user);
    if ($user->type !== SubjectType::USER) {
      throw new InvalidArgumentException('Track positions require a user subject');
    }
    $nodes = $this->activeDirectParents($user, $contexts);
    $positions = [];
    foreach ($this->listTracks() as $track) {
      $matched = [];
      foreach ($nodes as $node) {
        if ($track->contains($node->key)) {
          $matched[$node->key] = true;
        }
      }
      if ($matched === []) {
        continue;
      }
      $positions[$track->name] = array_values(array_filter(
        $track->groups,
        static fn(string $group): bool => isset($matched[$group])
      ));
    }
    return $positions;
  }

  public function setPermission(
    SubjectRef $subject,
    string $permission,
    bool $value,
    string $actor,
    ?ContextSet $contexts = null,
    ?int $expiresAt = null
  ): Node {
    $this->ensureSubject($subject);
    $node = new Node(
      $subject,
      NodeType::PERMISSION,
      Validation::permission($permission),
      $value ? 'true' : 'false',
      $contexts ?? ContextSet::empty(),
      $expiresAt,
      0,
      time()
    );
    $saved = $this->repository->saveNode(
      $node,
      $actor,
      $expiresAt !== null ? 'permission.settemp' : 'permission.set'
    );
    $this->invalidate();
    return $saved;
  }

  public function unsetPermission(
    SubjectRef $subject,
    string $permission,
    string $actor,
    ?ContextSet $contexts = null,
    ?bool $temporary = false
  ): int {
    $this->ensureSubject($subject);
    $removed = $this->repository->removeNodes(
      $subject,
      NodeType::PERMISSION,
      Validation::permission($permission),
      $contexts ?? ContextSet::empty(),
      $actor,
      'permission.unset',
      $temporary
    );
    $this->invalidate();
    return $removed;
  }

  public function setMeta(
    SubjectRef $subject,
    string $key,
    string $value,
    string $actor,
    ?ContextSet $contexts = null,
    ?int $expiresAt = null
  ): Node {
    return $this->setStringNode($subject, NodeType::META, $key, $value, 0, $actor, $contexts, $expiresAt, 'meta');
  }

  public function unsetMeta(
    SubjectRef $subject,
    string $key,
    string $actor,
    ?ContextSet $contexts = null,
    ?bool $temporary = false
  ): int {
    return $this->unsetStringNode($subject, NodeType::META, Validation::metaKey($key), null, $actor, $contexts, $temporary, 'meta');
  }

  public function setPrefix(
    SubjectRef $subject,
    int $priority,
    string $value,
    string $actor,
    ?ContextSet $contexts = null,
    ?int $expiresAt = null
  ): Node {
    return $this->setStringNode($subject, NodeType::PREFIX, 'prefix', $value, $priority, $actor, $contexts, $expiresAt, 'prefix');
  }

  public function unsetPrefix(
    SubjectRef $subject,
    int $priority,
    string $actor,
    ?ContextSet $contexts = null,
    ?bool $temporary = false
  ): int {
    return $this->unsetStringNode($subject, NodeType::PREFIX, 'prefix', $priority, $actor, $contexts, $temporary, 'prefix');
  }

  public function setSuffix(
    SubjectRef $subject,
    int $priority,
    string $value,
    string $actor,
    ?ContextSet $contexts = null,
    ?int $expiresAt = null
  ): Node {
    return $this->setStringNode($subject, NodeType::SUFFIX, 'suffix', $value, $priority, $actor, $contexts, $expiresAt, 'suffix');
  }

  public function unsetSuffix(
    SubjectRef $subject,
    int $priority,
    string $actor,
    ?ContextSet $contexts = null,
    ?bool $temporary = false
  ): int {
    return $this->unsetStringNode($subject, NodeType::SUFFIX, 'suffix', $priority, $actor, $contexts, $temporary, 'suffix');
  }

  public function addParent(
    SubjectRef $subject,
    string $parent,
    string $actor,
    ?ContextSet $contexts = null,
    ?int $expiresAt = null
  ): Node {
    $this->ensureSubject($subject);
    $parentName = Validation::groupName($parent);
    if ($this->repository->getGroup($parentName) === null) {
      throw new UnknownSubjectException("Unknown parent group '$parentName'");
    }
    if ($subject->type === SubjectType::GROUP) {
      if ($subject->identifier === $parentName || $this->groupReaches($parentName, $subject->identifier)) {
        throw new InvalidArgumentException(
          "Adding '$parentName' as parent of '{$subject->identifier}' would create a cycle"
        );
      }
    }
    $node = new Node(
      $subject,
      NodeType::PARENT,
      $parentName,
      'true',
      $contexts ?? ContextSet::empty(),
      $expiresAt,
      0,
      time()
    );
    $saved = $this->repository->saveNode(
      $node,
      $actor,
      $expiresAt !== null ? 'parent.addtemp' : 'parent.add'
    );
    $this->invalidate();
    return $saved;
  }

  public function removeParent(
    SubjectRef $subject,
    string $parent,
    string $actor,
    ?ContextSet $contexts = null,
    ?bool $temporary = false
  ): int {
    $this->ensureSubject($subject);
    $removed = $this->repository->removeNodes(
      $subject,
      NodeType::PARENT,
      Validation::groupName($parent),
      $contexts ?? ContextSet::empty(),
      $actor,
      'parent.remove',
      $temporary
    );
    $this->invalidate();
    return $removed;
  }

  public function checkPermission(SubjectRef $user, string $permission, ?ContextSet $contexts = null): PermissionDecision {
    return $this->resolver->resolve($this->snapshot($user), $permission, $contexts, time());
  }

  /** @return list<string> */
  public function effectiveGroups(SubjectRef $user, ?ContextSet $contexts = null): array {
    return $this->resolver->effectiveGroups($this->snapshot($user), $contexts, time());
  }

  public function primaryGroup(SubjectRef $user, ?ContextSet $contexts = null): string {
    return $this->resolver->primaryGroup($this->snapshot($user), $contexts, time());
  }

  public function resolveMeta(SubjectRef $user, string $key, ?ContextSet $contexts = null): MetaDecision {
    return $this->resolver->resolveMeta($this->snapshot($user), $key, $contexts, time());
  }

  public function resolvePrefix(SubjectRef $user, ?ContextSet $contexts = null): MetaDecision {
    return $this->resolver->resolvePrefix($this->snapshot($user), $contexts, time());
  }

  public function resolveSuffix(SubjectRef $user, ?ContextSet $contexts = null): MetaDecision {
    return $this->resolver->resolveSuffix($this->snapshot($user), $contexts, time());
  }

  /** @return array<string, string> */
  public function metaMap(SubjectRef $user, ?ContextSet $contexts = null): array {
    return $this->resolver->resolveMetaMap($this->snapshot($user), $contexts, time());
  }

  /**
  * Every permission key that could produce a value for this user, whether or
  * not the server has registered it. Wildcards are expanded by the caller
  * against the registered catalog; exact keys come from here.
  *
  * @return list<string>
  */
  public function resolvablePermissionKeys(SubjectRef $user): array {
    $snapshot = $this->snapshot($user);
    $keys = [];
    foreach ($snapshot->nodes as $nodes) {
      foreach ($nodes as $node) {
        if ($node->type === NodeType::PERMISSION && $node->key !== '*' && !str_ends_with($node->key, '.*')) {
          $keys[$node->key] = true;
        }
      }
    }
    return array_keys($keys);
  }

  /**
  * @param iterable<string> $permissions
  * @return array<string, bool>
  */
  public function resolvePermissions(SubjectRef $user, iterable $permissions, ?ContextSet $contexts = null): array {
    return $this->resolver->resolveMany($this->snapshot($user), $permissions, $contexts, time());
  }

  /** @return list<Node> */
  public function nodesFor(SubjectRef $subject): array {
    $this->ensureSubject($subject);
    return $this->repository->nodesFor($subject, true);
  }

  public function cleanupExpired(): ExpiredNodes {
    $expired = $this->repository->deleteExpired(time());
    if ($expired->count > 0) {
      $this->invalidate();
    }
    return $expired;
  }

  /** @return list<array<string, mixed>> */
  public function recentAudit(int $limit = 20): array {
    return $this->repository->recentAudit($limit);
  }

  /**
  * @param iterable<string> $fields
  * @param iterable<string> $restartRequired
  */
  public function recordSettingsChange(string $actor, iterable $fields, iterable $restartRequired): void {
    $normalizedActor = trim($actor);
    if ($normalizedActor === '' || strlen($normalizedActor) > 128) {
      throw new InvalidArgumentException('Settings actor must contain 1-128 characters');
    }
    $changed = array_values(array_unique(array_map(strval(...), iterator_to_array($fields, false))));
    if ($changed === []) {
      return;
    }
    $this->repository->recordAudit($normalizedActor, 'settings.update', [
      'fields' => $changed,
      'restart_required' => array_values(array_unique(array_map(strval(...), iterator_to_array($restartRequired, false))))
    ]);
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
    if ($this->repository->revision() !== $expectedRevision) {
      throw new RevisionConflictException(
        "Editor base revision $expectedRevision is stale; current revision is {$this->repository->revision()}"
      );
    }
    $normalizedActor = trim($actor);
    if ($normalizedActor === '' || strlen($normalizedActor) > 128) {
      throw new InvalidArgumentException('Editor actor must contain 1-128 characters');
    }
    if (trim($sessionId) === '') {
      throw new InvalidArgumentException('An editor session id is required');
    }

    $seenSubjects = [];
    foreach ($subjectChanges as $change) {
      if (isset($seenSubjects[$change->subject->key()])) {
        throw new InvalidArgumentException("Duplicate editor subject {$change->subject->key()}");
      }
      $seenSubjects[$change->subject->key()] = true;
      $this->ensureSubject($change->subject);
      $this->validateEditorNodes($change->subject, $change->before, true);
      $this->validateEditorNodes($change->subject, $change->after, false);
    }

    $seenTracks = [];
    $normalizedTracks = [];
    foreach ($trackChanges as $change) {
      $track = $this->getTrack($change->name);
      if (isset($seenTracks[$track->name])) {
        throw new InvalidArgumentException("Duplicate editor track '{$track->name}'");
      }
      $seenTracks[$track->name] = true;
      $before = (new TrackRecord($track->name, $change->before))->groups;
      $after = (new TrackRecord($track->name, $change->after))->groups;
      foreach ($after as $group) {
        $this->requireGroup($group);
      }
      $normalizedTracks[] = new EditorTrackChange($track->name, $before, $after);
    }

    $this->validateEditorGroupGraph($subjectChanges);
    $result = $this->repository->applyEditorBatch(
      $expectedRevision,
      $subjectChanges,
      $normalizedTracks,
      $normalizedActor,
      $sessionId
    );
    if ($result->changed()) {
      $this->invalidate();
    }
    return $result;
  }

  public function snapshot(SubjectRef $user): PermissionSnapshot {
    if ($user->type !== SubjectType::USER) {
      throw new InvalidArgumentException('Permission checks require a user subject');
    }
    $cached = $this->snapshotCache[$user->identifier] ?? null;
    if ($cached !== null && $cached[0] === $this->repository->revision()) {
      return $cached[1];
    }

    // A database that stops answering must not take a player's permissions
    // with it: the last snapshot that did load is served until it answers
    // again. Only a player nobody has ever resolved has nothing to fall back
    // on, and for that one the caller decides what to do.
    try {
      $snapshot = $this->repository->loadSnapshot($user, $this->defaultGroup);
    } catch (Throwable $throwable) {
      if ($cached === null) {
        throw $throwable;
      }
      return $cached[1];
    }

    $this->snapshotCache[$user->identifier] = [$this->repository->revision(), $snapshot];
    return $snapshot;
  }

  /** @param list<Node> $nodes */
  private function validateEditorNodes(SubjectRef $subject, array $nodes, bool $allowExpired): void {
    $now = time();
    foreach ($nodes as $node) {
      if (!$node->subject->equals($subject)) {
        throw new InvalidArgumentException('Editor nodes must belong to their own subject');
      }
      if (!$allowExpired && !$node->activeAt($now)) {
        throw new InvalidArgumentException('Submitted editor nodes must not already be expired');
      }
      if ($node->type === NodeType::PARENT && $this->repository->getGroup($node->key) === null) {
        throw new UnknownSubjectException("Unknown parent group '{$node->key}'");
      }
    }
  }

  /**
  * A changeset may rewire several groups at once, so cycles have to be checked
  * against the graph the changeset would produce, not the one on disk.
  *
  * @param list<EditorSubjectChange> $changes
  */
  private function validateEditorGroupGraph(array $changes): void {
    $parents = [];
    foreach ($this->listGroups() as $group) {
      $subject = SubjectRef::group($group->name);
      $parents[$group->name] = [];
      foreach ($this->repository->nodesFor($subject, true) as $node) {
        if ($node->type === NodeType::PARENT) {
          $parents[$group->name][] = $node->key;
        }
      }
    }
    foreach ($changes as $change) {
      if ($change->subject->type !== SubjectType::GROUP) {
        continue;
      }
      $parents[$change->subject->identifier] = [];
      foreach ($change->after as $node) {
        if ($node->type === NodeType::PARENT) {
          $parents[$change->subject->identifier][] = $node->key;
        }
      }
    }

    $state = [];
    $visit = function (string $group) use (&$visit, &$state, $parents): void {
      if (($state[$group] ?? 0) === 2) {
        return;
      }
      if (($state[$group] ?? 0) === 1) {
        throw new InvalidArgumentException("Group inheritance cycle detected at '$group'");
      }
      $state[$group] = 1;
      foreach ($parents[$group] ?? [] as $parent) {
        $visit($parent);
      }
      $state[$group] = 2;
    };
    foreach (array_keys($parents) as $group) {
      $visit($group);
    }
  }

  private function ensureSubject(SubjectRef $subject): void {
    if ($subject->type === SubjectType::GROUP) {
      $this->requireGroup($subject->identifier);
      return;
    }
    if ($this->repository->findUser($subject->identifier) === null) {
      throw new UnknownSubjectException("Unknown user UUID '{$subject->identifier}'");
    }
  }

  private function requireGroup(string $name): string {
    $group = Validation::groupName($name);
    if ($this->repository->getGroup($group) === null) {
      throw new UnknownSubjectException("Unknown group '$group'");
    }
    return $group;
  }

  private function setStringNode(
    SubjectRef $subject,
    NodeType $type,
    string $key,
    string $value,
    int $priority,
    string $actor,
    ?ContextSet $contexts,
    ?int $expiresAt,
    string $auditPrefix
  ): Node {
    $this->ensureSubject($subject);
    $node = new Node(
      $subject,
      $type,
      $key,
      $value,
      $contexts ?? ContextSet::empty(),
      $expiresAt,
      $priority,
      time()
    );
    $saved = $this->repository->saveNode(
      $node,
      $actor,
      $expiresAt !== null ? "$auditPrefix.settemp" : "$auditPrefix.set"
    );
    $this->invalidate();
    return $saved;
  }

  private function unsetStringNode(
    SubjectRef $subject,
    NodeType $type,
    string $key,
    ?int $priority,
    string $actor,
    ?ContextSet $contexts,
    ?bool $temporary,
    string $auditPrefix
  ): int {
    $this->ensureSubject($subject);
    $removed = $this->repository->removeNodes(
      $subject,
      $type,
      $key,
      $contexts ?? ContextSet::empty(),
      $actor,
      "$auditPrefix.unset",
      $temporary,
      $priority
    );
    $this->invalidate();
    return $removed;
  }

  private function groupReaches(string $start, string $target): bool {
    $seen = [];
    $queue = [Validation::groupName($start)];
    $goal = Validation::groupName($target);
    while ($queue !== []) {
      $group = array_shift($queue);
      if (isset($seen[$group])) {
        continue;
      }
      $seen[$group] = true;
      if ($group === $goal) {
        return true;
      }
      foreach ($this->repository->nodesFor(SubjectRef::group($group), true) as $node) {
        if ($node->type === NodeType::PARENT) {
          $queue[] = $node->key;
        }
      }
    }
    return false;
  }

  /**
  * @param list<string> $groups
  * @param array<string, mixed> $details
  */
  private function setTrackGroups(
    string $name,
    array $groups,
    string $actor,
    string $action,
    array $details
  ): TrackRecord {
    $record = $this->repository->setTrackGroups($name, $groups, $actor, $action, $details);
    $this->invalidate();
    return $record;
  }

  private function moveOnTrack(
    SubjectRef $user,
    string $trackName,
    TrackMoveAction $action,
    string $actor,
    ContextSet $contexts,
    bool $crossBoundary
  ): TrackMoveResult {
    $this->ensureSubject($user);
    if ($user->type !== SubjectType::USER) {
      throw new InvalidArgumentException('Only users can be promoted or demoted');
    }
    $track = $this->getTrack($trackName);
    if (count($track->groups) < 2) {
      throw new InvalidArgumentException("Track '{$track->name}' needs at least two groups for promote/demote");
    }

    $matching = [];
    foreach ($this->activeDirectParents($user, $contexts) as $node) {
      if ($track->contains($node->key)) {
        $matching[] = $node;
      }
    }
    if (count($matching) > 1) {
      return new TrackMoveResult($action, TrackMoveStatus::AMBIGUOUS_CALL, $track->name);
    }

    if ($matching === []) {
      if ($action === TrackMoveAction::DEMOTE || !$crossBoundary) {
        return new TrackMoveResult($action, TrackMoveStatus::NOT_ON_TRACK, $track->name);
      }
      $destination = $track->groups[0];
      $newNode = new Node($user, NodeType::PARENT, $destination, 'true', $contexts, null, 0, time());
      $this->repository->replaceParentNode(null, $newNode, $actor, 'track.promote', [
        'track' => $track->name,
        'status' => TrackMoveStatus::ADDED_TO_FIRST_GROUP->value,
        'from' => null,
        'to' => $destination,
        'contexts' => $contexts->pairs()
      ]);
      $this->invalidate();
      return new TrackMoveResult(
        $action,
        TrackMoveStatus::ADDED_TO_FIRST_GROUP,
        $track->name,
        null,
        $destination,
        true
      );
    }

    $oldNode = $matching[0];
    $index = $track->indexOf($oldNode->key);
    if ($action === TrackMoveAction::PROMOTE) {
      if ($index === count($track->groups) - 1) {
        return new TrackMoveResult($action, TrackMoveStatus::END_OF_TRACK, $track->name, $oldNode->key);
      }
      $destination = $track->groups[$index + 1];
      $status = TrackMoveStatus::SUCCESS;
    } elseif ($index === 0) {
      if (!$crossBoundary) {
        return new TrackMoveResult($action, TrackMoveStatus::FIRST_GROUP_PROTECTED, $track->name, $oldNode->key);
      }
      $destination = null;
      $status = TrackMoveStatus::REMOVED_FROM_FIRST_GROUP;
    } else {
      $destination = $track->groups[$index - 1];
      $status = TrackMoveStatus::SUCCESS;
    }

    $newNode = $destination === null ? null : new Node(
      $user,
      NodeType::PARENT,
      $destination,
      'true',
      $oldNode->contexts,
      $oldNode->expiresAt,
      0,
      time()
    );
    $this->repository->replaceParentNode($oldNode, $newNode, $actor, 'track.' . $action->value, [
      'track' => $track->name,
      'status' => $status->value,
      'from' => $oldNode->key,
      'to' => $destination,
      'contexts' => $contexts->pairs(),
      'preserved_expiry' => $oldNode->expiresAt
    ]);
    $this->invalidate();
    return new TrackMoveResult($action, $status, $track->name, $oldNode->key, $destination, true);
  }

  /** @return list<Node> */
  private function activeDirectParents(SubjectRef $user, ?ContextSet $contexts): array {
    $now = time();
    $nodes = [];
    foreach ($this->repository->nodesFor($user, true) as $node) {
      if ($node->type !== NodeType::PARENT || !$node->activeAt($now)) {
        continue;
      }
      if ($contexts !== null && !$node->contexts->equals($contexts)) {
        continue;
      }
      $nodes[] = $node;
    }
    return $nodes;
  }

  private function invalidate(): void {
    $this->snapshotCache = [];
  }
}
