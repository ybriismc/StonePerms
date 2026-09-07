<?php

declare(strict_types = 1);

namespace stoneperms\application;

use stoneperms\domain\ContextSet;
use stoneperms\domain\Node;
use stoneperms\domain\SubjectRef;
use stoneperms\domain\Validation;

/**
* Copies one permission store into another.
*
* Used to move a server that already has data onto a shared database. It is
* additive and it never overwrites: a group or a track the target already has
* is left exactly as it is and reported, so pointing two servers that both
* have a `default` group at one database cannot destroy either version.
*
* Nodes are written through `saveNode`, which replaces the same node on the
* same subject with the same contexts, so running a migration twice ends where
* running it once did.
*
* `serverScope` exists because merging changes what a node means. A node in
* one server's own file applied only to that server by accident of where it
* lived; in a shared database it applies everywhere. Giving the migration a
* scope tags every copied node with `server=<name>`, which keeps each server
* behaving exactly as it did until someone decides otherwise.
*/
final class StorageMigrator {

  public function __construct(
    private readonly PermissionRepository $source,
    private readonly PermissionRepository $target
  ) {}

  public function copy(string $actor, bool $dryRun, ?string $serverScope = null): StorageMigrationReport {
    $scope = $serverScope === null ? null : Validation::contextValue($serverScope);
    $notes = [];
    $groups = 0;
    $groupsKept = 0;
    $tracks = 0;
    $tracksKept = 0;
    $players = 0;
    $nodes = 0;

    $subjects = [];

    foreach ($this->source->listGroups() as $group) {
      $existing = $this->target->getGroup($group->name);
      if ($existing !== null) {
        $groupsKept++;
        if ($existing->weight !== $group->weight) {
          $notes[] = "group '{$group->name}' exists with weight {$existing->weight}, "
            . "not {$group->weight}; the one already there was kept";
        }
      } else {
        if (!$dryRun) {
          $this->target->createGroup($group, $actor);
        }
        $groups++;
      }
      $subjects[] = SubjectRef::group($group->name);
    }

    foreach ($this->source->listTracks() as $track) {
      if ($this->target->getTrack($track->name) !== null) {
        $tracksKept++;
        $notes[] = "track '{$track->name}' exists and was kept";
        continue;
      }
      if (!$dryRun) {
        $this->target->createTrack($track, $actor, 'storage.migrate');
      }
      $tracks++;
    }

    // The profile row carries the user row, so copying profiles covers both.
    foreach ($this->source->listPlayerProfiles() as $profile) {
      if (!$dryRun) {
        $this->target->upsertPlayerProfile($profile);
      }
      $players++;
      $subjects[] = SubjectRef::user($profile->uniqueId);
    }

    foreach ($subjects as $subject) {
      foreach ($this->source->nodesFor($subject, true) as $node) {
        if (!$dryRun) {
          $this->target->saveNode($this->scoped($node, $scope), $actor, 'storage.migrate');
        }
        $nodes++;
      }
    }

    if ($scope !== null) {
      $notes[] = "every copied node was scoped to server=$scope";
    }

    return new StorageMigrationReport($dryRun, $groups, $groupsKept, $tracks, $tracksKept, $players, $nodes, $notes);
  }

  private function scoped(Node $node, ?string $scope): Node {
    if ($scope === null) {
      return $node;
    }
    $pairs = $node->contexts->pairs();
    $pairs[] = ['server', $scope];
    return new Node(
      $node->subject,
      $node->type,
      $node->key,
      $node->value,
      ContextSet::of($pairs),
      $node->expiresAt,
      $node->priority,
      $node->createdAt
    );
  }
}
