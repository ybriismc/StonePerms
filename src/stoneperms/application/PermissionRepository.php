<?php

declare(strict_types = 1);

namespace stoneperms\application;

use stoneperms\domain\ContextSet;
use stoneperms\domain\GroupRecord;
use stoneperms\domain\Node;
use stoneperms\domain\NodeType;
use stoneperms\domain\PermissionSnapshot;
use stoneperms\domain\PlayerProfile;
use stoneperms\domain\SubjectRef;
use stoneperms\domain\TrackRecord;
use stoneperms\domain\UserRecord;

/**
* The single seam between the permission logic and where the data lives.
*
* Every mutation bumps the revision, which is what lets the editor protocol
* detect that a changeset was built against stale data.
*/
interface PermissionRepository {

  public function revision(): int;

  public function initialize(string $defaultGroup): void;

  public function close(): void;

  public function upsertUser(UserRecord $user): void;

  public function findUser(string $identifier): ?UserRecord;

  /** @return list<UserRecord> */
  public function listUsers(): array;

  public function upsertPlayerProfile(PlayerProfile $profile): PlayerProfile;

  public function getPlayerProfile(string $identifier): ?PlayerProfile;

  /** @return list<PlayerProfile> */
  public function listPlayerProfiles(): array;

  public function createGroup(GroupRecord $group, string $actor): bool;

  public function getGroup(string $name): ?GroupRecord;

  /** @return list<GroupRecord> */
  public function listGroups(): array;

  public function setGroupWeight(string $name, int $weight, string $actor): void;

  public function deleteGroup(string $name, string $actor): bool;

  public function createTrack(TrackRecord $track, string $actor, string $action = 'track.create'): bool;

  public function getTrack(string $name): ?TrackRecord;

  /** @return list<TrackRecord> */
  public function listTracks(): array;

  /**
  * @param list<string> $groups
  * @param array<string, mixed> $details
  */
  public function setTrackGroups(
    string $name,
    array $groups,
    string $actor,
    string $action,
    array $details
  ): TrackRecord;

  public function renameTrack(string $name, string $newName, string $actor): bool;

  public function deleteTrack(string $name, string $actor): bool;

  public function saveNode(Node $node, string $actor, string $action): Node;

  public function removeNodes(
    SubjectRef $subject,
    NodeType $type,
    string $key,
    ContextSet $contexts,
    string $actor,
    string $action,
    ?bool $temporary = null,
    ?int $priority = null
  ): int;

  /**
  * Swaps one parent for another in a single transaction so a promote never
  * leaves the user briefly holding both groups or neither.
  *
  * @param array<string, mixed> $details
  */
  public function replaceParentNode(
    ?Node $oldNode,
    ?Node $newNode,
    string $actor,
    string $action,
    array $details
  ): ?Node;

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
  ): EditorStorageResult;

  /** @return list<Node> */
  public function nodesFor(SubjectRef $subject, bool $includeExpired = true): array;

  public function loadSnapshot(SubjectRef $user, string $defaultGroup): PermissionSnapshot;

  public function deleteExpired(int $timestamp): ExpiredNodes;

  /** @return list<array<string, mixed>> */
  public function recentAudit(int $limit = 20): array;

  /** @param array<string, mixed> $details */
  public function recordAudit(string $actor, string $action, array $details): void;
}
